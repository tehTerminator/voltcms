<?php
declare(strict_types=1);

namespace Cms;

/**
 * Applies core/migrations/NNNN_name.sql files in order and records them in schema_migrations.
 *
 * A migration is either NNNN_name.sql (plain statements) or NNNN_name.php, which returns a function(Database $db): void
 * for changes SQL cannot express portably (conditional ALTERs, converting data, creating files). Use one file per version.
 *
 * Rules for migration files:
 *  - Use {p} for the table prefix; use CREATE TABLE IF NOT EXISTS so an interrupted run can be repeated.
 *  - MySQL commits DDL implicitly, so a migration is not transactional. A failed migration is not
 *    recorded and is retried on the next run.
 *  - Statements end with ";" at the end of a line. Do not put ";" inside string literals or comments.
 */
final class Migrator
{
    public function __construct(private Database $db, private string $dir)
    {
    }

    /** @return string[] versions already applied */
    public function applied(): array
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS {p}schema_migrations ('
            . 'version VARCHAR(64) NOT NULL, applied_at DATETIME NOT NULL, PRIMARY KEY (version)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        return array_column($this->db->all('SELECT version FROM {p}schema_migrations'), 'version');
    }

    /** @return array<string,string> version => file path, sorted by version */
    private function files(): array
    {
        $found = [];
        foreach (['sql', 'php'] as $ext) {
            foreach (glob(rtrim($this->dir, '/') . '/*.' . $ext) ?: [] as $file) {
                $v = basename($file, '.' . $ext);
                if (preg_match('/^\d{4}_[a-z0-9_]+$/', $v) !== 1) {
                    continue;
                }
                if (isset($found[$v])) {
                    throw new \RuntimeException('Migration ' . $v . ' exists as both .sql and .php.');
                }
                $found[$v] = $file;
            }
        }
        ksort($found, SORT_STRING);
        return $found;
    }

    /** @return string[] all migration versions shipped with this release, sorted */
    public function available(): array
    {
        return array_keys($this->files());
    }

    /** @return string[] */
    public function pending(): array
    {
        return array_values(array_diff($this->available(), $this->applied()));
    }

    /** @return string[] versions applied by this call */
    public function run(): array
    {
        $done = [];
        $files = $this->files();
        foreach ($this->pending() as $version) {
            $file = $files[$version];
            try {
                if (str_ends_with($file, '.php')) {
                    $step = require $file;
                    if (!is_callable($step)) {
                        throw new \RuntimeException('a PHP migration must return a function');
                    }
                    $step($this->db);
                } else {
                    foreach (self::splitStatements((string) file_get_contents($file)) as $statement) {
                        $this->db->exec($statement);
                    }
                }
            } catch (\Throwable $e) {
                throw new \RuntimeException('Migration ' . $version . ' failed: ' . $e->getMessage(), 0, $e);
            }
            $this->db->run(
                'INSERT INTO {p}schema_migrations (version, applied_at) VALUES (?, ?)',
                [$version, gmdate('Y-m-d H:i:s')]
            );
            $done[] = $version;
        }
        return $done;
    }

    /** @return string[] */
    public static function splitStatements(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $parts = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn (string $s): bool => $s !== ''));
    }
}
