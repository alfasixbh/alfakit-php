<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Pwa;

use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * VAPID (RFC 8292) keys and tokens for Web Push, with PHP's OpenSSL extension only.
 * Keys are stored base64url-encoded: the uncompressed P-256 public point (65 bytes) and
 * the private scalar (32 bytes), the format every Web Push library uses.
 */
final class Vapid
{
    private const PUBLIC_KEY_DER_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public static function isConfigured(): bool
    {
        return self::publicKey() !== '' && self::privateKey() !== '';
    }

    public static function publicKey(): string
    {
        return (string) config('alfakit.pwa.vapid.public_key', '');
    }

    public static function privateKey(): string
    {
        return (string) config('alfakit.pwa.vapid.private_key', '');
    }

    /** `mailto:` or `https:` contact for push services; the app URL by default. */
    public static function subject(): string
    {
        $subject = (string) config('alfakit.pwa.vapid.subject', '');

        return $subject !== '' ? $subject : (string) config('app.url', 'https://localhost');
    }

    /**
     * A new key pair.
     *
     * @return array{publicKey: string, privateKey: string}
     */
    public static function generateKeys(): array
    {
        [$public, $private] = self::generateRawKeyPair();

        return ['publicKey' => self::base64UrlEncode($public), 'privateKey' => self::base64UrlEncode($private)];
    }

    /**
     * A new P-256 key pair: the 65-byte public point and 32-byte private scalar.
     *
     * @return array{0: string, 1: string}
     */
    public static function generateRawKeyPair(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);

        if ($key === false) {
            throw new RuntimeException('OpenSSL could not generate a P-256 key.');
        }

        $details = openssl_pkey_get_details($key);

        if ($details === false || ! isset($details['ec']['x'], $details['ec']['y'], $details['ec']['d'])) {
            throw new RuntimeException('OpenSSL did not return the P-256 key parameters.');
        }

        return [
            "\x04".str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT).str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT),
            str_pad($details['ec']['d'], 32, "\0", STR_PAD_LEFT),
        ];
    }

    /**
     * The `Authorization` header for a push service endpoint.
     */
    public static function authorizationHeader(string $endpoint, ?int $expiresAt = null): string
    {
        $scheme = parse_url($endpoint, PHP_URL_SCHEME);
        $host = parse_url($endpoint, PHP_URL_HOST);
        $port = parse_url($endpoint, PHP_URL_PORT);
        $audience = $scheme.'://'.$host.($port !== null ? ':'.$port : '');

        $header = self::base64UrlEncode((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::base64UrlEncode((string) json_encode([
            'aud' => $audience,
            'exp' => $expiresAt ?? time() + 12 * 3600,
            'sub' => self::subject(),
        ], JSON_UNESCAPED_SLASHES));

        $signingInput = $header.'.'.$claims;
        $publicKey = self::base64UrlDecode(self::publicKey());
        $privateKey = self::privateKeyResource(self::base64UrlDecode(self::privateKey()), $publicKey);

        if (! openssl_sign($signingInput, $der, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('OpenSSL could not sign the VAPID token.');
        }

        return 'vapid t='.$signingInput.'.'.self::base64UrlEncode(self::derToRawSignature($der)).', k='.self::publicKey();
    }

    public static function publicKeyResource(string $point): OpenSSLAsymmetricKey
    {
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin(self::PUBLIC_KEY_DER_PREFIX).$point), 64, "\n")."-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);

        if ($key === false) {
            throw new RuntimeException('Invalid P-256 public key.');
        }

        return $key;
    }

    public static function privateKeyResource(string $scalar, string $point): OpenSSLAsymmetricKey
    {
        $der = hex2bin('30770201010420').$scalar.hex2bin('a00a06082a8648ce3d030107a144034200').$point;
        $pem = "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n";
        $key = openssl_pkey_get_private($pem);

        if ($key === false) {
            throw new RuntimeException('Invalid P-256 private key.');
        }

        return $key;
    }

    /** ECDSA DER signature → JOSE `r || s` (64 bytes). */
    public static function derToRawSignature(string $der): string
    {
        $offset = 2;

        if ((ord($der[1]) & 0x80) !== 0) {
            $offset += ord($der[1]) & 0x7F;
        }

        $parts = [];

        for ($i = 0; $i < 2; $i++) {
            $length = ord($der[$offset + 1]);
            $value = substr($der, $offset + 2, $length);
            $parts[] = str_pad(ltrim($value, "\0"), 32, "\0", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $parts[0].$parts[1];
    }

    /** JOSE `r || s` → ECDSA DER signature (for verifying tokens). */
    public static function rawToDerSignature(string $raw): string
    {
        $integer = static function (string $value): string {
            $value = ltrim($value, "\0");

            if ($value === '' || (ord($value[0]) & 0x80) !== 0) {
                $value = "\0".$value;
            }

            return "\x02".chr(strlen($value)).$value;
        };

        $body = $integer(substr($raw, 0, 32)).$integer(substr($raw, 32, 32));

        return "\x30".chr(strlen($body)).$body;
    }

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    }
}
