<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ResourceTabs\Pages;

use AlfaSix\Alfakit\Resources\Pages\Enums\ContentTabPosition;
use Closure;

/**
 * Test switches for the combined content tab and per-page relation managers.
 */
trait ConfiguresRelationManagerTabs
{
    public static bool $combined = false;

    public static ?string $contentLabel = null;

    public static ?ContentTabPosition $contentPosition = null;

    /** @var array<class-string>|null */
    public static ?array $relationManagers = null;

    /** `fn (self $page): array` replacing `getCombinedTabComponents()`. */
    public static ?Closure $combinedTabs = null;

    public static function resetTabs(): void
    {
        static::$combined = false;
        static::$contentLabel = null;
        static::$contentPosition = null;
        static::$relationManagers = null;
        static::$combinedTabs = null;
    }

    public function getCombinedTabComponents(): array
    {
        return static::$combinedTabs === null ? parent::getCombinedTabComponents() : (static::$combinedTabs)($this);
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return static::$combined;
    }

    public function getContentTabLabel(): ?string
    {
        return static::$contentLabel;
    }

    public function getContentTabIcon(): ?string
    {
        return static::$combined ? 'file-text' : null;
    }

    public function getContentTabPosition(): ?ContentTabPosition
    {
        return static::$contentPosition;
    }

    public function getAllRelationManagers(): array
    {
        return static::$relationManagers ?? parent::getAllRelationManagers();
    }
}
