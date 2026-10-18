<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Commands\Concerns\DetectsFrontendAdapter;
use AlfaSix\Alfakit\Commands\Support\PhpDocTypeScript;
use AlfaSix\Alfakit\Forms\Components\Field;
use AlfaSix\Alfakit\Infolists\Components\Entry;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Tables\Columns\Column;
use AlfaSix\Alfakit\Widgets\Widget;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Finder\SplFileInfo;
use Throwable;

/**
 * Writes TypeScript props types for the app's custom fields, entries, layout components,
 * columns and widgets, from the PHPDoc array shapes of the methods that feed their schema:
 *
 * ```php
 *
 * /** @return array{swatches: list<string>, size?: int} *\/
 * protected function getSchemaProps(): array
 * ```
 *
 * becomes `export type ColorWheelProps = FieldProps<{ swatches: string[]; size?: number }>`.
 */
#[AsCommand(name: 'alfakit:types', description: 'Generate TypeScript props types for your custom Alfakit components')]
class TypesCommand extends Command
{
    use DetectsFrontendAdapter;

    /**
     * The base classes, most specific first, with the props helper type and the methods whose
     * return shapes fill its type arguments.
     *
     * @var array<class-string, array{helper: string, methods: list<string>, prefix: string}>
     */
    protected const KINDS = [
        Field::class => ['helper' => 'FieldProps', 'methods' => ['getSchemaProps'], 'prefix' => ''],
        Entry::class => ['helper' => 'EntryProps', 'methods' => ['getSchemaProps'], 'prefix' => ''],
        Component::class => ['helper' => 'SchemaComponentProps', 'methods' => ['getSchemaProps'], 'prefix' => ''],
        Column::class => ['helper' => 'ColumnProps', 'methods' => ['getSchemaProps', 'getCellProps'], 'prefix' => 'column:'],
        Widget::class => ['helper' => 'WidgetProps', 'methods' => ['getViewData', 'getSchemaProps'], 'prefix' => ''],
    ];

    protected $signature = 'alfakit:types
        {--path= : Where to look for component classes (default: app/)}
        {--output= : The declaration file (default: resources/js/alfakit/types.d.ts)}
        {--adapter= : react, vue or svelte (detected from package.json)}';

    /** The real path of the scanned folder. */
    protected string $scanPath = '';

    public function handle(Filesystem $files): int
    {
        $adapter = $this->resolveAdapter();

        if ($adapter === null) {
            return self::FAILURE;
        }

        $path = (string) ($this->option('path') ?? app_path());
        $this->scanPath = rtrim((string) realpath($path), DIRECTORY_SEPARATOR);
        $output = (string) ($this->option('output') ?? resource_path('js/alfakit/types.d.ts'));
        $components = [];

        foreach ($this->classes($files, $path) as $class) {
            $component = $this->describe($class);

            if ($component === null) {
                continue;
            }

            if (isset($components[$component['name']])) {
                $this->components->warn("Skipped {$class}: ".$components[$component['name']]['class']." already defines {$component['name']}Props.");

                continue;
            }

            $components[$component['name']] = $component;
        }

        ksort($components);

        $files->ensureDirectoryExists(dirname($output));
        $files->put($output, $this->render($adapter, $components));

        $this->components->info(sprintf('Wrote %d component type%s to %s', count($components), count($components) === 1 ? '' : 's', Str::after($output, base_path().'/')));

        return self::SUCCESS;
    }

    /**
     * Concrete classes declared in PHP files under the path.
     *
     * @return list<class-string>
     */
    protected function classes(Filesystem $files, string $path): array
    {
        if (! is_dir($path)) {
            return [];
        }

        $classes = [];

        /** @var SplFileInfo $file */
        foreach ($files->allFiles($path) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = $file->getContents();

            // Only files that can be Alfakit components (and only those get loaded).
            if (! str_contains($contents, 'Alfakit') || preg_match('/^\s*(?:final\s+|readonly\s+)*class\s+(\w+)/m', $contents, $class) !== 1) {
                continue;
            }

            $namespace = preg_match('/^namespace\s+([^;]+);/m', $contents, $match) === 1 ? trim($match[1]).'\\' : '';
            $name = $namespace.$class[1];

            if (! class_exists($name)) {
                try {
                    require_once $file->getPathname();
                } catch (Throwable) {
                    continue;
                }
            }

            if (class_exists($name, false)) {
                $classes[] = $name;
            }
        }

        return $classes;
    }

    /**
     * @param  class-string  $class
     * @return array{name: string, class: string, key: string, helper: string, arguments: list<string>}|null
     */
    protected function describe(string $class): ?array
    {
        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || str_starts_with($class, 'AlfaSix\\Alfakit\\')) {
            return null;
        }

        foreach (self::KINDS as $base => $kind) {
            if (! $reflection->isSubclassOf($base)) {
                continue;
            }

            $key = $this->registryKey($reflection, $base);

            if ($key === null) {
                return null;
            }

            $arguments = array_map(fn (string $method): string => $this->shape($reflection, $method), $kind['methods']);

            // A subclass of a plugin or package component that adds no props of its own
            // (`EventsCalendar extends CalendarWidget`) has nothing to type.
            if ($key !== $reflection->getShortName() && array_unique($arguments) === ['Record<never, never>']) {
                return null;
            }

            return [
                'name' => $reflection->getShortName(),
                'class' => $class,
                'key' => $kind['prefix'].$key,
                'helper' => $kind['helper'],
                'arguments' => $arguments,
            ];
        }

        return null;
    }

    /**
     * The key its frontend component registers under; null for subclasses that keep a built-in
     * renderer (`StatusColumn extends IconColumn`).
     *
     * @param  ReflectionClass<Component|Column|Widget>  $reflection
     */
    protected function registryKey(ReflectionClass $reflection, string $base): ?string
    {
        if ($base === Widget::class) {
            $component = $reflection->getStaticPropertyValue('component', null);

            if (is_string($component) && $component !== '') {
                return $component;
            }
        }

        try {
            $instance = $reflection->newInstanceWithoutConstructor();
            $type = method_exists($instance, 'getType') ? (string) $instance->getType() : $reflection->getShortName();
        } catch (Throwable) {
            $type = $reflection->getShortName();
        }

        for ($parent = $reflection->getParentClass(); $parent !== false; $parent = $parent->getParentClass()) {
            if (! $this->isAppClass($parent) && $parent->getShortName() === $type) {
                return null;
            }
        }

        return $type;
    }

    /**
     * Whether a class is declared under the scanned path (not Alfakit, a plugin or vendor).
     *
     * @param  ReflectionClass<object>  $reflection
     */
    protected function isAppClass(ReflectionClass $reflection): bool
    {
        $file = $reflection->getFileName();

        return $file !== false && str_starts_with((string) realpath($file), $this->scanPath.DIRECTORY_SEPARATOR);
    }

    /**
     * The TypeScript object type of a method's return, from its PHPDoc array shape.
     *
     * @param  ReflectionClass<Component|Column|Widget>  $reflection
     */
    protected function shape(ReflectionClass $reflection, string $method): string
    {
        if (! $reflection->hasMethod($method)) {
            return 'Record<never, never>';
        }

        $declaration = $reflection->getMethod($method);

        // Inherited from Alfakit or a plugin: the class adds nothing.
        if (! $this->isAppClass($declaration->getDeclaringClass())) {
            return 'Record<never, never>';
        }

        $type = PhpDocTypeScript::returnType($declaration->getDocComment());
        $converted = $type === null ? 'unknown' : PhpDocTypeScript::convert($type);

        // `array{}` and unshaped arrays give no keys to type.
        return str_starts_with($converted, '{') ? $converted : 'Record<string, unknown>';
    }

    /**
     * @param  array<string, array{name: string, class: string, key: string, helper: string, arguments: list<string>}>  $components
     */
    protected function render(string $adapter, array $components): string
    {
        $helpers = array_values(array_unique(array_column($components, 'helper')));
        sort($helpers);

        $lines = [
            '// Generated by `php artisan alfakit:types` from the PHPDoc array shapes of your',
            '// components\' getSchemaProps() / getCellProps() / getViewData(). Do not edit: run it again.',
        ];

        if ($helpers !== []) {
            $lines[] = 'import type { '.implode(', ', $helpers)." } from '@alfakit/{$adapter}'";
        }

        $map = [];

        foreach ($components as $component) {
            $name = $component['name'];
            $arguments = $component['arguments'];

            // Drop trailing empty arguments: `WidgetProps<{ … }>`, `ColumnProps<{ … }>`.
            while (count($arguments) > 1 && end($arguments) === 'Record<never, never>') {
                array_pop($arguments);
            }

            $lines[] = '';
            $lines[] = "/** {$component['class']} (registry key `{$component['key']}`) */";
            $lines[] = "export type {$name}Props = {$component['helper']}<".implode(', ', $arguments).'>';

            $schema = match ($component['helper']) {
                'ColumnProps' => "{$name}Props['column']",
                'WidgetProps' => "{$name}Props['widget']",
                default => "{$name}Props['component']",
            };
            $lines[] = "export type {$name}Schema = {$schema}";
            $map[] = '  '.PhpDocTypeScript::key($component['key']).": {$name}Props";
        }

        $lines[] = '';
        $lines[] = '/** Props of every custom component, by registry key. */';
        $lines[] = $map === [] ? 'export type AlfakitComponents = Record<never, never>' : "export interface AlfakitComponents {\n".implode("\n", $map)."\n}";

        return implode("\n", $lines)."\n";
    }
}
