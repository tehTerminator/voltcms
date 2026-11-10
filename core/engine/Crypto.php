<?php
declare(strict_types=1);

namespace Cms;

/** Authenticated encryption (AES-256-GCM) and keyed hashing derived from the application key. */
final class Crypto
{
    private static function key(string $purpose): string
    {
        return hash('sha256', $purpose . '|' . Config::appKey(), true);
    }

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key('enc'), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cipher === false) {
            throw new \RuntimeException('Encryption failed.');
        }
        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $encoded): ?string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key('enc'), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? null : $plain;
    }

    /** Keyed hash for values we must match later but never reveal (IPs, usernames, recovery codes). */
    public static function hmac(string $data): string
    {
        return hash_hmac('sha256', $data, self::key('mac'));
    }
}
