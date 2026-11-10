<?php
declare(strict_types=1);

namespace Cms;

/**
 * Database-backed failed-login throttle. Works without cron or APCu.
 * A client is blocked after 5 failures for the same IP+username, or 10 from one IP,
 * within 15 minutes. The username alone is not a lock key, so an attacker cannot lock the
 * administrator out from elsewhere. IPs and usernames are stored only as keyed hashes.
 */
final class RateLimiter
{
    private const WINDOW = 900;
    private const MAX_PER_PAIR = 5;
    private const MAX_PER_IP = 10;

    public function __construct(private Database $db)
    {
    }

    public function blocked(string $ip, string $username): bool
    {
        $since = gmdate('Y-m-d H:i:s', time() - self::WINDOW);
        [$ipHash, $userHash] = $this->hashes($ip, $username);
        $perIp = (int) $this->db->value('SELECT COUNT(*) FROM {p}login_attempts WHERE ip_hash = ? AND attempted_at > ?', [$ipHash, $since]);
        if ($perIp >= self::MAX_PER_IP) {
            return true;
        }
        $perPair = (int) $this->db->value(
            'SELECT COUNT(*) FROM {p}login_attempts WHERE ip_hash = ? AND username_hash = ? AND attempted_at > ?',
            [$ipHash, $userHash, $since]
        );
        return $perPair >= self::MAX_PER_PAIR;
    }

    public function fail(string $ip, string $username): void
    {
        [$ipHash, $userHash] = $this->hashes($ip, $username);
        $this->db->run(
            'INSERT INTO {p}login_attempts (ip_hash, username_hash, attempted_at) VALUES (?, ?, ?)',
            [$ipHash, $userHash, gmdate('Y-m-d H:i:s')]
        );
        if (random_int(1, 50) === 1) { // opportunistic cleanup instead of cron
            $this->db->run('DELETE FROM {p}login_attempts WHERE attempted_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]);
        }
    }

    public function clear(string $ip, string $username): void
    {
        [$ipHash, $userHash] = $this->hashes($ip, $username);
        $this->db->run('DELETE FROM {p}login_attempts WHERE ip_hash = ? AND username_hash = ?', [$ipHash, $userHash]);
    }

    /** @return array{0:string,1:string} */
    private function hashes(string $ip, string $username): array
    {
        return [Crypto::hmac('ip|' . $ip), Crypto::hmac('user|' . strtolower(substr($username, 0, 64)))];
    }
}
