<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Pwa;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Sends Web Push messages: payload encryption (RFC 8291, `aes128gcm`) and VAPID
 * authorisation (RFC 8292) with PHP's OpenSSL extension, no extra packages. Bound in the
 * container, so an app can swap it for its own sender.
 */
class WebPush
{
    /** Seconds the push service keeps an undelivered message. */
    public int $ttl = 86400;

    /**
     * Send one payload to one subscription.
     *
     * @return bool|null true when accepted, false when the subscription is gone (404/410), null on other failures
     */
    public function send(PushSubscription $subscription, string $payload): ?bool
    {
        if (! Vapid::isConfigured()) {
            return null;
        }

        try {
            $body = static::encrypt($payload, Vapid::base64UrlDecode($subscription->public_key), Vapid::base64UrlDecode($subscription->auth_token));

            $response = Http::withHeaders([
                'Authorization' => Vapid::authorizationHeader($subscription->endpoint),
                'Content-Encoding' => 'aes128gcm',
                'TTL' => (string) $this->ttl,
                'Urgency' => 'normal',
            ])->withBody($body, 'application/octet-stream')->timeout(10)->post($subscription->endpoint);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        if (in_array($response->status(), [404, 410], true)) {
            return false;
        }

        return $response->successful() ? true : null;
    }

    /**
     * Encrypt a payload for a subscription (`aes128gcm`, one record).
     *
     * @param  string  $userPublicKey  the subscription's `p256dh` key (65 raw bytes)
     * @param  string  $authSecret  the subscription's `auth` secret (16 raw bytes)
     * @param  array{0: string, 1: string}|null  $serverKeys  [public point, private scalar] (tests)
     */
    public static function encrypt(string $payload, string $userPublicKey, string $authSecret, ?array $serverKeys = null, ?string $salt = null): string
    {
        if (strlen($userPublicKey) !== 65 || strlen($authSecret) < 16) {
            throw new RuntimeException('Invalid push subscription keys.');
        }

        [$serverPublic, $serverPrivate] = $serverKeys ?? Vapid::generateRawKeyPair();
        $salt ??= random_bytes(16);

        $sharedSecret = openssl_pkey_derive(Vapid::publicKeyResource($userPublicKey), Vapid::privateKeyResource($serverPrivate, $serverPublic), 32);

        if ($sharedSecret === false) {
            throw new RuntimeException('OpenSSL could not derive the shared secret.');
        }

        $sharedSecret = str_pad($sharedSecret, 32, "\0", STR_PAD_LEFT);

        // RFC 8291 §3.4: combine the ECDH secret with the auth secret.
        $prkKey = hash_hmac('sha256', $sharedSecret, $authSecret, true);
        $ikm = hash_hmac('sha256', "WebPush: info\0".$userPublicKey.$serverPublic."\x01", $prkKey, true);

        // RFC 8188: content encryption key and nonce.
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);

        $tag = '';
        $ciphertext = openssl_encrypt($payload."\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);

        if ($ciphertext === false) {
            throw new RuntimeException('OpenSSL could not encrypt the push payload.');
        }

        return $salt.pack('N', 4096).chr(65).$serverPublic.$ciphertext.$tag;
    }
}
