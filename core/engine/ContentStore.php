<?php
declare(strict_types=1);

namespace Cms;

/**
 * Flat-file storage for content bodies: user/content/posts/{id}.html.
 * Revisions live in user/backups/revisions/{content_id}/{revision_id}.html.
 * Purged content is archived (never deleted) in user/backups/deleted/{id}-{timestamp}/.
 * Every write is atomic (temp file + rename). Missing files return null instead of throwing.
 */
final class ContentStore
{
    public function __construct(private string $userDir)
    {
    }

    public function postPath(int $id): string
    {
        return $this->userDir . '/content/posts/' . $id . '.html';
    }

    public function exists(int $id): bool
    {
        return is_file($this->postPath($id));
    }

    public function read(int $id): ?string
    {
        $path = $this->postPath($id);
        if (!is_file($path)) {
            return null;
        }
        $data = @file_get_contents($path);
        return $data === false ? null : $data;
    }

    public function write(int $id, string $html): bool
    {
        $path = $this->postPath($id);
        return Files::ensureDir(dirname($path)) && Files::writeAtomic($path, $html);
    }

    public function revisionPath(int $contentId, int $revisionId): string
    {
        return $this->userDir . '/backups/revisions/' . $contentId . '/' . $revisionId . '.html';
    }

    public function writeRevision(int $contentId, int $revisionId, string $html): bool
    {
        $path = $this->revisionPath($contentId, $revisionId);
        return Files::ensureDir(dirname($path)) && Files::writeAtomic($path, $html);
    }

    public function readRevision(int $contentId, int $revisionId): ?string
    {
        $path = $this->revisionPath($contentId, $revisionId);
        $data = is_file($path) ? @file_get_contents($path) : false;
        return $data === false ? null : $data;
    }

    public function deleteRevision(int $contentId, int $revisionId): void
    {
        @unlink($this->revisionPath($contentId, $revisionId));
    }

    /**
     * Move a purged item's body and revision folder into backups/deleted/ and write meta.json.
     * @return string|null the archive folder, or null if nothing could be archived
     */
    public function archive(int $id, array $meta): ?string
    {
        $dir = $this->userDir . '/backups/deleted/' . $id . '-' . gmdate('Ymd-His');
        if (!Files::ensureDir($dir)) {
            return null;
        }
        if ($this->exists($id) && !@rename($this->postPath($id), $dir . '/body.html')) {
            return null; // leave the file where it is rather than lose it
        }
        $revisions = $this->userDir . '/backups/revisions/' . $id;
        if (is_dir($revisions)) {
            @rename($revisions, $dir . '/revisions');
        }
        Files::writeAtomic($dir . '/meta.json', (string) json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $dir;
    }

    /** @return int[] ids that have a body file on disk */
    public function scanPostIds(): array
    {
        $ids = [];
        foreach (glob($this->userDir . '/content/posts/*.html') ?: [] as $file) {
            $name = basename($file, '.html');
            if (ctype_digit($name)) {
                $ids[] = (int) $name;
            }
        }
        sort($ids);
        return $ids;
    }
}
