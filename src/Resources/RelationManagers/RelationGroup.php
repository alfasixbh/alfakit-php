<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Resources\RelationManagers;

use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\Alfakit\Support\Icons;
use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Several relation managers under one tab of an edit/view page:
 * `RelationGroup::make('Contacts', [PhonesRelationManager::class, EmailsRelationManager::class])->icon('users')`.
 */
final class RelationGroup
{
    use EvaluatesClosures;

    private string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $icon = null;

    private string|int|Closure|null $badge = null;

    private string|Closure|null $badgeColor = null;

    private bool|Closure $isBadgeDeferred = false;

    /**
     * @param  array<class-string<RelationManager>|RelationManagerConfiguration>  $managers
     */
    public function __construct(
        private readonly string|Closure $label,
        private readonly array $managers,
    ) {}

    /**
     * @param  array<class-string<RelationManager>|RelationManagerConfiguration>  $managers
     */
    public static function make(string|Closure $label, array $managers): self
    {
        return new self($label, $managers);
    }

    public function icon(string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function badge(string|int|Closure|null $badge): self
    {
        $this->badge = $badge;

        return $this;
    }

    /**
     * Load the badge (pass it as a closure) in a request after the page has rendered, so
     * an expensive count doesn't slow the page down.
     */
    public function deferBadge(bool|Closure $condition = true): self
    {
        $this->isBadgeDeferred = $condition;

        return $this;
    }

    public function isBadgeDeferred(?Model $ownerRecord = null): bool
    {
        return (bool) $this->resolve($this->isBadgeDeferred, $ownerRecord);
    }

    /**
     * `badge` and `badgeColor`, as the deferred-badge request returns them.
     *
     * @return array<string, string|int>
     */
    public function getBadgeSchema(?Model $ownerRecord = null): array
    {
        $badge = $this->getBadge($ownerRecord);

        return array_filter([
            'badge' => $badge,
            'badgeColor' => $this->getBadgeColor($ownerRecord, $badge),
        ], static fn (mixed $value): bool => $value !== null);
    }

    public function badgeColor(string|Closure|null $color): self
    {
        $this->badgeColor = $color;

        return $this;
    }

    public function getLabel(?Model $ownerRecord = null): string
    {
        return (string) $this->resolve($this->label, $ownerRecord);
    }

    public function getIcon(?Model $ownerRecord = null): ?string
    {
        $icon = $this->resolve($this->icon, $ownerRecord);

        return $icon === null ? null : Icons::resolve($icon);
    }

    public function getBadge(?Model $ownerRecord = null): string|int|null
    {
        $badge = $this->resolve($this->badge, $ownerRecord);

        return $badge === null || is_int($badge) ? $badge : (string) $badge;
    }

    /**
     * Colour closures receive the badge value as `$badge`.
     */
    public function getBadgeColor(?Model $ownerRecord = null, string|int|null $badge = null): ?string
    {
        $color = $this->resolve($this->badgeColor, $ownerRecord, ['badge' => $badge ?? $this->getBadge($ownerRecord)]);

        return $color === null ? null : (string) $color;
    }

    /**
     * @return array<class-string<RelationManager>|RelationManagerConfiguration>
     */
    public function getManagers(): array
    {
        return $this->managers;
    }

    /**
     * A stable key for the group's tab.
     */
    public function getKey(): string
    {
        $members = array_map(static fn (string|RelationManagerConfiguration $manager): string => $manager instanceof RelationManagerConfiguration ? $manager->getRelationManager().json_encode($manager->getProperties()) : $manager, $this->managers);

        return 'relationGroup:'.substr(sha1(implode('|', $members)), 0, 12);
    }

    protected function getEvaluationIdentifier(): string
    {
        return 'group';
    }

    /**
     * @param  array<string, mixed>  $injections
     */
    private function resolve(mixed $value, ?Model $ownerRecord, array $injections = []): mixed
    {
        return $this->evaluate($value, [...$injections, 'ownerRecord' => $ownerRecord, 'record' => $ownerRecord], $ownerRecord === null ? [] : [Model::class => $ownerRecord, $ownerRecord::class => $ownerRecord]);
    }
}
