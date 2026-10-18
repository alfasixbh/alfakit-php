<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Migration\Rector;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * `->toolbarActions([CreateAction::make(), BulkActionGroup::make([DeleteBulkAction::make()])])`
 * → `->headerActions([CreateAction::make()])->bulkActions([DeleteBulkAction::make()])`;
 * `BulkActionGroup` wrappers inside `bulkActions()` / `groupedBulkActions()` are flattened.
 */
final class FilamentTableActionsRector extends AbstractRector
{
    use ResolvesAlfakitParent;

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Split toolbarActions() into headerActions() and bulkActions() and unwrap BulkActionGroup', [
            new CodeSample('$table->toolbarActions([CreateAction::make(), BulkActionGroup::make([DeleteBulkAction::make()])]);', '$table->headerActions([CreateAction::make()])->bulkActions([DeleteBulkAction::make()]);'),
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
        if (! $node instanceof MethodCall || ! $node->name instanceof Identifier) {
            return null;
        }

        $method = $node->name->toString();

        if (! in_array($method, ['toolbarActions', 'bulkActions', 'groupedBulkActions'], true)) {
            return null;
        }

        $array = isset($node->args[0]) && $node->args[0] instanceof Arg && $node->args[0]->value instanceof Array_ ? $node->args[0]->value : null;

        if ($array === null) {
            if ($method !== 'bulkActions') {
                $node->name = new Identifier($method === 'toolbarActions' ? 'headerActions' : 'bulkActions');

                return $node;
            }

            return null;
        }

        $header = [];
        $bulk = [];
        $changed = false;

        foreach ($array->items as $item) {
            if ($this->isBulkActionGroup($item->value)) {
                $changed = true;

                foreach ($this->innerItems($item->value) as $inner) {
                    $bulk[] = $inner;
                }

                continue;
            }

            if ($method === 'toolbarActions' && ! str_contains($this->print($item->value), 'Bulk')) {
                $header[] = $item;
            } else {
                $bulk[] = $item;
            }
        }

        if ($method === 'toolbarActions') {
            $result = $node->var;

            if ($header !== []) {
                $result = new MethodCall($result, 'headerActions', [new Arg(new Array_($header))]);
            }

            if ($bulk !== []) {
                $result = new MethodCall($result, 'bulkActions', [new Arg(new Array_($bulk))]);
            }

            return $result === $node->var ? $node->var : $result;
        }

        if (! $changed && $method === 'bulkActions') {
            return null;
        }

        $node->name = new Identifier('bulkActions');
        $array->items = $bulk;

        return $node;
    }

    private function isBulkActionGroup(Node $node): bool
    {
        if (! $node instanceof StaticCall) {
            return false;
        }

        $class = $this->getName($node->class);

        return $class !== null && $this->classBasename($class) === 'BulkActionGroup';
    }

    /**
     * @return array<int, ArrayItem>
     */
    private function innerItems(Node $call): array
    {
        if (! $call instanceof StaticCall || ! isset($call->args[0]) || ! $call->args[0] instanceof Arg || ! $call->args[0]->value instanceof Array_) {
            return [];
        }

        return array_values($call->args[0]->value->items);
    }

    private function print(Node $node): string
    {
        return (new \PhpParser\PrettyPrinter\Standard)->prettyPrint([$node]);
    }
}
