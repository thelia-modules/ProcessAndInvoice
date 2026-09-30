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

/*
 * Child process of ConcurrentStepTest, started from the root of the project:
 *   php step_worker.php <batchId>
 * Boots the kernel, prints "ready", waits for a line on its standard input, runs one step of
 * the batch, then prints as JSON the orders it dispatched a status change for and the orders
 * it announced as processed.
 */

use ProcessAndInvoice\Event\OrderProcessedEvent;
use ProcessAndInvoice\Event\ProcessAndInvoiceEvents;
use ProcessAndInvoice\Service\BatchProcessor;
use Propel\Runtime\Propel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\Session\Session;

require dirname(__DIR__).'/bootstrap.php';

$batchId = (int) ($argv[1] ?? 0);

$kernelClass = $_SERVER['KERNEL_CLASS'] ?? 'App\Kernel';
$kernel = new $kernelClass('test', false);
$kernel->boot();

$databaseName = $_SERVER['DATABASE_NAME'] ?? '';
$connectedDatabase = Propel::getConnection()->query('SELECT DATABASE()')->fetchColumn();
if (!str_ends_with((string) $databaseName, '_test') || $connectedDatabase !== $databaseName) {
    fwrite(\STDERR, sprintf("Refusing to run on \"%s\" (expected \"%s\").\n", (string) $connectedDatabase, (string) $databaseName));
    exit(1);
}

// The test container reaches the private services.
// A lock never released fails the worker instead of hanging the suite.
Propel::getConnection()->exec('SET SESSION innodb_lock_wait_timeout = 20');

$container = $kernel->getContainer()->get('test.service_container');
$dispatcher = $container->get('event_dispatcher');

// The step runs from the back office: the same singletons and request as IntegrationTestCase.
$container->get('thelia.translator');
$container->get('thelia.url.manager');
$request = Request::create('http://localhost');
$request->setSession(new Session(new MockArraySessionStorage()));
$container->get('request_stack')->push($request);
Propel::disableInstancePooling();
$processor = $container->get(BatchProcessor::class);

$statusChanges = [];
$announced = [];
$dispatcher->addListener(TheliaEvents::ORDER_UPDATE_STATUS, static function (OrderEvent $event) use (&$statusChanges): void {
    $statusChanges[] = (int) $event->getOrder()->getId();
}, 200);
$dispatcher->addListener(ProcessAndInvoiceEvents::ORDER_PROCESSED, static function (OrderProcessedEvent $event) use (&$announced): void {
    $announced[] = (int) $event->getOrder()->getId();
});

echo "ready\n";
fgets(\STDIN);

$step = $processor->processNext($batchId);

echo json_encode([
    'statusChanges' => $statusChanges,
    'announced' => $announced,
    'failures' => array_map(static fn ($failure): array => [$failure->orderId, $failure->reason], $step->failures),
], \JSON_THROW_ON_ERROR)."\n";
