<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Migration\Rector;

use AlfaSix\Alfakit\Migration\FilamentClassMap;
use AlfaSix\Alfakit\Migration\MigrationReport;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Fluent methods that AlfaKit names differently (`lazy()` → `live(onBlur: true)`,
 * `groupedBulkActions()` → `bulkActions()`) or does not have (`extraAlpineAttributes()`,
 * `livewire()`, `js()`, …): the latter are removed from the chain so the code
 * keeps running, and every change is reported. `->view('blade.view')` on a component is
 * reported (Blade is not rendered).
 */
final class FilamentMethodsRector extends AbstractRector
{
    use ReportsToMigrationFile;

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Rename or remove Filament fluent methods that AlfaKit does not have', [
            new CodeSample("TextInput::make('x')->lazy()->extraAlpineAttributes(['x-on:click' => 'a()']);", "TextInput::make('x')->live(onBlur: true);"),
        ]);
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [MethodCall::class];
    }

    public function refactor(Node $node): ?Node
    {
        if (! $node instanceof MethodCall || ! $node->name instanceof Identifier || ! ($node->var instanceof MethodCall || $node->var instanceof StaticCall)) {
            return null;
        }

        $method = $node->name->toString();
        $renamed = FilamentClassMap::renamedMethods();
        $removed = FilamentClassMap::removedMethods();

        if ($method === 'view' && isset($node->args[0]) && $node->args[0] instanceof Arg && $node->args[0]->value instanceof String_) {
            $root = $this->chainRootClass($node);

            if (! in_array($root, ['View', 'ViewColumn', 'ViewEntry', 'ViewField'], true)) {
                $this->report(MigrationReport::SECTION_VIEWS, $node, "Blade view `{$node->args[0]->value->value}` on a component", 'Blade is not rendered: register a frontend component in `createAlfakit({ components })` and use `View::make(\'name\')`, `ViewColumn::make()->view(\'name\')` or a custom component (`make:alfakit-field|column|entry`).');
            }

            return null;
        }

        if (isset($renamed[$method])) {
            [$newName, $arguments] = $renamed[$method];
            $node->name = new Identifier($newName);

            if ($arguments !== null) {
                [$argumentName, $argumentValue] = array_map(trim(...), explode(':', $arguments, 2));
                $node->args = [new Arg(new ConstFetch(new Name($argumentValue)), false, false, [], new Identifier($argumentName))];
            }

            $this->report(MigrationReport::SECTION_METHODS, $node, "`->{$method}()` became `->{$newName}(".($arguments ?? '').')`');

            return $node;
        }

        if (isset($removed[$method])) {
            $this->report(MigrationReport::SECTION_METHODS, $node, "`->{$method}()`", ucfirst($removed[$method]).'.');

            return $node->var;
        }

        return null;
    }

    private function chainRootClass(MethodCall $call): ?string
    {
        $node = $call->var;

        while ($node instanceof MethodCall) {
            $node = $node->var;
        }

        if (! $node instanceof StaticCall) {
            return null;
        }

        $class = $this->getName($node->class);

        if ($class === null) {
            return null;
        }

        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
