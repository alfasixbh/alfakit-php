<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Doctor;

use Composer\InstalledVersions;

/**
 * The facts `alfakit:doctor` reads from outside the configuration: versions, the
 * environment, cache state and files of the application. Bound in the container, so tests
 * (and unusual deployments) replace it with `app()->instance(DoctorEnvironment::class, …)`.
 */
class DoctorEnvironment
{
    public function basePath(string $path = ''): string
    {
        return base_path($path);
    }

    public function publicPath(string $path = ''): string
    {
        return public_path($path);
    }

    public function environment(): string
    {
        return (string) app()->environment();
    }

    public function isProduction(): bool
    {
        return $this->environment() === 'production';
    }

    public function phpVersion(): string
    {
        return PHP_VERSION;
    }

    public function laravelVersion(): string
    {
        return app()->version();
    }

    public function hasExtension(string $extension): bool
    {
        return extension_loaded($extension);
    }

    /** The installed version of a Composer package, or null when it is not installed. */
    public function composerVersion(string $package): ?string
    {
        if (! class_exists(InstalledVersions::class) || ! InstalledVersions::isInstalled($package)) {
            return null;
        }

        return InstalledVersions::getPrettyVersion($package);
    }

    public function configurationIsCached(): bool
    {
        return app()->configurationIsCached();
    }

    public function routesAreCached(): bool
    {
        return app()->routesAreCached();
    }

    public function eventsAreCached(): bool
    {
        return app()->eventsAreCached();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function readJson(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }
}
