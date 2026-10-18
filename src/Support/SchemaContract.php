<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support;

/**
 * The version of the schema contract: the JSON shape `toSchema()` produces and the
 * renderers consume. It is shared with every page as `alfakit.schemaContract` and mirrored
 * by `SCHEMA_CONTRACT_VERSION` in `@alfakit/core`.
 *
 * The major part changes only with a breaking change to the contract (a prop removed or
 * its meaning changed); the minor part changes when props are added. Renderers compare the
 * major parts and refuse to render a page whose contract they do not understand.
 */
final class SchemaContract
{
    public const VERSION = '1.0';

    /**
     * The major part of a contract or package version (`1.4` → 1, `v2.0.1` → 2), or null
     * when the version carries no numeric major (`dev-main`).
     */
    public static function major(?string $version): ?int
    {
        if ($version === null || preg_match('/^v?(\d+)(?:\.|$)/', trim($version), $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * Whether a renderer built for `$client` can display pages serialised with `$server`.
     * Unknown versions (missing or without a numeric major) are treated as compatible: an
     * older renderer never breaks only because the server is newer within the same major.
     */
    public static function isCompatible(?string $server, ?string $client = self::VERSION): bool
    {
        $serverMajor = self::major($server);
        $clientMajor = self::major($client);

        if ($serverMajor === null || $clientMajor === null) {
            return true;
        }

        return $serverMajor === $clientMajor;
    }
}
