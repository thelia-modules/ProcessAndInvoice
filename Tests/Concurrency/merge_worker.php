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
 * Child process of PdfMergerTest, started from the root of the project under a low limit of
 * open files: php merge_worker.php <target> <source>...
 */

use ProcessAndInvoice\Service\PdfMerger;

require getcwd().'/vendor/autoload.php';

[, $targetPath] = $argv;

(new PdfMerger())->merge(array_slice($argv, 2), $targetPath);
