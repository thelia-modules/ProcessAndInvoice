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

use ProcessAndInvoice\Event\ProcessAndInvoiceEvents;
use ProcessAndInvoice\Service\BatchFailure;
use ProcessAndInvoice\Service\BatchProcessor;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\CartQuery;
use Thelia\Model\CustomerQuery;
use Thelia\Model\Order;
use Thelia\Model\OrderAddressQuery;
use Thelia\Model\OrderStatus;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * Runs without the transaction IntegrationTestCase normally opens ($useTransaction = false):
 * the transaction of each order must be the outermost one for its rollback to really undo
 * the status change, the way it does in production. Fixtures are removed by hand.
 *
 * Runs on a disposable `*_test` database only.
 */
final class BatchTransactionTest extends IntegrationTestCase
{
    use BatchTestTrait;

    protected bool $useTransaction = false;

    private FixtureFactory $fixtures;

    /** @var list<Order> */
    private array $orders = [];

    /** @var list<array{string, callable}> */
    private array $listeners = [];

    protected function setUp(): void
    {
        $this->refuseAnyOtherDatabase();

        parent::setUp();
        $this->assertConnectedToTheTestDatabase();

        $this->fixtures = $this->createFixtureFactory();
    }

    protected function tearDown(): void
    {
        foreach ($this->listeners as [$eventName, $listener]) {
            $this->dispatcher()->removeListener($eventName, $listener);
        }

        $this->removeBatchFiles();

        foreach ($this->batchIds as $batchId) {
            $this->repository()->connection()->prepare('DELETE FROM process_and_invoice_batch WHERE id = :id')->execute(['id' => $batchId]);
        }

        foreach ($this->orders as $order) {
            $cartId = $order->getCartId();
            $customerId = $order->getCustomerId();
            $invoiceAddressId = $order->getInvoiceOrderAddressId();
            $deliveryAddressId = $order->getDeliveryOrderAddressId();

            $order->delete();
            CartQuery::create()->findPk($cartId)?->delete();
            CustomerQuery::create()->findPk($customerId)?->delete();
            OrderAddressQuery::create()->findPk($invoiceAddressId)?->delete();
            OrderAddressQuery::create()->findPk($deliveryAddressId)?->delete();
        }

        parent::tearDown();
    }

    public function testAFailureAfterTheStatusChangeRollsTheOrderBackToPaidAndTheBatchGoesOn(): void
    {
        $failing = $this->paidOrder();
        $processed = $this->paidOrder();

        $announced = [];
        // Below the core status write (128): the status is already saved when this fails.
        $this->listen(TheliaEvents::ORDER_UPDATE_STATUS, static function ($event) use ($failing): void {
            if ($event->getOrder()->getId() === $failing->getId()) {
                throw new \RuntimeException('Forced failure for the test.');
            }
        }, 20);
        $this->listen(ProcessAndInvoiceEvents::ORDER_PROCESSED, static function ($event) use (&$announced): void {
            $announced[] = (int) $event->getOrder()->getId();
        });

        $state = $this->openBatch();
        $step = $this->processor()->processNext($state->batch->id);
        $result = $this->processor()->complete($state->batch->id);

        self::assertSame(OrderStatus::CODE_PAID, $this->statusCodeOf($failing), 'The status change is rolled back with the failure.');
        self::assertSame(OrderStatus::CODE_PROCESSING, $this->statusCodeOf($processed));
        self::assertEquals([new BatchFailure((int) $failing->getId(), (string) $failing->getRef(), BatchProcessor::FAILURE_STATUS_UPDATE)], $step->failures);
        self::assertSame([(int) $processed->getId()], $announced, 'An order rolled back is never announced.');
        self::assertSame(1, $result->processedCount);
    }

    public function testAFailingListenerOfTheAnnouncementDoesNotUndoTheProcessing(): void
    {
        $order = $this->paidOrder();

        $this->listen(ProcessAndInvoiceEvents::ORDER_PROCESSED, static function (): void {
            throw new \RuntimeException('Forced failure for the test.');
        });

        $state = $this->openBatch();
        $step = $this->processor()->processNext($state->batch->id);

        self::assertSame([(int) $order->getId()], $step->processedOrderIds);
        self::assertSame(OrderStatus::CODE_PROCESSING, $this->statusCodeOf($order));

        $this->processor()->complete($state->batch->id);
    }

    private function paidOrder(): Order
    {
        return $this->orders[] = $this->fixtures->order(null, ['statusCode' => OrderStatus::CODE_PAID]);
    }

    private function listen(string $eventName, callable $listener, int $priority = 0): void
    {
        $this->dispatcher()->addListener($eventName, $listener, $priority);
        $this->listeners[] = [$eventName, $listener];
    }
}
