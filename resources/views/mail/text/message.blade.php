@php($brand = \AlfaSix\Alfakit\Mail\MailTheme::brand())
<x-mail::layout>
    {{-- Header --}}
    <x-slot:header>
        <x-mail::header :url="$brand->url ?? config('app.url')">
            {{ $brand->name }}
        </x-mail::header>
    </x-slot:header>

    {{-- Body --}}
    {{ $slot }}

    {{-- Subcopy --}}
    @isset($subcopy)
        <x-slot:subcopy>
            <x-mail::subcopy>
                {{ $subcopy }}
            </x-mail::subcopy>
        </x-slot:subcopy>
    @endisset

    {{-- Footer --}}
    <x-slot:footer>
        <x-mail::footer>
            © {{ date('Y') }} {{ $brand->name }}. {{ __('alfakit::mail.footer.rights') }}
        </x-mail::footer>
    </x-slot:footer>
</x-mail::layout>
