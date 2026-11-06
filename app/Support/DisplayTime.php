<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Times are stored in the application's timezone, which also decides when a day ends for credits
 * and daily readings, so only what people read is converted.
 */
final class DisplayTime
{
    public static function format(?CarbonInterface $time, string $format): string
    {
        return $time?->copy()->setTimezone(config('app.display_timezone'))->format($format) ?? '';
    }
}
