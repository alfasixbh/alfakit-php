<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Commands\AiSetupCommand;
use AlfaSix\Alfakit\Commands\Support\AiKeyTester;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;

/*
 * `alfakit:ai-setup`: laravel/ai keys go to .env (never printed), .env.example gets empty
 * entries, the default provider/model is written to config/ai.php, and every key is tested
 * through AiKeyTester (faked here: no network).
 */

class FakeAiKeyTester extends AiKeyTester
{
    /** @var array<string, array{status: string, message: string}> */
    public static array $results = [];

    /** @var array<int, array{provider: string, key: mixed}> */
    public static array $calls = [];

    public function test(string $provider): array
    {
        self::$calls[] = ['provider' => $provider, 'key' => config("ai.providers.{$provider}.key")];

        return self::$results[$provider] ?? ['status' => self::OK, 'message' => 'tiny-model'];
    }
}

beforeEach(function (): void {
    $this->sandbox = sys_get_temp_dir().'/alfakit-ai-setup-'.uniqid();
    (new Filesystem)->ensureDirectoryExists($this->sandbox.'/config');
    file_put_contents($this->sandbox.'/.env', "APP_NAME=Demo\nOPENAI_API_KEY=\n");
    file_put_contents($this->sandbox.'/.env.example', "APP_NAME=Laravel\n");
    copy(dirname(__DIR__, 4).'/vendor/laravel/ai/config/ai.php', $this->sandbox.'/config/ai.php');

    $this->originalBasePath = $this->app->basePath();
    $this->app->setBasePath($this->sandbox);
    $this->app->useConfigPath($this->sandbox.'/config');

    FakeAiKeyTester::$results = [];
    FakeAiKeyTester::$calls = [];
    $this->app->bind(AiKeyTester::class, FakeAiKeyTester::class);
    Process::fake();
});

afterEach(function (): void {
    AiSetupCommand::$laravelAiInstalled = null;
    $this->app->setBasePath($this->originalBasePath);
    $this->app->useConfigPath($this->originalBasePath.'/config');
    (new Filesystem)->deleteDirectory($this->sandbox);
});

it('reads the providers and their environment keys from config/ai.php', function (): void {
    $providers = AiSetupCommand::providers();

    expect($providers['openai'])->toMatchArray(['driver' => 'openai', 'key' => 'OPENAI_API_KEY', 'url' => 'OPENAI_URL', 'urlDefault' => 'https://api.openai.com/v1'])
        ->and($providers['anthropic']['key'])->toBe('ANTHROPIC_API_KEY')
        ->and($providers['azure'])->toMatchArray(['key' => 'AZURE_OPENAI_API_KEY', 'url' => 'AZURE_OPENAI_URL', 'urlDefault' => null])
        ->and($providers['bedrock']['key'])->toBe('AWS_BEARER_TOKEN_BEDROCK')
        ->and($providers)->toHaveKeys(['gemini', 'mistral', 'ollama', 'openrouter', 'xai', 'openai-compatible']);
});

it('stores keys from a hidden prompt, sets the default and tests each key without printing it', function (): void {
    $this->artisan('alfakit:ai-setup', ['--providers' => 'openai,anthropic'])
        ->expectsQuestion('openai API key (OPENAI_API_KEY)', 'sk-openai-secret-123456')
        ->expectsQuestion('anthropic API key (ANTHROPIC_API_KEY)', 'sk-ant-secret-abcdef')
        ->expectsChoice('Default provider', 'anthropic', ['openai', 'anthropic'])
        ->expectsQuestion('Default text model for anthropic', 'claude-small')
        ->doesntExpectOutputToContain('sk-openai-secret-123456')
        ->doesntExpectOutputToContain('sk-ant-secret-abcdef')
        ->expectsOutputToContain('works')
        ->assertSuccessful();

    $env = file_get_contents($this->sandbox.'/.env');
    $example = file_get_contents($this->sandbox.'/.env.example');
    $config = file_get_contents($this->sandbox.'/config/ai.php');

    expect($env)->toContain('OPENAI_API_KEY=sk-openai-secret-123456')
        ->toContain('ANTHROPIC_API_KEY=sk-ant-secret-abcdef')
        ->and(substr_count($env, 'OPENAI_API_KEY='))->toBe(1)
        ->and($example)->toContain("OPENAI_API_KEY=\n")->toContain("ANTHROPIC_API_KEY=\n")->not->toContain('secret')
        ->and($config)->toContain("    'default' => 'anthropic',")
        ->toContain("'models' => ['text' => ['default' => 'claude-small']],")
        ->and(FakeAiKeyTester::$calls)->toBe([
            ['provider' => 'openai', 'key' => 'sk-openai-secret-123456'],
            ['provider' => 'anthropic', 'key' => 'sk-ant-secret-abcdef'],
        ]);

    expect(shell_exec('php -l '.escapeshellarg($this->sandbox.'/config/ai.php')))->toContain('No syntax errors');
});

it('keeps an existing key when asked and reports failed tests with the key masked', function (): void {
    file_put_contents($this->sandbox.'/.env', "OPENAI_API_KEY=sk-existing-key-999999\n");
    FakeAiKeyTester::$results['openai'] = ['status' => AiKeyTester::FAILED, 'message' => 'Incorrect API key provided: sk-existing-key-999999. Check sk-proj-abcdefghijk.'];

    $this->artisan('alfakit:ai-setup', ['--providers' => 'openai', '--default' => 'openai'])
        ->expectsConfirmation('openai: keep the API key already in .env?', 'yes')
        ->expectsQuestion('Default text model for openai', '')
        ->doesntExpectOutputToContain('sk-existing-key-999999')
        ->doesntExpectOutputToContain('sk-proj-abcdefghijk')
        ->expectsOutputToContain('failed')
        ->assertFailed();

    expect(file_get_contents($this->sandbox.'/.env'))->toBe("OPENAI_API_KEY=sk-existing-key-999999\n");
});

it('takes keys from the environment without interaction and can skip the test', function (): void {
    putenv('MISTRAL_API_KEY=ms-from-env-key');

    try {
        $this->artisan('alfakit:ai-setup', ['--providers' => 'mistral', '--default' => 'mistral', '--no-test' => true, '--no-interaction' => true])
            ->assertSuccessful();
    } finally {
        putenv('MISTRAL_API_KEY');
    }

    expect(file_get_contents($this->sandbox.'/.env'))->toContain('MISTRAL_API_KEY=ms-from-env-key')
        ->and(file_get_contents($this->sandbox.'/config/ai.php'))->toContain("    'default' => 'mistral',")
        ->and(FakeAiKeyTester::$calls)->toBe([]);
});

it('asks for the URL of providers without a default one', function (): void {
    $this->artisan('alfakit:ai-setup', ['--providers' => 'azure', '--no-test' => true, '--default' => 'azure', '--model' => 'gpt-mini'])
        ->expectsQuestion('azure URL (AZURE_OPENAI_URL)', 'https://demo.openai.azure.com')
        ->expectsQuestion('azure API key (AZURE_OPENAI_API_KEY)', 'az-key-1')
        ->assertSuccessful();

    expect(file_get_contents($this->sandbox.'/.env'))->toContain('AZURE_OPENAI_URL=https://demo.openai.azure.com')->toContain('AZURE_OPENAI_API_KEY=az-key-1')
        ->and(file_get_contents($this->sandbox.'/.env.example'))->toContain("AZURE_OPENAI_URL=\n");
});

it('checks configured keys with --check', function (): void {
    file_put_contents($this->sandbox.'/.env', "OPENAI_API_KEY=sk-one-1234567\nGEMINI_API_KEY=AIzaSecretSecret\n");
    FakeAiKeyTester::$results['gemini'] = ['status' => AiKeyTester::FAILED, 'message' => 'API key not valid: AIzaSecretSecret'];

    $this->artisan('alfakit:ai-setup', ['--check' => true])
        ->expectsOutputToContain('openai')
        ->expectsOutputToContain('gemini')
        ->doesntExpectOutputToContain('AIzaSecretSecret')
        ->assertFailed();

    expect(array_column(FakeAiKeyTester::$calls, 'provider'))->toBe(['gemini', 'openai']);

    FakeAiKeyTester::$results = [];
    $this->artisan('alfakit:ai-setup', ['--check' => true])->assertSuccessful();
});

it('never installs laravel/ai with --check, which guards deployments', function (): void {
    AiSetupCommand::$laravelAiInstalled = false;

    $this->artisan('alfakit:ai-setup', ['--check' => true, '--no-interaction' => true])
        ->expectsOutputToContain('laravel/ai is not installed')
        ->assertFailed();

    Process::assertNothingRan();
});

it('rejects unknown providers', function (): void {
    $this->artisan('alfakit:ai-setup', ['--providers' => 'skynet', '--no-interaction' => true])->assertFailed();
});

it('masks keys and token-like strings in error messages', function (): void {
    expect(AiKeyTester::sanitize("Bad key sk-abc123456789 for\nkey-xyz and my-secret-value", ['my-secret-value']))
        ->toBe('Bad key [hidden] for key-xyz and [hidden]');
});
