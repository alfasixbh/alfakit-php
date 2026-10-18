<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use ReflectionMethod;
use WeakMap;

/**
 * Policy checks for the panel's user. An ability is allowed when the model has no policy
 * or the policy doesn't define it, matching how resources authorise, unless the panel
 * uses `strictAuthorization()`: then a missing policy or method denies. A record ability
 * checked without a record (a record action that has none yet) denies when the policy
 * method needs the model.
 */
final class Authorization
{
    /** @var WeakMap<object, array<string, array{0: array<string, string>, 1: object|null}>>|null */
    private static ?WeakMap $policies = null;

    /** @var array<string, bool> */
    private static array $requiresRecord = [];

    /**
     * @param  Model|class-string<Model>|null  $model
     */
    public static function allows(string $ability, Model|string|null $model): bool
    {
        if ($model === null) {
            return true;
        }

        $policy = self::policyFor($model);

        if ($policy === null || ! method_exists($policy, $ability)) {
            return ! self::isStrict();
        }

        // A record ability (`view(User $user, Post $post)`) asked without a record denies
        // instead of calling the policy without its model.
        if (is_string($model) && self::requiresRecord($policy, $ability)) {
            return false;
        }

        return Gate::forUser(alfakit()->getCurrentOrDefaultPanel()->auth()->user())->check($ability, $model);
    }

    /**
     * `Gate::getPolicyFor()`, remembered per gate and model class: guessing the policy
     * name (class_exists over several candidates, reading `#[UsePolicy]`) for every row
     * of a table was a measurable share of a 500-row page. Remembered for the current
     * request only; registering a policy later (`Gate::policy()`) changes the cache key.
     *
     * @param  Model|class-string<Model>  $model
     */
    public static function policyFor(Model|string $model): ?object
    {
        $class = is_string($model) ? $model : $model::class;
        $gate = Gate::getFacadeRoot();

        if (! $gate instanceof \Illuminate\Auth\Access\Gate) {
            $policy = Gate::getPolicyFor($class);

            return is_object($policy) ? $policy : null;
        }

        // Per request (Octane, tests), so a policy resolved with request-scoped dependencies
        // is never reused for another request.
        $scope = app()->bound('request') ? app('request') : $gate;
        self::$policies ??= new WeakMap;
        /** @var array<string, string> $policies */
        $policies = $gate->policies();
        $key = spl_object_id($gate).'|'.$class;
        $cache = self::$policies[$scope] ?? [];

        if (! isset($cache[$key]) || $cache[$key][0] !== $policies) {
            $policy = $gate->getPolicyFor($class);
            $cache[$key] = [$policies, is_object($policy) ? $policy : null];
            self::$policies[$scope] = $cache;
        }

        return $cache[$key][1];
    }

    /**
     * Whether the policy's `$ability` method needs a model instance: it has a required
     * parameter after the user (`view(User $user, Post $post)`), so Laravel's Gate can't
     * call it with the model class alone (`viewAny`, `create`, or `view(User $user, ?Post
     * $post = null)` can).
     */
    public static function requiresRecord(object|string $policy, string $ability): bool
    {
        if (! method_exists($policy, $ability)) {
            return false;
        }

        $key = (is_string($policy) ? $policy : $policy::class).'@'.$ability;

        if (isset(self::$requiresRecord[$key])) {
            return self::$requiresRecord[$key];
        }

        $parameters = (new ReflectionMethod($policy, $ability))->getParameters();

        return self::$requiresRecord[$key] = isset($parameters[1]) && ! $parameters[1]->isOptional();
    }

    /**
     * Whether the current panel denies abilities that no policy method defines.
     */
    public static function isStrict(): bool
    {
        $panel = alfakit()->getCurrentPanel() ?? alfakit()->getDefaultPanel();

        return $panel !== null && $panel->hasStrictAuthorization();
    }
}
