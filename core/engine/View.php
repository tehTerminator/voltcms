<?php
declare(strict_types=1);

namespace Cms;

/** Plain PHP templates from core/admin/views, wrapped in layout.php. Views must escape with e(). */
final class View
{
    public static function render(string $view, array $vars = [], bool $layout = true): string
    {
        if (preg_match('/^[a-z0-9_]+$/', $view) !== 1) {
            throw new \InvalidArgumentException('Invalid view name.');
        }
        $dir = CMS_ROOT . '/core/admin/views/';
        $content = self::capture($dir . $view . '.php', $vars);
        if (!$layout) {
            return $content;
        }
        return self::capture($dir . 'layout.php', [
            'content' => $content,
            'title' => (string) ($vars['title'] ?? 'Admin'),
            'nav' => (bool) ($vars['nav'] ?? false),
            'flashes' => Session::pullFlash(),
        ]);
    }

    public static function capture(string $__file, array $__vars): string
    {
        extract($__vars, EXTR_SKIP);
        ob_start();
        try {
            require $__file;
            return (string) ob_get_clean();
        } catch (\Throwable $t) {
            ob_end_clean();
            throw $t;
        }
    }
}
