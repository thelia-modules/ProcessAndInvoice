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

use Thelia\Model\Order;
use Thelia\Model\OrderStatus;
use Thelia\Test\IntegrationTestCase;

/**
 * Two processes running a step of the same batch at the same instant (two tabs, a double
 * click). The data must be committed for the child processes to see it: this suite runs
 * outside a transaction and removes its fixtures itself. Disposable `*_test` database only.
 */
final class ConcurrentStepTest extends IntegrationTestCase
{
    use BatchTestTrait;

    private const ORDER_COUNT = 4;

    protected bool $useTransaction = false;

    /** @var list<Order> */
    private array $orders = [];

    protected function setUp(): void
    {
        $this->refuseAnyOtherDatabase();

        parent::setUp();
        $this->assertConnectedToTheTestDatabase();
    }

    protected function tearDown(): void
    {
        $this->removeBatchFiles();

        $connection = $this->getPropelConnection();
        foreach ($this->batchIds as $batchId) {
            $connection->exec('DELETE FROM process_and_invoice_batch WHERE id = '.$batchId);
        }

        foreach ($this->orders as $order) {
            $connection->exec('DELETE FROM `order` WHERE id = '.$order->getId());
            $connection->exec('DELETE FROM cart WHERE id = '.$order->getCartId());
            $connection->exec('DELETE FROM customer WHERE id = '.$order->getCustomerId());
            $connection->exec('DELETE FROM order_address WHERE id IN ('.$order->getInvoiceOrderAddressId().', '.$order->getDeliveryOrderAddressId().')');
        }

        parent::tearDown();
    }

    public function testTwoStepsOnTheSameBatchProcessEachOrderOnce(): void
    {
        $fixtures = $this->createFixtureFactory();
        for ($index = 0; $index < self::ORDER_COUNT; ++$index) {
            $this->orders[] = $fixtures->order(null, ['statusCode' => OrderStatus::CODE_PAID]);
        }

        $state = $this->openBatch();

        [$first, $second] = $this->runConcurrently((string) $state->batch->id, (string) $state->batch->id);

        $orderIds = array_map(static fn (Order $order): int => (int) $order->getId(), $this->orders);
        $statusChanges = array_merge($first['statusChanges'], $second['statusChanges']);
        $announced = array_merge($first['announced'], $second['announced']);
        sort($statusChanges);
        sort($announced);

        self::assertSame($orderIds, $statusChanges, 'One status change per order, whichever process made it: '.json_encode([$first, $second]));
        self::assertSame($orderIds, $announced, 'One announcement per order.');
        self::assertSame([], array_merge($first['failures'], $second['failures']), 'The step that finds an order taken skips it without recording a failure.');
        self::assertSame(0, $this->repository()->countPending($state->batch->id));

        foreach ($this->orders as $order) {
            self::assertSame(OrderStatus::CODE_PROCESSING, $this->statusCodeOf($order));
        }
    }

    /**
     * Starts one worker per batch id, waits until every one has booted, releases them together
     * and returns their decoded results in the same order.
     *
     * @return list<array{statusChanges: list<int>, announced: list<int>, failures: list<array{int, string}>}>
     */
    private function runConcurrently(string ...$batchIds): array
    {
        $command = [\PHP_BINARY];
        // The worker boots the same kernel as this run, with the same cache directory.
        $prepend = (string) \ini_get('auto_prepend_file');
        if ('' !== $prepend) {
            $command[] = '-d';
            $command[] = 'auto_prepend_file='.$prepend;
        }

        // The error output goes to a file: a pipe nobody reads fills up and blocks the worker.
        $workers = [];
        foreach ($batchIds as $batchId) {
            $errorFile = (string) tempnam(sys_get_temp_dir(), 'step-worker');
            $process = proc_open(
                [...$command, __DIR__.'/Concurrency/step_worker.php', $batchId],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errorFile, 'w']],
                $pipes,
                getcwd() ?: null,
            );
            self::assertIsResource($process);
            $workers[] = [$process, $pipes, $errorFile];
        }

        foreach ($workers as [$process, $pipes, $errorFile]) {
            $ready = fgets($pipes[1]);
            if ("ready\n" !== $ready) {
                self::fail('A worker did not start: '.$ready.file_get_contents($errorFile).' (exit code '.proc_close($process).')');
            }
        }

        foreach ($workers as [$process, $pipes, $errorFile]) {
            fwrite($pipes[0], "go\n");
            fclose($pipes[0]);
        }

        $results = [];
        foreach ($workers as [$process, $pipes, $errorFile]) {
            $output = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $errors = (string) file_get_contents($errorFile);
            unlink($errorFile);

            if (0 !== proc_close($process)) {
                self::fail('A worker failed: '.$output.$errors);
            }

            $results[] = json_decode(trim($output), true, 512, \JSON_THROW_ON_ERROR);
        }

        return $results;
    }
}
