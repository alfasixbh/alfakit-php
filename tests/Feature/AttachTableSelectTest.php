<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\AttachAction;
use AlfaSix\Alfakit\Forms\Components\ModalTableSelect;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Get;
use AlfaSix\Alfakit\Support\Enums\Width;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tables\TableState;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Project;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\Pivot\Course;
use AlfaSix\Alfakit\Tests\Fixtures\Pivot\CourseResource\Pages\EditCourse;
use AlfaSix\Alfakit\Tests\Fixtures\Pivot\CourseResource\RelationManagers\StudentsRelationManager;
use AlfaSix\Alfakit\Tests\Fixtures\Pivot\PivotPanelProvider;
use AlfaSix\Alfakit\Tests\Fixtures\Pivot\Student;
use Illuminate\Database\Eloquent\Builder;

beforeEach(function (): void {
    PivotPanelProvider::registerPanel();
    $this->actingAs(User::query()->create(['name' => 'Pick', 'email' => 'pick'.uniqid().'@example.com', 'password' => 'password']));
});

class AttachTableSelectStudentsTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->searchable(), TextColumn::make('email')]);
    }
}

function attachTableSelectAction(Course $course, AttachAction $action): AttachAction
{
    return $action->relationManager(StudentsRelationManager::make($course, app(EditCourse::class)));
}

it('picks the records to attach from a modal table', function (): void {
    $course = Course::query()->create(['name' => 'Maths']);
    $ada = Student::query()->create(['name' => 'Ada']);
    $grace = Student::query()->create(['name' => 'Grace']);
    Student::query()->create(['name' => 'Hidden']);
    $course->students()->attach($ada->id);

    $select = attachTableSelectAction($course, AttachAction::make()
        ->multiple()
        ->tableSelect(AttachTableSelectStudentsTable::class)
        ->recordSelectOptionsQuery(fn (Builder $query): Builder => $query->where('name', '!=', 'Hidden')))
        ->getRecordSelect();

    expect($select)->toBeInstanceOf(ModalTableSelect::class)
        ->and($select->isMultiple())->toBeTrue();

    $schema = schema([$select])->fill();
    $payload = $select->getTablePayload(new TableState);

    // Not yet attached, and narrowed by recordSelectOptionsQuery().
    expect($payload['labels'])->toBe([(string) $grace->id => 'Grace'])
        ->and(array_column($payload['table']['columns'], 'name'))->toBe(['name', 'email']);

    $schema->applyClientState(['recordId' => [$grace->id, $ada->id]]);

    expect($schema->getValidator()->errors()->keys())->toBe(['recordId.1']);
});

it('accepts a closure and keeps customising the select with recordSelect()', function (): void {
    $course = Course::query()->create(['name' => 'Maths']);
    Student::query()->create(['name' => 'Ada']);

    $select = attachTableSelectAction($course, AttachAction::make()
        ->tableSelect(fn (Table $table): Table => $table->columns([TextColumn::make('name')]))
        ->recordSelect(fn (ModalTableSelect $select): ModalTableSelect => $select->selectActionLabel('Choose students')))
        ->getRecordSelect();

    schema([$select])->fill();

    expect($select->toSchema())->toMatchArray(['type' => 'ModalTableSelect', 'selectActionLabel' => 'Choose students'])
        ->and(array_values($select->getTablePayload(new TableState)['labels']))->toBe(['Ada']);
});

it('keeps the searchable select without tableSelect()', function (): void {
    $course = Course::query()->create(['name' => 'Maths']);

    expect(attachTableSelectAction($course, AttachAction::make())->getRecordSelect())->not->toBeInstanceOf(ModalTableSelect::class);
});

it('customises the select button and modal with selectAction()', function (): void {
    $field = ModalTableSelect::make('project_id')
        ->label('Project')
        ->recordTitleAttribute('name')
        ->tableConfiguration(fn (Table $table): Table => $table->query(Project::query()))
        ->selectAction(fn (Action $action): Action => $action
            ->label('Pick a project')
            ->icon('search')
            ->modalHeading('Search projects')
            ->modalDescription('Only <active> projects')
            ->modalSubmitActionLabel('Use project')
            ->modalWidth(Width::FiveExtraLarge));

    schema([$field])->fill();

    expect($field->toSchema())->toMatchArray([
        'selectActionLabel' => 'Pick a project',
        'selectActionIcon' => 'search',
        'modalHeading' => 'Search projects',
        'modalDescription' => 'Only &lt;active&gt; projects',
        'modalSubmitActionLabel' => 'Use project',
        'modalWidth' => '5xl',
    ]);
});

it('keeps the default select button and modal texts', function (): void {
    $field = ModalTableSelect::make('project_id')->label('Project')->selectActionLabel('Browse');

    schema([$field])->fill();
    $schema = $field->toSchema();

    expect($schema['selectActionLabel'])->toBe('Browse')
        ->and($schema['modalHeading'])->toContain('project')
        ->and($schema)->not->toHaveKey('modalWidth')
        ->and($schema)->not->toHaveKey('selectActionIcon');
});

it('passes form values to the table configuration with tableArguments()', function (): void {
    $alpha = Team::query()->create(['name' => 'Alpha', 'slug' => 'alpha-'.uniqid()]);
    $beta = Team::query()->create(['name' => 'Beta', 'slug' => 'beta-'.uniqid()]);
    Project::query()->create(['team_id' => $alpha->id, 'name' => 'Alpha project']);
    Project::query()->create(['team_id' => $beta->id, 'name' => 'Beta project']);

    $field = ModalTableSelect::make('project_id')
        ->recordTitleAttribute('name')
        ->tableArguments(fn (Get $get): array => ['team_id' => $get('team_id')])
        ->tableConfiguration(fn (Table $table, array $arguments): Table => $table
            ->query(Project::query())
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->when($arguments['team_id'] ?? null, fn (Builder $query, mixed $team): Builder => $query->where('team_id', $team))));

    $schema = schema([TextInput::make('team_id'), $field])->fill(['team_id' => $beta->id]);

    expect($field->getTableArguments())->toBe(['team_id' => $beta->id])
        ->and(array_values($field->getTablePayload(new TableState)['labels']))->toBe(['Beta project']);

    $schema->applyClientState(['team_id' => null]);

    expect($field->getTablePayload(new TableState)['labels'])->toHaveCount(2);
});
