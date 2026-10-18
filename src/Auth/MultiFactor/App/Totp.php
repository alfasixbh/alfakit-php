<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Auth\MultiFactor\App;

use InvalidArgumentException;

/**
 * Time-based one-time passwords (RFC 6238, HMAC-SHA1, 6 digits, 30 second steps), the
 * format every authenticator app understands.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public const DIGITS = 6;

    public const PERIOD = 30;

    /**
     * A new random secret, base32 encoded (160 bits, as RFC 4226 recommends).
     */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /**
     * The code for a time step (defaults to now).
     */
    public static function code(string $secret, ?int $timestep = null): string
    {
        $timestep ??= self::timestep();
        $key = self::base32Decode($secret);
        $hash = hash_hmac('sha1', pack('N2', ($timestep >> 32) & 0xFFFFFFFF, $timestep & 0xFFFFFFFF), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * The time step a code matches within `$window` steps of now, or null. Callers store
     * the matched step and pass it as `$after` so a code can't be used twice.
     */
    public static function verify(string $secret, string $code, int $window = 1, ?int $after = null, ?int $timestep = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (preg_match('/^\d{'.self::DIGITS.'}$/', $code) !== 1) {
            return null;
        }

        $timestep ??= self::timestep();

        for ($step = $timestep - $window; $step <= $timestep + $window; $step++) {
            if ($after !== null && $step <= $after) {
                continue;
            }

            if (hash_equals(self::code($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    public static function timestep(?int $time = null): int
    {
        return intdiv($time ?? time(), self::PERIOD);
    }

    /**
     * The `otpauth://` URI encoded in the setup QR code.
     */
    public static function uri(string $secret, string $issuer, string $holder): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($holder);

        return 'otpauth://totp/'.$label.'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';

        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }

    public static function base32Decode(string $encoded): string
    {
        $encoded = strtoupper(rtrim(str_replace([' ', '-'], '', $encoded), '='));
        $bits = '';

        foreach (str_split($encoded) as $character) {
            $value = strpos(self::ALPHABET, $character);

            if ($value === false) {
                throw new InvalidArgumentException('The secret is not valid base32.');
            }

            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';

        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr((int) bindec($chunk));
            }
        }

        return $bytes;
    }
}
