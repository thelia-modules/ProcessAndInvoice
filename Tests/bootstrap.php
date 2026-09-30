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
 * Run from the root of the Thelia project the module is installed in, against a disposable
 * database whose name ends with `_test` (see the Tests section of the README).
 */

use Symfony\Component\Dotenv\Dotenv;

$projectRoot = getcwd();

require $projectRoot.'/bootstrap.php';
require $projectRoot.'/vendor/autoload.php';

(new Dotenv())->bootEnv($projectRoot.'/.env');
