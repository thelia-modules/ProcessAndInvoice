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

final class ProcessAndInvoiceEvents
{
    /**
     * Dispatched once for each order a batch moves to "processing", after the status change
     * is committed, with an {@see OrderProcessedEvent}. An order whose status change fails is
     * never announced.
     */
    public const ORDER_PROCESSED = 'process_and_invoice.order_processed';
}
