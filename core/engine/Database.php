<?php
declare(strict_types=1);

namespace Cms;

use PDO;
use PDOStatement;

/**
 * Thin PDO wrapper. Always prepared statements (real, not emulated).
 * Write SQL with the {p} placeholder for the table prefix, e.g. "SELECT * FROM {p}users WHERE id = ?".
 * Timestamps are generated in PHP as UTC (gmdate) so results do not depend on server time zones.
 */
final class Database
{
    private PDO $pdo;
    private string $prefix;

    public function __construct(array $cfg)
    {
        $this->prefix = (string) ($cfg['prefix'] ?? '');
        if (preg_match('/^[A-Za-z0-9_]{0,20}$/', $this->prefix) !== 1) {
            throw new \InvalidArgumentException('Invalid table prefix.');
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            (string) $cfg['host'],
            (int) ($cfg['port'] ?? 3306),
            (string) $cfg['name']
        );
        $this->pdo = new PDO($dsn, (string) $cfg['user'], (string) $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    private function sql(string $sql): string
    {
        return str_replace('{p}', $this->prefix, $sql);
    }

    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($this->sql($sql));
        $stmt->execute($params);
        return $stmt;
    }

    /** Execute a statement without parameters (DDL/migrations). */
    public function exec(string $sql): void
    {
        $this->pdo->exec($this->sql($sql));
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function value(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public function insertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
