<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands\Support;

use LogicException;
use Throwable;

/**
 * Checks an AI provider's credentials with the smallest possible laravel/ai request: a
 * one-word prompt to the provider's cheapest text model. Bound in the container so tests
 * (and apps behind a proxy) can swap it.
 */
class AiKeyTester
{
    public const OK = 'ok';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    /**
     * @return array{status: string, message: string}
     */
    public function test(string $provider): array
    {
        if (! class_exists('Laravel\\Ai\\AiManager') || ! app()->bound('Laravel\\Ai\\AiManager')) {
            return ['status' => self::SKIPPED, 'message' => 'laravel/ai is not loaded yet; run php artisan alfakit:ai-setup --check'];
        }

        /** @var \Laravel\Ai\AiManager $manager */
        $manager = app('Laravel\\Ai\\AiManager');

        // The key may have been written a moment ago; build the driver from fresh config.
        if (method_exists($manager, 'forgetInstance')) {
            $manager->forgetInstance($provider);
        }

        try {
            $model = $manager->textProvider($provider)->cheapestTextModel();
        } catch (LogicException) {
            return ['status' => self::SKIPPED, 'message' => 'no text generation to test with'];
        } catch (Throwable $exception) {
            return ['status' => self::FAILED, 'message' => $exception->getMessage()];
        }

        try {
            \Laravel\Ai\agent('Reply with the single word OK.')->prompt('ping', provider: $provider, model: $model, timeout: 30);
        } catch (Throwable $exception) {
            return ['status' => self::FAILED, 'message' => $exception->getMessage()];
        }

        return ['status' => self::OK, 'message' => $model];
    }

    /**
     * An error message that is safe to print: secrets removed, long tokens masked.
     *
     * @param  array<int, string>  $secrets
     */
    public static function sanitize(string $message, array $secrets = []): string
    {
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $message = str_replace($secret, '[hidden]', $message);
            }
        }

        // Anything that looks like a key or token (partial keys show up in provider errors).
        $message = (string) preg_replace('/\b(?:sk|pk|key|xai|gsk|AIza)[A-Za-z0-9_\-]{6,}|[A-Za-z0-9_\-]{24,}/', '[hidden]', $message);
        $message = trim((string) preg_replace('/\s+/', ' ', $message));

        return mb_strlen($message) > 200 ? mb_substr($message, 0, 197).'...' : $message;
    }
}
