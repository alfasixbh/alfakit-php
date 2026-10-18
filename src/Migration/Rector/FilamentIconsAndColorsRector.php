<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Migration\Rector;

use AlfaSix\Alfakit\Migration\FilamentClassMap;
use AlfaSix\Alfakit\Migration\MigrationReport;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * `Heroicon::OutlinedUsers` → `'users'` (Lucide) and `Color::Amber` → `'#d97706'`,
 * `Color::hex('#…')` → `'#…'`: AlfaKit icons are Lucide names and colours are CSS values.
 * Render hook constants become `Slot` constants (`PanelsRenderHook::TOPBAR_END` →
 * `Slot::TOPBAR_END`) and `FilamentView::registerRenderHook()` becomes `Slots::register()`.
 */
final class FilamentIconsAndColorsRector extends AbstractRector
{
    use ReportsToMigrationFile;

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Convert Heroicon enum cases to Lucide icon names and Filament Color palettes to CSS colours', [
            new CodeSample("\$icon = Heroicon::OutlinedUsers;\n\$color = Color::Amber;", "\$icon = 'users';\n\$color = '#d97706';"),
        ]);
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [ClassConstFetch::class, StaticCall::class];
    }

    public function refactor(Node $node): ?Node
    {
        if (! $node instanceof ClassConstFetch && ! $node instanceof StaticCall) {
            return null;
        }

        $class = $this->getName($node->class);

        if ($class === null || ! $node->name instanceof Identifier) {
            return null;
        }

        $member = $node->name->toString();

        if ($node instanceof ClassConstFetch && isset(FilamentClassMap::renderHooks()[$class])) {
            $slot = FilamentClassMap::slotFor($class, $member);

            return $slot === null ? null : new ClassConstFetch(new FullyQualified(FilamentClassMap::ALFAKIT.'Theming\\Slot'), $slot);
        }

        if ($node instanceof StaticCall && $class === 'Filament\\Support\\Facades\\FilamentView' && $member === 'registerRenderHook') {
            $this->report(MigrationReport::SECTION_PANEL, $node, '`FilamentView::registerRenderHook()` became `Slots::register()`', 'Slot content is HTML, a Blade view or schema components; `scopes:` takes page, resource, relation manager or widget classes (docs: design/slots).');

            return new StaticCall(new FullyQualified(FilamentClassMap::ALFAKIT.'Theming\\Slots'), 'register', $node->args);
        }

        if ($node instanceof ClassConstFetch && $class === 'Filament\\Support\\Icons\\Heroicon') {
            [$lucide, $known] = FilamentClassMap::icon($member);

            if (! $known) {
                $this->report(MigrationReport::SECTION_ICONS, $node, "Icon `Heroicon::{$member}` became `{$lucide}`", 'Check the name exists at https://lucide.dev/icons and adjust it if needed.');
            }

            return new String_($lucide);
        }

        if ($class !== 'Filament\\Support\\Colors\\Color') {
            return null;
        }

        if ($node instanceof StaticCall) {
            if (in_array($member, ['hex', 'rgb', 'generatePalette', 'generateV3Palette'], true) && isset($node->args[0]) && $node->args[0] instanceof Node\Arg) {
                return $node->args[0]->value;
            }

            return null;
        }

        $colors = FilamentClassMap::colors();

        if (! isset($colors[$member])) {
            $this->report(MigrationReport::SECTION_PANEL, $node, "`Color::{$member}` has no palette", 'Use a CSS colour string, e.g. `\'#2563eb\'`.');

            return null;
        }

        return new String_($colors[$member]);
    }
}
