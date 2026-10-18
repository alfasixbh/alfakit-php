<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands\Concerns;

use function Laravel\Prompts\select;

/**
 * The app's frontend adapter: `--adapter`, else the `@alfakit/*` (or Inertia) package in
 * `package.json`, else a prompt.
 */
trait DetectsFrontendAdapter
{
    /** @var array<string, string> */
    protected array $adapterExtensions = ['react' => 'tsx', 'vue' => 'vue', 'svelte' => 'svelte'];

    protected function resolveAdapter(): ?string
    {
        $adapter = $this->option('adapter') ?? $this->detectFrontendAdapter();

        if ($adapter === null && $this->input->isInteractive()) {
            $adapter = select('Which frontend adapter renders your panel?', ['react' => 'React', 'vue' => 'Vue', 'svelte' => 'Svelte'], 'react');
        }

        $adapter ??= 'react';

        if (! isset($this->adapterExtensions[$adapter])) {
            $this->components->error("Unknown adapter [{$adapter}]. Use react, vue or svelte.");

            return null;
        }

        return (string) $adapter;
    }

    protected function detectFrontendAdapter(): ?string
    {
        $packageJson = base_path('package.json');

        if (! file_exists($packageJson)) {
            return null;
        }

        $json = json_decode((string) file_get_contents($packageJson), true);
        $dependencies = is_array($json) ? [...(array) ($json['dependencies'] ?? []), ...(array) ($json['devDependencies'] ?? [])] : [];

        foreach (['react', 'vue', 'svelte'] as $adapter) {
            if (isset($dependencies["@alfakit/{$adapter}"])) {
                return $adapter;
            }
        }

        return match (true) {
            isset($dependencies['@inertiajs/react']) => 'react',
            isset($dependencies['@inertiajs/vue3']) => 'vue',
            isset($dependencies['@inertiajs/svelte']) => 'svelte',
            default => null,
        };
    }
}
