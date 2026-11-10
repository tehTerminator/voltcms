<?php
declare(strict_types=1);

namespace Cms;

/**
 * Checks a child theme before it can be activated. Themes are trusted PHP code: validation catches mistakes
 * (broken JSON, syntax errors, missing files, wrong compatibility), it is not a sandbox.
 */
final class ThemeValidator
{
    private const ALLOWED_EXT = ['php', 'json', 'css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'woff', 'woff2', 'txt', 'md', 'html'];
    private const MAX_FILES = 500;
    private const MAX_FILE_BYTES = 2000000;

    public function __construct(private string $userDir, private string $coreDir)
    {
    }

    /** @return array{errors:string[],warnings:string[],meta:array} */
    public function validate(string $name): array
    {
        $errors = [];
        $warnings = [];
        $dir = $this->userDir . '/themes/' . $name;
        $meta = Theme::readMeta($dir . '/theme.json', $name);

        if ($name === 'base' || preg_match(Theme::NAME_PATTERN, $name) !== 1) {
            return ['errors' => ['The folder name must be 1 to 39 characters: lowercase letters, digits and hyphens, starting with a letter or digit ("base" is reserved).'], 'warnings' => [], 'meta' => $meta];
        }
        if (!is_dir($dir) || is_link($dir)) {
            return ['errors' => ['The theme folder does not exist.'], 'warnings' => [], 'meta' => $meta];
        }

        $errors = array_merge($errors, $this->checkManifest($dir, $meta));
        [$fileErrors, $fileWarnings] = $this->checkFiles($dir);
        return ['errors' => array_merge($errors, $fileErrors), 'warnings' => $fileWarnings, 'meta' => $meta];
    }

    private function checkManifest(string $dir, array $meta): array
    {
        $errors = [];
        $file = $dir . '/theme.json';
        if (!is_file($file)) {
            return ['theme.json is missing.'];
        }
        if (filesize($file) > 20000) {
            return ['theme.json is too large.'];
        }
        $raw = json_decode((string) file_get_contents($file), true);
        if (!is_array($raw)) {
            return ['theme.json is not valid JSON (' . json_last_error_msg() . ').'];
        }
        if (!is_string($raw['name'] ?? null) || trim($raw['name']) === '' || mb_strlen($raw['name']) > 60) {
            $errors[] = 'theme.json needs a "name" (1 to 60 characters).';
        }
        if (!is_string($raw['version'] ?? null) || preg_match('/^\d+\.\d+(?:\.\d+)?(?:[-+][A-Za-z0-9.\-]+)?$/', $raw['version']) !== 1) {
            $errors[] = 'theme.json needs a "version" such as 1.0.0.';
        }
        foreach (['author' => 80, 'description' => 300] as $key => $max) {
            if (isset($raw[$key]) && (!is_string($raw[$key]) || mb_strlen($raw[$key]) > $max)) {
                $errors[] = 'theme.json "' . $key . '" must be text of up to ' . $max . ' characters.';
            }
        }
        if (array_key_exists('parent', $raw) && $raw['parent'] !== null && $raw['parent'] !== 'base') {
            $errors[] = 'theme.json "parent" must be "base" (or omitted). Only the base theme can be a parent.';
        }
        $constraint = $raw['requires']['cms'] ?? null;
        if ($constraint !== null) {
            $problem = self::compatibilityProblem(is_string($constraint) ? $constraint : '');
            if ($problem !== null) {
                $errors[] = $problem;
            }
        }
        foreach (['css', 'js'] as $kind) {
            $list = $raw['assets'][$kind] ?? [];
            if (!is_array($list)) {
                $errors[] = 'theme.json assets.' . $kind . ' must be a list of file paths.';
                continue;
            }
            $theme = new Theme(basename($dir), $this->userDir, $this->coreDir);
            foreach ($list as $path) {
                if (!is_string($path) || !Theme::isSafeRelative($path) || !str_ends_with($path, '.' . $kind)) {
                    $errors[] = 'theme.json assets.' . $kind . ' contains an invalid path (use paths inside assets/, ending in .' . $kind . ').';
                } elseif ($theme->locate('assets/' . $path) === null) {
                    $errors[] = 'Asset listed in theme.json was not found in this theme or the base theme: assets/' . $path;
                }
            }
        }
        return $errors;
    }

    /** @return array{0:string[],1:string[]} */
    private function checkFiles(string $dir): array
    {
        $errors = [];
        $warnings = [];
        $count = 0;
        $realRoot = realpath($dir);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $info) {
            /** @var \SplFileInfo $info */
            $rel = substr($info->getPathname(), strlen($dir) + 1);
            if ($info->isLink()) {
                $errors[] = 'Symbolic links are not allowed: ' . $rel;
                continue;
            }
            if ($info->isDir()) {
                continue;
            }
            if (++$count > self::MAX_FILES) {
                $errors[] = 'The theme has more than ' . self::MAX_FILES . ' files.';
                break;
            }
            $real = $info->getRealPath();
            if ($real === false || $realRoot === false || strncmp($real, $realRoot . DIRECTORY_SEPARATOR, strlen($realRoot) + 1) !== 0) {
                $errors[] = 'File outside the theme folder: ' . $rel;
                continue;
            }
            $ext = strtolower($info->getExtension());
            if (str_starts_with($info->getFilename(), '.') || !in_array($ext, self::ALLOWED_EXT, true)) {
                $errors[] = 'File type not allowed in a theme: ' . $rel;
                continue;
            }
            if ($info->getSize() > self::MAX_FILE_BYTES) {
                $errors[] = 'File is too large (over 2 MB): ' . $rel;
            }
            if ($ext === 'php') {
                $errors = array_merge($errors, $this->lint($real, $rel));
                if ($rel !== 'layout.php' && !str_starts_with($rel, 'templates/') && !str_starts_with($rel, 'partials/')) {
                    $warnings[] = $rel . ' is never loaded. Themes only use layout.php, templates/ and partials/.';
                }
            }
        }
        return [$errors, $warnings];
    }

    /** Syntax check without running the file. @return string[] */
    private function lint(string $file, string $rel): array
    {
        try {
            token_get_all((string) file_get_contents($file), TOKEN_PARSE);
            return [];
        } catch (\ParseError $e) {
            return ['PHP syntax error in ' . $rel . ' on line ' . $e->getLine() . ': ' . $e->getMessage()];
        }
    }

    /** Compare a constraint such as ">=0.3.0" with the running version. @return string|null a problem description */
    public static function compatibilityProblem(string $constraint, ?string $cmsVersion = null): ?string
    {
        if (preg_match('/^\s*(>=|<=|>|<|=)?\s*(\d+\.\d+(?:\.\d+)?)\s*$/', $constraint, $m) !== 1) {
            return 'theme.json requires.cms must look like ">=0.3.0".';
        }
        $current = preg_match('/^\d+\.\d+(?:\.\d+)?/', $cmsVersion ?? CMS_VERSION, $v) === 1 ? $v[0] : '0.0.0';
        $op = $m[1] === '' ? '>=' : $m[1];
        return version_compare($current, $m[2], $op) ? null : 'This theme needs CMS version ' . $op . ' ' . $m[2] . ' but this site runs ' . $current . '.';
    }
}
