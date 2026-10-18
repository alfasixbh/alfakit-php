<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Controllers;

use AlfaSix\Alfakit\Actions\Exceptions\Cancel;
use AlfaSix\Alfakit\Actions\Exceptions\Halt;
use AlfaSix\Alfakit\Http\Controllers\Concerns\ResolvesContext;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Pages\Attributes\Expose;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Support\SchemaContext;
use AlfaSix\Alfakit\Support\TemporaryUpload;
use BackedEnum;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inertia\Support\SessionKey;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /_alfakit/pages/call` {token, method, arguments, forms?}: runs a page method marked
 * `#[Expose]` for the page's own frontend component.
 *
 * The page is rebuilt from the context token (user, panel access, auth middleware, tenant
 * and `canAccess()` re-checked, see ResolvesContext), so a call can only reach the page the
 * user is on. The method must carry `#[Expose]`; its ability, rate limit and rules are
 * checked, scalar, enum and upload parameters are cast from the JSON arguments, and every
 * other parameter is injected by the container.
 *
 * Answers `{ result, notifications, errors, redirect?, reload? }`: notifications sent during
 * the call come back in the response instead of waiting for the next page.
 */
class PageMethodController
{
    use ResolvesContext;

    public function call(Request $request): JsonResponse|Response
    {
        $context = $this->resolveContext($request);
        $this->ensureTenantMatchesToken($request, $context);
        $page = $this->resolvePage($context);

        $name = $request->input('method');
        abort_if(! is_string($name) || $name === '', 400, 'Missing page method.');

        $exposed = $page::getExposedMethod($name);
        abort_if($exposed === null, 404, "The method [{$name}] is not exposed by [".$page::class.'].');

        [$method, $expose] = $exposed;

        $arguments = $request->input('arguments', []);
        abort_unless(is_array($arguments), 422, 'Page method arguments must be an object or a list.');

        $forms = $request->input('forms', []);
        $page->setClientFormStates(is_array($forms) ? $forms : []);

        $this->authorizeCall($expose);

        $rateLimitKey = $this->rateLimitKey($page, $name);

        if ($expose->rateLimit !== null && RateLimiter::tooManyAttempts($rateLimitKey, $expose->rateLimit)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);

            Notification::make()
                ->danger()
                ->title(__('alfakit::actions.rate_limited.title'))
                ->body(trans_choice('alfakit::actions.rate_limited.body', $seconds, ['seconds' => $seconds, 'minutes' => (int) ceil($seconds / 60)]))
                ->send();

            return $this->respond($request, $page, null, status: 429, extra: ['retryAfter' => $seconds]);
        }

        try {
            $named = $this->validateArguments($page, $method, $expose, $this->nameArguments($method, $arguments));
            $parameters = $this->castParameters($method, $named);

            if ($expose->rateLimit !== null) {
                RateLimiter::hit($rateLimitKey, max(1, $expose->decaySeconds));
            }

            $run = static fn (): mixed => app()->call([$page, $name], $parameters);
            $result = $this->usesTransaction($expose, $page) ? DB::transaction($run) : $run();
        } catch (ValidationException $exception) {
            return $this->respond($request, $page, null, status: 422, extra: [
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
            ]);
        } catch (Halt|Cancel) {
            return $this->respond($request, $page, null, extra: ['halted' => true]);
        }

        if ($result instanceof Responsable) {
            $result = $result->toResponse($request);
        }

        // A redirect is followed by the browser with an Inertia visit, which shows the
        // notifications flashed during the call.
        if ($result instanceof RedirectResponse) {
            return response()->json(['result' => null, 'notifications' => [], 'errors' => null, 'redirect' => \AlfaSix\Alfakit\Support\Url::sanitize($result->getTargetUrl())]);
        }

        if ($result instanceof Response) {
            return $result;
        }

        return $this->respond($request, $page, $result);
    }

    /**
     * A token only works under its own tenant's URL: the method runs for the tenant of the
     * page the user is on, never for another tenant named by the endpoint URL.
     */
    protected function ensureTenantMatchesToken(Request $request, SchemaContext $context): void
    {
        $route = $request->route('tenant');
        $expected = $context->parameters['tenant'] ?? null;

        if ($route === null || $expected === null) {
            return;
        }

        $key = $route instanceof Model ? alfakit()->getCurrentOrDefaultPanel()->getTenantRouteKey($route) : $route;

        abort_unless(is_scalar($key) && (string) $key === (string) $expected, 404);
    }

    protected function authorizeCall(Expose $expose): void
    {
        if ($expose->authorize === null) {
            return;
        }

        $ability = is_array($expose->authorize) ? (string) ($expose->authorize[0] ?? '') : $expose->authorize;
        $arguments = is_array($expose->authorize) ? array_slice($expose->authorize, 1) : [];

        abort_if($ability === '', 500, 'Expose::$authorize needs an ability.');

        Gate::forUser(alfakit()->getCurrentOrDefaultPanel()->auth()->user())->authorize($ability, $arguments);
    }

    /**
     * One bucket per panel, page, method and user (or IP address for guest pages).
     */
    protected function rateLimitKey(Page $page, string $method): string
    {
        $user = alfakit()->getCurrentOrDefaultPanel()->auth()->id();

        return 'alfakit-page-method:'.sha1(implode('|', [
            alfakit()->getCurrentPanel()?->getId() ?? '',
            $page::class,
            $method,
            $user !== null ? 'user:'.$user : 'ip:'.request()->ip(),
        ]));
    }

    /**
     * `#[Expose(transaction:)]` wins, then the page's `$hasDatabaseTransactions`, then the panel.
     */
    protected function usesTransaction(Expose $expose, object $page): bool
    {
        $pageSetting = method_exists($page, 'hasDatabaseTransactions') ? $page->hasDatabaseTransactions() : null;

        return $expose->transaction ?? $pageSetting ?? alfakit()->getCurrentOrDefaultPanel()->hasDatabaseTransactions();
    }

    /**
     * Positional arguments (`call('add', [12, 2])`) are named after the method's parameters.
     *
     * @param  array<mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function nameArguments(ReflectionMethod $method, array $arguments): array
    {
        if ($arguments === [] || ! array_is_list($arguments)) {
            return array_filter($arguments, is_string(...), ARRAY_FILTER_USE_KEY);
        }

        $named = [];

        foreach ($method->getParameters() as $index => $parameter) {
            if (array_key_exists($index, $arguments)) {
                $named[$parameter->getName()] = $arguments[$index];
            }
        }

        return $named;
    }

    /**
     * Apply the attribute's rules (or the rules returned by the page method it names). With
     * rules, only validated arguments reach the method.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @throws ValidationException
     *
     * @return array<string, mixed>
     */
    protected function validateArguments(Page $page, ReflectionMethod $method, Expose $expose, array $arguments): array
    {
        $rules = $expose->rules;

        if (is_string($rules)) {
            $rules = app()->call([$page, $rules], ['arguments' => $arguments]);
        }

        if (! is_array($rules) || $rules === []) {
            return $arguments;
        }

        $validated = Validator::make($arguments, $rules, $expose->messages)->validate();

        // Parameters without rules keep their default instead of an unvalidated value.
        return array_intersect_key($validated, array_flip(array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), $method->getParameters())));
    }

    /**
     * Cast JSON values to the method's scalar, enum and `TemporaryUpload` parameter types.
     * Class-typed parameters (services, the request…) are left to the container and can't be
     * filled from the browser.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @throws ValidationException
     *
     * @return array<string, mixed>
     */
    protected function castParameters(ReflectionMethod $method, array $arguments): array
    {
        $parameters = [];
        $errors = [];

        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();
            $isClass = $type instanceof ReflectionNamedType && ! $type->isBuiltin();
            $class = $isClass ? $type->getName() : null;

            if ($isClass && ! is_a((string) $class, BackedEnum::class, true) && $class !== TemporaryUpload::class) {
                continue;
            }

            if ($parameter->isVariadic()) {
                continue;
            }

            if (! array_key_exists($name, $arguments)) {
                if (! $parameter->isDefaultValueAvailable() && ! $parameter->allowsNull()) {
                    $errors[$name] = [__('validation.required', ['attribute' => $name])];
                }

                continue;
            }

            $value = $arguments[$name];

            if ($value === null && ($type === null || $type->allowsNull())) {
                $parameters[$name] = null;

                continue;
            }

            [$ok, $cast] = $this->cast($type, $value);

            if (! $ok) {
                $errors[$name] = [$this->castMessage($type, $name)];

                continue;
            }

            $parameters[$name] = $cast;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $parameters;
    }

    /**
     * @return array{0: bool, 1: mixed}
     */
    protected function cast(?ReflectionType $type, mixed $value): array
    {
        if (! $type instanceof ReflectionNamedType) {
            return [true, $value];
        }

        $name = $type->getName();

        return match (true) {
            $name === 'mixed' => [true, $value],
            $name === 'int' => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) || (is_float($value) && floor($value) === $value) ? [true, (int) $value] : [false, null],
            $name === 'float' => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? [true, (float) $value] : [false, null],
            $name === 'string' => is_string($value) || is_int($value) || is_float($value) ? [true, (string) $value] : [false, null],
            $name === 'bool' => is_bool($value) || in_array($value, [0, 1, '0', '1', 'true', 'false'], true) ? [true, filter_var($value, FILTER_VALIDATE_BOOLEAN)] : [false, null],
            $name === 'array' => is_array($value) ? [true, $value] : [false, null],
            $name === TemporaryUpload::class => is_string($value) && ($upload = TemporaryUpload::find($value)) !== null ? [true, $upload] : [false, null],
            is_a($name, BackedEnum::class, true) => (is_string($value) || is_int($value)) && ($case = $name::tryFrom($value)) !== null ? [true, $case] : [false, null],
            default => [false, null],
        };
    }

    protected function castMessage(?ReflectionType $type, string $attribute): string
    {
        $name = $type instanceof ReflectionNamedType ? $type->getName() : 'mixed';

        return match (true) {
            $name === 'int' => __('validation.integer', ['attribute' => $attribute]),
            $name === 'float' => __('validation.numeric', ['attribute' => $attribute]),
            $name === 'string' => __('validation.string', ['attribute' => $attribute]),
            $name === 'bool' => __('validation.boolean', ['attribute' => $attribute]),
            $name === 'array' => __('validation.array', ['attribute' => $attribute]),
            $name === TemporaryUpload::class => __('alfakit::forms.file_upload.unavailable'),
            default => __('validation.in', ['attribute' => $attribute]),
        };
    }

    /**
     * The JSON answer, with the notifications sent during the call taken out of the flash
     * data (so they are not shown a second time on the next page).
     *
     * @param  array<string, mixed>  $extra
     */
    protected function respond(Request $request, Page $page, mixed $result, int $status = 200, array $extra = []): JsonResponse
    {
        $notifications = [];

        if ($request->hasSession()) {
            $flash = (array) $request->session()->get(SessionKey::FLASH_DATA, []);
            $notifications = array_values((array) ($flash[Notification::FLASH_KEY] ?? []));
            unset($flash[Notification::FLASH_KEY]);

            if ($flash === []) {
                $request->session()->forget(SessionKey::FLASH_DATA);
            } else {
                $request->session()->flash(SessionKey::FLASH_DATA, $flash);
            }
        }

        $reload = $page->pullPropsToReload();

        return response()->json([
            'result' => $result,
            'notifications' => $notifications,
            'errors' => null,
            ...($reload === null ? [] : ['reload' => $reload]),
            ...$extra,
        ], $status);
    }
}
