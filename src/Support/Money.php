<?php

namespace OnlineEfd\Support;

/** Money is handled as integer cents internally to avoid float drift in totals. */
final class Money
{
    public static function cents(float|int|string $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    public static function format(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    public static function quantity(float|int|string $qty): string
    {
        $formatted = number_format((float) $qty, 3, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }
}
