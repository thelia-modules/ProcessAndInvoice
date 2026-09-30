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

namespace ProcessAndInvoice\Event;

use Symfony\Contracts\EventDispatcher\Event;
use Thelia\Model\Order;

/**
 * An order a batch has just moved from "paid" to "processing", its invoice printed in the
 * batch document.
 */
final class OrderProcessedEvent extends Event
{
    public function __construct(
        private readonly Order $order,
        private readonly int $batchId,
    ) {
    }

    public function getOrder(): Order
    {
        return $this->order;
    }

    public function getBatchId(): int
    {
        return $this->batchId;
    }
}
