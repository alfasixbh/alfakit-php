<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireSubscription
{
    /** @var array<string> */
    public static array $subscribed = ['alpha'];

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = alfakit()->getTenant();

        if ($tenant !== null && ! in_array($tenant->getAttribute('slug'), self::$subscribed, true)) {
            return redirect()->to(alfakit()->getCurrentOrDefaultPanel()->getTenantBillingUrl($tenant) ?? '/');
        }

        return $next($request);
    }
}
