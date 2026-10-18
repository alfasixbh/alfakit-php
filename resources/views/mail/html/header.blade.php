@props(['url' => null])
@php
    $brand = \AlfaSix\Alfakit\Mail\MailTheme::brand();
    $url ??= $brand->url;
    $height = $brand->logoHeight;
@endphp
<tr>
<td class="header" align="center">
@if ($url)
<a href="{{ $url }}" style="display: inline-block;" target="_blank" rel="noopener">
@endif
@if ($brand->logo)
<img src="{{ $brand->logo }}" class="logo logo-light" alt="{{ $brand->name }}" height="{{ $height }}" style="border: 0; display: inline-block; height: {{ $height }}px; max-height: {{ $height }}px; width: auto;">
@if ($brand->darkLogo)
<!--[if !mso]><!-->
<img src="{{ $brand->darkLogo }}" class="logo logo-dark" alt="{{ $brand->name }}" height="{{ $height }}" style="border: 0; display: none; height: {{ $height }}px; max-height: 0; overflow: hidden; width: auto;">
<!--<![endif]-->
@endif
@else
<span class="brand-name">{{ trim($slot) !== '' ? trim($slot) : $brand->name }}</span>
@endif
@if ($url)
</a>
@endif
</td>
</tr>
