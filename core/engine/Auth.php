<?php
declare(strict_types=1);

namespace Cms;

/** Single-administrator authentication: password, optional TOTP or recovery code, throttling. */
final class Auth
{
    private const PENDING_TTL = 300; // seconds allowed between password and second factor
    private const RECOVERY_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';
    private static ?string $dummyHash = null;
    private ?array $userCache = null;

    public function __construct(private Database $db, private RateLimiter $limiter)
    {
    }

    /** @return string 'ok' | '2fa' | 'blocked' | 'invalid' */
    public function attempt(string $username, string $password): string
    {
        $ip = Request::ip();
        if ($this->limiter->blocked($ip, $username)) {
            Logger::security('login_blocked', ['ip' => $ip]);
            return 'blocked';
        }
        $row = $username === '' ? null : $this->db->one('SELECT * FROM {p}users WHERE username = ? LIMIT 1', [$username]);
        // Always run one password_verify so unknown usernames cost the same time as wrong passwords.
        $valid = password_verify($password, $row['password_hash'] ?? self::dummyHash());
        if ($row === null || !$valid) {
            $this->limiter->fail($ip, $username);
            Logger::security('login_failed', ['ip' => $ip]);
            return 'invalid';
        }
        if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
            $this->db->run('UPDATE {p}users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $row['id']]);
        }
        if ((int) $row['totp_enabled'] === 1) {
            Session::reset();
            $_SESSION['_pending'] = ['id' => (int) $row['id'], 'at' => time()];
            return '2fa';
        }
        $this->completeLogin($row);
        return 'ok';
    }

    public function hasPendingSecondFactor(): bool
    {
        $p = $_SESSION['_pending'] ?? null;
        return is_array($p) && time() - (int) ($p['at'] ?? 0) <= self::PENDING_TTL;
    }

    /** @return string 'ok' | 'invalid' | 'blocked' | 'expired' */
    public function verifySecondFactor(string $input): string
    {
        $pending = $_SESSION['_pending'] ?? null;
        if (!$this->hasPendingSecondFactor()) {
            unset($_SESSION['_pending']);
            return 'expired';
        }
        $row = $this->db->one('SELECT * FROM {p}users WHERE id = ?', [(int) $pending['id']]);
        if ($row === null) {
            unset($_SESSION['_pending']);
            return 'expired';
        }
        $ip = Request::ip();
        if ($this->limiter->blocked($ip, $row['username'])) {
            return 'blocked';
        }
        $code = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $input));
        $ok = false;
        if (preg_match('/^\d{6}$/', $code) === 1) {
            $secret = Crypto::decrypt((string) $row['totp_secret_enc']);
            if ($secret !== null) {
                $last = $row['totp_last_step'] === null ? null : (int) $row['totp_last_step'];
                $step = Totp::verify($secret, $code, $last);
                if ($step !== null) {
                    // Atomic replay guard: only the request that advances last_step wins.
                    $ok = $this->db->run(
                        'UPDATE {p}users SET totp_last_step = ? WHERE id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)',
                        [$step, $row['id'], $step]
                    )->rowCount() === 1;
                }
            }
        } elseif (strlen($code) === 12) {
            $ok = $this->useRecoveryCode((int) $row['id'], $code);
        }
        if (!$ok) {
            $this->limiter->fail($ip, $row['username']);
            Logger::security('login_2fa_failed', ['ip' => $ip]);
            return 'invalid';
        }
        $this->completeLogin($row);
        return 'ok';
    }

    private function completeLogin(array $row): void
    {
        Session::reset(); // new session id on privilege change (prevents fixation)
        $_SESSION['uid'] = (int) $row['id'];
        $this->db->run('UPDATE {p}users SET last_login_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s'), $row['id']]);
        $this->limiter->clear(Request::ip(), $row['username']);
        Logger::security('login_ok', ['ip' => Request::ip()]);
    }

    /** The signed-in administrator row, re-read from the database on first use per request. */
    public function user(): ?array
    {
        if ($this->userCache !== null) {
            return $this->userCache;
        }
        $id = $_SESSION['uid'] ?? null;
        if (!is_int($id)) {
            return null;
        }
        $row = $this->db->one('SELECT * FROM {p}users WHERE id = ?', [$id]);
        if ($row === null) {
            Session::reset();
            return null;
        }
        return $this->userCache = $row;
    }

    public function logout(): void
    {
        Session::reset();
        $this->userCache = null;
        Logger::security('logout', ['ip' => Request::ip()]);
    }

    /** Re-check the current password for sensitive actions, throttled like a login. */
    public function confirmPassword(array $user, string $password): bool
    {
        $ip = Request::ip();
        if ($this->limiter->blocked($ip, $user['username'])) {
            return false;
        }
        if (password_verify($password, $user['password_hash'])) {
            return true;
        }
        $this->limiter->fail($ip, $user['username']);
        Logger::security('password_confirm_failed', ['ip' => $ip]);
        return false;
    }

    public function changePassword(int $userId, string $newPassword): void
    {
        $this->db->run('UPDATE {p}users SET password_hash = ? WHERE id = ?', [password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
        Session::reset();
        $_SESSION['uid'] = $userId; // keep this session signed in, with a fresh id
        $this->userCache = null;
        Logger::security('password_changed', ['ip' => Request::ip()]);
    }

    /** Replace all recovery codes. Returns the plain codes once; only keyed hashes are stored. */
    public function newRecoveryCodes(int $userId, int $count = 10): array
    {
        $codes = [];
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $raw = '';
            for ($j = 0; $j < 12; $j++) {
                $raw .= self::RECOVERY_ALPHABET[random_int(0, strlen(self::RECOVERY_ALPHABET) - 1)];
            }
            $codes[] = implode('-', str_split($raw, 4));
            $rows[] = Crypto::hmac('recovery|' . $raw);
        }
        $this->db->transaction(function (Database $db) use ($userId, $rows): void {
            $db->run('DELETE FROM {p}recovery_codes WHERE user_id = ?', [$userId]);
            foreach ($rows as $hash) {
                $db->run('INSERT INTO {p}recovery_codes (user_id, code_hash) VALUES (?, ?)', [$userId, $hash]);
            }
        });
        return $codes;
    }

    private function useRecoveryCode(int $userId, string $normalised): bool
    {
        $hash = Crypto::hmac('recovery|' . $normalised);
        $id = $this->db->value('SELECT id FROM {p}recovery_codes WHERE user_id = ? AND code_hash = ? AND used_at IS NULL', [$userId, $hash]);
        if ($id === null) {
            return false;
        }
        return $this->db->run('UPDATE {p}recovery_codes SET used_at = ? WHERE id = ? AND used_at IS NULL', [gmdate('Y-m-d H:i:s'), $id])->rowCount() === 1;
    }

    private static function dummyHash(): string
    {
        return self::$dummyHash ??= password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
    }
}
