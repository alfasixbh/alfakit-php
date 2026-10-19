<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Settings\SettingsCluster;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * @property-read Schema $form
 */
class ShippingSettings extends Page
{
    protected static ?string $cluster = SettingsCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $title = 'Shipping';

    protected string $view = 'filament.clusters.settings.pages.shipping-settings';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'free_shipping' => true,
            'free_shipping_threshold' => 50,
            'carrier' => 'ups',
            'zones' => [['name' => 'Domestic', 'rate' => 5]],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Rates')
                    ->schema([
                        Toggle::make('free_shipping')->live(),
                        TextInput::make('free_shipping_threshold')
                            ->numeric()
                            ->prefix('$')
                            ->visible(fn ($get): bool => (bool) $get('free_shipping')),
                        Select::make('carrier')
                            ->options(['ups' => 'UPS', 'dhl' => 'DHL', 'fedex' => 'FedEx'])
                            ->required(),
                        Repeater::make('zones')
                            ->schema([
                                TextInput::make('name')->required(),
                                TextInput::make('rate')->numeric()->required(),
                            ])
                            ->columns(2)
                            ->collapsible(),
                    ]),
            ])
            ->statePath('data');
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Save')
                ->submit('save'),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();

        cache()->forever('shipping-settings', $data);

        Notification::make()
            ->success()
            ->title('Saved')
            ->send();
    }
}
