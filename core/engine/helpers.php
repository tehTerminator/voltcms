<?php
declare(strict_types=1);

/** Escape a value for HTML text and quoted attribute contexts. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Build an application URL (respects a subfolder install). */
function url(string $path = '/'): string
{
    return Cms\Url::to($path);
}

/** Hidden CSRF input for POST forms. */
function csrf_field(): string
{
    return Cms\Csrf::field();
}

/** URL of a content row: its full address (parent/child/...), percent-encoded per segment so Unicode slugs work. */
function content_url(array $row): string
{
    return Cms\Url::content((string) ($row['path'] ?? $row['slug']));
}

/**
 * Format a stored UTC "Y-m-d H:i:s" timestamp for display, in the time zone and date format chosen in Site settings
 * (for example "21 Oct 2026"). Pass a PHP date() format as the second argument to override the format.
 */
function format_date(?string $utc, ?string $format = null): string
{
    return Cms\Format::date($utc, $format);
}

/** The date as Y-m-d in the site's zone, for <time datetime="...">. */
function iso_date(?string $utc): string
{
    return Cms\Format::isoDate($utc);
}

/** URL of a tag page: /tag/{slug}. */
function tag_url(array $tag): string
{
    return Cms\Url::to('/tag/' . rawurlencode((string) $tag['slug']));
}
