<?php
declare(strict_types=1);

/**
 * Functions available inside theme templates, layouts and partials.
 * See THEME_GUIDE.md for the full reference.
 */

/** URL of a theme asset (path inside the theme's assets/ folder). Child theme file first, then base. '' if not found. */
function get_theme_url(string $path = ''): string
{
    $theme = Cms\Theme::current();
    return $theme === null ? '' : $theme->url($path);
}

/** Include a partial from partials/{name}.php. Current template variables are available; $vars adds or overrides. */
function partial(string $name, array $vars = []): void
{
    Cms\Theme::renderPartial($name, $vars);
}

/** <link> tags for the global stylesheets and the template-specific stylesheet of the page being rendered. */
function theme_head_assets(): string
{
    $theme = Cms\Theme::current();
    if ($theme === null) {
        return '';
    }
    $out = '';
    foreach ($theme->assets((string) (Cms\Theme::currentVars()['template'] ?? ''))['css'] as $url) {
        $out .= '<link rel="stylesheet" href="' . e($url) . '">' . "\n";
    }
    return $out;
}

/** <script defer> tags for the global scripts and the template-specific script of the page being rendered. */
function theme_footer_assets(): string
{
    $theme = Cms\Theme::current();
    if ($theme === null) {
        return '';
    }
    $out = '';
    foreach ($theme->assets((string) (Cms\Theme::currentVars()['template'] ?? ''))['js'] as $url) {
        $out .= '<script src="' . e($url) . '" defer></script>' . "\n";
    }
    return $out;
}

/** Metadata of the active theme: name, title, version, author, is_child. */
function theme_info(): array
{
    return Cms\Theme::currentVars()['theme'] ?? [];
}
