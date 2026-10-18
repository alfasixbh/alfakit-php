<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Parity3;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Pages\Page;

/** A configurable page: registered as `general` and `advanced`. */
class P3SettingsPage extends Page
{
    protected static ?string $configurationClass = P3SettingsConfiguration::class;

    protected static ?string $slug = 'settings';

    protected static ?string $navigationGroup = 'System';

    public ?string $category = null;

    protected ?bool $hasDatabaseTransactions = true;

    public function mount(mixed ...$parameters): void
    {
        $configuration = static::getConfiguration();
        $this->category = $configuration instanceof P3SettingsConfiguration ? $configuration->getCategory() : null;
    }

    public static function getNavigationLabel(): string
    {
        $configuration = static::getConfiguration();

        return $configuration instanceof P3SettingsConfiguration ? 'Settings: '.$configuration->getCategory() : 'Settings';
    }

    public function getTitle(): string
    {
        return 'Settings '.($this->category ?? 'all');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('touch')->action(fn (): null => null),
            Action::make('outside')->databaseTransaction(false)->action(fn (): null => null),
        ];
    }
}
