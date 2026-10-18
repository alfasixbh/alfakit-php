<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Resources\RelationManagers;

/**
 * A relation manager registered with properties: `CommentsRelationManager::make(['status'
 * => 'approved'])` in `getRelations()`. The properties fill the manager's public
 * properties when the page creates it.
 */
final class RelationManagerConfiguration
{
    /**
     * @param  class-string<RelationManager>  $relationManager
     * @param  array<string, mixed>  $properties
     */
    public function __construct(
        public readonly string $relationManager,
        public readonly array $properties = [],
    ) {}

    /**
     * @return class-string<RelationManager>
     */
    public function getRelationManager(): string
    {
        return $this->relationManager;
    }

    /**
     * @return array<string, mixed>
     */
    public function getProperties(): array
    {
        return $this->properties;
    }
}
