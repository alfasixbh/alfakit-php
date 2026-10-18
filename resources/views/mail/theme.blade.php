@php
    $brand = \AlfaSix\Alfakit\Mail\MailTheme::brand();
    $start = \AlfaSix\Alfakit\Mail\MailTheme::direction()->isRtl() ? 'right' : 'left';
    $end = $start === 'left' ? 'right' : 'left';
    $primary = $brand->primary;
    $onPrimary = $brand->primaryForeground();
@endphp
/* AlfaKit mail theme: inlined by Laravel's CSS inliner. Dark styles live in the layout. */

body,
body *:not(html):not(style):not(br):not(tr):not(code) {
    box-sizing: border-box;
    font-family: {!! $brand->font !!};
}

body {
    -webkit-text-size-adjust: none;
    background-color: #f4f5f7;
    color: #374151;
    height: 100%;
    line-height: 1.5;
    margin: 0;
    padding: 0;
    width: 100% !important;
}

p, ul, ol, blockquote, h1, h2, h3, .content-cell, .table th, .table td {
    text-align: {{ $start }};
}

p, ul, ol, blockquote {
    font-size: 16px;
    line-height: 1.6em;
    margin-top: 0;
}

a {
    color: {{ $primary }};
}

a img {
    border: none;
}

h1 {
    color: #111827;
    font-size: 20px;
    font-weight: bold;
    line-height: 1.3;
    margin-top: 0;
}

h2 {
    color: #111827;
    font-size: 17px;
    font-weight: bold;
    margin-top: 0;
}

h3 {
    color: #111827;
    font-size: 15px;
    font-weight: bold;
    margin-top: 0;
}

blockquote {
    border-{{ $start }}: 3px solid #e5e7eb;
    color: #4b5563;
    margin-{{ $start }}: 0;
    padding-{{ $start }}: 12px;
}

code {
    background-color: #f3f4f6;
    border-radius: 4px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 15px;
    padding: 2px 4px;
}

p.sub {
    font-size: 12px;
}

img {
    max-width: 100%;
}

.wrapper {
    background-color: #f4f5f7;
    margin: 0;
    padding: 0;
    width: 100%;
}

.content {
    margin: 0;
    padding: 0;
    width: 100%;
}

.header {
    padding: 28px 0 20px;
    text-align: center;
}

.header a {
    color: #111827;
    font-size: 20px;
    font-weight: bold;
    text-decoration: none;
}

.brand-name {
    color: #111827;
    font-size: 20px;
    font-weight: bold;
}

.body {
    background-color: #f4f5f7;
    margin: 0;
    padding: 0;
    width: 100%;
}

.inner-body {
    background-color: #ffffff;
    border: 1px solid #e5e7eb;
    border-top: 4px solid {{ $primary }};
    border-radius: 8px;
    margin: 0 auto;
    padding: 0;
    width: 570px;
}

.inner-body a {
    word-break: break-word;
}

.content-cell {
    max-width: 100vw;
    padding: 32px;
}

.subcopy {
    border-top: 1px solid #e5e7eb;
    margin-top: 24px;
    padding-top: 24px;
}

.subcopy p {
    color: #6b7280;
    font-size: 13px;
}

.footer {
    margin: 0 auto;
    padding: 0;
    text-align: center;
    width: 570px;
}

.footer p {
    color: #6b7280;
    font-size: 12px;
    text-align: center;
}

.footer a {
    color: #6b7280;
    text-decoration: underline;
}

.table table {
    margin: 24px auto;
    width: 100%;
}

.table th {
    border-bottom: 1px solid #e5e7eb;
    color: #111827;
    margin: 0;
    padding-bottom: 8px;
}

.table td {
    border-bottom: 1px solid #f3f4f6;
    color: #374151;
    font-size: 15px;
    line-height: 20px;
    margin: 0;
    padding: 10px 0;
}

.action {
    margin: 28px auto;
    padding: 0;
    text-align: center;
    width: 100%;
}

.button {
    -webkit-text-size-adjust: none;
    border-radius: 6px;
    color: {{ $onPrimary }};
    display: inline-block;
    font-size: 15px;
    font-weight: 600;
    overflow: hidden;
    text-decoration: none;
}

.button-blue,
.button-primary {
    background-color: {{ $primary }};
    border-bottom: 10px solid {{ $primary }};
    border-left: 20px solid {{ $primary }};
    border-right: 20px solid {{ $primary }};
    border-top: 10px solid {{ $primary }};
    color: {{ $onPrimary }};
}

.button-green,
.button-success {
    background-color: #15803d;
    border-bottom: 10px solid #15803d;
    border-left: 20px solid #15803d;
    border-right: 20px solid #15803d;
    border-top: 10px solid #15803d;
    color: #ffffff;
}

.button-red,
.button-error {
    background-color: #b91c1c;
    border-bottom: 10px solid #b91c1c;
    border-left: 20px solid #b91c1c;
    border-right: 20px solid #b91c1c;
    border-top: 10px solid #b91c1c;
    color: #ffffff;
}

.panel {
    border-{{ $start }}: 4px solid {{ $primary }};
    margin: 20px 0;
}

.panel-content {
    background-color: #f9fafb;
    color: #374151;
    padding: 16px;
}

.panel-content p {
    color: #374151;
}

.panel-item {
    padding: 0;
}

.panel-item p:last-of-type {
    margin-bottom: 0;
    padding-bottom: 0;
}

.break-all {
    word-break: break-all;
}

.text-end {
    text-align: {{ $end }};
}
