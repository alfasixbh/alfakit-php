<div>
    @if ($subscribed)
        <p>Thanks for subscribing!</p>
    @else
        <form wire:submit="subscribe">
            {{ $this->form }}

            <x-filament::button type="submit">Subscribe</x-filament::button>
        </form>
    @endif

    <x-filament-actions::modals />
</div>
