<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Pages\Concerns;

use AlfaSix\Alfakit\Pages\Attributes\Expose;
use ReflectionClass;
use ReflectionMethod;

/**
 * Public page methods marked `#[Expose]`, callable from the page's own frontend component
 * through `POST /_alfakit/pages/call`.
 */
trait HasExposedMethods
{
    /** @var array<class-string, array<string, array{0: ReflectionMethod, 1: Expose}|null>> */
    private static array $exposedMethods = [];

    /**
     * Props (`getViewData()` keys, or `page`) the browser reloads after the current call;
     * `true` reloads every prop.
     *
     * @var array<string>|true|null
     */
    protected array|bool|null $propsToReload = null;

    /**
     * The exposed method by name, with its attribute; null for anything else (missing,
     * static, not public, or without `#[Expose]`).
     *
     * @return array{0: ReflectionMethod, 1: Expose}|null
     */
    public static function getExposedMethod(string $name): ?array
    {
        if (array_key_exists($name, self::$exposedMethods[static::class] ?? [])) {
            return self::$exposedMethods[static::class][$name];
        }

        $exposed = null;

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1 && method_exists(static::class, $name)) {
            $method = new ReflectionMethod(static::class, $name);
            $attributes = $method->getAttributes(Expose::class);

            if ($method->isPublic() && ! $method->isStatic() && ! $method->isConstructor() && $attributes !== []) {
                $exposed = [$method, $attributes[0]->newInstance()];
            }
        }

        return self::$exposedMethods[static::class][$name] = $exposed;
    }

    /**
     * Names of every exposed method.
     *
     * @return array<string>
     */
    public static function getExposedMethodNames(): array
    {
        $names = [];

        foreach ((new ReflectionClass(static::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (static::getExposedMethod($method->getName()) !== null) {
                $names[] = $method->getName();
            }
        }

        return $names;
    }

    /**
     * Ask the browser to reload these props once the current call answers (an Inertia
     * partial reload): keys of `getViewData()`, or `page` for the page's own blocks. Without
     * arguments, every prop reloads.
     */
    protected function reloadProps(string ...$props): void
    {
        if ($props === [] || $this->propsToReload === true) {
            $this->propsToReload = true;

            return;
        }

        $this->propsToReload = array_values(array_unique([...(is_array($this->propsToReload) ? $this->propsToReload : []), ...$props]));
    }

    /**
     * @return array<string>|true|null
     */
    public function pullPropsToReload(): array|bool|null
    {
        $props = $this->propsToReload;
        $this->propsToReload = null;

        return $props;
    }
}
