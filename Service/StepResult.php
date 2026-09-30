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

final readonly class StepResult
{
    /**
     * @param list<int>          $processedOrderIds orders moved to "processing" by this step
     * @param list<BatchFailure> $failures          orders this step left out
     */
    public function __construct(
        public array $processedOrderIds,
        public array $failures,
        public int $pendingCount,
    ) {
    }
}
