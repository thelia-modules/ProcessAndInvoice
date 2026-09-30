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

final readonly class CompletionResult
{
    /**
     * @param list<BatchFailure> $failures     every order of the batch left out
     * @param string|null        $documentPath null when a closed batch could not build its document
     */
    public function __construct(
        public Batch $batch,
        public int $processedCount,
        public array $failures,
        public ?string $documentPath,
    ) {
    }
}
