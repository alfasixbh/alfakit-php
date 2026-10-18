<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Jobs;

use AlfaSix\Alfakit\Actions\Exports\Models\Export;
use AlfaSix\Alfakit\Actions\Imports\Models\Import;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;

/**
 * Import and export jobs run outside the request that started them (on a queue worker,
 * or inline with the sync driver). They restore the panel, tenant and locale of that
 * request while they work, and put the previous values back afterwards so a long-lived
 * worker never carries one run's tenant into the next job.
 */
trait RestoresRunContext
{
    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    protected function withinRunContext(Import|Export $run, ?string $locale, Closure $callback): mixed
    {
        $manager = alfakit();
        $previousPanel = $manager->getCurrentPanel();
        $previousTenant = $manager->getTenant();
        $previousLocale = App::getLocale();

        $panel = $run->getPanel();

        if ($panel !== null) {
            $manager->setCurrentPanel($panel);
        }

        if ($run->getAttribute('tenant_type') !== null) {
            $tenant = $run->getRelationValue('tenant');

            // A tenant that was deleted since the run started fails closed: nothing is
            // processed outside of it.
            abort_if(! $tenant instanceof Model, 404, 'The tenant of this run no longer exists.');

            $manager->setTenant($tenant, isQuiet: true);
        }

        if ($locale !== null && $locale !== '') {
            App::setLocale($locale);
        }

        try {
            return $callback();
        } finally {
            $manager->setCurrentPanel($previousPanel);
            $manager->setTenant($previousTenant, isQuiet: true);
            App::setLocale($previousLocale);
        }
    }
}
