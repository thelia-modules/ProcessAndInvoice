<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ProcessAndInvoice\Tests;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\Admin;
use Thelia\Model\ConfigQuery;
use Thelia\Model\OrderStatus;
use Thelia\Test\IntegrationTestCase;

/**
 * The requests go through the kernel itself. Runs on a disposable `*_test` database only.
 */
final class BatchControllerTest extends IntegrationTestCase
{
    use BatchTestTrait;

    private const TOKEN = 'process-and-invoice-test-token';

    protected function setUp(): void
    {
        $this->refuseAnyOtherDatabase();

        parent::setUp();
        $this->assertConnectedToTheTestDatabase();
    }

    protected function tearDown(): void
    {
        $this->removeBatchFiles();

        parent::tearDown();
    }

    public function testTheWholeProcessingRunsThroughTheRoutes(): void
    {
        $order = $this->createFixtureFactory()->order(null, ['statusCode' => OrderStatus::CODE_PAID]);
        $session = $this->adminSession($this->createFixtureFactory()->admin());

        $opened = $this->post('/admin/module/ProcessAndInvoice/batch', $session, self::TOKEN);
        self::assertSame(200, $opened->getStatusCode(), (string) $opened->getContent());
        $batch = json_decode((string) $opened->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        $this->batchIds[] = $batch['batchId'];
        self::assertSame(1, $batch['pendingCount']);

        $step = $this->post('/admin/module/ProcessAndInvoice/batch/'.$batch['batchId'].'/step', $session, self::TOKEN);
        self::assertSame(200, $step->getStatusCode(), (string) $step->getContent());

        $completed = $this->post('/admin/module/ProcessAndInvoice/batch/'.$batch['batchId'].'/complete', $session, self::TOKEN);
        self::assertSame(200, $completed->getStatusCode(), (string) $completed->getContent());
        $completion = json_decode((string) $completed->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(1, $completion['processedCount']);
        self::assertSame(OrderStatus::CODE_PROCESSING, $this->statusCodeOf($order));

        $request = Request::create($completion['downloadUrl'], 'GET');
        $request->setSession($session);
        $download = $this->handleAsMainRequest($request);

        self::assertInstanceOf(BinaryFileResponse::class, $download);
        self::assertSame('application/pdf', $download->headers->get('Content-Type'));
        self::assertStringContainsString('attachment; filename=ordersInvoice_', (string) $download->headers->get('Content-Disposition'));
    }

    public function testTheButtonSitsAboveTheOrderListOfTheTwigBackOffice(): void
    {
        ConfigQuery::write('active-admin-template', 'default-twig');

        $request = Request::create('/admin/orders', 'GET');
        $request->setSession($this->adminSession($this->createFixtureFactory()->admin()));
        $response = $this->handleAsMainRequest($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('id="process-and-invoice-button"', (string) $response->getContent());
        self::assertMatchesRegularExpression('/data-token="[^"]+"/', (string) $response->getContent(), 'The button carries the back-office token.');
        self::assertMatchesRegularExpression('#data-open-url="[^"]*/admin/module/ProcessAndInvoice/batch"#', (string) $response->getContent());
        self::assertMatchesRegularExpression('#data-step-url="[^"]*/admin/module/ProcessAndInvoice/batch/0/step"#', (string) $response->getContent(), 'The script replaces /batch/0/ by the real batch.');
    }

    public function testTheCloseRouteEndsABatchThatCannotGoOn(): void
    {
        $pending = $this->createFixtureFactory()->order(null, ['statusCode' => OrderStatus::CODE_PAID]);
        $session = $this->adminSession($this->createFixtureFactory()->admin());
        $state = $this->openBatch();

        $refused = $this->post('/admin/module/ProcessAndInvoice/batch/'.$state->batch->id.'/close', $session, 'not-the-token');
        self::assertSame(403, $refused->getStatusCode());
        self::assertNotNull($this->repository()->findOpen());

        $closed = $this->post('/admin/module/ProcessAndInvoice/batch/'.$state->batch->id.'/close', $session, self::TOKEN);
        self::assertSame(200, $closed->getStatusCode(), (string) $closed->getContent());
        $payload = json_decode((string) $closed->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(0, $payload['processedCount']);
        self::assertSame([['reference' => (string) $pending->getRef(), 'reason' => 'The batch was closed before its turn']], $payload['failures']);
        self::assertNull($this->repository()->findOpen());
        self::assertSame(OrderStatus::CODE_PAID, $this->statusCodeOf($pending));
    }

    public function testNoPaidOrderAnswersNothingToProcess(): void
    {
        $response = $this->post('/admin/module/ProcessAndInvoice/batch', $this->adminSession($this->createFixtureFactory()->admin()), self::TOKEN);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertTrue(json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR)['nothingToProcess']);
        self::assertNull($this->repository()->findOpen());
    }

    public function testAMissingOrWrongTokenOpensNothing(): void
    {
        $this->createFixtureFactory()->order(null, ['statusCode' => OrderStatus::CODE_PAID]);
        $session = $this->adminSession($this->createFixtureFactory()->admin());

        $response = $this->post('/admin/module/ProcessAndInvoice/batch', $session, 'not-the-token');

        self::assertSame(403, $response->getStatusCode());
        self::assertNull($this->repository()->findOpen());
    }

    public function testAnAdministratorWithoutTheOrderUpdateRightOpensNothing(): void
    {
        $this->createFixtureFactory()->order(null, ['statusCode' => OrderStatus::CODE_PAID]);
        $viewer = $this->createFixtureFactory()->restrictedAdmin([AdminResources::ORDER => [AccessManager::VIEW]]);

        $response = $this->post('/admin/module/ProcessAndInvoice/batch', $this->adminSession($viewer), self::TOKEN);

        self::assertSame(403, $response->getStatusCode());
        self::assertNull($this->repository()->findOpen());
    }

    private function post(string $uri, Session $session, string $token): Response
    {
        $request = Request::create($uri, 'POST', ['_token' => $token]);
        $request->setSession($session);

        return $this->handleAsMainRequest($request);
    }

    private function adminSession(Admin $admin): Session
    {
        $session = new Session(new MockArraySessionStorage());
        $session->setAdminUser($admin);
        $session->set((string) static::getContainer()->getParameter('thelia.token_id'), self::TOKEN);

        return $session;
    }

    /**
     * IntegrationTestCase pushes a synthetic request: it is taken off the stack while the
     * kernel handles this one, so that this request is the main request the controller reads.
     */
    private function handleAsMainRequest(Request $request): Response
    {
        $requestStack = static::getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $requestStack);

        $pushedRequests = [];
        while (null !== $pushedRequest = $requestStack->pop()) {
            $pushedRequests[] = $pushedRequest;
        }

        try {
            return self::$kernel->handle($request);
        } finally {
            while (null !== $requestStack->getCurrentRequest()) {
                $requestStack->pop();
            }
            foreach (array_reverse($pushedRequests) as $pushedRequest) {
                $requestStack->push($pushedRequest);
            }
        }
    }
}
