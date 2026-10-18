<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Filters\QueryBuilder;
use AlfaSix\Alfakit\Tables\Filters\QueryBuilder\Constraints\RelationshipConstraint;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tables\TableState;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Project;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\Alfakit\Tests\Fixtures\Models\TenantUser;
use Illuminate\Http\Request;

it('limits relationship constraint options and indicator labels to the current tenant', function (): void {
    $user = TenantUser::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'secret']);
    $alpha = Team::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
    $other = Team::query()->create(['name' => 'Other', 'slug' => 'other']);
    $user->teams()->attach([$alpha->id]);
    Project::query()->create(['team_id' => $alpha->id, 'name' => 'Alpha project']);
    Project::query()->create(['team_id' => $other->id, 'name' => 'Other project']);

    $this->actingAs($user);
    alfakit()->setCurrentPanel(alfakit()->getPanel('app'));
    alfakit()->setTenant($alpha);

    $state = TableState::fromRequest(Request::create('/', 'GET', ['filters' => ['queryBuilder' => ['query' => ['rules' => [
        ['type' => 'constraint', 'constraint' => 'team', 'operator' => 'has', 'value' => [(string) $other->id]],
    ]]]]]));

    $schema = Table::make()->query(Project::query())
        ->columns([TextColumn::make('name')])
        ->filters([QueryBuilder::make()->constraints([RelationshipConstraint::make('team')->titleAttribute('name')])])
        ->state($state)
        ->toSchema();

    $constraint = $schema['filters'][0]['schema'][0]['constraints'][0];

    expect(array_column($constraint['options'], 'label'))->toBe(['Alpha'])
        // Another tenant's record is never named, only shown by its key.
        ->and($schema['filterIndicators'][0]['label'])->toBe('Team has “'.$other->id.'”');
});
