<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\FileUpload;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Filament 5.6.4 (#19868): every file URL a FileUpload sends to the browser goes through
 * `Str::sanitizeUrl()`, so a disk or callback can't produce a `javascript:` link.
 */

it('drops javascript: URLs from the disk, the open and the download callbacks', function (): void {
    Storage::fake('evil');
    Storage::disk('evil')->put('docs/a.pdf', 'pdf');
    Storage::disk('evil')->buildTemporaryUrlsUsing(fn (string $path): string => "\tJaVa\nScRiPt:alert('{$path}')");

    $field = FileUpload::make('file')->disk('evil')->visibility('private')->openable()->downloadable()
        ->getOpenableFileUrlUsing(fn (string $file): string => 'javascript:alert(1)')
        ->getDownloadableFileUrlUsing(fn (string $file): string => 'data:text/html,<script>alert(1)</script>');

    schema([$field])->fill(['file' => 'docs/a.pdf']);

    $file = $field->toSchema()['files'][0];

    expect($field->getFileUrl('docs/a.pdf'))->toBeNull()
        ->and($field->getOpenableFileUrl('docs/a.pdf'))->toBeNull()
        ->and($field->getDownloadableFileUrl('docs/a.pdf'))->toBeNull()
        ->and($file)->not->toHaveKey('url')
        ->and($file)->not->toHaveKey('openUrl')
        ->and($file)->not->toHaveKey('downloadUrl');
});

it('keeps safe file URLs', function (): void {
    Storage::fake('safe');
    Storage::disk('safe')->put('docs/a.pdf', 'pdf');

    $field = FileUpload::make('file')->disk('safe')
        ->getOpenableFileUrlUsing(fn (string $file): string => "https://cdn.test/view/{$file}")
        ->getDownloadableFileUrlUsing(fn (string $file): string => "/download/{$file}");

    schema([$field])->fill(['file' => 'docs/a.pdf']);

    $file = $field->toSchema()['files'][0];

    expect($file['url'])->toBe(Storage::disk('safe')->url('docs/a.pdf'))
        ->and($file['openUrl'])->toBe('https://cdn.test/view/docs/a.pdf')
        ->and($file['downloadUrl'])->toBe('/download/docs/a.pdf');
});

it('accepts an allowlist of schemes in Str::sanitizeUrl()', function (): void {
    expect(Str::sanitizeUrl('mailto:a@b.test'))->toBe('mailto:a@b.test')
        ->and(Str::sanitizeUrl('mailto:a@b.test', ['http', 'https']))->toBeNull()
        ->and(Str::sanitizeUrl('http://a.test', ['https']))->toBeNull()
        ->and(str('https://a.test')->sanitizeUrl(['https'])->toString())->toBe('https://a.test');
});
