<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Schemas\Components\Flex;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Schemas\Components\Wizard;
use AlfaSix\Alfakit\Schemas\Components\Wizard\Step;
use AlfaSix\Alfakit\Support\Enums\Alignment;
use AlfaSix\Alfakit\Support\Enums\Operation;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\SchemaActions\SchemaActionsPage;

enum ParityXLocale: string
{
    case German = 'de';
    case English = 'en';
}

// ---------------------------------------------------------------- Wizard::goToStep()

it('asks the browser to show a step with goToStep()', function (): void {
    $wizard = Wizard::make([
        Step::make('Cart')->key('cart')->schema([]),
        Step::make('Payment details')->schema([]),
    ]);
    $serialized = schema([$wizard])->fill()->toSchema();

    expect($serialized[0])->not->toHaveKey('goToStep');

    $wizard->goToStep('cart');
    $first = schema([$wizard])->fill()->toSchema()[0]['goToStep'];

    expect($first['step'])->toBe('cart')->and($first['id'])->toBeString();

    // By the slug of the label too; unknown steps are ignored.
    $wizard->goToStep('payment-details');
    $second = schema([$wizard])->fill()->toSchema()[0];

    expect($second['goToStep']['step'])->toBe($second['schema'][1]['key'])
        ->and($second['goToStep']['id'])->not->toBe($first['id']);

    $wizard->goToStep('missing');

    expect($wizard->getRequestedStep())->toBe($second['schema'][1]['key']);
});

it('sends goToStep() from an afterStateUpdated hook in the refreshed form', function (): void {
    $user = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password']);
    $this->actingAs($user);
    SchemaActionsPage::reset();
    SchemaActionsPage::$formUsing = fn (): array => [
        Wizard::make([
            Step::make('Cart')->key('cart')->schema([
                TextInput::make('coupon')->live()->afterStateUpdated(function (TextInput $component): void {
                    $component->getContainer()->getRootSchema()->getComponent('checkout')?->goToStep('cart');
                }),
            ]),
            Step::make('Payment')->key('payment')->schema([TextInput::make('card')]),
        ])->key('checkout')->skippable(),
    ];

    $payload = $this->postJson('/admin/_alfakit/schema/refresh', [
        'token' => SchemaActionsPage::token($user),
        'component' => 'form',
        'state' => ['coupon' => 'SAVE', 'card' => null],
        'path' => 'coupon',
    ])->assertOk()->json();

    $wizard = collect($payload['components'])->firstWhere('type', 'Wizard');

    expect($wizard['goToStep']['step'])->toBe('cart');
    SchemaActionsPage::reset();
});

// ---------------------------------------------------------------- Operation enum

it('accepts the Operation enum in hiddenOn(), visibleOn() and disabledOn()', function (): void {
    $serialized = schema([
        TextInput::make('slug')->disabledOn(Operation::Edit),
        TextInput::make('code')->disabledOn([Operation::Create, 'view']),
        TextInput::make('secret')->hiddenOn(Operation::Edit),
        TextInput::make('created')->visibleOn([Operation::Create]),
        TextInput::make('edited')->visibleOn(Operation::Edit),
    ], operation: 'edit')->fill()->toSchema();

    $byName = collect($serialized)->keyBy('name');

    expect($byName['slug']['disabled'] ?? false)->toBeTrue()
        ->and($byName['code']['disabled'] ?? false)->toBeFalse()
        ->and($byName->has('secret'))->toBeFalse()
        ->and($byName->has('created'))->toBeFalse()
        ->and($byName->has('edited'))->toBeTrue();

    expect(Operation::normalize([Operation::View, 'edit']))->toBe(['view', 'edit']);
});

// ---------------------------------------------------------------- Flex alignment

it('serialises the horizontal alignment of a Flex', function (): void {
    $serialized = schema([
        Flex::make([Section::make('A')->schema([])->grow(false)])->alignment(Alignment::Center),
        Flex::make([])->alignEnd(),
        Flex::make([])->alignment('right'),
        Flex::make([])->alignLeft(),
        Flex::make([]),
    ])->fill()->toSchema();

    expect(collect($serialized)->map(fn (array $flex): ?string => $flex['alignment'] ?? null)->all())
        ->toBe(['center', 'end', 'end', 'start', null]);
});

// ---------------------------------------------------------------- Locale enums

it('accepts a backed enum as the numeric() and money() locale', function (): void {
    expect(TextEntry::make('amount')->numeric(2, locale: ParityXLocale::German)->formatState(1234.5))->toBe('1.234,50')
        ->and(TextEntry::make('amount')->numeric(2, locale: fn (): ParityXLocale => ParityXLocale::English)->formatState(1234.5))->toBe('1,234.50')
        ->and(TextEntry::make('price')->money('EUR', locale: ParityXLocale::German)->formatState(10))->toContain('10,00');
});
