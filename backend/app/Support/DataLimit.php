<?php

namespace App\Support;

use InvalidArgumentException;

class DataLimit
{
    public static function toBytes(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        if (! preg_match('/^\s*(\d+(?:\.\d+)?)\s*(M|MB|G|GB)\s*$/i', $value, $matches)) {
            throw new InvalidArgumentException('Data allowance must use MB or GB.');
        }

        $multiplier = str_starts_with(strtoupper($matches[2]), 'G')
            ? 1024 ** 3
            : 1024 ** 2;

        return (int) round((float) $matches[1] * $multiplier);
    }

    public static function notation(string|int|float $value, string $unit): string
    {
        $normalizedUnit = strtoupper($unit);
        if (! in_array($normalizedUnit, ['MB', 'GB'], true) || ! is_numeric($value) || (float) $value <= 0) {
            throw new InvalidArgumentException('Data allowance must be greater than zero and use MB or GB.');
        }

        $number = rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');

        return $number.($normalizedUnit === 'GB' ? 'G' : 'M');
    }
}
