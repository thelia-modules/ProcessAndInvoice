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

use ProcessAndInvoice\ProcessAndInvoice;
use Thelia\Core\Install\Database;
use Thelia\Test\IntegrationTestCase;

/**
 * Upgrades a 2.0.0 schema (Tests/Fixtures/TheliaMain-2.0.0.sql, the installation script of the
 * 2.x line) to 3.0.0. The DDL commits by itself: this suite runs outside a transaction and puts
 * the 3.0.0 tables back when it ends. Disposable `*_test` database only.
 */
final class UpdateTest extends IntegrationTestCase
{
    use BatchTestTrait;

    protected bool $useTransaction = false;

    protected function setUp(): void
    {
        $this->refuseAnyOtherDatabase();

        parent::setUp();
        $this->assertConnectedToTheTestDatabase();
    }

    protected function tearDown(): void
    {
        $connection = $this->getPropelConnection();
        $connection->exec('DROP TABLE IF EXISTS pdf_invoice');
        (new Database($connection))->insertSql(null, [\dirname(__DIR__).'/Config/TheliaMain.sql']);

        parent::tearDown();
    }

    public function testA200SchemaGetsTheBatchTablesAndKeepsItsInvoiceMarks(): void
    {
        $connection = $this->getPropelConnection();
        $connection->exec('DROP TABLE IF EXISTS process_and_invoice_batch_order');
        $connection->exec('DROP TABLE IF EXISTS process_and_invoice_batch');
        $connection->exec('DROP TABLE IF EXISTS pdf_invoice');
        (new Database($connection))->insertSql(null, [__DIR__.'/Fixtures/TheliaMain-2.0.0.sql']);
        $connection->exec('SET FOREIGN_KEY_CHECKS = 0');
        $connection->exec('INSERT INTO pdf_invoice (order_id, invoiced) VALUES (4242, 1)');
        $connection->exec('SET FOREIGN_KEY_CHECKS = 1');

        $module = new ProcessAndInvoice();
        $module->update('2.0.0', '3.0.0', $connection);
        self::assertSame(['process_and_invoice_batch', 'process_and_invoice_batch_order'], $this->batchTables(), 'The update script creates the batch tables.');

        // Played again (a second refresh, an activation after the update): nothing changes.
        $module->update('2.0.0', '3.0.0', $connection);
        $module->postActivation($connection);

        self::assertSame(['process_and_invoice_batch', 'process_and_invoice_batch_order'], $this->batchTables());
        self::assertSame('4242', (string) $connection->query('SELECT order_id FROM pdf_invoice WHERE invoiced = 1')->fetchColumn(), 'The 2.x table and its rows are left as they are.');

        $this->paidOrderCleanup(fn () => self::assertNotNull($this->processor()->open(null), 'The upgraded schema runs a batch.'));
    }

    public function testAnUpdateBetweenTwo3xVersionsPlaysNoScript(): void
    {
        $connection = $this->getPropelConnection();
        $connection->exec('DROP TABLE IF EXISTS process_and_invoice_batch_order');
        $connection->exec('DROP TABLE IF EXISTS process_and_invoice_batch');

        (new ProcessAndInvoice())->update('3.0.0', '3.0.1', $connection);

        self::assertSame([], $this->batchTables(), 'Config/update/3.0.0.sql is not played again from 3.0.0.');
    }

    /**
     * @return list<string>
     */
    private function batchTables(): array
    {
        $tables = $this->getPropelConnection()->query("SHOW TABLES LIKE 'process\\_and\\_invoice\\_batch%'")->fetchAll(\PDO::FETCH_COLUMN);
        sort($tables);

        return $tables;
    }

    /**
     * Runs the check with one committed paid order and removes the order, its batch and its
     * fixtures afterwards.
     */
    private function paidOrderCleanup(callable $check): void
    {
        $order = $this->createFixtureFactory()->order(null, ['statusCode' => 'paid']);
        $connection = $this->getPropelConnection();

        try {
            $check();
        } finally {
            $connection->exec('DELETE FROM process_and_invoice_batch');
            $connection->exec('DELETE FROM `order` WHERE id = '.$order->getId());
            $connection->exec('DELETE FROM cart WHERE id = '.$order->getCartId());
            $connection->exec('DELETE FROM customer WHERE id = '.$order->getCustomerId());
            $connection->exec('DELETE FROM order_address WHERE id IN ('.$order->getInvoiceOrderAddressId().', '.$order->getDeliveryOrderAddressId().')');
        }
    }
}
