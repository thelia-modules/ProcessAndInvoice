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
 * A request on a batch that cannot be served as asked. The message is a translation key
 * of the module domain, safe to show to the administrator.
 */
final class BatchException extends \RuntimeException
{
    public const NOT_FOUND = 'This batch does not exist.';
    public const ALREADY_COMPLETED = 'This batch is already complete.';
    public const NOT_FINISHED = 'Some orders of this batch are still waiting to be processed.';
    public const MISSING_STATUS = 'The "paid" or "processing" order status is missing.';

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND);
    }

    public static function alreadyCompleted(): self
    {
        return new self(self::ALREADY_COMPLETED);
    }

    public static function notFinished(): self
    {
        return new self(self::NOT_FINISHED);
    }

    public static function missingStatus(): self
    {
        return new self(self::MISSING_STATUS);
    }
}
