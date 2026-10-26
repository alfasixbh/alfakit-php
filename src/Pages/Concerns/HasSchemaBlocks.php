<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Pages\Concerns;

use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tables\TableState;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Named schema pieces a custom page component renders where it wants them
 * (`<PageForm name="customer" />`, `<PageTable name="products" />`, `<PageInfolist />`).
 *
 * Any public `{name}Form(Schema $schema): Schema`, `{name}Table(Table $table): Table` or
 * `{name}Infolist(Schema $schema): Schema` method is one: it is serialised as a content block
 * keyed `form:{name}`, `table:{name}` or `infolist:{name}`, and every internal endpoint
 * (live refresh, select options, field and table actions, polling, inline edits) resolves
 * it by that key, so it behaves exactly like the page's own form or table.
 */
trait HasSchemaBlocks
{
    /** @var array<class-string, array<string, array{kind: string, name: string, method: string}>> */
    private static array $schemaBlockMethods = [];

    /** @var array<string, Schema|Table|null> */
    protected array $cachedSchemaBlocks = [];

    /**
     * Initial state of named forms, keyed by form name. Set it in `mount()`:
     * `$this->formsData['customer'] = ['customer_id' => $id]`.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $formsData = [];

    /**
     * Form state sent along with an exposed method call (`forms: ['customer']`), by form name.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $clientFormStates = [];

    /**
     * Methods that look like named pieces but belong to AlfaKit itself.
     *
     * @return array<string>
     */
    protected static function getReservedSchemaBlockMethods(): array
    {
        return ['filtersForm'];
    }

    /**
     * The page's named pieces, keyed by component (`form:customer`).
     *
     * @return array<string, array{kind: string, name: string, method: string}>
     */
    public static function getSchemaBlockMethods(): array
    {
        return self::$schemaBlockMethods[static::class] ??= static::discoverSchemaBlockMethods();
    }

    /**
     * @return array<string, array{kind: string, name: string, method: string}>
     */
    protected static function discoverSchemaBlockMethods(): array
    {
        $found = [];
        $reserved = static::getReservedSchemaBlockMethods();

        foreach ((new ReflectionClass(static::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->getNumberOfParameters() < 1 || $method->getNumberOfRequiredParameters() > 1 || in_array($method->getName(), $reserved, true)) {
                continue;
            }

            if (preg_match('/^([a-z][A-Za-z0-9]*)(Form|Table|Infolist)$/', $method->getName(), $matches) !== 1) {
                continue;
            }

            $expected = $matches[2] === 'Table' ? Table::class : Schema::class;
            $parameter = $method->getParameters()[0]->getType();
            $return = $method->getReturnType();

            if (! $parameter instanceof ReflectionNamedType || $parameter->getName() !== $expected) {
                continue;
            }

            if (! $return instanceof ReflectionNamedType || ! is_a($return->getName(), $expected, true)) {
                continue;
            }

            $kind = strtolower($matches[2]);
            $found["{$kind}:{$matches[1]}"] = ['kind' => $kind, 'name' => $matches[1], 'method' => $method->getName()];
        }

        return $found;
    }

    /**
     * The named piece for a component key (`form:customer`, `table:products`, `infolist:summary`),
     * or null when the page has none (or it has no components).
     */
    public function getSchemaBlock(string $component): Schema|Table|null
    {
        if (array_key_exists($component, $this->cachedSchemaBlocks)) {
            return $this->cachedSchemaBlocks[$component];
        }

        $meta = static::getSchemaBlockMethods()[$component] ?? null;

        if ($meta === null) {
            return null;
        }

        $method = $meta['method'];

        $block = match ($meta['kind']) {
            'table' => $this->makeSchemaBlockTable($method, $meta['name']),
            'infolist' => $this->{$method}($this->makeSchema()->operation('view')),
            default => $this->{$method}($this->makeSchema()),
        };

        if ($block instanceof Schema) {
            if ($block->getComponents(withHidden: true) === []) {
                $block = null;
            } elseif ($meta['kind'] === 'form') {
                // Cached before filling: fields may look the form up while hydrating.
                $this->cachedSchemaBlocks[$component] = $block;
                $block->fill($this->formsData[$meta['name']] ?? null);
            }
        }

        return $this->cachedSchemaBlocks[$component] = $block;
    }

    protected function makeSchemaBlockTable(string $method, string $name): ?Table
    {
        $table = $this->{$method}(Table::make($this));

        if (! $table instanceof Table || ($table->getColumns() === [] && $table->getQuery() === null)) {
            return null;
        }

        // Each named table keeps its own query string (`products_search`, `products_page`).
        if ($table->getQueryStringIdentifier() === null) {
            $table->queryStringIdentifier($name);
        }

        return $table->state(TableState::fromRequest(request(), $table->getQueryStringIdentifier()));
    }

    public function getNamedForm(string $name): ?Schema
    {
        $form = $this->getSchemaBlock("form:{$name}");

        return $form instanceof Schema ? $form : null;
    }

    public function getNamedTable(string $name): ?Table
    {
        $table = $this->getSchemaBlock("table:{$name}");

        return $table instanceof Table ? $table : null;
    }

    public function getNamedInfolist(string $name): ?Schema
    {
        $infolist = $this->getSchemaBlock("infolist:{$name}");

        return $infolist instanceof Schema ? $infolist : null;
    }

    /**
     * Named forms and infolists, for the action and schema endpoints.
     *
     * @return array<Schema>
     */
    protected function getSchemaBlockSchemas(): array
    {
        $schemas = [];

        foreach (array_keys(static::getSchemaBlockMethods()) as $component) {
            $block = $this->getSchemaBlock($component);

            if ($block instanceof Schema) {
                $schemas[] = $block;
            }
        }

        return $schemas;
    }

    /**
     * The serialised named pieces, appended to the page's content blocks.
     *
     * @return array<array<string, mixed>>
     */
    protected function serializeSchemaBlocks(): array
    {
        $blocks = [];

        foreach (static::getSchemaBlockMethods() as $component => $meta) {
            $block = $this->getSchemaBlock($component);

            if ($block instanceof Table) {
                $blocks[] = $this->serializeTable($block, $component);
            } elseif ($block instanceof Schema && $meta['kind'] === 'form') {
                $blocks[] = [
                    'type' => 'Form',
                    'key' => $component,
                    'component' => $component,
                    ...$block->toPayload(),
                    'actions' => [],
                    'operation' => $block->getOperation(),
                ];
            } elseif ($block instanceof Schema) {
                $blocks[] = ['type' => 'Infolist', 'key' => $component, 'components' => $block->toSchema(), 'columns' => $block->getColumns()];
            }
        }

        return $blocks;
    }

    /**
     * Receive the browser state of named forms sent with an exposed method call.
     *
     * @param  array<mixed>  $states
     */
    public function setClientFormStates(array $states): void
    {
        $this->clientFormStates = array_filter($states, static fn (mixed $state, mixed $name): bool => is_string($name) && is_array($state), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Validate a named form against the state the browser sent with this call and return its
     * dehydrated data, exactly like an action's form. Errors are reported under
     * `forms.{name}.{field}`, and the renderer shows them on the form's fields.
     *
     *
     * @throws ValidationException
     *
     * @return array<string, mixed>
     */
    protected function formState(string $name): array
    {
        $form = $this->getNamedForm($name) ?? throw new InvalidArgumentException('The page ['.static::class."] has no form named [{$name}] (define a public {$name}Form(Schema \$schema): Schema method).");

        $form->applyClientState($this->clientFormStates[$name] ?? []);

        try {
            return $form->validate();
        } catch (ValidationException $exception) {
            $errors = Arr::mapWithKeys($exception->errors(), static fn (array $messages, string $key): array => ["forms.{$name}.{$key}" => $messages]);

            throw ValidationException::withMessages($errors);
        }
    }
}
