<?php
declare(strict_types=1);

namespace Cms;

/** Site settings used by the public site and SEO: name, URL, language, description, logo, social image; search index tools. */
final class SettingsAdmin
{
    public function __construct(private Database $db, private array $user)
    {
    }

    /** @return int 0 when handled, otherwise 404 or 405 */
    public function handle(string $path, string $method): int
    {
        switch ($path) {
            case '/admin/settings':
                return $method === 'GET' ? $this->form() : ($method === 'POST' ? $this->save() : 405);
            case '/admin/settings/reindex':
                return $method === 'POST' ? $this->reindex() : 405;
        }
        return 404;
    }

    /** @return string|null normalised site URL (no trailing slash), '' for blank, null if invalid */
    public static function cleanSiteUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || strlen($url) > 200
            || preg_match('/^[A-Za-z0-9.\-]+$/', $parts['host']) !== 1) {
            return null;
        }
        return strtolower($parts['scheme']) . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . (int) $parts['port'] : '') . rtrim((string) ($parts['path'] ?? ''), '/');
    }

    /** @return array{0:string[],1:array} [errors, clean values] */
    public static function validate(array $in, MediaRepository $media): array
    {
        $get = static fn (string $k): string => isset($in[$k]) && is_string($in[$k]) ? trim($in[$k]) : '';
        $errors = [];
        $name = $get('site_name');
        if ($name === '' || mb_strlen($name) > 100) {
            $errors[] = 'Enter a site name (up to 100 characters).';
        }
        $url = self::cleanSiteUrl($get('site_url'));
        if ($url === null) {
            $errors[] = 'The site URL must look like https://example.com (http or https, no query string).';
        }
        $lang = $get('site_lang') === '' ? 'en' : $get('site_lang');
        if (preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})?$/', $lang) !== 1) {
            $errors[] = 'The language code must look like en, hi or en-IN.';
        }
        $desc = $get('site_description');
        if (mb_strlen($desc) > 320) {
            $errors[] = 'The site description is too long (max 320 characters).';
        }
        $perRaw = $get('items_per_page');
        $perPage = $perRaw === '' ? '10' : $perRaw; // an invalid value is kept as typed so the form can show it again with the error
        if ($perRaw !== '' && !(ctype_digit($perRaw) && (int) $perRaw >= 5 && (int) $perRaw <= 50)) {
            $errors[] = 'Items per page must be a whole number from 5 to 50.';
        }
        $zone = $get('timezone') === '' ? 'UTC' : $get('timezone');
        if (!Format::isValidTimezone($zone)) {
            $errors[] = 'Choose a time zone from the list, for example Asia/Kolkata or UTC.';
        }
        $dateFormat = $get('date_format') === '' ? Format::DEFAULT_FORMAT : $get('date_format');
        if (!isset(Format::DATE_FORMATS[$dateFormat])) {
            $errors[] = 'Choose one of the listed date formats.';
        }
        $footer = trim((string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $get('footer_text')));
        if (mb_strlen($footer) > 300) {
            $errors[] = 'The footer text is too long (max 300 characters).';
        }
        $maintenance = ($in['maintenance_mode'] ?? '') === '1' ? '1' : '0';
        $maintenanceMessage = $get('maintenance_message');
        if (mb_strlen($maintenanceMessage) > 500) {
            $errors[] = 'The maintenance message is too long (max 500 characters).';
        }
        $ids = [];
        foreach (['site_logo_media_id' => 'logo', 'site_social_media_id' => 'social image'] as $key => $label) {
            $v = $get($key);
            $ids[$key] = '';
            if ($v !== '' && $v !== '0') {
                $row = ctype_digit($v) ? $media->find((int) $v) : null;
                if ($row === null || !MediaRepository::isImage($row)) {
                    $errors[] = 'Choose an image from the media library for the ' . $label . '.';
                } else {
                    $ids[$key] = (string) (int) $v;
                }
            }
        }
        return [$errors, ['site_name' => $name, 'site_url' => $url ?? '', 'site_lang' => $lang, 'site_description' => $desc,
            'items_per_page' => $perPage, 'date_format' => $dateFormat, 'timezone' => $zone, 'footer_text' => $footer,
            'maintenance_mode' => $maintenance, 'maintenance_message' => $maintenanceMessage] + $ids];
    }

    private function form(?array $values = null, array $errors = [], int $status = 200): int
    {
        $settings = new Settings($this->db);
        $values ??= [
            'site_name' => (string) $settings->get('site_name', ''), 'site_url' => (string) $settings->get('site_url', ''),
            'site_lang' => (string) $settings->get('site_lang', 'en'), 'site_description' => (string) $settings->get('site_description', ''),
            'site_logo_media_id' => (string) $settings->get('site_logo_media_id', ''), 'site_social_media_id' => (string) $settings->get('site_social_media_id', ''),
            'items_per_page' => (string) $settings->get('items_per_page', '10'), 'date_format' => (string) $settings->get('date_format', Format::DEFAULT_FORMAT),
            'timezone' => (string) $settings->get('timezone', 'UTC'), 'footer_text' => (string) $settings->get('footer_text', ''),
            'maintenance_mode' => (string) $settings->get('maintenance_mode', '0'), 'maintenance_message' => (string) $settings->get('maintenance_message', ''),
        ];
        $media = new MediaRepository($this->db);
        $picked = static function (string $id) use ($media): array {
            $row = ctype_digit($id) && (int) $id > 0 ? $media->find((int) $id) : null;
            return [$row === null ? '' : (string) $row['original_name'], $row === null ? '' : MediaRepository::thumbUrl($row)];
        };
        [$logoLabel, $logoThumb] = $picked((string) $values['site_logo_media_id']);
        [$socialLabel, $socialThumb] = $picked((string) $values['site_social_media_id']);
        Response::html(View::render('settings', [
            'logoLabel' => $logoLabel, 'logoThumb' => $logoThumb, 'socialLabel' => $socialLabel, 'socialThumb' => $socialThumb,
            'title' => 'Site settings', 'nav' => true, 'v' => $values, 'errors' => $errors, 'detectedUrl' => Url::origin(),
            'formats' => Format::DATE_FORMATS, 'zones' => \DateTimeZone::listIdentifiers(),
            'searchMode' => (new SearchIndex($this->db))->mode(),
            'indexComplete' => $settings->get('search_index_complete', '1') === '1',
            'indexed' => (int) $this->db->value('SELECT COUNT(*) FROM {p}search_index'),
        ]), $status);
        return 0;
    }

    private function save(): int
    {
        [$errors, $clean] = self::validate($_POST, new MediaRepository($this->db));
        if ($errors !== []) {
            return $this->form($clean, $errors, 422);
        }
        $settings = new Settings($this->db);
        foreach ($clean as $key => $value) {
            $settings->set($key, (string) $value);
        }
        Logger::info('Site settings saved');
        Session::flash('success', 'Settings saved.');
        Response::redirect('/admin/settings');
        return 0;
    }

    /** Rebuild the search index in time-limited steps; each step redirects back here until finished. */
    private function reindex(): int
    {
        $settings = new Settings($this->db);
        $after = $settings->get('search_index_complete', '1') === '0' ? (int) $settings->get('search_index_after', '0') : 0;
        $result = (new SearchIndex($this->db))->rebuild(new ContentStore(CMS_ROOT . '/user'), $after, 100, microtime(true) + 15.0);
        if ($result['next'] === null) {
            $settings->set('search_index_complete', '1');
            $settings->set('search_index_after', '0');
            Session::flash('success', 'Search index rebuilt.');
        } else {
            Session::flash('warning', 'Rebuilding... press the button again to continue (processed up to item ' . $result['next'] . ').');
            $settings->set('search_index_complete', '0');
            $settings->set('search_index_after', (string) $result['next']);
        }
        Response::redirect('/admin/settings');
        return 0;
    }
}
