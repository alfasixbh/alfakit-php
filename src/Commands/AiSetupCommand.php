<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Commands\Support\AiKeyTester;
use AlfaSix\Alfakit\Commands\Support\AppEnvironment;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Configure laravel/ai providers: API keys into `.env` (never printed), empty entries in
 * `.env.example`, the published `config/ai.php`, the default provider and model, then a
 * tiny live request per key. `--check` only reports and tests what is configured.
 */
#[AsCommand(name: 'alfakit:ai-setup', description: 'Set up laravel/ai providers: API keys, default provider and model, and a live key check')]
class AiSetupCommand extends Command
{
    protected $signature = 'alfakit:ai-setup
        {--providers= : Comma-separated providers from config/ai.php (openai,anthropic,gemini,…)}
        {--default= : The default provider (ai.default)}
        {--model= : The default text model of the default provider}
        {--check : Only report which providers have keys and test them}
        {--no-test : Do not send a test request}
        {--no-install : Do not install laravel/ai when it is missing}';

    /** Whether laravel/ai is installed; null detects it (tests set it). */
    public static ?bool $laravelAiInstalled = null;

    public function handle(AiKeyTester $tester): int
    {
        if (! $this->ensureInstalled()) {
            return self::FAILURE;
        }

        $providers = static::providers();

        if ($this->option('check')) {
            return $this->check($providers, $tester);
        }

        $chosen = $this->chooseProviders($providers);

        if ($chosen === null) {
            return self::FAILURE;
        }

        if ($chosen === []) {
            $this->components->warn('No providers chosen.');

            return self::SUCCESS;
        }

        $secrets = [];

        foreach ($chosen as $name) {
            $secrets = [...$secrets, ...$this->configureProvider($name, $providers[$name])];
        }

        $this->publishConfig();
        $this->configureDefault($chosen);

        $results = $this->option('no-test') ? [] : $this->testProviders($chosen, $providers, $tester, $secrets);

        $this->newLine();
        $this->components->info('laravel/ai is configured.');

        foreach ($chosen as $name) {
            $this->components->twoColumnDetail($name, $this->describe($name, $providers[$name], $results[$name] ?? null));
        }

        $failed = array_filter($results, static fn (array $result): bool => $result['status'] === AiKeyTester::FAILED);

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Providers and their environment keys, read from `config/ai.php` (or the package's
     * copy): the `key` variable, and a `url` variable when the URL has no default.
     *
     * @return array<string, array{driver: string, key: ?string, url: ?string, urlDefault: ?string}>
     */
    public static function providers(): array
    {
        $path = collect([config_path('ai.php'), base_path('vendor/laravel/ai/config/ai.php'), static::packageConfigPath()])
            ->first(static fn (?string $file): bool => is_string($file) && is_file($file));

        return is_string($path) ? static::parseProviders((string) file_get_contents($path)) : [];
    }

    /**
     * @return array<string, array{driver: string, key: ?string, url: ?string, urlDefault: ?string}>
     */
    public static function parseProviders(string $config): array
    {
        $start = strpos($config, "'providers' => [");

        if ($start === false) {
            return [];
        }

        preg_match_all("/^\s{8}'([a-z0-9-]+)' => \[(.*?)^\s{8}\],/ms", substr($config, $start), $blocks, PREG_SET_ORDER);

        $providers = [];

        foreach ($blocks as [, $name, $body]) {
            preg_match("/'driver' => '([^']+)'/", $body, $driver);
            preg_match("/^\s*'key' => env\('([A-Z0-9_]+)'/m", $body, $key);
            preg_match("/^\s*'url' => env\('([A-Z0-9_]+)'(?:,\s*'([^']*)')?\)/m", $body, $url);

            $providers[$name] = [
                'driver' => $driver[1] ?? $name,
                'key' => $key[1] ?? null,
                'url' => $url[1] ?? null,
                'urlDefault' => $url[2] ?? null,
            ];
        }

        return $providers;
    }

    protected static function packageConfigPath(): ?string
    {
        if (! class_exists('Laravel\\Ai\\AiServiceProvider')) {
            return null;
        }

        $file = (new ReflectionClass('Laravel\\Ai\\AiServiceProvider'))->getFileName();

        return is_string($file) ? dirname($file, 2).'/config/ai.php' : null;
    }

    protected function ensureInstalled(): bool
    {
        if (static::$laravelAiInstalled ?? (AppEnvironment::hasComposerPackage('laravel/ai') || class_exists('Laravel\\Ai\\AiServiceProvider'))) {
            return true;
        }

        // `--check` only reports (it guards deployments): it never installs anything.
        if ($this->option('check')) {
            $this->components->error('laravel/ai is not installed, so no AI provider can work. Run php artisan alfakit:ai-setup to install and configure it.');

            return false;
        }

        if ($this->option('no-install') || ! confirm('laravel/ai is not installed. Install it with Composer now?', true)) {
            $this->components->error('laravel/ai is required. Install it: composer require laravel/ai');

            return false;
        }

        $result = AppEnvironment::composerRequire(['laravel/ai'], output: fn (string $buffer) => $this->output->write($buffer), allowDependencyChanges: fn (): bool => AppEnvironment::allowDependencyChanges($this));

        if ($result->failed()) {
            $this->components->error('Installing laravel/ai failed. Run `composer require laravel/ai` yourself.');

            return false;
        }

        return true;
    }

    /**
     * @param  array<string, array{driver: string, key: ?string, url: ?string, urlDefault: ?string}>  $providers
     * @return array<int, string>|null
     */
    protected function chooseProviders(array $providers): ?array
    {
        $option = $this->option('providers');

        if (is_string($option) && trim($option) !== '') {
            $requested = array_values(array_unique(array_filter(array_map('trim', explode(',', Str::lower($option))))));
            $unknown = array_diff($requested, array_keys($providers));

            if ($unknown !== []) {
                $this->components->error('Unknown provider(s): '.implode(', ', $unknown).'. Available: '.implode(', ', array_keys($providers)).'.');

                return null;
            }

            return $requested;
        }

        if (! $this->input->isInteractive()) {
            $this->components->error('Pass --providers=openai,anthropic,… when running without interaction.');

            return null;
        }

        $configured = array_keys(array_filter($providers, static fn (array $provider): bool => $provider['key'] !== null && filled(AppEnvironment::envValue($provider['key']))));

        /** @var array<int, string> $chosen */
        $chosen = multiselect(
            label: 'Which AI providers do you use?',
            options: array_combine(array_keys($providers), array_map(static fn (string $name, array $provider): string => $name.($provider['key'] !== null ? " ({$provider['key']})" : ''), array_keys($providers), $providers)),
            default: $configured,
            scroll: 12,
            required: true,
        );

        return $chosen;
    }

    /**
     * Ask for the key (and URL, when the provider has no default one) and write them.
     *
     * @param  array{driver: string, key: ?string, url: ?string, urlDefault: ?string}  $provider
     * @return array<int, string> Values to keep out of any output.
     */
    protected function configureProvider(string $name, array $provider): array
    {
        $values = [];
        $secrets = [];

        if ($provider['url'] !== null && ($provider['urlDefault'] === null || $name === 'ollama')) {
            $current = AppEnvironment::envValue($provider['url']);
            $url = $this->input->isInteractive()
                ? text("{$name} URL ({$provider['url']})", default: (string) ($current ?: $provider['urlDefault'] ?? ''), required: $provider['urlDefault'] === null)
                : ($current ?: (string) (getenv($provider['url']) ?: ''));

            if ($url !== '' && $url !== $current) {
                $values[$provider['url']] = $url;
            }
        }

        if ($provider['key'] !== null) {
            $current = AppEnvironment::envValue($provider['key']);
            $key = $this->resolveKey($name, $provider['key'], $current);

            if ($key !== null && $key !== $current) {
                $values[$provider['key']] = $key;
            }

            if (is_string($key ?? $current) && ($key ?? $current) !== '') {
                $secrets[] = (string) ($key ?? $current);
                config(["ai.providers.{$name}.key" => $key ?? $current]);
            }
        }

        if ($values !== []) {
            AppEnvironment::writeEnv($values, overwrite: true, create: true);

            foreach ($values as $variable => $value) {
                if ($variable === $provider['url']) {
                    config(["ai.providers.{$name}.url" => $value]);
                }
            }
        }

        $example = array_fill_keys(array_values(array_filter([$provider['key'], $provider['urlDefault'] === null ? $provider['url'] : null])), '');
        AppEnvironment::writeEnv($example, base_path('.env.example'));

        return $secrets;
    }

    /**
     * A new key from a password prompt (never echoed), or from the environment when run
     * without interaction (`OPENAI_API_KEY=… php artisan alfakit:ai-setup -n`). Null keeps
     * the current value.
     */
    protected function resolveKey(string $name, string $variable, ?string $current): ?string
    {
        $hasCurrent = $current !== null && $current !== '';

        if (! $this->input->isInteractive()) {
            $fromEnvironment = getenv($variable);

            return is_string($fromEnvironment) && $fromEnvironment !== '' && ! $hasCurrent ? $fromEnvironment : null;
        }

        if ($hasCurrent && confirm("{$name}: keep the API key already in .env?", true)) {
            return null;
        }

        $key = password(label: "{$name} API key ({$variable})", placeholder: 'Paste the key; it is not shown', hint: 'Leave empty to skip.');

        return trim($key) === '' ? null : trim($key);
    }

    protected function publishConfig(): void
    {
        if (is_file(config_path('ai.php'))) {
            return;
        }

        if ($this->getApplication()?->has('make:agent')) {
            $this->callSilently('vendor:publish', ['--tag' => 'ai-config']);
        } else {
            AppEnvironment::artisan(['vendor:publish', '--tag=ai-config']);
        }

        $this->components->task('Published config/ai.php', fn (): bool => is_file(config_path('ai.php')));
    }

    /**
     * @param  array<int, string>  $chosen
     */
    protected function configureDefault(array $chosen): void
    {
        $default = $this->option('default');
        $model = $this->option('model');

        if (! is_string($default) || $default === '') {
            $default = null;

            if ($this->input->isInteractive() && count($chosen) > 0) {
                $current = config('ai.default');
                $default = (string) select('Default provider', $chosen, in_array($current, $chosen, true) ? $current : $chosen[0]);
            }
        }

        if ($default === null) {
            return;
        }

        if ((! is_string($model) || $model === '') && $this->input->isInteractive()) {
            $model = text("Default text model for {$default}", placeholder: 'Leave empty for the provider default');
        }

        $path = config_path('ai.php');

        if (! is_file($path)) {
            $this->components->warn("config/ai.php not found: set 'default' => '{$default}' there once it is published.");

            return;
        }

        $contents = static::setDefaultProvider((string) file_get_contents($path), $default);

        if (is_string($model) && $model !== '') {
            $contents = static::setDefaultModel($contents, $default, $model) ?? $contents;
        }

        file_put_contents($path, $contents);
        config(['ai.default' => $default]);

        $this->components->task("Default provider: {$default}".(is_string($model) && $model !== '' ? " ({$model})" : ''));
    }

    public static function setDefaultProvider(string $config, string $provider): string
    {
        return (string) preg_replace("/^(\s{4}'default' => )(?:'[^']*'|env\([^)]*\)),/m", "$1'{$provider}',", $config, 1);
    }

    /**
     * Set `models.text.default` in the provider's block. Null when the block already
     * lists models (left for the user to edit) or is missing.
     */
    public static function setDefaultModel(string $config, string $provider, string $model): ?string
    {
        $model = str_replace("'", '', $model);
        $pattern = "/(^\s{8}'".preg_quote($provider, '/')."' => \[\n)(.*?)(^\s{8}\],)/ms";

        if (preg_match($pattern, $config, $match) !== 1) {
            return null;
        }

        if (str_contains($match[2], "'models'")) {
            return (string) preg_replace("/('text' => \[\s*'default' => )'[^']*'/", "$1'{$model}'", $config, 1) ?: null;
        }

        $line = "            'models' => ['text' => ['default' => '{$model}']],\n";

        return (string) preg_replace($pattern, "$1$2{$line}$3", $config, 1);
    }

    /**
     * @param  array<string, array{driver: string, key: ?string, url: ?string, urlDefault: ?string}>  $providers
     */
    protected function check(array $providers, AiKeyTester $tester): int
    {
        $option = $this->option('providers');
        $names = is_string($option) && trim($option) !== ''
            ? array_values(array_filter(array_map('trim', explode(',', Str::lower($option)))))
            : array_keys(array_filter($providers, static fn (array $provider): bool => $provider['key'] !== null && filled(AppEnvironment::envValue($provider['key']))));

        if ($names === []) {
            $this->components->warn('No provider has an API key in .env. Run php artisan alfakit:ai-setup.');

            return self::FAILURE;
        }

        $secrets = [];

        foreach ($names as $name) {
            $variable = $providers[$name]['key'] ?? null;
            $value = $variable === null ? null : AppEnvironment::envValue($variable);

            if (is_string($value) && $value !== '') {
                $secrets[] = $value;
                config(["ai.providers.{$name}.key" => $value]);
            }
        }

        $results = $this->option('no-test') ? [] : $this->testProviders($names, $providers, $tester, $secrets);

        $this->components->twoColumnDetail('<fg=gray>Provider</>', '<fg=gray>Status</>');

        foreach ($names as $name) {
            $this->components->twoColumnDetail($name, isset($providers[$name]) ? $this->describe($name, $providers[$name], $results[$name] ?? null) : '<fg=red>unknown provider</>');
        }

        $failed = array_filter($results, static fn (array $result): bool => $result['status'] === AiKeyTester::FAILED);

        return $failed === [] && array_diff($names, array_keys($providers)) === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<int, string>  $names
     * @param  array<string, array{driver: string, key: ?string, url: ?string, urlDefault: ?string}>  $providers
     * @param  array<int, string>  $secrets
     * @return array<string, array{status: string, message: string}>
     */
    protected function testProviders(array $names, array $providers, AiKeyTester $tester, array $secrets): array
    {
        $results = [];

        foreach ($names as $name) {
            if (! isset($providers[$name])) {
                continue;
            }

            $variable = $providers[$name]['key'];

            if ($variable !== null && blank(AppEnvironment::envValue($variable)) && $name !== 'ollama') {
                $results[$name] = ['status' => AiKeyTester::SKIPPED, 'message' => 'no key'];

                continue;
            }

            $this->components->task("Testing {$name}", function () use ($tester, $name, $secrets, &$results): bool {
                $result = $tester->test($name);
                $result['message'] = AiKeyTester::sanitize($result['message'], $secrets);
                $results[$name] = $result;

                return $result['status'] !== AiKeyTester::FAILED;
            });
        }

        return $results;
    }

    /**
     * @param  array{driver: string, key: ?string, url: ?string, urlDefault: ?string}  $provider
     * @param  array{status: string, message: string}|null  $result
     */
    protected function describe(string $name, array $provider, ?array $result): string
    {
        $hasKey = $provider['key'] === null || filled(AppEnvironment::envValue($provider['key']));
        $key = $hasKey ? 'key set' : '<fg=yellow>no key</>';

        return match ($result['status'] ?? null) {
            AiKeyTester::OK => "{$key} · <fg=green>works</> ({$result['message']})",
            AiKeyTester::FAILED => "{$key} · <fg=red>failed</>: {$result['message']}",
            AiKeyTester::SKIPPED => "{$key} · <fg=gray>not tested</> ({$result['message']})",
            default => $key,
        };
    }
}
