<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Migration\Rector;

use AlfaSix\Alfakit\Migration\FilamentClassMap;
use AlfaSix\Alfakit\Migration\MigrationReport;
use AlfaSix\Alfakit\Migration\TokenRewriter;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * `App\Filament` → `App\Alfakit` and `App\Providers\Filament` → `App\Providers\Alfakit` in
 * namespaces, imports and inline names, plus the same in strings together with
 * `app_path('Filament/...')`, Filament class names in strings and `heroicon-*` icon names.
 */
final class FilamentNamespaceRector extends AbstractRector
{
    use ReportsToMigrationFile;

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Rename the App\Filament namespace to App\Alfakit (names and strings) and Heroicon names to Lucide names', [
            new CodeSample("namespace App\\Filament\\Resources;\n\$icon = 'heroicon-o-users';", "namespace App\\Alfakit\\Resources;\n\$icon = 'users';"),
        ]);
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [Name::class, String_::class];
    }

    public function refactor(Node $node): ?Node
    {
        if ($node instanceof Name) {
            $written = $node->toString();
            $renamed = TokenRewriter::renameAppNamespace($written);

            if ($renamed === $written) {
                return null;
            }

            return $node instanceof FullyQualified ? new FullyQualified($renamed) : new Name($renamed);
        }

        if (! $node instanceof String_) {
            return null;
        }

        $value = $node->value;
        $new = TokenRewriter::renameAppNamespace($value);

        if ($new === $value && FilamentClassMap::isFilament($value)) {
            $new = FilamentClassMap::target($value) ?? $value;
        }

        if (str_starts_with($new, 'Filament/')) {
            $new = 'Alfakit/'.substr($new, 9);
        } elseif (str_starts_with($new, 'app/Filament/')) {
            $new = 'app/Alfakit/'.substr($new, 13);
        }

        if (preg_match('/^heroicon-[osmc]-([a-z0-9-]+)$/', $new, $matches) === 1) {
            [$lucide, $known] = FilamentClassMap::icon($matches[1]);
            $new = $lucide;

            if (! $known) {
                $this->report(MigrationReport::SECTION_ICONS, $node, "Icon `{$value}` became `{$lucide}`", 'Check the name exists at https://lucide.dev/icons and adjust it if needed.');
            }
        }

        if ($new === $value) {
            return null;
        }

        $node->value = $new;

        return $node;
    }
}
