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

use Thelia\Model\OrderStatus;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * Runs on a disposable `*_test` database, inside the transaction IntegrationTestCase opens
 * and rolls back. The repository reads through the Propel connection, whose statements answer an
 * empty string, not false, to fetchColumn() when a query returns no row.
 */
final class BatchRepositoryTest extends IntegrationTestCase
{
    use BatchTestTrait;

    private FixtureFactory $fixtures;

    protected function setUp(): void
    {
        $this->refuseAnyOtherDatabase();

        parent::setUp();
        $this->assertConnectedToTheTestDatabase();

        $this->fixtures = $this->createFixtureFactory();
    }

    public function testAPendingOrderIsReservedAndAnOrderAlreadyProcessedIsNot(): void
    {
        $order = $this->fixtures->order(null, ['statusCode' => OrderStatus::CODE_PAID]);
        $orderId = (int) $order->getId();
        $batchId = $this->repository()->open(null, $this->statusId(OrderStatus::CODE_PAID));

        self::assertTrue($this->repository()->reserve($batchId, $orderId));

        $this->repository()->markProcessed($batchId, $orderId, '10.00');

        self::assertFalse($this->repository()->reserve($batchId, $orderId), 'Processed by another step: it is not reserved again.');
    }

    public function testAnOrderWhoseFailureIsRecordedIsNotReservedAgain(): void
    {
        $order = $this->fixtures->order(null, ['statusCode' => OrderStatus::CODE_PAID]);
        $orderId = (int) $order->getId();
        $batchId = $this->repository()->open(null, $this->statusId(OrderStatus::CODE_PAID));

        self::assertTrue($this->repository()->markFailed($batchId, $orderId, 'No invoice.'));

        self::assertFalse($this->repository()->reserve($batchId, $orderId));
    }

    public function testAnOrderOutsideTheBatchIsNotReserved(): void
    {
        $inBatch = $this->fixtures->order(null, ['statusCode' => OrderStatus::CODE_PAID]);
        $batchId = $this->repository()->open(null, $this->statusId(OrderStatus::CODE_PAID));
        $outside = $this->fixtures->order(null, ['statusCode' => OrderStatus::CODE_PAID]);

        self::assertTrue($this->repository()->reserve($batchId, (int) $inBatch->getId()));
        self::assertFalse($this->repository()->reserve($batchId, (int) $outside->getId()));
    }

    public function testTheStatusOfAnOrderThatNoLongerExistsIsNullNotZero(): void
    {
        $order = $this->fixtures->order(null, ['statusCode' => OrderStatus::CODE_PAID]);

        self::assertSame($this->statusId(OrderStatus::CODE_PAID), $this->repository()->lockOrderStatus((int) $order->getId()));
        self::assertNull($this->repository()->lockOrderStatus(2147483647));
    }
}
