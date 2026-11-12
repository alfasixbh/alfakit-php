<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Support\ModelKey;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;

it('only compares whole numbers with an integer key', function (): void {
    $post = new Post;

    expect(ModelKey::isComparable($post, 'id', '12'))->toBeTrue()
        ->and(ModelKey::isComparable($post, 'id', 12))->toBeTrue()
        ->and(ModelKey::isComparable($post, 'id', 'abc'))->toBeFalse()
        ->and(ModelKey::isComparable($post, 'id', '12abc'))->toBeFalse()
        ->and(ModelKey::isComparable($post, 'id', '99999999999999999999'))->toBeFalse()
        ->and(ModelKey::isComparable($post, 'id', ['1']))->toBeFalse()
        // Other columns take any scalar.
        ->and(ModelKey::isComparable($post, 'slug', 'abc'))->toBeTrue()
        ->and(ModelKey::isComparable($post, 'slug', ['abc']))->toBeFalse();
});

it('answers 404 for a record key that cannot be a key', function (): void {
    $this->actingAs(admin());

    inertiaGet($this, '/admin/posts/abc/edit')->assertNotFound();
    inertiaGet($this, '/admin/posts/12abc/edit')->assertNotFound();
});

it('sends disabled dates in the field format and refuses them on submit', function (): void {
    $field = AlfaSix\Alfakit\Forms\Components\DateTimePicker::make('at')->disabledDates(['2026-12-25', Illuminate\Support\Carbon::parse('2026-12-26 10:00'), 'not a date']);
    $form = schema([$field])->fill();

    // The renderers parse these with the field's own format.
    expect($field->getDisabledDates())->toBe(['2026-12-25 00:00', '2026-12-26 10:00']);

    $form->applyClientState(['at' => '2026-12-25 09:30']);
    expect(fn () => $form->validate())->toThrow(Illuminate\Validation\ValidationException::class);

    $form->applyClientState(['at' => '2026-12-24 09:30']);
    expect($form->validate()['at'])->toStartWith('2026-12-24 09:30');
});

it('renders a code block without doubling its lines', function (): void {
    $html = AlfaSix\Alfakit\Forms\Components\RichEditor\RichContentRenderer::make([
        'type' => 'doc',
        'content' => [['type' => 'codeBlock', 'attrs' => ['language' => 'php'], 'content' => [['type' => 'text', 'text' => "line1\nline2"]]]],
    ])->toHtml();

    expect($html)->toContain("line1\nline2")->not->toContain('<br');
});
