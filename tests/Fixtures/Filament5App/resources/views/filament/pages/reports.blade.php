<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Revenue</x-slot>

        <div x-data="{ period: @entangle('period') }">
            <select x-model="period" wire:model.live="period">
                <option value="week">Week</option>
                <option value="month">Month</option>
            </select>
        </div>

        @livewire(\App\Filament\Widgets\OrdersChart::class)
    </x-filament::section>
</x-filament-panels::page>
