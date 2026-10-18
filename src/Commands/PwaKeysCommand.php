<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Commands\Support\AppEnvironment;
use AlfaSix\Alfakit\Pwa\Vapid;
use Illuminate\Console\Command;

/**
 * VAPID keys for `Panel::pwa(push: true)`: generates a key pair and writes it to `.env`.
 */
class PwaKeysCommand extends Command
{
    protected $signature = 'alfakit:pwa-keys
        {--show : Print the keys instead of writing them to .env}
        {--force : Replace keys that are already set (existing subscriptions stop working)}';

    protected $description = 'Generate the VAPID keys AlfaKit uses to send Web Push notifications';

    public function handle(): int
    {
        $keys = Vapid::generateKeys();
        $variables = [
            'ALFAKIT_PWA_VAPID_PUBLIC_KEY' => $keys['publicKey'],
            'ALFAKIT_PWA_VAPID_PRIVATE_KEY' => $keys['privateKey'],
        ];

        if ($this->option('show')) {
            foreach ($variables as $key => $value) {
                $this->line("{$key}={$value}");
            }

            return self::SUCCESS;
        }

        if (! is_file(base_path('.env'))) {
            $this->components->error('No .env file found. Run with --show and add the keys yourself.');

            return self::FAILURE;
        }

        if (! $this->option('force') && AppEnvironment::envValue('ALFAKIT_PWA_VAPID_PUBLIC_KEY') !== null && AppEnvironment::envValue('ALFAKIT_PWA_VAPID_PUBLIC_KEY') !== '') {
            $this->components->warn('VAPID keys are already set. Use --force to replace them (every browser has to subscribe again).');

            return self::SUCCESS;
        }

        AppEnvironment::writeEnv($variables, overwrite: true);
        AppEnvironment::writeEnv(['ALFAKIT_PWA_VAPID_PUBLIC_KEY' => '', 'ALFAKIT_PWA_VAPID_PRIVATE_KEY' => '', 'ALFAKIT_PWA_VAPID_SUBJECT' => ''], base_path('.env.example'));

        $this->components->info('VAPID keys written to .env. Set ALFAKIT_PWA_VAPID_SUBJECT to a mailto: address or your site URL.');

        return self::SUCCESS;
    }
}
