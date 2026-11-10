<?php
declare(strict_types=1);

namespace Cms;

/**
 * Upload validation and media records. Rules: the file's real type is detected from its content (never from the client's
 * name or declared type) and must be on the allowlist; the stored name is random with an extension derived from the detected type;
 * images must decode and stay within size limits. Files are stored world-readable so the web server can serve them,
 * and user/uploads/.htaccess forbids script execution there. SVG is not accepted (it can carry scripts).
 */
final class MediaWriter
{
    public const MAX_DIMENSION = 12000;
    public const MAX_PIXELS = 60000000;
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    private const IMAGETYPES = ['image/jpeg' => IMAGETYPE_JPEG, 'image/png' => IMAGETYPE_PNG, 'image/gif' => IMAGETYPE_GIF, 'image/webp' => 18];

    private Thumbnailer $thumbs;

    public function __construct(private Database $db, private string $uploadDir, private int $maxBytes = 5242880, ?Thumbnailer $thumbs = null)
    {
        $this->thumbs = $thumbs ?? new Thumbnailer();
    }

    public function thumbnailsAvailable(): bool
    {
        return $this->thumbs->available();
    }

    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    /**
     * @param callable|null $mover function(string $from, string $to): bool; defaults to move_uploaded_file (tests pass rename/copy)
     * @return array{ok:bool,error:?string,id:?int}
     */
    public function import(string $tmpPath, string $clientName, ?int $userId, ?callable $mover = null): array
    {
        $fail = static fn (string $m): array => ['ok' => false, 'error' => $m, 'id' => null];
        $label = '"' . mb_substr(preg_replace('/[^\PC]/u', '', basename($clientName)) ?? '', 0, 80) . '"';
        if (!is_file($tmpPath)) {
            return $fail($label . ': the file did not arrive.');
        }
        $size = (int) filesize($tmpPath);
        if ($size === 0) {
            return $fail($label . ' is empty.');
        }
        if ($size > $this->maxBytes) {
            return $fail($label . ' is larger than the limit of ' . self::formatBytes($this->maxBytes) . '.');
        }
        if (!class_exists(\finfo::class)) {
            return $fail('The PHP fileinfo extension is required to check uploads.');
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmpPath);
        if (!isset(self::TYPES[$mime])) {
            return $fail($label . ' is not an allowed file type (found ' . ($mime === '' ? 'unknown' : $mime) . '). Allowed: JPG, PNG, GIF, WebP, PDF.');
        }
        $width = null;
        $height = null;
        if (isset(self::IMAGETYPES[$mime])) {
            $info = @getimagesize($tmpPath);
            if ($info === false || $info[2] !== self::IMAGETYPES[$mime] || $info[0] < 1 || $info[1] < 1) {
                return $fail($label . ' is not a valid image.');
            }
            [$width, $height] = [(int) $info[0], (int) $info[1]];
            if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION || $width * $height > self::MAX_PIXELS) {
                return $fail($label . ' is too large in pixels (max ' . self::MAX_DIMENSION . ' per side).');
            }
        } elseif (file_get_contents($tmpPath, false, null, 0, 5) !== '%PDF-') {
            return $fail($label . ' is not a valid PDF.');
        }

        $sub = gmdate('Y/m');
        if (!Files::ensureDir($this->uploadDir . '/' . $sub)) {
            return $fail('Could not create the upload folder. Check permissions on user/uploads.');
        }
        do {
            $name = bin2hex(random_bytes(10)) . '.' . self::TYPES[$mime];
            $target = $this->uploadDir . '/' . $sub . '/' . $name;
        } while (file_exists($target));
        if (!($mover ?? 'move_uploaded_file')($tmpPath, $target)) {
            return $fail($label . ' could not be saved. Check permissions on user/uploads.');
        }
        @chmod($target, 0644);

        $original = mb_substr(trim((string) preg_replace('/[\x00-\x1f\x7f]+/u', '', basename($clientName))), 0, 255);
        try {
            $this->db->run(
                'INSERT INTO {p}media (path, original_name, mime, size, width, height, uploaded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$sub . '/' . $name, $original === '' ? $name : $original, $mime, $size, $width, $height, $userId, gmdate('Y-m-d H:i:s')]
            );
        } catch (\Throwable $e) {
            @unlink($target); // never leave a file the database does not know about
            Logger::error('Media insert failed: ' . $e->getMessage());
            return $fail($label . ' could not be recorded.');
        }
        $id = $this->db->insertId();
        if (isset(self::IMAGETYPES[$mime])) {
            $this->makeThumbnail($id, $sub . '/' . $name, $mime); // a failed thumbnail never fails the upload
        }
        return ['ok' => true, 'error' => null, 'id' => $id];
    }

    /**
     * Create (or re-create) the thumbnail of one stored image and record the result.
     * @param string $relPath the image's path inside user/uploads
     * @return string the recorded status: ok | small | skipped | failed
     */
    public function makeThumbnail(int $id, string $relPath, string $mime): string
    {
        if (!$this->thumbs->available()) {
            return 'skipped'; // nothing is recorded, so the image is picked up later once GD is enabled
        }
        $old = $this->db->one('SELECT thumb_path FROM {p}media WHERE id = ?', [$id]);
        if ($old !== null && $old['thumb_path'] !== null && is_file($this->uploadDir . '/' . $old['thumb_path'])) {
            @unlink($this->uploadDir . '/' . $old['thumb_path']);
        }
        $dir = dirname($relPath);
        $base = pathinfo($relPath, PATHINFO_FILENAME);
        $r = $this->thumbs->create($this->uploadDir . '/' . $relPath, $mime, $this->uploadDir . '/' . $dir . '/thumbs/' . $base);
        $rel = $r['status'] === 'ok' ? substr($r['file'], strlen($this->uploadDir) + 1) : null;
        $this->db->run(
            'UPDATE {p}media SET thumb_path = ?, thumb_width = ?, thumb_height = ?, thumb_status = ? WHERE id = ?',
            [$rel, $r['width'] ?? null, $r['height'] ?? null, $r['status'], $id]
        );
        if (isset($r['reason'])) {
            Logger::info('Thumbnail not created', ['media' => $id, 'status' => $r['status'], 'reason' => $r['reason']]);
        }
        return $r['status'];
    }

    /**
     * Thumbnails for images uploaded before thumbnails existed (or while GD was missing), in time-limited steps.
     * @return array{next:?int,made:int,skipped:int} next = last id handled when time ran out, null when finished
     */
    public function generateMissing(int $afterId, float $deadline): array
    {
        $made = 0;
        $skipped = 0;
        $in = implode(',', array_fill(0, count(MediaRepository::IMAGE_MIMES), '?'));
        while (true) {
            $rows = $this->db->all(
                'SELECT id, path, mime FROM {p}media WHERE thumb_status IS NULL AND id > ? AND mime IN (' . $in . ') ORDER BY id LIMIT 20',
                array_merge([$afterId], MediaRepository::IMAGE_MIMES)
            );
            if ($rows === []) {
                return ['next' => null, 'made' => $made, 'skipped' => $skipped];
            }
            foreach ($rows as $r) {
                $afterId = (int) $r['id'];
                if (!is_file($this->uploadDir . '/' . $r['path'])) {
                    $this->db->run("UPDATE {p}media SET thumb_status = 'failed' WHERE id = ?", [$r['id']]);
                    $skipped++;
                } else {
                    $this->makeThumbnail($afterId, (string) $r['path'], (string) $r['mime']) === 'ok' ? $made++ : $skipped++;
                }
                if (microtime(true) > $deadline) {
                    return ['next' => $afterId, 'made' => $made, 'skipped' => $skipped];
                }
            }
        }
    }

    public function updateAlt(int $id, string $alt): void
    {
        $alt = mb_substr(trim($alt), 0, 255);
        $this->db->run('UPDATE {p}media SET alt_text = ? WHERE id = ?', [$alt === '' ? null : $alt, $id]);
    }

    /** @return string[] where the file is used (settings and content social images) */
    public function usage(int $id): array
    {
        $out = [];
        $s = new Settings($this->db);
        if ((int) $s->get('site_logo_media_id', '0') === $id) {
            $out[] = 'the site logo';
        }
        if ((int) $s->get('site_social_media_id', '0') === $id) {
            $out[] = 'the default social image';
        }
        foreach ($this->db->all('SELECT id, title FROM {p}content WHERE og_image_media_id = ? LIMIT 10', [$id]) as $r) {
            $out[] = 'the social image of "' . $r['title'] . '"';
        }
        return $out;
    }

    /** Delete a record; the file is archived under user/backups/deleted/, never removed. @return string|null an error */
    public function delete(int $id): ?string
    {
        $row = $this->db->one('SELECT * FROM {p}media WHERE id = ?', [$id]);
        if ($row === null) {
            return 'That file was not found.';
        }
        $used = $this->usage($id);
        if ($used !== []) {
            return 'This file is still used as ' . implode(', ', $used) . '. Choose another image there first.';
        }
        $this->db->run('DELETE FROM {p}media WHERE id = ?', [$id]);
        $src = $this->uploadDir . '/' . $row['path'];
        if (is_file($src)) {
            $dir = dirname($this->uploadDir) . '/backups/deleted/media-' . $id . '-' . gmdate('Ymd-His');
            if (!Files::ensureDir($dir) || !@rename($src, $dir . '/' . basename($row['path']))) {
                Logger::error('Could not archive deleted media file', ['id' => $id]);
                return null;
            }
            if ($row['thumb_path'] !== null && is_file($this->uploadDir . '/' . $row['thumb_path'])) {
                @rename($this->uploadDir . '/' . $row['thumb_path'], $dir . '/thumb-' . basename((string) $row['thumb_path']));
            }
            Files::writeAtomic($dir . '/meta.json', (string) json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        return null;
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        return $bytes >= 1024 ? round($bytes / 1024) . ' KB' : $bytes . ' bytes';
    }

    public static function uploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'larger than the server allows (upload_max_filesize is ' . ini_get('upload_max_filesize') . ')',
            UPLOAD_ERR_PARTIAL => 'only partly uploaded; please try again',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => 'could not be stored by the server (temporary folder problem)',
            default => 'did not upload',
        };
    }
}
