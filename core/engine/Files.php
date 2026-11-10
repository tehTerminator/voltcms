<?php
declare(strict_types=1);

namespace Cms;

/** Central file helpers. All writes that replace existing data go through writeAtomic(). */
final class Files
{
    public static function ensureDir(string $dir, int $mode = 0755): bool
    {
        return is_dir($dir) || (@mkdir($dir, $mode, true) || is_dir($dir));
    }

    /** Write to a temp file in the same directory, then rename over the target. */
    public static function writeAtomic(string $path, string $data, int $mode = 0640): bool
    {
        $dir = dirname($path);
        $tmp = @tempnam($dir, '.tmp_');
        if ($tmp === false) {
            return false;
        }
        $written = @file_put_contents($tmp, $data, LOCK_EX);
        if ($written !== strlen($data)) {
            @unlink($tmp);
            return false;
        }
        @chmod($tmp, $mode);
        if (!@rename($tmp, $path)) {
            // Windows cannot rename over an existing file; this fallback is not atomic there.
            @unlink($path);
            if (!@rename($tmp, $path)) {
                @unlink($tmp);
                return false;
            }
        }
        return true;
    }
}
