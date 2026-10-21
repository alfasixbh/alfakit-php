<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\Builder;
use AlfaSix\Alfakit\Forms\Components\Repeater;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Schemas\Components\Group;
use AlfaSix\Alfakit\Schemas\Components\Section;

/*
 * Sibling layout components get distinct keys. Sections inside a `statePath()` group (the
 * Shield role form's resource sections under `permissions`), a repeater item or a builder
 * block used to take their container's state path as key, so the renderers saw several
 * children keyed `permissions` (React: "two children with the same key").
 */

/**
 * @param  array<int, array<string, mixed>>  $components
 * @return list<string>
 */
function layoutChildKeys(array $components): array
{
    return array_values(array_map(static fn (array $component): string => (string) $component['key'], $components));
}

it('gives sibling sections in a statePath() group distinct keys', function (): void {
    $form = schema([
        Group::make()->statePath('permissions')->schema([
            Section::make('Posts')->schema([TextInput::make('posts')]),
            Section::make('Pages')->schema([TextInput::make('pages')]),
        ]),
    ])->fill();

    $group = $form->toPayload()['components'][0];
    $keys = layoutChildKeys($group['schema']);

    expect($group['key'])->toBe('permissions')
        ->and(array_unique($keys))->toHaveCount(2)
        ->and($keys)->not->toContain('permissions')
        // Fields keep their state path as key.
        ->and($group['schema'][0]['schema'][0]['key'])->toBe('permissions.posts');
});

it('gives sibling sections of a repeater item and a builder block distinct keys', function (): void {
    $form = schema([
        Repeater::make('items')->schema([
            Section::make('One')->schema([TextInput::make('a')]),
            Section::make('Two')->schema([TextInput::make('b')]),
        ]),
        Builder::make('fields')->blocks([
            Builder\Block::make('text')->schema([
                Group::make()->schema([TextInput::make('label')]),
                Group::make()->schema([TextInput::make('hint')]),
            ]),
        ]),
    ])->fill(['items' => [['a' => null, 'b' => null]], 'fields' => [['type' => 'text', 'data' => []]]]);

    [$repeater, $builder] = $form->toPayload()['components'];

    expect(array_unique(layoutChildKeys($repeater['items'][0]['schema'])))->toHaveCount(2)
        ->and(array_unique(layoutChildKeys($repeater['template'])))->toHaveCount(2)
        ->and(array_unique(layoutChildKeys($builder['items'][0]['schema'])))->toHaveCount(2);
});
