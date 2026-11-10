<?php
declare(strict_types=1);

namespace Cms;

/** Admin screens for themes: list, activate (after validation), create a child theme skeleton. */
final class ThemeAdmin
{
    private string $userDir;
    private string $coreDir;
    private ThemeValidator $validator;
    private Settings $settings;

    public function __construct(private Database $db, private array $user)
    {
        $this->userDir = CMS_ROOT . '/user';
        $this->coreDir = CMS_ROOT . '/core';
        $this->validator = new ThemeValidator($this->userDir, $this->coreDir);
        $this->settings = new Settings($db);
    }

    /** @return int 0 when handled, otherwise 404 or 405 */
    public function handle(string $path, string $method): int
    {
        switch ($path) {
            case '/admin/themes':
                return $method === 'GET' ? $this->listPage() : 405;
            case '/admin/themes/activate':
                return $method === 'POST' ? $this->activate() : 405;
            case '/admin/themes/create':
                return $method === 'POST' ? $this->create() : 405;
        }
        return 404;
    }

    private function listPage(): int
    {
        $active = (string) $this->settings->get('active_theme', 'base');
        $themes = [];
        $baseMeta = Theme::readMeta($this->coreDir . '/themes/base/theme.json', 'base');
        $themes[] = ['name' => 'base', 'meta' => $baseMeta, 'errors' => [], 'warnings' => [], 'overrides' => []];
        $dirs = glob($this->userDir . '/themes/*', GLOB_ONLYDIR) ?: [];
        sort($dirs);
        foreach (array_slice($dirs, 0, 60) as $dir) {
            $name = basename($dir);
            $result = $this->validator->validate($name);
            $themes[] = ['name' => $name, 'meta' => $result['meta'], 'errors' => $result['errors'], 'warnings' => $result['warnings'], 'overrides' => $this->overrides($dir)];
        }
        $names = array_column($themes, 'name');
        $activeMissing = $active !== 'base' && !in_array($active, $names, true);
        Response::html(View::render('themes', [
            'title' => 'Themes', 'nav' => true, 'themes' => $themes, 'active' => $active, 'activeMissing' => $activeMissing,
            'activeBroken' => $active !== 'base' && !$activeMissing && !is_file($this->userDir . '/themes/' . $active . '/theme.json'),
        ]));
        return 0;
    }

    /** Files a child theme overrides, as short relative paths. @return string[] */
    private function overrides(string $dir): array
    {
        $out = [];
        foreach (['layout.php'] as $f) {
            if (is_file($dir . '/' . $f)) {
                $out[] = $f;
            }
        }
        foreach (['templates', 'partials'] as $sub) {
            foreach (glob($dir . '/' . $sub . '/*.php') ?: [] as $file) {
                $out[] = $sub . '/' . basename($file);
            }
        }
        return array_slice($out, 0, 30);
    }

    private function activate(): int
    {
        $name = Request::post('name');
        if ($name !== 'base') {
            $result = $this->validator->validate($name);
            if ($result['errors'] !== []) {
                Session::flash('danger', 'The theme "' . $name . '" was not activated: ' . implode(' ', array_slice($result['errors'], 0, 4)));
                Response::redirect('/admin/themes');
                return 0;
            }
        }
        $this->settings->set('active_theme', $name);
        Logger::info('Theme activated', ['theme' => $name]);
        Session::flash('success', $name === 'base' ? 'The base theme is now active.' : 'The theme "' . $name . '" is now active.');
        Response::redirect('/admin/themes');
        return 0;
    }

    private function create(): int
    {
        $slug = strtolower(trim(Request::post('slug')));
        $title = trim(Request::post('title'));
        $error = null;
        if (preg_match(Theme::NAME_PATTERN, $slug) !== 1 || $slug === 'base') {
            $error = 'The folder name must be lowercase letters, digits and hyphens (max 39), and not "base".';
        } elseif ($title === '' || mb_strlen($title) > 60) {
            $error = 'Enter a theme name (up to 60 characters).';
        } elseif (file_exists($this->userDir . '/themes/' . $slug)) {
            $error = 'A theme folder named "' . $slug . '" already exists.';
        }
        if ($error === null) {
            $error = $this->scaffold($slug, $title);
        }
        Session::flash($error === null ? 'success' : 'danger', $error ?? 'Child theme created in user/themes/' . $slug . '/. Edit its files over FTP or your file manager, then activate it.');
        Response::redirect('/admin/themes');
        return 0;
    }

    /** Create user/themes/{slug}/ with a manifest and a stylesheet. @return string|null an error message */
    private function scaffold(string $slug, string $title): ?string
    {
        $root = $this->userDir . '/themes/' . $slug;
        foreach (['', '/assets', '/assets/css', '/assets/js', '/assets/css/templates', '/templates', '/partials'] as $sub) {
            if (!Files::ensureDir($root . $sub)) {
                return 'Could not create the theme folder. Check permissions on user/themes.';
            }
        }
        $manifest = [
            'name' => $title, 'version' => '1.0.0', 'author' => (string) $this->user['username'],
            'description' => 'Child theme of Base.', 'parent' => 'base', 'requires' => ['cms' => '>=0.3.0'],
            'assets' => ['css' => ['css/child.css'], 'js' => []],
        ];
        $css = "/* Styles for the \"" . str_replace('*/', '', $title) . "\" theme. Loaded after the base theme's CSS on every page.\n"
            . "   To restyle one template only, add assets/css/templates/{template}.css (for example article.css). */\n";
        $readme = "Child theme \"" . $title . "\"\n\nOverride any base file by creating the same path here:\n"
            . "  layout.php, templates/article.php, partials/footer.php, assets/css/theme.css ...\n"
            . "Files you do not create are taken from the base theme. See THEME_GUIDE.md.\n";
        $ok = Files::writeAtomic($root . '/theme.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", 0644)
            && Files::writeAtomic($root . '/assets/css/child.css', $css, 0644)
            && Files::writeAtomic($root . '/README.txt', $readme, 0644);
        return $ok ? null : 'Could not write the theme files. Check permissions on user/themes.';
    }
}
