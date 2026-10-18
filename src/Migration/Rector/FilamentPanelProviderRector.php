<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Migration\Rector;

use AlfaSix\Alfakit\Migration\FilamentClassMap;
use AlfaSix\Alfakit\Migration\MigrationReport;
use AlfaSix\Alfakit\Migration\TokenRewriter;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\PrettyPrinter\Standard;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Panel providers: Filament's explicit middleware stack becomes `['web']`, `authMiddleware`
 * loses Filament's `Authenticate` (AlfaKit adds its own), `AccountWidget` /
 * `FilamentInfoWidget` leave the widgets list, plugins and the panel methods without an
 * equivalent (`renderHook`, `viteTheme`, `assets`, `routes`, …) are removed and reported.
 */
final class FilamentPanelProviderRector extends AbstractRector
{
    use ReportsToMigrationFile;
    use ResolvesAlfakitParent;

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Convert Filament panel provider configuration to AlfaKit', [
            new CodeSample('$panel->middleware([EncryptCookies::class, StartSession::class])->authMiddleware([Authenticate::class])->plugins([Shield::make()]);', "\$panel->middleware(['web']);"),
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
        if (! $node instanceof Class_ || ! $this->parentIs($node, 'PanelProvider')) {
            return null;
        }

        $changed = false;
        $removedPanel = FilamentClassMap::removedPanelMethods();
        $unsupported = FilamentClassMap::unsupported();

        $this->traverseNodesWithCallable($node->stmts, function (Node $call) use (&$changed, $removedPanel, $unsupported): ?Node {
            if (! $call instanceof MethodCall || ! $call->name instanceof Identifier) {
                return null;
            }

            $method = $call->name->toString();

            if (in_array($method, ['plugins', 'plugin'], true)) {
                $this->reportPlugins($call);
                $changed = true;

                return $call->var;
            }

            // ->renderHook(PanelsRenderHook::X, …) → ->slot(Slot::Y, …) when the hook has a slot.
            if ($method === 'renderHook' && ($slot = $this->slotForHookArgument($call)) !== null) {
                $call->name = new Identifier('slot');
                $call->args[0] = new Arg(new ClassConstFetch(new FullyQualified(FilamentClassMap::ALFAKIT.'Theming\\Slot'), $slot));
                $this->report(MigrationReport::SECTION_PANEL, $call, '`->renderHook()` became `->slot()`', 'Slot content is HTML, a Blade view or schema components; check the slot renders where you expect (docs: design/slots).');
                $changed = true;

                return $call;
            }

            if (isset($removedPanel[$method])) {
                $argument = isset($call->args[0]) && $call->args[0] instanceof Arg ? $this->print($call->args[0]->value) : '';
                $this->report(MigrationReport::SECTION_PANEL, $call, "`->{$method}(".mb_strimwidth($argument, 0, 60, '…').')`', $removedPanel[$method]);
                $changed = true;

                return $call->var;
            }

            $array = isset($call->args[0]) && $call->args[0] instanceof Arg && $call->args[0]->value instanceof Array_ ? $call->args[0]->value : null;

            if ($array === null) {
                return null;
            }

            if ($method === 'middleware') {
                $standard = FilamentClassMap::standardWebMiddleware();
                $classes = array_map($this->itemClass(...), $array->items);

                if ($classes !== [] && ! in_array(null, $classes, true) && array_diff($classes, $standard) === []) {
                    $call->args[0]->value = new Array_([new ArrayItem(new String_('web'))]);
                    $this->report(MigrationReport::SECTION_PANEL, $call, 'Panel middleware stack', "Replaced Filament's explicit session/cookie/CSRF middleware with the `web` group; AlfaKit adds `SetUpPanel`, locale and Inertia middleware itself.");
                    $changed = true;

                    return $call;
                }

                return $this->filterItems($call, $array, static fn (string $class): bool => isset($unsupported[$class]), $changed) ? $call : null;
            }

            if ($method === 'authMiddleware') {
                $filtered = $this->filterItems($call, $array, static fn (string $class): bool => in_array($class, ['Filament\\Http\\Middleware\\Authenticate', FilamentClassMap::ALFAKIT.'Http\\Middleware\\Authenticate'], true), $changed);

                if ($array->items === []) {
                    $changed = true;

                    return $call->var;
                }

                return $filtered ? $call : null;
            }

            if (in_array($method, ['widgets', 'pages', 'resources'], true)) {
                return $this->filterItems($call, $array, function (string $class) use ($unsupported, $call): bool {
                    if (! isset($unsupported[$class])) {
                        return false;
                    }

                    $this->report(MigrationReport::SECTION_PANEL, $call, "`{$class}` removed from the panel", $unsupported[$class]);

                    return true;
                }, $changed) ? $call : null;
            }

            return null;
        });

        return $changed ? $node : null;
    }

    /**
     * Remove `X::class` items matching the callback; returns whether anything was removed.
     *
     * @param  callable(string): bool  $matches
     */
    private function filterItems(MethodCall $call, Array_ $array, callable $matches, bool &$changed): bool
    {
        $kept = [];
        $removed = false;

        foreach ($array->items as $item) {
            $class = $this->itemClass($item);

            if ($class !== null && $matches($class)) {
                $removed = true;

                continue;
            }

            $kept[] = $item;
        }

        if ($removed) {
            $array->items = $kept;
            $changed = true;
        }

        return $removed;
    }

    private function itemClass(?ArrayItem $item): ?string
    {
        if (! $item instanceof ArrayItem || ! $item->value instanceof ClassConstFetch || ! $item->value->name instanceof Identifier || $item->value->name->toString() !== 'class') {
            return null;
        }

        return $this->getName($item->value->class);
    }

    private function reportPlugins(MethodCall $call): void
    {
        foreach ($call->args as $arg) {
            if (! $arg instanceof Arg) {
                continue;
            }

            $values = $arg->value instanceof Array_ ? array_map(static fn (?ArrayItem $item): ?Node => $item?->value, $arg->value->items) : [$arg->value];

            foreach ($values as $value) {
                if ($value === null) {
                    continue;
                }

                $class = $value instanceof StaticCall ? $this->getName($value->class) : null;
                $class ??= $this->print($value);
                $this->report(MigrationReport::SECTION_PLUGINS, $value, "Plugin `{$class}` removed from the panel", TokenRewriter::pluginSuggestion($class));
            }
        }
    }

    private function slotForHookArgument(MethodCall $call): ?string
    {
        $argument = isset($call->args[0]) && $call->args[0] instanceof Arg ? $call->args[0]->value : null;

        if (! $argument instanceof ClassConstFetch || ! $argument->name instanceof Identifier) {
            return null;
        }

        $class = $this->getName($argument->class);

        // Already converted by FilamentIconsAndColorsRector (and maybe imported as `Slot`).
        if (in_array($class, [FilamentClassMap::ALFAKIT.'Theming\\Slot', 'Slot'], true) || ($class !== null && str_ends_with($class, '\\Theming\\Slot'))) {
            return defined(FilamentClassMap::ALFAKIT.'Theming\\Slot::'.$argument->name->toString()) ? $argument->name->toString() : null;
        }

        return $class === null ? null : FilamentClassMap::slotFor($class, $argument->name->toString());
    }

    private function print(Node $node): string
    {
        return trim((string) preg_replace('/\s+/', ' ', (new Standard)->prettyPrint([$node])));
    }
}
