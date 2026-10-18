<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Migration\Rector;

use AlfaSix\Alfakit\Migration\MigrationReport;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\UnionType;
use PhpParser\PrettyPrinter\Standard;
use Rector\Rector\AbstractRector;
use ReflectionClass;
use ReflectionNamedType;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Make class members compatible with the AlfaKit parent, read through reflection: property
 * types and `static` (`string|BackedEnum|null $navigationIcon` → `?string`, ChartWidget's
 * `$heading` is not static), method visibility and scalar return types, ChartWidget's `getType()` renamed
 * to `getChartType()`,
 * `mount()` taking `mixed ...$parameters` on pages. Removes the Livewire `$view` (reported:
 * a frontend component replaces the Blade view) and `public ?array $data` on pages.
 */
final class FilamentClassMembersRector extends AbstractRector
{
    use ReportsToMigrationFile;
    use ResolvesAlfakitParent;

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Align property and method declarations with the AlfaKit parent class', [
            new CodeSample('protected static string|BackedEnum|null $navigationIcon = Heroicon::Users;', "protected static ?string \$navigationIcon = 'users';"),
        ]);
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [Class_::class];
    }

    public function refactor(Node $node): ?Node
    {
        if (! $node instanceof Class_) {
            return null;
        }

        $parent = $this->alfakitParent($node);
        $reflection = $parent !== null && class_exists($parent) ? new ReflectionClass($parent) : null;
        $isPage = $this->parentIs($node, 'Pages\\Page', 'Widgets\\Widget', 'Resources\\RelationManagers\\RelationManager');
        $changed = false;
        $statements = [];

        foreach ($node->stmts as $statement) {
            if ($statement instanceof Property) {
                $name = $this->getName($statement->props[0]->name);

                if ($name === 'view' && ($isPage || $reflection === null)) {
                    $default = $statement->props[0]->default;
                    $view = $default instanceof String_ ? "`{$default->value}` " : '';
                    $this->report(MigrationReport::SECTION_VIEWS, $statement, "Custom Blade view {$view}(`\$view`)", 'Blade is not rendered. Set `protected static ?string $component = \'my-component\';` and register a frontend component for your renderer in `createAlfakit({ components })`, or compose the page from schema components (`form()`, `infolist()`, widgets).');
                    $changed = true;

                    continue;
                }

                if ($name === 'data' && $isPage && $statement->isPublic()) {
                    $changed = true;

                    continue;
                }

                if ($reflection !== null && $name !== null && $this->alignProperty($statement, $name, $reflection)) {
                    $changed = true;
                }
            }

            // Filament's `ChartWidget::getType()` returns the chart type ('line'); AlfaKit's
            // `getType()` is the schema type, so the override becomes `getChartType()`.
            if ($statement instanceof ClassMethod && $this->getName($statement) === 'getType' && $this->parentIs($node, 'Widgets\\ChartWidget')) {
                $statement->name = new Identifier('getChartType');
                $statements[] = $statement;
                $changed = true;

                continue;
            }

            if ($statement instanceof ClassMethod && $reflection !== null && $this->alignMethod($statement, $reflection, $this->parentIs($node, 'Pages\\Page'))) {
                $changed = true;
            }

            $statements[] = $statement;
        }

        if (! $changed) {
            return null;
        }

        $node->stmts = $statements;

        return $node;
    }

    /**
     * @param  ReflectionClass<object>  $parent
     */
    private function alignProperty(Property $property, string $name, ReflectionClass $parent): bool
    {
        if (! $parent->hasProperty($name)) {
            return false;
        }

        $parentProperty = $parent->getProperty($name);
        $changed = false;

        if ($parentProperty->isStatic() !== $property->isStatic()) {
            $property->flags = $parentProperty->isStatic() ? $property->flags | Modifiers::STATIC : $property->flags & ~Modifiers::STATIC;
            $changed = true;
        }

        $type = $parentProperty->getType();

        if ($type instanceof ReflectionNamedType && $type->isBuiltin() && ! in_array($type->getName(), ['mixed', 'null'], true)) {
            $expected = $type->allowsNull() ? new NullableType(new Identifier($type->getName())) : new Identifier($type->getName());

            if ($property->type === null || $this->printType($property->type) !== $this->printType($expected)) {
                $property->type = $expected;
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * @param  ReflectionClass<object>  $parent
     */
    private function alignMethod(ClassMethod $method, ReflectionClass $parent, bool $isPlainPage): bool
    {
        $name = $method->name->toString();
        $changed = false;

        if ($isPlainPage && $name === 'mount') {
            if ($method->params === []) {
                $method->params = [new Param(new Variable('parameters'), null, new Identifier('mixed'), false, true)];
                $changed = true;
            } else {
                $this->report(MigrationReport::SECTION_METHODS, $method, '`mount()` with parameters', 'AlfaKit pages receive route parameters as `mount(mixed ...$parameters)`; read them with `request()->route()` or from `$parameters`.');
            }
        }

        if (! $parent->hasMethod($name)) {
            return $changed;
        }

        $parentMethod = $parent->getMethod($name);

        if ($parentMethod->isPublic() && ! $method->isPublic()) {
            $method->flags = ($method->flags & ~(Modifiers::PROTECTED | Modifiers::PRIVATE)) | Modifiers::PUBLIC;
            $changed = true;
        }

        $returnType = $parentMethod->getReturnType();

        if ($returnType instanceof ReflectionNamedType && $returnType->isBuiltin() && ! in_array($returnType->getName(), ['mixed', 'void', 'null', 'static', 'self'], true) && $method->returnType !== null) {
            $expected = $returnType->allowsNull() ? new NullableType(new Identifier($returnType->getName())) : new Identifier($returnType->getName());
            $actual = $this->printType($method->returnType);

            if ($actual !== $this->printType($expected)) {
                $method->returnType = $expected;
                $changed = true;
                $this->report(MigrationReport::SECTION_METHODS, $method, "`{$name}()` return type `{$actual}` became `".$this->printType($expected).'`', 'Return a plain value here; AlfaKit sends it to the renderer as JSON (no `Htmlable`).');
            }
        }

        return $changed;
    }

    private function printType(Node $type): string
    {
        $printed = (new Standard)->prettyPrint([$type]);

        if ($type instanceof UnionType || $type instanceof NullableType || $type instanceof Identifier || $type instanceof Name) {
            $parts = explode('|', str_replace('?', '', $printed));

            if ($type instanceof NullableType) {
                $parts[] = 'null';
            }

            $parts = array_map(fn (string $part): string => $this->shortName(trim($part, '\\')), $parts);
            sort($parts);

            return implode('|', $parts);
        }

        return $printed;
    }

    private function shortName(string $name): string
    {
        $position = strrpos($name, '\\');

        return strtolower($position === false ? $name : substr($name, $position + 1));
    }
}
