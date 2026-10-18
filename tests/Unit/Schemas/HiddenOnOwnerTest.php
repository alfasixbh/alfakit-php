<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\EditAction;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Resources\RelationManagers\RelationManager;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Filters\Filter;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Pivot\CourseResource\RelationManagers\StudentsRelationManager;

/*
 * `hiddenOn()` / `visibleOn()` accept classes, as in Filament: one shared form or table
 * can differ between the resource page and a relation manager.
 */

function ownedSchema(?object $owner, string $operation = 'edit'): Schema
{
    return Schema::make()->operation($operation)->owner($owner)->components([
        TextInput::make('title'),
        TextInput::make('course')->hiddenOn(RelationManager::class),
        TextInput::make('role')->visibleOn(RelationManager::class),
        Section::make('Nested')->schema([
            TextInput::make('notes')->hiddenOn([RelationManager::class, 'create']),
        ]),
    ]);
}

function visibleNames(Schema $schema): array
{
    return collect($schema->getFlatComponents())
        ->filter(fn ($c) => $c instanceof TextInput && ! $c->isHiddenInTree())
        ->map(fn (TextInput $c) => $c->getName())
        ->values()
        ->all();
}

it('hides and shows schema components by owner class, also inside layouts', function (): void {
    $manager = new StudentsRelationManager;

    expect(visibleNames(ownedSchema($manager)))->toBe(['title', 'role'])
        ->and(visibleNames(ownedSchema(new stdClass)))->toBe(['title', 'course', 'notes'])
        ->and(visibleNames(ownedSchema(null)))->toBe(['title', 'course', 'notes'])
        ->and(visibleNames(ownedSchema(new stdClass, 'create')))->toBe(['title', 'course']);
});

it('keeps operation names working next to classes', function (): void {
    $schema = Schema::make()->operation('view')->components([
        TextInput::make('a')->hiddenOn('view'),
        TextInput::make('b')->visibleOn(['create', 'edit']),
        TextInput::make('c')->visibleOn('view'),
    ]);

    expect(visibleNames($schema))->toBe(['c']);
});

it('gives action forms their context as owner', function (): void {
    $manager = new StudentsRelationManager;
    $action = EditAction::make()->context($manager)->form([TextInput::make('course')->hiddenOn(RelationManager::class)]);

    expect($action->getForm()?->getOwner())->toBe($manager)
        ->and($action->getForm()?->getFlatComponents(withHidden: true)[0]->isHidden())->toBeTrue()
        ->and($action->getForm()?->getFlatComponents())->toBe([]);
});

it('hides table columns and filters by the table owner', function (): void {
    $build = fn (?object $owner): Table => Table::make($owner)
        ->columns([
            TextColumn::make('title'),
            TextColumn::make('course.name')->hiddenOn(RelationManager::class),
            TextColumn::make('pivot.role')->visibleOn(RelationManager::class),
        ])
        ->filters([
            Filter::make('mine')->hiddenOn(RelationManager::class),
        ]);

    $visible = fn (Table $table): array => array_values(array_map(fn ($c) => $c->getName(), array_filter($table->getColumns(), fn ($c) => ! $c->isHidden())));

    $inManager = $build(new StudentsRelationManager);
    $onPage = $build(new stdClass);

    expect($visible($inManager))->toBe(['title', 'pivot.role'])
        ->and($visible($onPage))->toBe(['title', 'course.name'])
        ->and(collect($inManager->getFilters())->first()->isHidden())->toBeTrue()
        ->and(collect($onPage->getFilters())->first()->isHidden())->toBeFalse();
});

it('gives page schemas the page as owner', function (): void {
    $page = new AlfaSix\Alfakit\Tests\Fixtures\Panel\Resources\PostResource\Pages\CreatePost;
    $schema = (fn (): Schema => $this->makeSchema())->call($page);

    expect($schema->getOwner())->toBe($page);

    $schema->components([
        TextInput::make('only_on_create')->visibleOn(AlfaSix\Alfakit\Resources\Pages\CreateRecord::class),
        TextInput::make('not_on_create')->hiddenOn(AlfaSix\Alfakit\Resources\Pages\CreateRecord::class),
    ]);

    expect(visibleNames($schema))->toBe(['only_on_create']);
});
