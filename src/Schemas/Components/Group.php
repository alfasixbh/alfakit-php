<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components;

use AlfaSix\Alfakit\Schemas\Components\Concerns\EntanglesStateWithSingularRelationship;
use Closure;

/**
 * An invisible wrapper. Useful to apply a shared `statePath`, `hidden` condition or
 * column span to several components. With `relationship()`, the children edit a related
 * record (HasOne, MorphOne, BelongsTo); Fieldset and Section inherit both.
 */
class Group extends Component
{
    use Concerns\CanDeferLoading;
    use EntanglesStateWithSingularRelationship;

    protected ?string $relativeStatePath = null;

    /**
     * @param  array<Component>|Closure  $schema
     */
    public function __construct(array|Closure $schema = [])
    {
        $this->schema($schema);
    }

    public static function make(mixed ...$arguments): static
    {
        return parent::make(...$arguments);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->hiddenLabel();
    }

    /**
     * Nest the children's state under a sub-key (e.g. `address.street`).
     */
    public function statePath(?string $path): static
    {
        $this->relativeStatePath = $path;

        return $this;
    }

    public function getStatePath(bool $isAbsolute = true): ?string
    {
        $containerPath = $this->getContainer()->getStatePath();
        // A relationship nests its children's state under the relationship name.
        $relativePath = $this->relativeStatePath ?? ($this->hasRelationship() ? $this->getRelationshipName() : null);

        if ($relativePath === null) {
            return $containerPath;
        }

        return filled($containerPath) ? "{$containerPath}.{$relativePath}" : $relativePath;
    }
}
