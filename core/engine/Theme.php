<?php
declare(strict_types=1);

namespace Cms;

/**
 * A theme: the built-in "base" theme (core/themes/base) or a child theme (user/themes/{name}).
 *
 * File resolution order for every theme file (layout, templates, partials, assets):
 *   1. the active child theme  -> user/themes/{name}/{path}
 *   2. the base theme          -> core/themes/base/{path}
 *   3. a built-in safe fallback (Theme::safeFallback) when even the base files are unusable.
 * Resolution results are memoised per request. There is no persistent cache because resolution is a few
 * stat() calls, so there is nothing to invalidate when themes change.
 *
 * Child theme assets are linked directly (static files under /user/themes/). Base theme assets live under core/,
 * which is not web-accessible, so they are served by ThemeAssets at /assets/base/... with long-lived caching.
 */
final class Theme
{
    public const NAME_PATTERN = '/^[a-z0-9][a-z0-9-]{0,38}$/';

    private static ?Theme $current = null;
    /** @var array<int,array> stack of template variable sets, used by partial() */
    private static array $varStack = [];

    private ?array $meta = null;
    private array $memo = [];

    public function __construct(private string $name, private string $userDir, private string $coreDir)
    {
    }

    /** The theme named by the active_theme setting; falls back to base when it is missing or malformed. */
    public static function forSite(?string $active, string $userDir, string $coreDir): self
    {
        $name = $active === null || $active === '' ? 'base' : $active;
        if ($name !== 'base' && (preg_match(self::NAME_PATTERN, $name) !== 1 || !is_file($userDir . '/themes/' . $name . '/theme.json'))) {
            $name = 'base';
        }
        return new self($name, $userDir, $coreDir);
    }

    public function baseTheme(): self
    {
        return $this->name === 'base' ? $this : new self('base', $this->userDir, $this->coreDir);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function isChild(): bool
    {
        return $this->name !== 'base';
    }

    // ---- file resolution ---------------------------------------------------------------------

    /** A relative path is safe when every segment is letters, digits, "_" or "-" with optional dotted extensions. */
    public static function isSafeRelative(string $path): bool
    {
        if ($path === '' || strlen($path) > 200 || str_contains($path, '\\') || str_starts_with($path, '/')) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if (preg_match('/^[A-Za-z0-9_\-]+(?:\.[A-Za-z0-9_\-]+)*$/', $segment) !== 1) {
                return false;
            }
        }
        return true;
    }

    /** @return array{path:string,source:string}|null source is "child" or "base" */
    public function locate(string $relative): ?array
    {
        if (array_key_exists($relative, $this->memo)) {
            return $this->memo[$relative];
        }
        $found = null;
        if (self::isSafeRelative($relative)) {
            $roots = [];
            if ($this->isChild()) {
                $roots['child'] = $this->userDir . '/themes/' . $this->name;
            }
            $roots['base'] = $this->coreDir . '/themes/base';
            foreach ($roots as $source => $root) {
                $real = $this->containedFile($root, $relative);
                if ($real !== null) {
                    $found = ['path' => $real, 'source' => $source];
                    break;
                }
            }
        }
        return $this->memo[$relative] = $found;
    }

    /** The file's real path if it exists inside $root (symlinks pointing outside the theme are refused). */
    private function containedFile(string $root, string $relative): ?string
    {
        $path = $root . '/' . $relative;
        if (!is_file($path)) {
            return null;
        }
        $realRoot = realpath($root);
        $real = realpath($path);
        if ($realRoot === false || $real === false || strncmp($real, $realRoot . DIRECTORY_SEPARATOR, strlen($realRoot) + 1) !== 0) {
            return null;
        }
        return $real;
    }

    // ---- URLs and assets -----------------------------------------------------------------------

    /**
     * URL of a theme asset, given its path inside the theme's assets/ folder (e.g. "css/theme.css", "img/logo.svg").
     * A child theme's file wins over the base theme's file of the same path. Returns '' when no theme has the file.
     * With no argument it returns the assets root of the active theme (no existence check).
     */
    public function url(string $assetPath = ''): string
    {
        $asset = trim($assetPath, '/');
        if ($asset === '') {
            return $this->isChild() ? Url::to('/user/themes/' . $this->name . '/assets') : Url::to('/assets/base');
        }
        $hit = $this->locate('assets/' . $asset);
        if ($hit === null) {
            return '';
        }
        if ($hit['source'] === 'child') {
            return Url::to('/user/themes/' . $this->name . '/assets/' . $asset) . '?v=' . (int) @filemtime($hit['path']);
        }
        return Url::to('/assets/base/' . $asset) . '?v=' . rawurlencode(CMS_VERSION);
    }

    /** Parsed theme.json merged with defaults. Never throws; a bad file yields defaults. */
    public function meta(): array
    {
        if ($this->meta !== null) {
            return $this->meta;
        }
        $dir = $this->isChild() ? $this->userDir . '/themes/' . $this->name : $this->coreDir . '/themes/base';
        return $this->meta = self::readMeta($dir . '/theme.json', $this->name);
    }

    /** @return array{name:string,title:string,version:string,author:string,description:string,parent:?string,requires:array,assets:array{css:string[],js:string[]}} */
    public static function readMeta(string $file, string $fallbackName): array
    {
        $raw = is_file($file) && filesize($file) <= 20000 ? json_decode((string) file_get_contents($file), true) : null;
        $raw = is_array($raw) ? $raw : [];
        $list = static function ($v): array {
            return is_array($v) ? array_values(array_filter($v, static fn ($x): bool => is_string($x) && Theme::isSafeRelative($x))) : [];
        };
        return [
            'name' => $fallbackName,
            'title' => is_string($raw['name'] ?? null) ? mb_substr($raw['name'], 0, 60) : $fallbackName,
            'version' => is_string($raw['version'] ?? null) ? mb_substr($raw['version'], 0, 20) : '',
            'author' => is_string($raw['author'] ?? null) ? mb_substr($raw['author'], 0, 80) : '',
            'description' => is_string($raw['description'] ?? null) ? mb_substr($raw['description'], 0, 300) : '',
            'parent' => is_string($raw['parent'] ?? null) ? $raw['parent'] : null,
            'requires' => is_array($raw['requires'] ?? null) ? $raw['requires'] : [],
            'assets' => ['css' => $list($raw['assets']['css'] ?? []), 'js' => $list($raw['assets']['js'] ?? [])],
        ];
    }

    /**
     * Asset URLs for a page: the base theme's declared files, then the child theme's declared files, then the
     * template-specific files css/templates/{template}.css and js/templates/{template}.js when a theme has them.
     * @return array{css:string[],js:string[]}
     */
    public function assets(string $template): array
    {
        $out = ['css' => [], 'js' => []];
        $metas = $this->isChild() ? [$this->baseTheme()->meta(), $this->meta()] : [$this->meta()];
        foreach (['css', 'js'] as $kind) {
            $paths = [];
            foreach ($metas as $m) {
                foreach ($m['assets'][$kind] as $p) {
                    $paths[$p] = true;
                }
            }
            if (preg_match('/^[a-z0-9_-]+$/', $template) === 1) {
                $paths[$kind . '/templates/' . $template . '.' . $kind] = true;
            }
            foreach (array_keys($paths) as $p) {
                if (!str_ends_with($p, '.' . $kind)) {
                    continue;
                }
                $url = $this->url($p);
                if ($url !== '' && !in_array($url, $out[$kind], true)) {
                    $out[$kind][] = $url;
                }
            }
        }
        return $out;
    }

    // ---- rendering context (used by the helper functions in theme_helpers.php) -----------------------

    public static function setCurrent(?self $theme): void
    {
        self::$current = $theme;
    }

    public static function current(): ?self
    {
        return self::$current;
    }

    public static function pushVars(array $vars): void
    {
        self::$varStack[] = $vars;
    }

    public static function popVars(): void
    {
        array_pop(self::$varStack);
    }

    public static function currentVars(): array
    {
        return self::$varStack === [] ? [] : self::$varStack[array_key_last(self::$varStack)];
    }

    /** Include partials/{name}.php (child first, then base). A missing partial prints nothing and is logged once. */
    public static function renderPartial(string $name, array $vars = []): void
    {
        $theme = self::$current;
        if ($theme === null || preg_match('#^[a-z0-9_-]+(?:/[a-z0-9_-]+)*$#', $name) !== 1) {
            return;
        }
        $hit = $theme->locate('partials/' . $name . '.php');
        if ($hit === null) {
            Logger::error('Theme partial not found', ['theme' => $theme->name(), 'partial' => $name]);
            return;
        }
        echo View::capture($hit['path'], $vars + self::currentVars());
    }

    /** Last-resort page used when no theme can render. Needs no template files. */
    public static function safeFallback(array $vars): string
    {
        $title = (string) ($vars['title'] ?? '');
        $name = (string) ($vars['site']['name'] ?? 'Site');
        $body = isset($vars['body']) ? (string) $vars['body'] : '<p>' . e((string) ($vars['message'] ?? 'This page is temporarily unavailable.')) . '</p>';
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . e($title !== '' ? $title . ' | ' . $name : $name) . '</title></head><body>'
            . '<h1>' . e($title !== '' ? $title : $name) . '</h1>' . $body . '</body></html>';
    }
}
