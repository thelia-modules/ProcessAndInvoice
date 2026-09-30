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

use ProcessAndInvoice\Service\BatchProcessor;
use ProcessAndInvoice\Service\BatchRepository;
use ProcessAndInvoice\Service\BatchState;
use ProcessAndInvoice\Service\BatchStorage;
use Propel\Runtime\Propel;
use setasign\Fpdi\Fpdi;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Thelia\Model\Order;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatusQuery;

/**
 * Shared helpers of the batch tests. The tests run on a disposable `*_test` database only.
 */
trait BatchTestTrait
{
    /** @var list<int> */
    private array $batchIds = [];

    private function refuseAnyOtherDatabase(): void
    {
        $databaseName = $_SERVER['DATABASE_NAME'] ?? getenv('DATABASE_NAME');
        if (!\is_string($databaseName) || !str_ends_with($databaseName, '_test')) {
            self::fail(\sprintf('Refusing to run on the database "%s": use a *_test database.', (string) $databaseName));
        }
    }

    /**
     * Called after parent::setUp(): the Propel connection must really point at the test database
     * (var/propel/test/ caches the connection of whichever run built it).
     */
    private function assertConnectedToTheTestDatabase(): void
    {
        $databaseName = $_SERVER['DATABASE_NAME'] ?? getenv('DATABASE_NAME');
        $connectedDatabase = Propel::getConnection()->query('SELECT DATABASE()')->fetchColumn();

        if ($connectedDatabase !== $databaseName) {
            self::fail(\sprintf('Connected to "%s" instead of "%s": rebuild var/propel/test.', (string) $connectedDatabase, (string) $databaseName));
        }
    }

    private function openBatch(): BatchState
    {
        $state = $this->processor()->open(null);
        self::assertInstanceOf(BatchState::class, $state, 'A paid order is waiting: a batch opens.');
        $this->batchIds[] = $state->batch->id;

        return $state;
    }

    private function processor(): BatchProcessor
    {
        $processor = static::getContainer()->get(BatchProcessor::class);
        self::assertInstanceOf(BatchProcessor::class, $processor);

        return $processor;
    }

    private function repository(): BatchRepository
    {
        $repository = static::getContainer()->get(BatchRepository::class);
        self::assertInstanceOf(BatchRepository::class, $repository);

        return $repository;
    }

    private function storage(): BatchStorage
    {
        $storage = static::getContainer()->get(BatchStorage::class);
        self::assertInstanceOf(BatchStorage::class, $storage);

        return $storage;
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = static::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }

    private function statusId(string $code): int
    {
        $status = OrderStatusQuery::create()->findOneByCode($code)
            ?? throw new \RuntimeException("Seeded order status '$code' is missing: run bin/test-prepare.");

        return (int) $status->getId();
    }

    private function statusCodeOf(Order $order): string
    {
        $fresh = OrderQuery::create()->findPk($order->getId());
        self::assertInstanceOf(Order::class, $fresh);

        return (string) $fresh->getOrderStatus()?->getCode();
    }

    private function pageCount(string $pdfPath): int
    {
        return (new Fpdi())->setSourceFile($pdfPath);
    }

    private function removeBatchFiles(): void
    {
        foreach ($this->batchIds as $batchId) {
            (new Filesystem())->remove($this->storage()->directory($batchId));
        }
    }
}
