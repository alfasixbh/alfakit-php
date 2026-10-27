<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Concerns;

use AlfaSix\Alfakit\Support\Debug\ClosureRecorder;
use Closure;
use Illuminate\Contracts\Container\BindingResolutionException;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use WeakMap;

/**
 * Resolves user-supplied closures with dependency injection.
 *
 * Any configuration value in Alfakit may be a Closure. When the value is needed,
 * the closure is invoked and its parameters are resolved by name (e.g. `$record`,
 * `$state`, `$get`) or by type (e.g. `Model $record`, `Request $request`), with
 * a final fallback to the service container.
 */
trait EvaluatesClosures
{
    /**
     * The closure parameter name that resolves to `$this` (e.g. `component`, `action`).
     */
    protected function getEvaluationIdentifier(): string
    {
        return 'component';
    }

    /**
     * @param  array<string, mixed>  $namedInjections
     * @param  array<class-string, mixed>  $typedInjections
     */
    public function evaluate(mixed $value, array $namedInjections = [], array $typedInjections = []): mixed
    {
        if (! $value instanceof Closure) {
            return $value;
        }

        if (ClosureRecorder::$enabled) {
            ClosureRecorder::record($this, $value);
        }

        $dependencies = [];

        foreach (self::closureParameters($value) as $parameter) {
            $dependencies[] = $this->resolveClosureDependencyForEvaluation(
                $parameter,
                $namedInjections,
                $typedInjections,
            );
        }

        return $value(...$dependencies);
    }

    /**
     * Evaluate a callback that authorises the signed-in user (a plugin's `authorize(fn
     * (User $user): bool => ...)`). A guest is never authorised by a closure: it isn't
     * called, since its `User $user` can't take null (the guest login page asks, e.g. for
     * global search). The user is the one a `$user` parameter receives
     * (`resolveDefaultClosureDependencyForEvaluationByName('user')`). Booleans are returned
     * as they are.
     */
    protected function evaluateUserAuthorization(mixed $value): bool
    {
        if ($value instanceof Closure && ($this->resolveDefaultClosureDependencyForEvaluationByName('user')[0] ?? null) === null) {
            return false;
        }

        return (bool) $this->evaluate($value);
    }

    /**
     * The closure's parameters, reflected once per closure object: a table evaluates the
     * same column closures for every row, and reflection was a visible share of that.
     *
     * @return array<int, ReflectionParameter>
     */
    private static function closureParameters(Closure $closure): array
    {
        /** @var WeakMap<Closure, array<int, ReflectionParameter>>|null $cache */
        static $cache = null;
        $cache ??= new WeakMap;

        return $cache[$closure] ??= (new ReflectionFunction($closure))->getParameters();
    }

    /**
     * @param  array<string, mixed>  $namedInjections
     * @param  array<class-string, mixed>  $typedInjections
     */
    protected function resolveClosureDependencyForEvaluation(
        ReflectionParameter $parameter,
        array $namedInjections,
        array $typedInjections,
    ): mixed {
        $name = $parameter->getName();

        // A value injected by name must fit the parameter's type: an array row of a table
        // with custom `records()` is not a `Model $record`, so such a closure falls through
        // to the typed defaults (null for the model) instead of a TypeError.
        if (array_key_exists($name, $namedInjections) && self::parameterAccepts($parameter, $value = value($namedInjections[$name]))) {
            return $value;
        }

        if ($name === $this->getEvaluationIdentifier()) {
            return $this;
        }

        $defaultByName = $this->resolveDefaultClosureDependencyForEvaluationByName($name);

        if ($defaultByName !== [] && self::parameterAccepts($parameter, $defaultByName[0])) {
            return $defaultByName[0];
        }

        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
            /** @var class-string $className */
            $className = $type->getName();

            if (array_key_exists($className, $typedInjections)) {
                return value($typedInjections[$className]);
            }

            if ($this instanceof $className) {
                return $this;
            }

            $defaultByType = $this->resolveDefaultClosureDependencyForEvaluationByType($className);

            if ($defaultByType !== []) {
                return $defaultByType[0];
            }

            if (app()->bound($className) || class_exists($className) || interface_exists($className)) {
                try {
                    return app()->make($className);
                } catch (BindingResolutionException) {
                    // Fall through to defaults below.
                }
            }
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($parameter->allowsNull()) {
            return null;
        }

        throw new BindingResolutionException(
            'An attempt was made to evaluate a closure for ['.static::class."], but [\${$name}] was unresolvable.",
        );
    }

    /**
     * Whether an array value can be passed to the parameter (untyped, `array`, `iterable`
     * or `mixed`, alone or in a union). Non-array values are always passed as they are.
     */
    private static function parameterAccepts(ReflectionParameter $parameter, mixed $value): bool
    {
        if (! is_array($value)) {
            return true;
        }

        $type = $parameter->getType();

        if ($type === null) {
            return true;
        }

        foreach ($type instanceof ReflectionUnionType ? $type->getTypes() : [$type] as $candidate) {
            if ($candidate instanceof ReflectionNamedType && in_array($candidate->getName(), ['array', 'iterable', 'mixed'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Override to provide default injections by parameter name. Return an empty
     * array when the name is unknown, or a single-element array holding the value.
     *
     * @return array<mixed>
     */
    protected function resolveDefaultClosureDependencyForEvaluationByName(string $parameterName): array
    {
        return [];
    }

    /**
     * Override to provide default injections by parameter type.
     *
     * @param  class-string  $parameterType
     * @return array<mixed>
     */
    protected function resolveDefaultClosureDependencyForEvaluationByType(string $parameterType): array
    {
        return [];
    }
}
