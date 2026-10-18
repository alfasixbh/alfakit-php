@php
    $brand = \AlfaSix\Alfakit\Mail\MailTheme::brand();
    $direction = \AlfaSix\Alfakit\Mail\MailTheme::direction();
    $dir = $direction->value;
    $lang = \AlfaSix\Alfakit\Mail\MailTheme::lang();
    $forceDark = \AlfaSix\Alfakit\Mail\MailTheme::isDarkForced();
    $linkOnDark = $brand->primaryOnDark();
    $dark = <<<CSS
body, .wrapper, .body { background-color: #0b0f19 !important; color: #d1d5db !important; }
.inner-body { background-color: #111827 !important; border-color: #1f2937 !important; border-top-color: {$brand->primary} !important; }
.content-cell, p, ul, ol, td, th, .panel-content, .panel-content p, .table td { color: #d1d5db !important; }
h1, h2, h3, .header a, .brand-name, .table th { color: #f9fafb !important; }
a { color: {$linkOnDark} !important; }
a.button, .button { color: {$brand->primaryForeground()} !important; }
a.button-success, a.button-error, .button-success, .button-error { color: #ffffff !important; }
.panel-content { background-color: #1f2937 !important; }
blockquote, .subcopy, .table th, .table td { border-color: #374151 !important; }
code { background-color: #1f2937 !important; }
.subcopy p, .footer p, .footer a { color: #9ca3af !important; }
.logo-light { display: none !important; }
.logo-dark { display: inline-block !important; max-height: none !important; overflow: visible !important; }
CSS;
@endphp
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ $lang }}" dir="{{ $dir }}">
<head>
<title>{{ $brand->name }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="{{ $forceDark ? 'dark' : 'light dark' }}">
<meta name="supported-color-schemes" content="{{ $forceDark ? 'dark' : 'light dark' }}">
<style>
@media only screen and (max-width: 600px) {
.inner-body, .footer { width: 100% !important; }
.content-cell { padding: 24px 20px !important; }
}

@media only screen and (max-width: 500px) {
.button { width: 100% !important; text-align: center !important; }
}

@if ($forceDark)
{!! $dark !!}
@else
@media (prefers-color-scheme: dark) {
{!! $dark !!}
}

[data-ogsc] .inner-body { background-color: #111827 !important; }
[data-ogsc] h1, [data-ogsc] h2, [data-ogsc] h3 { color: #f9fafb !important; }
@endif
</style>
{!! $head ?? '' !!}
</head>
<body dir="{{ $dir }}">
@isset($preheader)
<div style="display: none; max-height: 0; overflow: hidden; mso-hide: all;" aria-hidden="true">{{ $preheader }}</div>
@endisset

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation" dir="{{ $dir }}">
<tr>
<td align="center">
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
{!! $header ?? '' !!}

<!-- Email Body -->
<tr>
<td class="body" width="100%" cellpadding="0" cellspacing="0" style="border: hidden !important;">
<table class="inner-body" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation" dir="{{ $dir }}">
<!-- Body content -->
<tr>
<td class="content-cell" dir="{{ $dir }}">
{!! Illuminate\Mail\Markdown::parse($slot) !!}

{!! $subcopy ?? '' !!}
</td>
</tr>
</table>
</td>
</tr>

{!! $footer ?? '' !!}
</table>
</td>
</tr>
</table>
</body>
</html>
