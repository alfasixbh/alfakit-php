<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Panel\Concerns;

/**
 * Identify the tenant from the host instead of the path: `->tenantDomain('{tenant:slug}.example.com')`
 * for subdomains, or `->tenantDomain('{tenant:domain}')` for each tenant's own domain.
 */
trait HasTenantDomain
{
    protected ?string $tenantDomain = null;

    public function tenantDomain(?string $domain): static
    {
        if ($domain === null) {
            $this->tenantDomain = null;

            return $this;
        }

        // `{tenant:slug}` names the attribute that holds the subdomain / domain.
        if (preg_match('/\{tenant:([A-Za-z0-9_]+)\}/', $domain, $matches) === 1) {
            $this->tenantSlugAttribute ??= $matches[1];
            $domain = str_replace($matches[0], '{tenant}', $domain);
        }

        $this->tenantDomain = $domain;

        return $this;
    }

    public function hasTenantDomain(): bool
    {
        return $this->hasTenancy() && $this->tenantDomain !== null;
    }

    /**
     * The route domain pattern, with `{tenant}` as the parameter.
     */
    public function getTenantDomain(): ?string
    {
        return $this->hasTenantDomain() ? $this->tenantDomain : null;
    }

    /**
     * A whole-domain tenant (`{tenant}`) may contain dots; a subdomain may not.
     */
    protected function getTenantDomainParameterPattern(): string
    {
        return $this->tenantDomain === '{tenant}' ? '[A-Za-z0-9.-]+' : '[A-Za-z0-9-]+';
    }
}
