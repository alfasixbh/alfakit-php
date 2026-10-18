<x-filament-widgets::widget>
    <x-filament::section heading="Customers by country">
        <ul>
            @foreach ($this->getCountries() as $country => $total)
                <li>{{ $country }}: {{ $total }}</li>
            @endforeach
        </ul>
    </x-filament::section>
</x-filament-widgets::widget>
