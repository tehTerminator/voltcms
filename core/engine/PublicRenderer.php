<?php
declare(strict_types=1);

namespace Cms;

/**
 * Renders public pages through the active theme.
 *
 * Template lookup: for each candidate name, templates/{name}.php in the child theme, then in the base theme.
 * If a child theme throws while rendering (parse error, runtime error, missing layout), the failure is logged and the
 * page is rendered again with the base theme. If the base theme fails too, Theme::safeFallback() produces a plain page.
 */
final class PublicRenderer
{
    private string $nonce;
    private Theme $theme;
    private ?array $site = null;

    public function __construct(private Settings $settings, private Database $db, ?string $userDir = null, ?string $coreDir = null)
    {
        require_once __DIR__ . '/theme_helpers.php';
        $this->nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
        $this->theme = Theme::forSite($settings->get('active_theme'), $userDir ?? CMS_ROOT . '/user', $coreDir ?? CMS_ROOT . '/core');
    }

    /** The `$site` theme variable. One settings query plus at most one media query per request. */
    public function site(): array
    {
        return $this->site ??= SiteInfo::load($this->settings, new MediaRepository($this->db));
    }

    public function nonce(): string
    {
        return $this->nonce;
    }

    public function theme(): Theme
    {
        return $this->theme;
    }

    /** @param string|string[] $templates one template name, or candidates in order of preference */
    public function render(string|array $templates, array $vars): string
    {
        $candidates = array_values(array_filter((array) $templates, static fn ($t): bool => is_string($t) && preg_match('/^[a-z0-9_-]+$/', $t) === 1));
        $vars += [
            'site' => $this->site(),
            'nonce' => $this->nonce,
            'preview' => false,
            'noindex' => false,
            'title' => '',
            'metaDescription' => '',
            'itemAssets' => ['css' => [], 'js' => []],
            'seo' => ['title' => '', 'description' => '', 'canonical' => '', 'robots' => '', 'og' => [], 'twitter' => [], 'feed' => ''],
            'tags' => [],
            'customCss' => '', // deprecated since 0.4.0: per-page code is now a linked stylesheet/script asset; kept so older themes do not break
            'customJs' => '',
        ];
        $themes = [$this->theme];
        if ($this->theme->isChild()) {
            $themes[] = $this->theme->baseTheme();
        }
        foreach ($themes as $theme) {
            try {
                return $this->renderWith($theme, $candidates, $vars);
            } catch (\Throwable $e) {
                Logger::error('Theme render failed: ' . $e->getMessage(), ['theme' => $theme->name(), 'file' => basename($e->getFile()), 'line' => $e->getLine()]);
            }
        }
        return Theme::safeFallback($vars);
    }

    private function renderWith(Theme $theme, array $candidates, array $vars): string
    {
        $template = null;
        $file = null;
        foreach ($candidates as $candidate) {
            $hit = $theme->locate('templates/' . $candidate . '.php');
            if ($hit !== null) {
                $template = $candidate;
                $file = $hit['path'];
                break;
            }
        }
        $layout = $theme->locate('layout.php');
        if ($file === null || $layout === null) {
            throw new \RuntimeException('No template or layout found for: ' . implode(', ', $candidates));
        }
        $meta = $theme->meta();
        $vars['template'] = $template;
        $vars['theme'] = ['name' => $theme->name(), 'title' => $meta['title'], 'version' => $meta['version'], 'author' => $meta['author'], 'is_child' => $theme->isChild()];

        Theme::setCurrent($theme);
        Theme::pushVars($vars);
        try {
            $content = View::capture($file, $vars);
            return View::capture($layout['path'], $vars + ['content' => $content]);
        } finally {
            Theme::popVars();
        }
    }
}
