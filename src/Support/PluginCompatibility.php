<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support;

use Composer\InstalledVersions;

/**
 * The compatibility report `alfakit:upgrade` prints: every installed Composer package that
 * declares `extra.alfakit` is checked against the installed `alfasix/alfakit` version, and
 * every `@alfakit/*` npm package in package.json against the PHP package's major version.
 *
 * ```json
 * "extra": {
 *     "alfakit": { "version": "^1.0", "frontend": "@acme/blog" }
 * }
 * ```
 *
 * Every row has a status: `compatible`, `incompatible` or `unknown` (a dev version, a
 * missing package.json entry, an unparsable constraint). The report never throws.
 */
final class PluginCompatibility
{
    public const COMPATIBLE = 'compatible';

    public const INCOMPATIBLE = 'incompatible';

    public const UNKNOWN = 'unknown';

    public function __construct(
        private ?string $alfakitVersion,
        private string $installedJsonPath,
        private string $packageJsonPath,
    ) {}

    public static function forApplication(): self
    {
        $version = InstalledVersions::isInstalled('alfasix/alfakit') ? InstalledVersions::getPrettyVersion('alfasix/alfakit') : null;

        return new self($version, base_path('vendor/composer/installed.json'), base_path('package.json'));
    }

    public function getAlfakitVersion(): ?string
    {
        return $this->alfakitVersion;
    }

    /**
     * One row per installed package declaring `extra.alfakit`.
     *
     * @return list<array{package: string, version: string|null, constraint: string|null, status: string, reason: string, frontend: string|null}>
     */
    public function plugins(): array
    {
        $rows = [];
        $npm = $this->npmDependencies();

        foreach ($this->installedPackages() as $package) {
            $extra = $package['extra']['alfakit'] ?? null;

            if (! is_array($extra) || ! is_string($package['name'] ?? null)) {
                continue;
            }

            $name = $package['name'];
            $version = is_string($package['version'] ?? null) ? $package['version'] : null;
            $constraint = is_string($extra['version'] ?? null) ? $extra['version'] : null;
            $frontend = is_string($extra['frontend'] ?? null) ? $extra['frontend'] : null;

            [$status, $reason] = $this->checkConstraint($constraint);

            if ($frontend !== null) {
                [$frontendStatus, $frontendReason] = $this->checkFrontend($frontend, $version, $npm);
                $status = $this->combine($status, $frontendStatus);
                $reason = trim("{$reason} {$frontendReason}");
            }

            $rows[] = [
                'package' => $name,
                'version' => $version,
                'constraint' => $constraint,
                'status' => $status,
                'reason' => $reason,
                'frontend' => $frontend,
            ];
        }

        return $rows;
    }

    /**
     * One row per `@alfakit/*` npm package in package.json, compared with the PHP major.
     *
     * @return list<array{package: string, constraint: string, status: string, reason: string}>
     */
    public function renderers(): array
    {
        $rows = [];
        $php = $this->alfakitVersion === null ? null : VersionConstraint::parse($this->alfakitVersion);

        foreach ($this->npmDependencies() as $package => $constraint) {
            if (! str_starts_with($package, '@alfakit/')) {
                continue;
            }

            $npm = self::constraintBase($constraint);

            if ($php === null) {
                [$status, $reason] = [self::UNKNOWN, 'alfasix/alfakit is a development version; cannot compare versions.'];
            } elseif ($npm === null) {
                [$status, $reason] = [self::UNKNOWN, "{$constraint} has no numeric version to compare with alfasix/alfakit {$this->alfakitVersion}."];
            } elseif (self::sameRelease($npm, $php)) {
                [$status, $reason] = [self::COMPATIBLE, "{$constraint} matches alfasix/alfakit {$this->alfakitVersion}."];
            } else {
                [$status, $reason] = [self::INCOMPATIBLE, "{$constraint} does not match alfasix/alfakit {$this->alfakitVersion}; run npm install {$package}@^{$php[0]}.{$php[1]}."];
            }

            $rows[] = ['package' => $package, 'constraint' => $constraint, 'status' => $status, 'reason' => $reason];
        }

        return $rows;
    }

    public function hasIncompatible(): bool
    {
        foreach ([...$this->plugins(), ...$this->renderers()] as $row) {
            if ($row['status'] === self::INCOMPATIBLE) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{string, string}
     */
    private function checkConstraint(?string $constraint): array
    {
        if ($constraint === null) {
            return [self::UNKNOWN, 'extra.alfakit.version is not declared.'];
        }

        if ($this->alfakitVersion === null) {
            return [self::UNKNOWN, 'alfasix/alfakit is not installed through Composer.'];
        }

        $satisfied = VersionConstraint::satisfies($this->alfakitVersion, $constraint);

        return match ($satisfied) {
            true => [self::COMPATIBLE, "requires alfasix/alfakit {$constraint}; {$this->alfakitVersion} is installed."],
            false => [self::INCOMPATIBLE, "requires alfasix/alfakit {$constraint}, but {$this->alfakitVersion} is installed."],
            null => [self::UNKNOWN, "requires alfasix/alfakit {$constraint}; cannot compare with {$this->alfakitVersion}."],
        };
    }

    /**
     * The frontend package must be in package.json at the plugin's own major version.
     *
     * @param  array<string, string>  $npm
     * @return array{string, string}
     */
    private function checkFrontend(string $frontend, ?string $pluginVersion, array $npm): array
    {
        $constraint = $npm[$frontend] ?? null;

        if ($constraint === null) {
            return [self::UNKNOWN, "Its frontend package {$frontend} is not in package.json."];
        }

        $plugin = $pluginVersion === null ? null : VersionConstraint::parse($pluginVersion);
        $declared = self::constraintBase($constraint);

        if ($plugin === null || $declared === null) {
            return [self::UNKNOWN, "{$frontend} {$constraint} cannot be compared with the plugin version ".($pluginVersion ?? 'unknown').'.'];
        }

        return self::sameRelease($declared, $plugin)
            ? [self::COMPATIBLE, "{$frontend} {$constraint} matches the plugin."]
            : [self::INCOMPATIBLE, "{$frontend} {$constraint} does not match the plugin version {$pluginVersion}."];
    }

    private function combine(string $a, string $b): string
    {
        if ($a === self::INCOMPATIBLE || $b === self::INCOMPATIBLE) {
            return self::INCOMPATIBLE;
        }

        if ($a === self::UNKNOWN || $b === self::UNKNOWN) {
            return self::UNKNOWN;
        }

        return self::COMPATIBLE;
    }

    /**
     * Same major; for 0.x releases the minor must match too (semver treats 0.x minors as
     * breaking).
     *
     * @param  array{int, int, int}  $a
     * @param  array{int, int, int}  $b
     */
    public static function sameRelease(array $a, array $b): bool
    {
        return $a[0] === $b[0] && ($a[0] !== 0 || $a[1] === $b[1]);
    }

    /**
     * The version an npm constraint starts from (`^1.2.0` → [1, 2, 0]); null for `*`,
     * `workspace:*`, `file:…`, `latest` and other non-numeric specifiers.
     *
     * @return array{int, int, int}|null
     */
    public static function constraintBase(string $constraint): ?array
    {
        $first = trim((string) preg_split('/\s*\|\|\s*/', trim($constraint))[0]);
        $first = (string) preg_replace('/^(\^|~|>=|<=|>|<|=)?\s*/', '', $first);
        $first = (string) preg_split('/[\s,]+/', $first)[0];

        return VersionConstraint::parse($first);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function installedPackages(): array
    {
        $json = $this->readJson($this->installedJsonPath);

        if ($json === null) {
            return [];
        }

        // Composer 2 wraps the list in `packages`; Composer 1 wrote the list itself.
        $packages = array_key_exists('packages', $json) ? $json['packages'] : $json;

        return is_array($packages) ? array_values(array_filter($packages, 'is_array')) : [];
    }

    /**
     * @return array<string, string>
     */
    private function npmDependencies(): array
    {
        $json = $this->readJson($this->packageJsonPath) ?? [];
        $dependencies = [];

        foreach ([...(array) ($json['dependencies'] ?? []), ...(array) ($json['devDependencies'] ?? [])] as $package => $constraint) {
            if (is_string($package) && is_string($constraint)) {
                $dependencies[$package] = $constraint;
            }
        }

        return $dependencies;
    }

    /**
     * @return array<mixed>|null
     */
    private function readJson(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $json = json_decode((string) file_get_contents($path), true);

        return is_array($json) ? $json : null;
    }
}
