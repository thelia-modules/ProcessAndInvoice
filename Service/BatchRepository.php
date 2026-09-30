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

use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Connection\StatementInterface;
use Propel\Runtime\Propel;
use Thelia\Model\Map\OrderTableMap;

/**
 * The batch tables are the module's own bookkeeping, read and written in plain SQL on the
 * connection the core uses for orders: a batch step can then wrap the core status change
 * and its own record in one transaction.
 */
final readonly class BatchRepository
{
    public function __construct(
        private ?ConnectionInterface $connection = null,
    ) {
    }

    public function connection(): ConnectionInterface
    {
        return $this->connection ?? Propel::getConnection(OrderTableMap::DATABASE_NAME);
    }

    public function findOpen(): ?Batch
    {
        return $this->fetchBatch('SELECT * FROM process_and_invoice_batch WHERE open_marker = 1', []);
    }

    public function find(int $batchId): ?Batch
    {
        return $this->fetchBatch('SELECT * FROM process_and_invoice_batch WHERE id = :id', ['id' => $batchId]);
    }

    /**
     * Opens a batch and takes, in the same statement, every order holding the given status.
     * The unique index on `open_marker` refuses a second open batch: the caller gets the
     * database error and reads the batch already open.
     *
     * @throws \PDOException when another batch is already open
     */
    public function open(?int $adminId, int $paidStatusId): int
    {
        $this->execute(
            'INSERT INTO process_and_invoice_batch (admin_id, open_marker, created_at) VALUES (:admin_id, 1, NOW())',
            ['admin_id' => $adminId],
        );

        $batchId = (int) $this->connection()->lastInsertId();

        $this->execute(
            'INSERT INTO process_and_invoice_batch_order (batch_id, order_id)
             SELECT :batch_id, o.id FROM `order` o WHERE o.status_id = :status_id',
            ['batch_id' => $batchId, 'status_id' => $paidStatusId],
        );

        return $batchId;
    }

    public function countOrders(int $batchId): int
    {
        return (int) $this->fetchColumn('SELECT COUNT(*) FROM process_and_invoice_batch_order WHERE batch_id = :batch_id', ['batch_id' => $batchId]);
    }

    public function countPending(int $batchId): int
    {
        return (int) $this->fetchColumn(
            'SELECT COUNT(*) FROM process_and_invoice_batch_order WHERE batch_id = :batch_id AND processed_at IS NULL AND failure IS NULL',
            ['batch_id' => $batchId],
        );
    }

    /**
     * @return list<int>
     */
    public function pendingOrderIds(int $batchId, int $limit): array
    {
        $statement = $this->execute(
            'SELECT order_id FROM process_and_invoice_batch_order
             WHERE batch_id = :batch_id AND processed_at IS NULL AND failure IS NULL
             ORDER BY order_id
             LIMIT '.max(1, $limit),
            ['batch_id' => $batchId],
        );

        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function countOrdersInStatus(int $statusId): int
    {
        return (int) $this->fetchColumn('SELECT COUNT(*) FROM `order` WHERE status_id = :status_id', ['status_id' => $statusId]);
    }

    /**
     * Locks the order row until the end of the running transaction and returns its current
     * status, or null when the order no longer exists.
     */
    public function lockOrderStatus(int $orderId): ?int
    {
        $statusId = $this->fetchColumn('SELECT status_id FROM `order` WHERE id = :id FOR UPDATE', ['id' => $orderId]);

        return false === $statusId || null === $statusId ? null : (int) $statusId;
    }

    /**
     * Locks the order of the batch until the end of the running transaction when it is still
     * pending. Returns false when another step already processed it or recorded a failure: a
     * concurrent step waits on this lock, then finds the order done.
     */
    public function reserve(int $batchId, int $orderId): bool
    {
        return false !== $this->fetchColumn(
            'SELECT id FROM process_and_invoice_batch_order
             WHERE batch_id = :batch_id AND order_id = :order_id AND processed_at IS NULL AND failure IS NULL
             FOR UPDATE',
            ['batch_id' => $batchId, 'order_id' => $orderId],
        );
    }

    /**
     * @throws \RuntimeException when the order is no longer pending, which the lock taken by
     *                           reserve() rules out
     */
    public function markProcessed(int $batchId, int $orderId, string $totalAmount): void
    {
        $updated = $this->execute(
            'UPDATE process_and_invoice_batch_order SET processed_at = NOW(), total_amount = :total_amount
             WHERE batch_id = :batch_id AND order_id = :order_id AND processed_at IS NULL AND failure IS NULL',
            ['total_amount' => $totalAmount, 'batch_id' => $batchId, 'order_id' => $orderId],
        )->rowCount();

        if (1 !== $updated) {
            throw new \RuntimeException(\sprintf('Order %d of batch %d is no longer pending.', $orderId, $batchId));
        }
    }

    /**
     * Records the failure of an order still pending. Returns false when another step
     * processed it or recorded a failure first.
     */
    public function markFailed(int $batchId, int $orderId, string $reason): bool
    {
        return 1 === $this->execute(
            'UPDATE process_and_invoice_batch_order SET failure = :failure
             WHERE batch_id = :batch_id AND order_id = :order_id AND processed_at IS NULL AND failure IS NULL',
            ['failure' => $reason, 'batch_id' => $batchId, 'order_id' => $orderId],
        )->rowCount();
    }

    /**
     * Records every order still pending as a failure with the given reason.
     */
    public function markPendingFailed(int $batchId, string $reason): void
    {
        $this->execute(
            'UPDATE process_and_invoice_batch_order SET failure = :failure
             WHERE batch_id = :batch_id AND processed_at IS NULL AND failure IS NULL',
            ['failure' => $reason, 'batch_id' => $batchId],
        );
    }

    public function orderReference(int $orderId): string
    {
        return (string) $this->fetchColumn('SELECT ref FROM `order` WHERE id = :id', ['id' => $orderId]);
    }

    /**
     * @return list<ReportRow>
     */
    public function processedRows(int $batchId): array
    {
        $statement = $this->execute(
            'SELECT o.id, o.ref, o.created_at, c.firstname, c.lastname, bo.total_amount
             FROM process_and_invoice_batch_order bo
             INNER JOIN `order` o ON o.id = bo.order_id
             LEFT JOIN customer c ON c.id = o.customer_id
             WHERE bo.batch_id = :batch_id AND bo.processed_at IS NOT NULL
             ORDER BY o.id',
            ['batch_id' => $batchId],
        );

        $rows = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rows[] = new ReportRow(
                orderId: (int) $row['id'],
                reference: (string) $row['ref'],
                createdAt: new \DateTimeImmutable((string) $row['created_at']),
                customerName: trim(((string) $row['firstname']).' '.((string) $row['lastname'])),
                totalAmount: (string) $row['total_amount'],
            );
        }

        return $rows;
    }

    /**
     * @return list<BatchFailure>
     */
    public function failures(int $batchId): array
    {
        $statement = $this->execute(
            'SELECT o.id, o.ref, bo.failure
             FROM process_and_invoice_batch_order bo
             INNER JOIN `order` o ON o.id = bo.order_id
             WHERE bo.batch_id = :batch_id AND bo.failure IS NOT NULL
             ORDER BY o.id',
            ['batch_id' => $batchId],
        );

        return array_map(
            static fn (array $row): BatchFailure => new BatchFailure((int) $row['id'], (string) $row['ref'], (string) $row['failure']),
            $statement->fetchAll(\PDO::FETCH_ASSOC),
        );
    }

    public function complete(int $batchId): void
    {
        $this->execute(
            'UPDATE process_and_invoice_batch SET completed_at = NOW(), open_marker = NULL WHERE id = :id',
            ['id' => $batchId],
        );
    }

    /**
     * @param array<string, int|string|null> $parameters
     */
    private function fetchBatch(string $sql, array $parameters): ?Batch
    {
        $row = $this->execute($sql, $parameters)->fetch(\PDO::FETCH_ASSOC);

        if (!\is_array($row)) {
            return null;
        }

        return new Batch(
            id: (int) $row['id'],
            adminId: null !== $row['admin_id'] ? (int) $row['admin_id'] : null,
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
            completedAt: null !== $row['completed_at'] ? new \DateTimeImmutable((string) $row['completed_at']) : null,
        );
    }

    /**
     * @param array<string, int|string|null> $parameters
     */
    private function fetchColumn(string $sql, array $parameters): mixed
    {
        return $this->execute($sql, $parameters)->fetchColumn();
    }

    /**
     * @param array<string, int|string|null> $parameters
     */
    private function execute(string $sql, array $parameters): \PDOStatement|StatementInterface
    {
        $statement = $this->connection()->prepare($sql);
        $statement->execute($parameters);

        return $statement;
    }
}
