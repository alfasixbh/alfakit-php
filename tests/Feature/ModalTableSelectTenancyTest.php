<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\ModalTableSelect;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tables\TableState;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Project;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\Alfakit\Tests\Fixtures\Tenancy\ProjectResource;

it('limits the picker table, labels and validation to the current tenant', function (): void {
    $alpha = Team::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
    $beta = Team::query()->create(['name' => 'Beta', 'slug' => 'beta']);
    $own = Project::query()->create(['team_id' => $alpha->id, 'name' => 'Alpha project']);
    $foreign = Project::query()->create(['team_id' => $beta->id, 'name' => 'Beta project']);

    alfakit()->setCurrentPanel(alfakit()->getPanel('app'));
    alfakit()->setTenant($alpha);

    $field = ModalTableSelect::make('project_ids')
        ->multiple()
        ->recordTitleAttribute('name')
        ->tableConfiguration(fn (Table $table): Table => $table->query(Project::query())->columns([TextColumn::make('name')]));

    $schema = schema([$field])->fill();
    $payload = $field->getTablePayload(new TableState);

    expect($payload['labels'])->toBe([(string) $own->id => 'Alpha project'])
        ->and(array_column($payload['table']['records'], 'key'))->toBe([$own->id])
        ->and($field->getOptionLabelsFor([$own->id, $foreign->id]))->toBe([(string) $own->id => 'Alpha project']);

    $schema->applyClientState(['project_ids' => [$own->id, $foreign->id]]);

    expect($schema->getValidator()->errors()->keys())->toBe(['project_ids.1']);
});

it('reuses a resource table as the picker, still scoped to the tenant', function (): void {
    $alpha = Team::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
    $beta = Team::query()->create(['name' => 'Beta', 'slug' => 'beta']);
    Project::query()->create(['team_id' => $alpha->id, 'name' => 'Alpha project']);
    Project::query()->create(['team_id' => $beta->id, 'name' => 'Beta project']);

    alfakit()->setCurrentPanel(alfakit()->getPanel('app'));
    alfakit()->setTenant($beta);

    $field = ModalTableSelect::make('project_id')->recordTitleAttribute('name')->tableConfiguration(ProjectResource::class);
    schema([$field])->fill();
    $payload = $field->getTablePayload(new TableState);

    expect(array_values($payload['labels']))->toBe(['Beta project'])
        ->and(array_column($payload['table']['columns'], 'name'))->toBe(['name']);
});

it('refuses a table configuration it cannot use', function (): void {
    $field = ModalTableSelect::make('x')->tableConfiguration(stdClass::class);
    schema([$field])->fill();

    expect(fn () => $field->getTable())->toThrow(LogicException::class);
});
