<?php

declare(strict_types=1);

namespace ZaberDev\Quota\Exceptions;

use InvalidArgumentException;

/**
 * Exception thrown when an unregistered or invalid quota driver is requested.
 */
class QuotaDriverNotFoundException extends InvalidArgumentException
{
    /**
     * Create a new exception instance for a missing driver.
     */
    public static function forDriver(string $driver): self
    {
        return new self("Quota driver [{$driver}] is not configured or registered.");
    }
}
