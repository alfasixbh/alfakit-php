<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Foundation\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'make:alfakit-issue', description: 'Print a link to open a GitHub issue prefilled with your versions')]
class MakeIssueCommand extends Command
{
    public const string REPOSITORY = 'https://github.com/alfasixbh/alfakit';

    protected $signature = 'make:alfakit-issue {--title= : The issue title}';

    public function handle(): int
    {
        $versions = $this->versions();

        $body = "## What happened?\n\n<!-- Describe the bug and what you expected instead. -->\n\n"
            ."## How to reproduce\n\n<!-- Steps, or a link to a repository that shows the bug. -->\n\n"
            ."## Versions\n\n".implode("\n", array_map(
                static fn (string $name, string $version): string => "- {$name}: {$version}",
                array_keys($versions),
                $versions,
            ))."\n";

        $url = self::REPOSITORY.'/issues/new?'.http_build_query([
            'title' => (string) ($this->option('title') ?? ''),
            'labels' => 'bug',
            'body' => $body,
        ], '', '&', PHP_QUERY_RFC3986);

        $this->components->twoColumnDetail('<fg=green;options=bold>Versions</>');

        foreach ($versions as $name => $version) {
            $this->components->twoColumnDetail($name, $version);
        }

        $this->newLine();
        $this->line('Open this link to report the issue (nothing is sent until you submit it):');
        $this->line($url);

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    public function versions(): array
    {
        $versions = [
            'AlfaKit' => self::package('alfasix/alfakit'),
            'Laravel' => Application::VERSION,
            'PHP' => PHP_VERSION,
            'Inertia (Laravel)' => self::package('inertiajs/inertia-laravel'),
        ];

        foreach ($this->npmVersions() as $package => $version) {
            $versions[$package] = $version;
        }

        return $versions;
    }

    private static function package(string $name): string
    {
        try {
            return InstalledVersions::isInstalled($name) ? (InstalledVersions::getPrettyVersion($name) ?? 'unknown') : 'not installed';
        } catch (Throwable) {
            return 'unknown';
        }
    }

    /**
     * The `@alfakit/*` renderer and Inertia adapter versions from package.json.
     *
     * @return array<string, string>
     */
    private function npmVersions(): array
    {
        $file = base_path('package.json');

        if (! is_file($file)) {
            return [];
        }

        $json = json_decode((string) file_get_contents($file), true);

        if (! is_array($json)) {
            return [];
        }

        $versions = [];

        foreach (['dependencies', 'devDependencies'] as $section) {
            foreach ((array) ($json[$section] ?? []) as $package => $version) {
                if (is_string($package) && is_string($version) && (str_starts_with($package, '@alfakit/') || str_starts_with($package, '@inertiajs/'))) {
                    $versions[$package] = $version;
                }
            }
        }

        ksort($versions);

        return $versions;
    }
}
