<?php
declare(strict_types=1);

namespace Cms;

/**
 * Files for code assets: user/assets/{css|js}/{file}. They are plain static files served directly by the web server
 * (cacheable by browsers, versioned by ?v=). Every overwrite first copies the old file to user/backups/assets/{id}/
 * (newest 10 kept); deleting archives instead of removing.
 */
final class CodeAssetStore
{
    public const KINDS = ['css' => 'Stylesheet', 'js' => 'Script'];
    private const KEEP = 10;

    public function __construct(private string $userDir)
    {
    }

    public function path(string $kind, string $file): string
    {
        return $this->userDir . '/assets/' . $kind . '/' . $file;
    }

    public function read(string $kind, string $file): ?string
    {
        $p = $this->path($kind, $file);
        $d = is_file($p) ? @file_get_contents($p) : false;
        return $d === false ? null : $d;
    }

    public function exists(string $kind, string $file): bool
    {
        return is_file($this->path($kind, $file));
    }

    /** Written world-readable (0644) because the web server, not PHP, serves these files. */
    public function write(string $kind, string $file, string $code): bool
    {
        $p = $this->path($kind, $file);
        return Files::ensureDir(dirname($p)) && Files::writeAtomic($p, $code, 0644);
    }

    /** Copy the current file into the backup folder and prune old copies. */
    public function backup(int $id, string $kind, string $file): void
    {
        $current = $this->read($kind, $file);
        if ($current === null) {
            return;
        }
        $dir = $this->userDir . '/backups/assets/' . $id;
        $name = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.' . $kind;
        if (Files::ensureDir($dir) && Files::writeAtomic($dir . '/' . $name, $current)) {
            foreach (array_slice($this->versions($id, $kind), self::KEEP) as $old) {
                @unlink($dir . '/' . $old['name']);
            }
        }
    }

    /** @return array<int,array{name:string,time:int,size:int}> newest first */
    public function versions(int $id, string $kind): array
    {
        $out = [];
        foreach (glob($this->userDir . '/backups/assets/' . $id . '/*.' . $kind) ?: [] as $f) {
            $out[] = ['name' => basename($f), 'time' => (int) filemtime($f), 'size' => (int) filesize($f)];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($b['name'], $a['name']));
        return $out;
    }

    public function readVersion(int $id, string $kind, string $name): ?string
    {
        if (preg_match('/^\d{8}-\d{6}-[0-9a-f]{4}\.(css|js)$/', $name) !== 1 || !str_ends_with($name, '.' . $kind)) {
            return null;
        }
        $p = $this->userDir . '/backups/assets/' . $id . '/' . $name;
        $d = is_file($p) ? @file_get_contents($p) : false;
        return $d === false ? null : $d;
    }

    /** Move the file and its backups into user/backups/deleted/asset-{id}-{time}/. @return string|null folder */
    public function archive(int $id, string $kind, string $file, array $meta): ?string
    {
        $dir = $this->userDir . '/backups/deleted/asset-' . $id . '-' . gmdate('Ymd-His');
        if (!Files::ensureDir($dir)) {
            return null;
        }
        if ($this->exists($kind, $file) && !@rename($this->path($kind, $file), $dir . '/' . $file)) {
            return null;
        }
        $versions = $this->userDir . '/backups/assets/' . $id;
        if (is_dir($versions)) {
            @rename($versions, $dir . '/versions');
        }
        Files::writeAtomic($dir . '/meta.json', (string) json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $dir;
    }

    /** @return array{css:string[],js:string[]} file names present on disk */
    public function scan(): array
    {
        $out = ['css' => [], 'js' => []];
        foreach (array_keys($out) as $kind) {
            foreach (glob($this->userDir . '/assets/' . $kind . '/*.' . $kind) ?: [] as $f) {
                $out[$kind][] = basename($f);
            }
        }
        return $out;
    }
}
