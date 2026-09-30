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

namespace ProcessAndInvoice;

use Propel\Runtime\Connection\ConnectionInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\Finder\Finder;
use Thelia\Core\Install\Database;
use Thelia\Module\BaseModule;

class ProcessAndInvoice extends BaseModule
{
    public const DOMAIN_NAME = 'processandinvoice';

    /**
     * The installation script only creates what is missing: it never drops a table,
     * so activating the module again keeps the batches already recorded.
     */
    public function postActivation(?ConnectionInterface $con = null): void
    {
        (new Database($con))->insertSql(null, [__DIR__.'/Config/TheliaMain.sql']);
    }

    /**
     * Plays every Config/update/<version>.sql newer than the installed version.
     */
    public function update($currentVersion, $newVersion, ?ConnectionInterface $con = null): void
    {
        $files = Finder::create()->files()->name('*.sql')->depth(0)->in(__DIR__.'/Config/update');
        $files->sort(static fn (\SplFileInfo $left, \SplFileInfo $right): int => version_compare(
            $left->getBasename('.sql'),
            $right->getBasename('.sql'),
        ));

        $database = new Database($con);

        foreach ($files as $file) {
            $version = $file->getBasename('.sql');

            if (version_compare((string) $currentVersion, $version, '<') && version_compare($version, (string) $newVersion, '<=')) {
                $database->insertSql(null, [$file->getPathname()]);
            }
        }
    }

    public static function configureServices(ServicesConfigurator $servicesConfigurator): void
    {
        $servicesConfigurator->load(self::getModuleCode().'\\', __DIR__)
            ->exclude([
                __DIR__.'/Config/*',
                __DIR__.'/I18n/*',
                __DIR__.'/Tests/*',
                __DIR__.'/templates/*',
            ])
            ->autowire()
            ->autoconfigure();
    }
}
