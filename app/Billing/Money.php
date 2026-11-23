<?php

declare(strict_types=1);

namespace App\Billing;

use InvalidArgumentException;

/**
 * All money in the app is integer nanodollars (1 USD = 1_000_000_000).
 * Pixelforge sends exact decimal strings; floats never touch an amount.
 */
final class Money
{
    public const int NANOS_PER_DOLLAR = 1_000_000_000;

    /** "0.046125000" => 46_125_000 */
    public static function fromDecimal(string $usd): int
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,9}))?$/', $usd, $matches) !== 1) {
            throw new InvalidArgumentException("Not a USD amount with at most 9 decimals: [{$usd}].");
        }

        return (int) $matches[1] * self::NANOS_PER_DOLLAR + (int) str_pad($matches[2] ?? '', 9, '0');
    }
}
