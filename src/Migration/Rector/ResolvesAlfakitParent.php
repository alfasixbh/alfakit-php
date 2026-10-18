<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Migration\Rector;

use AlfaSix\Alfakit\Migration\FilamentClassMap;
use PhpParser\Node\Stmt\Class_;

/**
 * The AlfaKit class a migrated class extends, whether the code still says `Filament\…`
 * (the rule runs before the rename) or already `AlfaSix\Alfakit\…`.
 */
trait ResolvesAlfakitParent
{
    protected function alfakitParent(Class_ $class): ?string
    {
        if ($class->extends === null) {
            return null;
        }

        $parent = (string) $this->getName($class->extends);

        if ($parent === '') {
            return null;
        }

        if (FilamentClassMap::isFilament($parent)) {
            $parent = FilamentClassMap::target($parent);
        }

        return $parent !== null && str_starts_with($parent, FilamentClassMap::ALFAKIT) ? $parent : null;
    }

    protected function parentIs(Class_ $class, string ...$alfakitClasses): bool
    {
        $parent = $this->alfakitParent($class);

        if ($parent === null) {
            return false;
        }

        foreach ($alfakitClasses as $candidate) {
            $candidate = FilamentClassMap::ALFAKIT.$candidate;

            if ($parent === $candidate || (class_exists($parent) && class_exists($candidate) && is_subclass_of($parent, $candidate))) {
                return true;
            }
        }

        return false;
    }

    protected function classBasename(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
