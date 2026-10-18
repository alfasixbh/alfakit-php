<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Middleware;

use AlfaSix\Alfakit\Localization\LocaleResolver;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Number;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the resolved locale, currency and number formatting for the request, so
 * translations, Carbon dates and `Number` helpers all follow the user's language. The
 * previous values are restored once the request terminates (long-lived Octane workers).
 */
class SetPanelLocale
{
    private const PREVIOUS = 'alfakit.previous_locale_state';

    public function __construct(private readonly LocaleResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $panel = alfakit()->getCurrentOrDefaultPanel();
        $locale = $this->resolver->resolve($panel, $request);

        // These settings are process-wide statics; under Octane they would leak into the
        // next (possibly non-panel) request, so `terminate()` puts them back.
        $request->attributes->set(self::PREVIOUS, [
            'app' => app()->getLocale(),
            'carbon' => Carbon::getLocale(),
            'carbonImmutable' => CarbonImmutable::getLocale(),
            'number' => Number::defaultLocale(),
            'currency' => Number::defaultCurrency(),
        ]);

        app()->setLocale($locale->getCode());
        Carbon::setLocale($locale->getCode());
        CarbonImmutable::setLocale($locale->getCode());
        Number::useLocale($locale->getCode());
        Number::useCurrency($panel->getCurrency());

        $response = $next($request);

        if (! $response->headers->has('Content-Language')) {
            $response->headers->set('Content-Language', $locale->getTag());
        }

        return $response;
    }

    /**
     * Runs after the response has been sent (streamed responses included), so restoring
     * the previous state cannot affect what this request renders.
     */
    public function terminate(Request $request, Response $response): void
    {
        $previous = $request->attributes->get(self::PREVIOUS);

        if (! is_array($previous)) {
            return;
        }

        $request->attributes->remove(self::PREVIOUS);

        app()->setLocale($previous['app']);
        Carbon::setLocale($previous['carbon']);
        CarbonImmutable::setLocale($previous['carbonImmutable']);
        Number::useLocale($previous['number']);
        Number::useCurrency($previous['currency']);
    }
}
