<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Migration\Rector;

use AlfaSix\Alfakit\Migration\MigrationReport;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Livewire form state on pages and widgets: `$this->form->fill([...])` → `$this->formData = [...]`,
 * other `$this->form->…()` calls go through `$this->getForm()`, and `->statePath('data')` on
 * the page form is dropped (AlfaKit keeps the state in `$formData`).
 */
final class FilamentLivewireFormRector extends AbstractRector
{
    use ReportsToMigrationFile;
    use ResolvesAlfakitParent;

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Replace Livewire form state handling on pages with AlfaKit $formData', [
            new CodeSample("\$this->form->fill(['title' => 'x']);\nreturn \$schema->components([...])->statePath('data');", "\$this->formData = ['title' => 'x'];\nreturn \$schema->components([...]);"),
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
        if (! $node instanceof Class_ || ! $this->parentIs($node, 'Pages\\Page', 'Widgets\\Widget', 'Resources\\RelationManagers\\RelationManager')) {
            return null;
        }

        $changed = false;

        $this->traverseNodesWithCallable($node->stmts, function (Node $call) use (&$changed): ?Node {
            if (! $call instanceof MethodCall || ! $call->name instanceof Identifier) {
                return null;
            }

            $method = $call->name->toString();

            if ($method === 'statePath' && isset($call->args[0]) && $call->args[0] instanceof Arg && $call->args[0]->value instanceof String_ && $call->args[0]->value->value === 'data') {
                $changed = true;

                return $call->var;
            }

            if (! $call->var instanceof PropertyFetch || ! $call->var->var instanceof Variable || $call->var->var->name !== 'this' || ! $this->isName($call->var->name, 'form')) {
                return null;
            }

            $changed = true;

            if ($method === 'fill') {
                $value = isset($call->args[0]) && $call->args[0] instanceof Arg ? $call->args[0]->value : new Array_([]);

                return new Assign(new PropertyFetch(new Variable('this'), 'formData'), $value);
            }

            $this->report(MigrationReport::SECTION_METHODS, $call, "`\$this->form->{$method}()` became `\$this->getForm()->{$method}()`", 'Pages hold the form state in `$this->formData`; actions receive the validated `$data`.');
            $call->var = new MethodCall(new Variable('this'), 'getForm');

            return $call;
        });

        return $changed ? $node : null;
    }
}
