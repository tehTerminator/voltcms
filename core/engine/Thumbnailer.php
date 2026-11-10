<?php
declare(strict_types=1);

namespace Cms;

/**
 * Creates grid thumbnails of uploaded images with PHP's GD extension.
 *
 * - The longest side of a thumbnail is MAX_SIDE pixels (about twice a grid cell, so it stays sharp on high-density screens).
 *   Images that are already that small get no thumbnail (the original is light enough).
 * - JPEG stays JPEG (quality 82); PNG and GIF become PNG with transparency kept; WebP stays WebP when GD can write it.
 * - JPEG orientation (EXIF) is applied, so phone photos are not sideways.
 * - Very large images are skipped when decoding them would not fit in the server's PHP memory limit.
 * - Without GD nothing happens and callers fall back to the original image.
 * Never throws: a result array explains what happened.
 */
final class Thumbnailer
{
    public const MAX_SIDE = 400;

    /** @param bool|null $enabled null = use GD when available; false disables thumbnails (used by tests) */
    public function __construct(private ?bool $enabled = null)
    {
    }

    public function available(): bool
    {
        return ($this->enabled ?? true) && extension_loaded('gd') && function_exists('imagecreatetruecolor');
    }

    public function supports(string $mime): bool
    {
        return $this->available() && match ($mime) {
            'image/jpeg' => function_exists('imagecreatefromjpeg') && function_exists('imagejpeg'),
            'image/png' => function_exists('imagecreatefrompng') && function_exists('imagepng'),
            'image/gif' => function_exists('imagecreatefromgif') && function_exists('imagepng'),
            'image/webp' => function_exists('imagecreatefromwebp'),
            default => false,
        };
    }

    /** Rough memory need of decoding and resizing (4 bytes per pixel for source and a little more for working copies). */
    public static function memoryOk(int $width, int $height): bool
    {
        $limit = self::bytes((string) ini_get('memory_limit'));
        if ($limit <= 0) {
            return true; // unlimited
        }
        $need = $width * $height * 5 + 6 * 1048576;
        return $need < ($limit - memory_get_usage(true)) * 0.85;
    }

    private static function bytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-1') {
            return -1;
        }
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1073741824,
            'm' => $n * 1048576,
            'k' => $n * 1024,
            default => $n,
        };
    }

    /**
     * @param string $targetBase full path of the thumbnail without extension; the extension is added from the output format
     * @return array{status:string,file?:string,width?:int,height?:int,reason?:string}
     *         status: ok | small (no thumbnail needed) | skipped (cannot be done on this server) | failed
     */
    public function create(string $source, string $mime, string $targetBase): array
    {
        if (!$this->supports($mime)) {
            return ['status' => 'skipped', 'reason' => 'This server cannot create thumbnails for ' . $mime . ' (PHP GD is missing or lacks support).'];
        }
        $info = @getimagesize($source);
        if ($info === false) {
            return ['status' => 'failed', 'reason' => 'The image could not be read.'];
        }
        [$w, $h] = [(int) $info[0], (int) $info[1]];
        $scale = min(self::MAX_SIDE / max(1, $w), self::MAX_SIDE / max(1, $h), 1.0);
        if ($scale >= 1.0) {
            return ['status' => 'small'];
        }
        if (!self::memoryOk($w, $h)) {
            return ['status' => 'skipped', 'reason' => 'The image is too large to process within this server\'s PHP memory limit.'];
        }
        try {
            $img = match ($mime) {
                'image/jpeg' => @imagecreatefromjpeg($source),
                'image/png' => @imagecreatefrompng($source),
                'image/gif' => @imagecreatefromgif($source),
                default => @imagecreatefromwebp($source),
            };
            if ($img === false) {
                return ['status' => 'failed', 'reason' => 'The image could not be decoded.'];
            }
            if ($mime === 'image/jpeg') {
                $img = $this->orient($img, $source);
                [$w, $h] = [imagesx($img), imagesy($img)];
                $scale = min(self::MAX_SIDE / max(1, $w), self::MAX_SIDE / max(1, $h), 1.0);
            }
            $tw = max(1, (int) round($w * $scale));
            $th = max(1, (int) round($h * $scale));
            $thumb = imagecreatetruecolor($tw, $th);
            $keepAlpha = $mime !== 'image/jpeg';
            if ($keepAlpha) {
                imagealphablending($thumb, false);
                imagesavealpha($thumb, true);
                imagefill($thumb, 0, 0, (int) imagecolorallocatealpha($thumb, 0, 0, 0, 127));
            }
            imagecopyresampled($thumb, $img, 0, 0, 0, 0, $tw, $th, $w, $h);

            $webp = $mime === 'image/webp' && function_exists('imagewebp');
            $ext = $mime === 'image/jpeg' ? 'jpg' : ($webp ? 'webp' : 'png');
            $target = $targetBase . '.' . $ext;
            if (!Files::ensureDir(dirname($target))) {
                return ['status' => 'failed', 'reason' => 'Could not create the thumbnail folder.'];
            }
            $tmp = $target . '.tmp';
            $ok = match ($ext) {
                'jpg' => @imagejpeg($thumb, $tmp, 82),
                'webp' => @imagewebp($thumb, $tmp, 80),
                default => @imagepng($thumb, $tmp, 6),
            };
            if (!$ok || !@rename($tmp, $target)) {
                @unlink($tmp);
                return ['status' => 'failed', 'reason' => 'The thumbnail could not be written.'];
            }
            @chmod($target, 0644);
            return ['status' => 'ok', 'file' => $target, 'width' => $tw, 'height' => $th];
        } catch (\Throwable $e) {
            Logger::error('Thumbnail failed: ' . $e->getMessage());
            return ['status' => 'failed', 'reason' => 'The thumbnail could not be created.'];
        }
    }

    /** Apply the JPEG EXIF orientation (phones store rotated photos with a flag instead of rotating the pixels). */
    private function orient(\GdImage $img, string $file): \GdImage
    {
        if (!function_exists('exif_read_data') || !function_exists('imagerotate')) {
            return $img;
        }
        $exif = @exif_read_data($file);
        $angle = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $img;
        }
        $rotated = @imagerotate($img, $angle, 0);
        return $rotated instanceof \GdImage ? $rotated : $img;
    }
}
