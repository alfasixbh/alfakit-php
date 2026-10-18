<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Panel\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * `->tenantUploadDirectory()` stores every FileUpload of a tenant under its own folder
 * (`tenants/{key}/<directory>` by default), so tenants' files never share a directory.
 */
trait HasTenantUploads
{
    protected string|Closure|bool $tenantUploadDirectory = false;

    /**
     * @param  string|Closure|bool  $directory  `true` for `tenants/{tenant}`, a pattern with `{tenant}` (the tenant key), or a closure receiving `$tenant`.
     */
    public function tenantUploadDirectory(string|Closure|bool $directory = true): static
    {
        $this->tenantUploadDirectory = $directory;

        return $this;
    }

    /**
     * The current tenant's upload folder, or null outside a tenant / when not configured.
     */
    public function getTenantUploadDirectory(?Model $tenant = null): ?string
    {
        $tenant ??= $this->getTenant();

        if ($tenant === null || $this->tenantUploadDirectory === false) {
            return null;
        }

        $directory = $this->tenantUploadDirectory === true ? 'tenants/{tenant}' : $this->tenantUploadDirectory;

        if ($directory instanceof Closure) {
            $directory = $this->evaluate($directory, ['tenant' => $tenant], [Model::class => $tenant]);
        }

        $directory = str_replace('{tenant}', (string) $tenant->getKey(), (string) $directory);
        $directory = trim($directory, '/');

        return $directory === '' || str_contains($directory, '..') ? null : $directory;
    }

    /**
     * Prefix a FileUpload directory with the tenant folder.
     */
    public function applyTenantUploadDirectory(?string $directory): ?string
    {
        $tenantDirectory = $this->getTenantUploadDirectory();

        if ($tenantDirectory === null) {
            return $directory;
        }

        $directory = trim((string) $directory, '/');

        return $directory === '' ? $tenantDirectory : $tenantDirectory.'/'.$directory;
    }
}
