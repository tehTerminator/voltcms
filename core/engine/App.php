<?php
declare(strict_types=1);

namespace Cms;

final class App
{
    private static ?Database $db = null;
    private static ?Settings $settings = null;

    public static function run(): void
    {
        Env::init();
        if (!Config::isInstalled()) {
            self::notInstalled();
            return;
        }
        Config::load();
        $path = Request::path();
        $route = Router::resolve($path);
        if ($route === 'asset') {
            ThemeAssets::serve(substr((string) $path, strlen('/assets/base/')), Request::method());
            return;
        }
        $settings = self::settings();
        Format::configure((string) $settings->get('timezone', 'UTC'), (string) $settings->get('date_format', Format::DEFAULT_FORMAT));
        if ($route !== 'admin' && Config::get('installed_version') !== CMS_VERSION) {
            // New code was uploaded but the database update has not been applied yet (done in the admin area).
            header('Retry-After: 300');
            self::page('error', ['title' => 'Updating', 'code' => 503,
                'message' => 'The site is being updated. Please check back in a few minutes.'], 503);
            return;
        }
        if ($route !== 'admin' && $settings->get('maintenance_mode', '0') === '1' && !self::administratorSignedIn()) {
            (new PublicApp())->maintenance((string) $settings->get('maintenance_message', ''));
            return;
        }
        switch ($route) {
            case 'admin':
                (new AdminApp())->handle((string) $path, Request::method());
                break;
            case 'home':
            case 'content':
            case 'search':
            case 'tag':
                (new PublicApp())->handle($route, (string) $path, Request::method());
                break;
            case 'feed':
            case 'sitemap':
            case 'robots':
                $db = self::db();
                (new Syndication($db, SiteInfo::load(self::settings(), new MediaRepository($db))))->serve($route, Request::method());
                break;
            default:
                (new PublicApp())->notFound();
        }
    }

    /** The settings table, loaded once per request and shared. */
    public static function settings(): Settings
    {
        return self::$settings ??= new Settings(self::db());
    }

    /** Maintenance mode lets a signed-in administrator keep browsing the public site. Starts the session only if a session cookie exists. */
    private static function administratorSignedIn(): bool
    {
        if (!isset($_COOKIE['cmssid'])) {
            return false;
        }
        Session::start();
        return is_int($_SESSION['uid'] ?? null);
    }

    public static function db(): Database
    {
        return self::$db ??= new Database(Config::db());
    }

    private static function page(string $view, array $vars, int $status): void
    {
        Response::secureHeaders();
        Response::html(View::render($view, $vars), $status);
    }

    private static function notInstalled(): void
    {
        if (is_file(CMS_ROOT . '/install.php')) {
            Response::redirect('/install.php');
        }
        self::page('error', ['title' => 'Not installed', 'code' => 503,
            'message' => 'The site is not installed yet. Upload install.php and open it in your browser.'], 503);
    }
}
