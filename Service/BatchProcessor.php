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

namespace ProcessAndInvoice\Service;

use ProcessAndInvoice\Event\OrderProcessedEvent;
use ProcessAndInvoice\Event\ProcessAndInvoiceEvents;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Domain\Order\Exception\OrderStatusTransitionRefusedException;
use Thelia\Model\Map\OrderTableMap;
use Thelia\Model\Order;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatus;
use Thelia\Model\OrderStatusQuery;

/**
 * Prints the invoices of the paid orders and moves exactly these orders to "processing".
 *
 * A batch takes the paid orders once, when it is opened: an order paid afterwards waits for
 * the next batch. Each step then handles the next orders one by one, each in its own
 * transaction: the order row is locked and its status read again, the order of the batch is
 * reserved (a concurrent step skips it), the invoice is printed and checked, then the core
 * status change (TheliaEvents::ORDER_UPDATE_STATUS, so the transition graph, the stock rules
 * and the configured status actions apply) is dispatched. An order the step cannot print or
 * move is rolled back, recorded as a failure, its invoice left out of the document, and the
 * step goes on. Interrupted, the batch is resumed where it stopped: an order is either
 * processed and recorded, or neither.
 */
final readonly class BatchProcessor
{
    public const STEP_SIZE = 10;

    public const FAILURE_STATUS_CHANGED = 'status_changed';
    public const FAILURE_INVOICE = 'invoice_failed';
    public const FAILURE_TRANSITION_REFUSED = 'transition_refused';
    public const FAILURE_STATUS_UPDATE = 'status_update_failed';
    public const FAILURE_BATCH_CLOSED = 'batch_closed';

    private const OUTCOME_PROCESSED = 'processed';
    private const OUTCOME_SKIPPED = 'skipped';

    public function __construct(
        private BatchRepository $repository,
        private BatchStorage $storage,
        private InvoicePdfRenderer $renderer,
        private PdfMerger $merger,
        private EventDispatcherInterface $dispatcher,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Opens a batch on the orders paid right now, or returns the batch still open: a single
     * batch runs at a time, so an interrupted one is resumed instead of taking its orders twice.
     * Returns null when no batch is open and no order is paid: there is nothing to process, and
     * the document of the previous batch is kept.
     */
    public function open(?int $adminId): ?BatchState
    {
        $openBatch = $this->repository->findOpen();

        if (null !== $openBatch) {
            return $this->state($openBatch, true);
        }

        $paidStatusId = $this->statusId(OrderStatus::CODE_PAID);

        if (0 === $this->repository->countOrdersInStatus($paidStatusId)) {
            return null;
        }

        $connection = $this->repository->connection();
        $connection->beginTransaction();

        try {
            $batchId = $this->repository->open($adminId, $paidStatusId);
            $connection->commit();
        } catch (\PDOException $exception) {
            $connection->rollBack();

            // Opened meanwhile from another tab or by another administrator.
            $openBatch = $this->repository->findOpen() ?? throw $exception;

            return $this->state($openBatch, true);
        }

        $this->storage->removeAllExcept($batchId);

        return $this->state($this->requireBatch($batchId), false);
    }

    public function processNext(int $batchId, int $stepSize = self::STEP_SIZE): StepResult
    {
        $batch = $this->requireOpenBatch($batchId);
        $paidStatusId = $this->statusId(OrderStatus::CODE_PAID);
        $processingStatusId = $this->statusId(OrderStatus::CODE_PROCESSING);

        $processedOrderIds = [];
        $failures = [];

        foreach ($this->repository->pendingOrderIds($batch->id, $stepSize) as $orderId) {
            $outcome = $this->processOrder($batch->id, $orderId, $paidStatusId, $processingStatusId);

            if (self::OUTCOME_PROCESSED === $outcome) {
                $processedOrderIds[] = $orderId;

                continue;
            }

            if (self::OUTCOME_SKIPPED === $outcome || !$this->repository->markFailed($batch->id, $orderId, $outcome)) {
                continue;
            }

            $failures[] = new BatchFailure($orderId, $this->repository->orderReference($orderId), $outcome);
        }

        return new StepResult($processedOrderIds, $failures, $this->repository->countPending($batch->id));
    }

    /**
     * Prints the report of the processed orders and merges it after their invoices, in order
     * id order, into the batch document, then closes the batch.
     */
    public function complete(int $batchId): CompletionResult
    {
        $batch = $this->requireOpenBatch($batchId);

        if ($this->repository->countPending($batch->id) > 0) {
            throw BatchException::notFinished();
        }

        $documentPath = $this->buildDocument($batch->id);
        $this->repository->complete($batch->id);
        $this->storage->removeWorkingFiles($batch->id);

        return $this->completion($batch->id, $documentPath);
    }

    /**
     * Closes a batch whatever its state, when it cannot go on (a document that cannot be built,
     * an order that keeps failing): the orders still pending are recorded as left out and keep
     * their status, the document is built from the processed orders when it can be, and the
     * next click opens a new batch.
     */
    public function close(int $batchId): CompletionResult
    {
        $batch = $this->requireOpenBatch($batchId);

        $this->repository->markPendingFailed($batch->id, self::FAILURE_BATCH_CLOSED);

        try {
            $documentPath = $this->buildDocument($batch->id);
        } catch (\Throwable $exception) {
            $documentPath = null;
            $this->logger->error('ProcessAndInvoice: batch {batchId} closed without its document: {message}', [
                'batchId' => $batch->id,
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
        }

        $this->repository->complete($batch->id);

        if (null !== $documentPath) {
            $this->storage->removeWorkingFiles($batch->id);
        }

        return $this->completion($batch->id, $documentPath);
    }

    /**
     * The document of a complete batch, or null when there is none to download.
     */
    public function documentPath(int $batchId): ?string
    {
        $batch = $this->repository->find($batchId);

        if (null === $batch || !$batch->isCompleted()) {
            return null;
        }

        $documentPath = $this->storage->documentPath($batch->id);

        return is_file($documentPath) ? $documentPath : null;
    }

    public function batch(int $batchId): ?Batch
    {
        return $this->repository->find($batchId);
    }

    /**
     * @return string OUTCOME_PROCESSED, OUTCOME_SKIPPED (handled by a concurrent step, or gone)
     *                or one of the FAILURE_* codes
     */
    private function processOrder(int $batchId, int $orderId, int $paidStatusId, int $processingStatusId): string
    {
        $connection = $this->repository->connection();
        $connection->beginTransaction();

        $invoicePath = $this->storage->invoicePath($batchId, $orderId);

        try {
            $currentStatusId = $this->repository->lockOrderStatus($orderId);

            if (null === $currentStatusId || !$this->repository->reserve($batchId, $orderId)) {
                $connection->rollBack();

                return self::OUTCOME_SKIPPED;
            }

            // Paid when the batch was opened, but moved since (by hand, a refund...): not ours anymore.
            if ($paidStatusId !== $currentStatusId) {
                $connection->rollBack();

                return self::FAILURE_STATUS_CHANGED;
            }

            // Read again under the lock, not from the instance pool.
            OrderTableMap::removeInstanceFromPool($orderId);
            $order = OrderQuery::create()->findPk($orderId) ?? throw new \RuntimeException(\sprintf('Order %d vanished under its lock.', $orderId));

            $invoice = $this->printInvoice($order);

            if (null === $invoice) {
                $connection->rollBack();

                return self::FAILURE_INVOICE;
            }

            $this->storage->write($invoicePath, $invoice);

            $event = new OrderEvent($order);
            $event->setStatus($processingStatusId);
            $this->dispatcher->dispatch($event, TheliaEvents::ORDER_UPDATE_STATUS);

            $this->repository->markProcessed($batchId, $orderId, \sprintf('%.6F', $event->getOrder()->getTotalAmount()));
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            $this->storage->remove($invoicePath);

            if ($exception instanceof OrderStatusTransitionRefusedException) {
                return self::FAILURE_TRANSITION_REFUSED;
            }

            $this->logFailure('move to processing', $orderId, $exception);

            return self::FAILURE_STATUS_UPDATE;
        }

        // Announced once the change is committed, so a listener never acts on a change
        // that is rolled back. A failing listener does not undo the processing.
        try {
            $this->dispatcher->dispatch(new OrderProcessedEvent($event->getOrder(), $batchId), ProcessAndInvoiceEvents::ORDER_PROCESSED);
        } catch (\Throwable $exception) {
            $this->logFailure('announce the processing of', $orderId, $exception);
        }

        return self::OUTCOME_PROCESSED;
    }

    /**
     * The invoice, checked readable by the merge before the order is moved: an invoice the
     * merge cannot read would block the document of the whole batch.
     */
    private function printInvoice(Order $order): ?string
    {
        try {
            $invoice = $this->renderer->invoice($order);
        } catch (\Throwable $exception) {
            $this->logFailure('print the invoice of', (int) $order->getId(), $exception);

            return null;
        }

        if (!$this->merger->isReadable($invoice)) {
            $this->logger->error('ProcessAndInvoice: the invoice of order #{orderId} is not a readable PDF.', ['orderId' => $order->getId()]);

            return null;
        }

        return $invoice;
    }

    private function buildDocument(int $batchId): string
    {
        $rows = $this->repository->processedRows($batchId);
        $totalTurnover = array_reduce($rows, static fn (float $total, ReportRow $row): float => $total + (float) $row->totalAmount, 0.0);

        $sourcePaths = [];
        foreach ($rows as $row) {
            $sourcePaths[] = $this->invoiceFile($batchId, $row->orderId);
        }

        $reportPath = $this->storage->reportPath($batchId);
        $this->storage->write($reportPath, $this->renderer->report($rows, number_format($totalTurnover, 2, '.', '')));
        $sourcePaths[] = $reportPath;

        $documentPath = $this->storage->documentPath($batchId);
        $this->merger->merge($sourcePaths, $documentPath);

        return $documentPath;
    }

    private function completion(int $batchId, ?string $documentPath): CompletionResult
    {
        return new CompletionResult(
            batch: $this->requireBatch($batchId),
            processedCount: \count($this->repository->processedRows($batchId)),
            failures: $this->repository->failures($batchId),
            documentPath: $documentPath,
        );
    }

    /**
     * The invoice printed by the step; printed again if its file went missing since.
     */
    private function invoiceFile(int $batchId, int $orderId): string
    {
        $invoicePath = $this->storage->invoicePath($batchId, $orderId);

        if (is_file($invoicePath)) {
            return $invoicePath;
        }

        $order = OrderQuery::create()->findPk($orderId) ?? throw new \RuntimeException(\sprintf('Order %d of batch %d no longer exists.', $orderId, $batchId));
        $this->storage->write($invoicePath, $this->renderer->invoice($order));

        return $invoicePath;
    }

    private function state(Batch $batch, bool $resumed): BatchState
    {
        return new BatchState(
            batch: $batch,
            orderCount: $this->repository->countOrders($batch->id),
            pendingCount: $this->repository->countPending($batch->id),
            resumed: $resumed,
        );
    }

    private function requireBatch(int $batchId): Batch
    {
        return $this->repository->find($batchId) ?? throw BatchException::notFound();
    }

    private function requireOpenBatch(int $batchId): Batch
    {
        $batch = $this->requireBatch($batchId);

        if ($batch->isCompleted()) {
            throw BatchException::alreadyCompleted();
        }

        return $batch;
    }

    private function statusId(string $code): int
    {
        $status = OrderStatusQuery::create()->findOneByCode($code) ?? throw BatchException::missingStatus();

        return (int) $status->getId();
    }

    private function logFailure(string $action, int $orderId, \Throwable $exception): void
    {
        $this->logger->error('ProcessAndInvoice: could not {action} order #{orderId}: {message}', [
            'action' => $action,
            'orderId' => $orderId,
            'message' => $exception->getMessage(),
            'exception' => $exception,
        ]);
    }
}
