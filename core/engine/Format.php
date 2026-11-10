<?php
declare(strict_types=1);

namespace Cms;

/**
 * Display formatting of stored dates. Everything is stored in UTC; this converts for display only, using the time zone and
 * date format from Site settings. An invalid stored zone or format never breaks a page: it falls back to UTC and the default.
 */
final class Format
{
    /** Setting value (a PHP date() format) => description with an example. */
    public const DATE_FORMATS = [
        'j M Y' => 'day month year, e.g. 21 Oct 2026',
        'j F Y' => 'day full month year, e.g. 21 October 2026',
        'F j, Y' => 'US style, e.g. October 21, 2026',
        'd/m/Y' => 'numeric day first, e.g. 21/10/2026',
        'Y-m-d' => 'ISO, e.g. 2026-10-21',
    ];
    public const DEFAULT_FORMAT = 'j M Y';

    private static string $zone = 'UTC';
    private static string $format = self::DEFAULT_FORMAT;

    public static function configure(string $timezone, string $dateFormat): void
    {
        self::$zone = self::isValidTimezone($timezone) ? $timezone : 'UTC';
        self::$format = isset(self::DATE_FORMATS[$dateFormat]) ? $dateFormat : self::DEFAULT_FORMAT;
    }

    public static function isValidTimezone(string $zone): bool
    {
        return $zone !== '' && in_array($zone, \DateTimeZone::listIdentifiers(), true);
    }

    public static function timezone(): string
    {
        return self::$zone;
    }

    /** A stored UTC "Y-m-d H:i:s" timestamp as a date in the site's zone; '' for empty or unreadable input. */
    public static function date(?string $utc, ?string $format = null): string
    {
        $dt = self::toZone($utc);
        return $dt === null ? '' : $dt->format($format ?? self::$format);
    }

    /** Y-m-d in the site's zone, for <time datetime="..."> so the machine date agrees with the displayed one. */
    public static function isoDate(?string $utc): string
    {
        return self::date($utc, 'Y-m-d');
    }

    private static function toZone(?string $utc): ?\DateTimeImmutable
    {
        if ($utc === null || $utc === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone(self::$zone));
        } catch (\Exception) {
            return null;
        }
    }
}
