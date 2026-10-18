<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components\Utilities;

use AlfaSix\Alfakit\Schemas\Components\Component;
use BackedEnum;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Stringable;

/**
 * Reads another field's current state, resolving relative paths like `../name`.
 *
 * Typed accessors (`$get->string('name')`, `integer()`, `boolean()`…) cast the value the
 * way Filament's do; pass `isNullable: true` to get `null` for a blank value instead.
 */
final class Get
{
    public function __construct(
        protected Component $component,
    ) {}

    public function __invoke(string $path = '', bool $isAbsolute = false): mixed
    {
        $statePath = $this->resolvePath($path, $isAbsolute);
        $state = $this->component->getContainer()->getRootSchema()->getRawState();

        return $statePath === '' ? $state : Arr::get($state, $statePath);
    }

    /**
     * @template TNullable of bool
     *
     * @param  TNullable  $isNullable
     * @return (TNullable is true ? ?string : string)
     */
    public function string(string $key, bool $isNullable = false, bool $isAbsolute = false): ?string
    {
        $state = $this($key, $isAbsolute);

        if ($isNullable && blank($state)) {
            return null;
        }

        if ($state instanceof BackedEnum) {
            return (string) $state->value;
        }

        return is_scalar($state) || $state instanceof Stringable ? (string) $state : '';
    }

    /**
     * @template TNullable of bool
     *
     * @param  TNullable  $isNullable
     * @return (TNullable is true ? ?int : int)
     */
    public function integer(string $key, bool $isNullable = false, bool $isAbsolute = false): ?int
    {
        $state = $this($key, $isAbsolute);

        if ($isNullable && blank($state)) {
            return null;
        }

        return is_scalar($state) ? (int) $state : 0;
    }

    /**
     * @template TNullable of bool
     *
     * @param  TNullable  $isNullable
     * @return (TNullable is true ? ?float : float)
     */
    public function float(string $key, bool $isNullable = false, bool $isAbsolute = false): ?float
    {
        $state = $this($key, $isAbsolute);

        if ($isNullable && blank($state)) {
            return null;
        }

        return is_scalar($state) ? (float) $state : 0.0;
    }

    /**
     * @template TNullable of bool
     *
     * @param  TNullable  $isNullable
     * @return (TNullable is true ? ?bool : bool)
     */
    public function boolean(string $key, bool $isNullable = false, bool $isAbsolute = false): ?bool
    {
        $state = $this($key, $isAbsolute);

        if ($isNullable && blank($state)) {
            return null;
        }

        // Form state from the browser may hold "false"/"off" strings; other strings cast as PHP does.
        if (is_string($state)) {
            return filter_var($state, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $state;
        }

        return (bool) $state;
    }

    /**
     * @template TNullable of bool
     *
     * @param  TNullable  $isNullable
     * @return (TNullable is true ? ?array<mixed> : array<mixed>)
     */
    public function array(string $key, bool $isNullable = false, bool $isAbsolute = false): ?array
    {
        $state = $this($key, $isAbsolute);

        if ($isNullable && ! is_array($state)) {
            return null;
        }

        return (array) ($state ?? []);
    }

    /**
     * The value parsed as a date (in the app timezone unless the value carries its own).
     * Without `isNullable`, a blank value parses as "now", like Filament.
     *
     * @template TNullable of bool
     *
     * @param  TNullable  $isNullable
     * @return (TNullable is true ? ?CarbonInterface : CarbonInterface)
     */
    public function date(string $key, bool $isNullable = false, bool $isAbsolute = false): ?CarbonInterface
    {
        $state = $this($key, $isAbsolute);

        if ($isNullable && blank($state)) {
            return null;
        }

        if ($state instanceof DateTimeInterface) {
            return Carbon::instance($state);
        }

        return Carbon::parse(is_scalar($state) ? (string) $state : null);
    }

    /**
     * The value as a backed enum case (`null` when it matches no case).
     *
     * @template TEnum of BackedEnum
     * @template TNullable of bool
     *
     * @param  class-string<TEnum>  $enumClass
     * @param  TNullable  $isNullable
     * @return ?TEnum
     */
    public function enum(string $key, string $enumClass, bool $isNullable = false, bool $isAbsolute = false): ?BackedEnum
    {
        $state = $this($key, $isAbsolute);

        if ($isNullable && blank($state)) {
            return null;
        }

        if ($state instanceof $enumClass) {
            return $state;
        }

        if (! is_int($state) && ! is_string($state)) {
            return null;
        }

        // Numeric strings from the browser still match int-backed enums.
        if (is_string($state) && is_numeric($state) && is_int($enumClass::cases()[0]->value ?? null)) {
            $state = (int) $state;
        }

        return $enumClass::tryFrom($state);
    }

    public function filled(string $key, bool $isAbsolute = false): bool
    {
        return filled($this($key, $isAbsolute));
    }

    public function blank(string $key, bool $isAbsolute = false): bool
    {
        return blank($this($key, $isAbsolute));
    }

    /**
     * The absolute state path `$path` refers to, as in Filament: relative paths start from
     * the component's container (a field's siblings, or a layout component's fellow
     * children, e.g. `items.0` inside a repeater item), each leading `../` goes one level
     * up, and `$isAbsolute` paths are used as given.
     */
    public function resolvePath(string $path, bool $isAbsolute = false): string
    {
        if ($isAbsolute) {
            return $path;
        }

        $containerPath = $this->component->hasContainer() ? $this->component->getContainer()->getStatePath() : null;

        return self::resolveFromContainerPath($containerPath, $path);
    }

    /**
     * Resolve `$path` relative to a container's state path (no segment is popped first).
     */
    public static function resolveFromContainerPath(?string $containerPath, string $path): string
    {
        $containerPath = trim((string) $containerPath, '.');
        $segments = $containerPath === '' ? [] : explode('.', $containerPath);

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '' && $segment !== '.') {
                $segments[] = $segment;
            }
        }

        return implode('.', $segments);
    }

    /**
     * Resolve `$path` relative to a field's own state path: the field's last segment is
     * dropped, so relative references start from its parent.
     */
    public static function resolveRelativePath(string $basePath, string $path): string
    {
        $basePath = trim($basePath, '.');
        $segments = $basePath === '' ? [] : explode('.', $basePath);
        array_pop($segments);

        return self::resolveFromContainerPath(implode('.', $segments), $path);
    }
}
