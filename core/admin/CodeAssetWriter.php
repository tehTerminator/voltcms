<?php
declare(strict_types=1);

namespace Cms;

/** Create, edit, restore and delete code assets (CSS/JS files). Admin only. */
final class CodeAssetWriter
{
    public const MAX_BYTES = 200000;

    public function __construct(private Database $db, private CodeAssetStore $store, private CodeAssetRepository $repo)
    {
    }

    /**
     * @param int|null $exceptId the asset being edited (its own name is not a duplicate)
     * @return array{0:string[],1:array} [errors, clean values]
     */
    public function validate(string $kind, string $name, string $description, string $orderRaw, string $code, ?int $exceptId): array
    {
        $errors = [];
        $name = trim($name);
        $description = trim($description);
        if (!isset(CodeAssetStore::KINDS[$kind])) {
            $errors[] = 'Choose Stylesheet or Script.';
        }
        if ($name === '' || mb_strlen($name) > 100) {
            $errors[] = 'Enter a name (up to 100 characters).';
        } elseif ($this->db->one('SELECT id FROM {p}code_assets WHERE kind = ? AND name = ? AND id <> ?', [$kind, $name, $exceptId ?? 0]) !== null) {
            $errors[] = 'Another ' . strtolower(CodeAssetStore::KINDS[$kind] ?? 'asset') . ' already has that name.';
        }
        if (mb_strlen($description) > 255) {
            $errors[] = 'The description is too long (max 255 characters).';
        }
        $orderRaw = trim($orderRaw);
        $order = 100;
        if ($orderRaw !== '') {
            if (ctype_digit($orderRaw) && (int) $orderRaw <= 9999) {
                $order = (int) $orderRaw;
            } else {
                $errors[] = 'Load order must be a whole number from 0 to 9999 (lower loads first).';
            }
        }
        $trimmed = trim($code);
        if ($trimmed === '') {
            $errors[] = 'Enter the code.';
        } elseif (strlen($code) > self::MAX_BYTES) {
            $errors[] = 'The code is too large (max ' . (self::MAX_BYTES / 1000) . ' KB).';
        } elseif (preg_match('/<\?(php|=)/i', $code) === 1) {
            $errors[] = 'PHP code is not allowed here.';
        } elseif (($kind === 'css' && preg_match('#^\s*<style\b|</style\s*>#i', $code) === 1) || ($kind === 'js' && preg_match('#^\s*<script\b|</script\s*>\s*$#i', $code) === 1)) {
            $errors[] = 'Paste only the code itself, without the <' . ($kind === 'css' ? 'style' : 'script') . '> tags.';
        }
        return [$errors, ['kind' => $kind, 'name' => $name, 'description' => $description, 'load_order' => $order, 'code' => $code]];
    }

    /** @return int the new asset id */
    public function create(array $c): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $ext = $c['kind'];
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($c['name'])), '-');
        $slug = $slug === '' ? 'asset' : substr($slug, 0, 50);
        return (int) $this->db->transaction(function (Database $db) use ($c, $now, $ext, $slug): int {
            $db->run(
                'INSERT INTO {p}code_assets (kind, name, description, file, version_hash, size, load_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$c['kind'], $c['name'], $c['description'] !== '' ? $c['description'] : null, 'pending-' . bin2hex(random_bytes(6)), self::hash($c['code']), strlen($c['code']), $c['load_order'], $now, $now]
            );
            $id = $db->insertId();
            $file = $id . '-' . $slug . '.' . $ext;
            $db->run('UPDATE {p}code_assets SET file = ? WHERE id = ?', [$file, $id]);
            if (!$this->store->write($c['kind'], $file, $c['code'])) {
                throw new \RuntimeException('Could not write the file. Check permissions on user/assets.');
            }
            return $id;
        });
    }

    /** Save changes. If the code changed, the previous file is kept in user/backups/assets/{id}/ first. */
    public function update(int $id, array $c): void
    {
        $row = $this->repo->find($id);
        if ($row === null) {
            throw new \RuntimeException('Asset not found.');
        }
        $old = $this->store->read($row['kind'], $row['file']);
        $changed = $old !== $c['code'];
        if ($changed) {
            $this->store->backup($id, $row['kind'], $row['file']);
        }
        $this->db->transaction(function (Database $db) use ($id, $row, $c, $changed): void {
            $db->run(
                'UPDATE {p}code_assets SET name = ?, description = ?, load_order = ?, version_hash = ?, size = ?, updated_at = ? WHERE id = ?',
                [$c['name'], $c['description'] !== '' ? $c['description'] : null, $c['load_order'], self::hash($c['code']), strlen($c['code']), gmdate('Y-m-d H:i:s'), $id]
            );
            if ($changed && !$this->store->write($row['kind'], $row['file'], $c['code'])) {
                throw new \RuntimeException('Could not write the file. Your previous version is kept in the version history.');
            }
        });
    }

    /** Delete an asset (unlinking it everywhere). The file and its versions are archived, not removed. */
    public function delete(int $id): void
    {
        $row = $this->repo->find($id);
        if ($row === null) {
            return;
        }
        $this->db->run('DELETE FROM {p}code_assets WHERE id = ?', [$id]);
        if ($this->store->archive($id, $row['kind'], $row['file'], $row) === null) {
            Logger::error('Could not archive files of deleted code asset', ['id' => $id]);
        }
    }

    public function restoreVersion(int $id, string $versionName): ?string
    {
        $row = $this->repo->find($id);
        $code = $row === null ? null : $this->store->readVersion($id, $row['kind'], $versionName);
        if ($row === null || $code === null) {
            return 'That version was not found.';
        }
        $this->update($id, ['name' => $row['name'], 'description' => (string) $row['description'], 'load_order' => (int) $row['load_order'], 'code' => $code]);
        return null;
    }

    private static function hash(string $code): string
    {
        return substr(sha1($code), 0, 10);
    }
}
