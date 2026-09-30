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

use ProcessAndInvoice\Event\OrderProcessedEvent;
use ProcessAndInvoice\Event\ProcessAndInvoiceEvents;
use ProcessAndInvoice\Service\BatchException;
use ProcessAndInvoice\Service\BatchFailure;
use ProcessAndInvoice\Service\BatchProcessor;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\PdfEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Domain\Order\Service\OrderStatusTransitionGraphProvider;
use Thelia\Model\Order;
use Thelia\Model\OrderStatus;
use Thelia\Model\OrderStatusTransition;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * Runs on a disposable `*_test` database, inside the transaction IntegrationTestCase opens
 * and rolls back.
 */
final class BatchProcessorTest extends IntegrationTestCase
{
    use BatchTestTrait;

    private FixtureFactory $fixtures;

    /** @var array<int, int> order id => number of ORDER_UPDATE_STATUS dispatched */
    private array $statusChanges = [];

    /** @var list<int> order ids announced by ORDER_PROCESSED */
    private array $announced = [];

    private \Closure $statusListener;

    private \Closure $processedListener;

    protected function setUp(): void
    {
        $this->refuseAnyOtherDatabase();

        parent::setUp();
        $this->assertConnectedToTheTestDatabase();

        $this->fixtures = $this->createFixtureFactory();

        $this->statusListener = function (OrderEvent $event): void {
            $orderId = (int) $event->getOrder()->getId();
            $this->statusChanges[$orderId] = ($this->statusChanges[$orderId] ?? 0) + 1;
        };
        $this->processedListener = function (OrderProcessedEvent $event): void {
            $this->announced[] = (int) $event->getOrder()->getId();
        };

        // Above the core (128): counts every attempt, refused ones included.
        $this->dispatcher()->addListener(TheliaEvents::ORDER_UPDATE_STATUS, $this->statusListener, 200);
        $this->dispatcher()->addListener(ProcessAndInvoiceEvents::ORDER_PROCESSED, $this->processedListener);
    }

    protected function tearDown(): void
    {
        $this->dispatcher()->removeListener(TheliaEvents::ORDER_UPDATE_STATUS, $this->statusListener);
        $this->dispatcher()->removeListener(ProcessAndInvoiceEvents::ORDER_PROCESSED, $this->processedListener);
        $this->removeBatchFiles();

        parent::tearDown();
    }

    public function testOnlyTheOrdersPaidWhenTheBatchOpensArePrintedAndMovedToProcessing(): void
    {
        $first = $this->paidOrder();
        $second = $this->paidOrder();
        $unpaid = $this->fixtures->order(null, ['statusCode' => OrderStatus::CODE_NOT_PAID]);

        $state = $this->openBatch();

        self::assertFalse($state->resumed);
        self::assertSame(2, $state->orderCount);

        // Paid while the batch runs: it waits for the next one.
        $paidDuringTheBatch = $this->paidOrder();

        $step = $this->processor()->processNext($state->batch->id);
        self::assertSame([(int) $first->getId(), (int) $second->getId()], $step->processedOrderIds);
        self::assertSame([], $step->failures);
        self::assertSame(0, $step->pendingCount);

        $result = $this->processor()->complete($state->batch->id);

        self::assertSame(2, $result->processedCount);
        self::assertSame(OrderStatus::CODE_PROCESSING, $this->statusCodeOf($first));
        self::assertSame(OrderStatus::CODE_PROCESSING, $this->statusCodeOf($second));
        self::assertSame(OrderStatus::CODE_PAID, $this->statusCodeOf($paidDuringTheBatch), 'Paid after the batch opened: neither printed nor processed.');
        self::assertSame(OrderStatus::CODE_NOT_PAID, $this->statusCodeOf($unpaid));

        self::assertSame([(int) $first->getId() => 1, (int) $second->getId() => 1], $this->statusChanges, 'The status changes through the core event, once per order.');
        self::assertSame([(int) $first->getId(), (int) $second->getId()], $this->announced, 'Each processed order is announced once.');

        self::assertFileExists($result->documentPath);
        self::assertSame(
            $this->pageCount($this->printedInvoice($first)) + $this->pageCount($this->printedInvoice($second)) + 1,
            $this->pageCount($result->documentPath),
            'The document holds the two invoices, then the one-page report.',
        );
        self::assertSame($result->documentPath, $this->processor()->documentPath($state->batch->id));
        self::assertFileDoesNotExist($this->storage()->invoicePath($state->batch->id, (int) $first->getId()), 'Working files are merged, then removed.');
    }

    public function testAnInterruptedBatchIsResumedWithoutProcessingAnOrderTwice(): void
    {
        $first = $this->paidOrder();
        $second = $this->paidOrder();

        $state = $this->openBatch();
        $this->processor()->processNext($state->batch->id, 1);

        // The browser closed here: the button is clicked again.
        $resumed = $this->processor()->open(null);
        self::assertNotNull($resumed);

        self::assertTrue($resumed->resumed);
        self::assertSame($state->batch->id, $resumed->batch->id);
        self::assertSame(2, $resumed->orderCount);
        self::assertSame(1, $resumed->pendingCount);

        $step = $this->processor()->processNext($resumed->batch->id);
        self::assertSame([(int) $second->getId()], $step->processedOrderIds);

        $result = $this->processor()->complete($resumed->batch->id);

        self::assertSame(2, $result->processedCount);
        self::assertSame([(int) $first->getId() => 1, (int) $second->getId() => 1], $this->statusChanges);
        self::assertSame([(int) $first->getId(), (int) $second->getId()], $this->announced);
    }

    public function testAnOrderNoLongerPaidWhenItsTurnComesIsLeftOutOfTheDocument(): void
    {
        $kept = $this->paidOrder();
        $cancelled = $this->paidOrder();

        $state = $this->openBatch();

        $cancelled->setStatusId($this->statusId(OrderStatus::CODE_CANCELED))->save($this->getPropelConnection());

        $step = $this->processor()->processNext($state->batch->id);
        $result = $this->processor()->complete($state->batch->id);

        self::assertSame([(int) $kept->getId()], $step->processedOrderIds);
        self::assertEquals([new BatchFailure((int) $cancelled->getId(), (string) $cancelled->getRef(), BatchProcessor::FAILURE_STATUS_CHANGED)], $result->failures);
        self::assertSame(1, $result->processedCount);
        self::assertSame(OrderStatus::CODE_CANCELED, $this->statusCodeOf($cancelled));
        self::assertArrayNotHasKey((int) $cancelled->getId(), $this->statusChanges);
        self::assertSame([(int) $kept->getId()], $this->announced);
    }

    public function testATransitionRefusedByTheGraphIsListedAndTheBatchGoesOn(): void
    {
        $order = $this->paidOrder();

        // Declaring one way out of "paid" makes the graph refuse every other one.
        $transition = new OrderStatusTransition();
        $transition->setFromStatusId($this->statusId(OrderStatus::CODE_PAID));
        $transition->setToStatusId($this->statusId(OrderStatus::CODE_SENT));
        $transition->save($this->getPropelConnection());
        $this->graphProvider()->reset();

        try {
            $state = $this->openBatch();

            $step = $this->processor()->processNext($state->batch->id);
            self::assertFileDoesNotExist($this->storage()->invoicePath($state->batch->id, (int) $order->getId()), 'The invoice of an order left out is dropped at once.');
            $result = $this->processor()->complete($state->batch->id);
        } finally {
            $this->graphProvider()->reset();
        }

        self::assertSame([], $step->processedOrderIds);
        self::assertEquals([new BatchFailure((int) $order->getId(), (string) $order->getRef(), BatchProcessor::FAILURE_TRANSITION_REFUSED)], $step->failures);
        self::assertSame(0, $result->processedCount);
        self::assertSame(1, $this->pageCount($result->documentPath), 'Nothing processed, nothing printed: the report only.');
        self::assertSame(OrderStatus::CODE_PAID, $this->statusCodeOf($order));
        self::assertSame([], $this->announced);
    }

    public function testAnOrderCancelledWhileTheStepRunsIsNotProcessed(): void
    {
        $first = $this->paidOrder();
        $second = $this->paidOrder();
        $state = $this->openBatch();

        // The second order is cancelled while the step handles the first one, after the step
        // listed its orders: its status must be read again at its turn.
        $cancelledStatusId = $this->statusId(OrderStatus::CODE_CANCELED);
        $connection = $this->getPropelConnection();
        $cancelSecond = static function (OrderEvent $event) use ($first, $second, $cancelledStatusId, $connection): void {
            if ($event->getOrder()->getId() === $first->getId()) {
                $connection->exec(\sprintf('UPDATE `order` SET status_id = %d WHERE id = %d', $cancelledStatusId, $second->getId()));
            }
        };
        $this->dispatcher()->addListener(TheliaEvents::ORDER_UPDATE_STATUS, $cancelSecond, 300);

        try {
            $step = $this->processor()->processNext($state->batch->id);
        } finally {
            $this->dispatcher()->removeListener(TheliaEvents::ORDER_UPDATE_STATUS, $cancelSecond);
        }

        self::assertSame([(int) $first->getId()], $step->processedOrderIds);
        self::assertEquals([new BatchFailure((int) $second->getId(), (string) $second->getRef(), BatchProcessor::FAILURE_STATUS_CHANGED)], $step->failures);
        self::assertSame(OrderStatus::CODE_CANCELED, $this->statusCodeOf($second));
        self::assertArrayNotHasKey((int) $second->getId(), $this->statusChanges);
        self::assertSame([(int) $first->getId()], $this->announced);
    }

    public function testAnInvoiceTheMergeCannotReadLeavesTheOrderPaid(): void
    {
        $order = $this->paidOrder();
        $state = $this->openBatch();

        // After the core rendering (128): the invoice comes out as something that is not a PDF.
        $corrupt = static function (PdfEvent $event): void {
            $event->setPdf('not a PDF');
        };
        $this->dispatcher()->addListener(TheliaEvents::GENERATE_PDF, $corrupt, 0);

        try {
            $step = $this->processor()->processNext($state->batch->id);
        } finally {
            $this->dispatcher()->removeListener(TheliaEvents::GENERATE_PDF, $corrupt);
        }

        self::assertEquals([new BatchFailure((int) $order->getId(), (string) $order->getRef(), BatchProcessor::FAILURE_INVOICE)], $step->failures);
        self::assertSame(OrderStatus::CODE_PAID, $this->statusCodeOf($order));
        self::assertArrayNotHasKey((int) $order->getId(), $this->statusChanges);

        $result = $this->processor()->complete($state->batch->id);
        self::assertSame(1, $this->pageCount((string) $result->documentPath), 'The batch completes, with the report only.');
    }

    public function testABatchWhoseDocumentCannotBeBuiltCanBeClosed(): void
    {
        $processed = $this->paidOrder();
        $state = $this->openBatch();
        $this->processor()->processNext($state->batch->id);

        // The printed invoice gets damaged on disk: the merge of the whole batch fails.
        file_put_contents($this->storage()->invoicePath($state->batch->id, (int) $processed->getId()), 'damaged');

        try {
            $this->processor()->complete($state->batch->id);
            self::fail('The merge of a damaged invoice must fail.');
        } catch (\Throwable) {
        }

        $closed = $this->processor()->close($state->batch->id);

        self::assertNull($closed->documentPath);
        self::assertTrue($closed->batch->isCompleted());
        self::assertSame(1, $closed->processedCount);
        self::assertNull($this->repository()->findOpen());
    }

    public function testClosingABatchLeavesItsPendingOrdersPaidAndLetsANewBatchOpen(): void
    {
        $processed = $this->paidOrder();
        $pending = $this->paidOrder();
        $state = $this->openBatch();
        $this->processor()->processNext($state->batch->id, 1);

        $closed = $this->processor()->close($state->batch->id);

        self::assertSame(1, $closed->processedCount);
        self::assertEquals([new BatchFailure((int) $pending->getId(), (string) $pending->getRef(), BatchProcessor::FAILURE_BATCH_CLOSED)], $closed->failures);
        self::assertNotNull($closed->documentPath);
        self::assertSame(2, $this->pageCount($closed->documentPath) - $this->pageCount($this->printedInvoice($processed)) + 1, 'The processed invoice, then the report.');
        self::assertSame(OrderStatus::CODE_PROCESSING, $this->statusCodeOf($processed));
        self::assertSame(OrderStatus::CODE_PAID, $this->statusCodeOf($pending));

        $next = $this->openBatch();
        self::assertNotSame($state->batch->id, $next->batch->id);
        self::assertFalse($next->resumed);
        self::assertSame(1, $next->orderCount);
    }

    public function testNothingOpensWhenNoOrderIsPaidAndThePreviousDocumentIsKept(): void
    {
        $this->paidOrder();
        $previous = $this->openBatch();
        $this->processor()->processNext($previous->batch->id);
        $document = $this->processor()->complete($previous->batch->id)->documentPath;

        self::assertNull($this->processor()->open(null));
        self::assertFileExists((string) $document);
        self::assertSame($document, $this->processor()->documentPath($previous->batch->id));
    }

    public function testACompleteBatchCannotBeProcessedAgain(): void
    {
        $this->paidOrder();
        $state = $this->openBatch();
        $this->processor()->processNext($state->batch->id);
        $this->processor()->complete($state->batch->id);

        $this->expectException(BatchException::class);
        $this->expectExceptionMessage(BatchException::ALREADY_COMPLETED);

        $this->processor()->processNext($state->batch->id);
    }

    public function testABatchIsNotCompletedWhileOrdersAreWaiting(): void
    {
        $this->paidOrder();
        $state = $this->openBatch();

        $this->expectException(BatchException::class);
        $this->expectExceptionMessage(BatchException::NOT_FINISHED);

        $this->processor()->complete($state->batch->id);
    }

    private function paidOrder(): Order
    {
        return $this->fixtures->order(null, ['statusCode' => OrderStatus::CODE_PAID]);
    }

    /**
     * The invoice of an order as the step prints it, rendered again for the page count.
     */
    private function printedInvoice(Order $order): string
    {
        $renderer = static::getContainer()->get(\ProcessAndInvoice\Service\InvoicePdfRenderer::class);
        self::assertInstanceOf(\ProcessAndInvoice\Service\InvoicePdfRenderer::class, $renderer);

        $path = tempnam(sys_get_temp_dir(), 'invoice');
        file_put_contents($path, $renderer->invoice($order));

        return $path;
    }

    private function graphProvider(): OrderStatusTransitionGraphProvider
    {
        $provider = static::getContainer()->get(OrderStatusTransitionGraphProvider::class);
        self::assertInstanceOf(OrderStatusTransitionGraphProvider::class, $provider);

        return $provider;
    }
}
