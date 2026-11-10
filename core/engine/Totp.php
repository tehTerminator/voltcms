<?php
declare(strict_types=1);

namespace Cms;

/** RFC 6238 TOTP (SHA-1, 6 digits, 30 s) in plain PHP. No external services involved. */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const PERIOD = 30;

    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function code(string $secretBase32, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::base32Decode($secretBase32), true);
        $offset = ord($hash[19]) & 0x0f;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;
        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Check a code within +-1 step. Returns the matched step, or null.
     * Steps at or below $lastStep are rejected so a code cannot be replayed.
     */
    public static function verify(string $secretBase32, string $code, ?int $lastStep, ?int $now = null): ?int
    {
        $current = intdiv($now ?? time(), self::PERIOD);
        $found = null;
        for ($i = -1; $i <= 1; $i++) {
            $step = $current + $i;
            if ($lastStep !== null && $step <= $lastStep) {
                continue;
            }
            if (hash_equals(self::code($secretBase32, $step), $code) && $found === null) {
                $found = $step;
            }
        }
        return $found;
    }

    public static function uri(string $secretBase32, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
            . '?secret=' . $secretBase32 . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=6&period=' . self::PERIOD;
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function base32Decode(string $text): string
    {
        $text = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $text) ?? '');
        $bits = '';
        foreach (str_split($text) as $c) {
            $bits .= str_pad(decbin((int) strpos(self::ALPHABET, $c)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
