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

/**
 * One processed order, as the batch report prints it.
 */
final readonly class ReportRow
{
    public function __construct(
        public int $orderId,
        public string $reference,
        public \DateTimeImmutable $createdAt,
        public string $customerName,
        public string $totalAmount,
    ) {
    }
}
