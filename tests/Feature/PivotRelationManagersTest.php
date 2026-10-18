<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\Pivot\Course;
use AlfaSix\Alfakit\Tests\Fixtures\Pivot\CourseResource\RelationManagers\LessonsRelationManager;
use AlfaSix\Alfakit\Tests\Fixtures\Pivot\CourseResource\RelationManagers\StudentsRelationManager;
use AlfaSix\Alfakit\Tests\Fixtures\Pivot\Lesson;
use AlfaSix\Alfakit\Tests\Fixtures\Pivot\PivotPanelProvider;
use AlfaSix\Alfakit\Tests\Fixtures\Pivot\Student;
use Illuminate\Support\Facades\DB;

/*
 * BelongsToMany relation managers: pivot columns in the table, pivot attributes in
 * CreateAction / EditAction, and the attach / associate record selects.
 */

beforeEach(function (): void {
    PivotPanelProvider::registerPanel();
    $this->actingAs(User::query()->create(['name' => 'Pivot', 'email' => 'pivot'.uniqid().'@example.com', 'password' => 'password']));
});

function rmPivotToken(mixed $test, Course $course): string
{
    return inertiaProps(inertiaGet($test, "/pivot/courses/{$course->id}/edit"))['page']['token'];
}

function rmPivotStudentsComponent(): string
{
    return 'relationManager:'.StudentsRelationManager::class;
}

function rmPivotModal(mixed $test, string $token, string $action, array $query = [], ?string $component = null): array
{
    return $test->getJson('/pivot/_alfakit/actions/modal?'.http_build_query(['token' => $token, 'component' => $component ?? rmPivotStudentsComponent(), 'action' => $action, ...$query]))
        ->assertOk()
        ->json();
}

function rmPivotRun(mixed $test, string $token, string $action, array $payload = [], ?string $component = null): Illuminate\Testing\TestResponse
{
    return inertiaPost($test, '/pivot/_alfakit/actions', ['token' => $token, 'component' => $component ?? rmPivotStudentsComponent(), 'action' => $action, ...$payload]);
}

/**
 * @return array<int, array<string, mixed>>
 */
function rmPivotRows(mixed $test, Course $course): array
{
    $page = inertiaProps(inertiaGet($test, "/pivot/courses/{$course->id}/edit"))['page'];
    $managers = collect($page['content'])->firstWhere('type', 'RelationManagers')['managers'];

    return collect($managers)->firstWhere('key', rmPivotStudentsComponent())['table']['records'];
}

it('lists pivot columns by name and through pivot.*, without overwriting the related key', function (): void {
    $course = Course::query()->create(['name' => 'Maths']);
    $student = Student::query()->create(['name' => 'Ada']);
    // A pivot row whose own id differs from the student's.
    DB::table('pivot_course_student')->insert(['course_id' => 99, 'student_id' => 99]);
    $course->students()->attach($student->id, ['role' => 'captain', 'seat' => 7]);

    $rows = rmPivotRows($this, $course);

    expect($rows)->toHaveCount(1)
        // The pivot row's id (2) does not replace the student's (1).
        ->and($rows[0]['key'])->toBe($student->id)
        ->and($rows[0]['cells']['role']['state'])->toBe('captain')
        ->and($rows[0]['cells']['pivot.seat']['state'])->toBe('7');
});

it('creates a related record with its pivot attributes', function (): void {
    $course = Course::query()->create(['name' => 'Maths']);
    $token = rmPivotToken($this, $course);

    rmPivotRun($this, $token, 'create', ['data' => ['name' => 'Grace', 'role' => 'mentor', 'seat' => '3']])->assertRedirect();

    $student = Student::query()->where('name', 'Grace')->sole();
    $pivot = $course->students()->whereKey($student->id)->sole()->pivot;

    expect($pivot->role)->toBe('mentor')
        ->and((int) $pivot->seat)->toBe(3)
        ->and($student->getAttributes())->not->toHaveKey('role');
});

it('fills the edit modal from the pivot and saves pivot attributes', function (): void {
    $course = Course::query()->create(['name' => 'Maths']);
    $other = Course::query()->create(['name' => 'Physics']);
    $student = Student::query()->create(['name' => 'Ada']);
    $course->students()->attach($student->id, ['role' => 'captain', 'seat' => 7]);
    $other->students()->attach($student->id, ['role' => 'member', 'seat' => 1]);
    $token = rmPivotToken($this, $course);

    $modal = rmPivotModal($this, $token, 'edit', ['record' => $student->id]);

    expect($modal['form']['state'])->toMatchArray(['name' => 'Ada', 'role' => 'captain', 'seat' => 7]);

    rmPivotRun($this, $token, 'edit', ['record' => $student->id, 'data' => ['name' => 'Ada L.', 'role' => 'lead', 'seat' => '9']])->assertRedirect();

    expect($student->fresh()->name)->toBe('Ada L.')
        ->and($course->students()->whereKey($student->id)->sole()->pivot->role)->toBe('lead')
        ->and((int) $course->students()->whereKey($student->id)->sole()->pivot->seat)->toBe(9)
        // The same student in another course keeps its own pivot row.
        ->and($other->students()->whereKey($student->id)->sole()->pivot->role)->toBe('member');
});

it('shows pivot attributes in the view modal', function (): void {
    $course = Course::query()->create(['name' => 'Maths']);
    $student = Student::query()->create(['name' => 'Ada']);
    $course->students()->attach($student->id, ['role' => 'captain']);

    $modal = rmPivotModal($this, rmPivotToken($this, $course), 'view', ['record' => $student->id]);

    expect($modal['form']['state'])->toMatchArray(['name' => 'Ada', 'role' => 'captain']);
});

it('cannot edit a student of another course through this manager', function (): void {
    $course = Course::query()->create(['name' => 'Maths']);
    $other = Course::query()->create(['name' => 'Physics']);
    $student = Student::query()->create(['name' => 'Ada']);
    $other->students()->attach($student->id, ['role' => 'member']);

    rmPivotRun($this, rmPivotToken($this, $course), 'edit', ['record' => $student->id, 'data' => ['name' => 'X', 'role' => 'owner']])->assertNotFound();

    expect($other->students()->whereKey($student->id)->sole()->pivot->role)->toBe('member');
});

it('attaches with pivot fields, and only records not yet attached by default', function (): void {
    $course = Course::query()->create(['name' => 'Maths']);
    $ada = Student::query()->create(['name' => 'Ada']);
    $course->students()->attach($ada->id);
    $token = rmPivotToken($this, $course);

    rmPivotRun($this, $token, 'attach', ['data' => ['recordId' => $ada->id, 'role' => 'x']])->assertStatus(422);

    $grace = Student::query()->create(['name' => 'Grace']);
    rmPivotRun($this, $token, 'attach', ['data' => ['recordId' => $grace->id, 'role' => 'guest']])->assertRedirect();

    expect($course->students()->whereKey($grace->id)->sole()->pivot->role)->toBe('guest');
});

it('allows duplicates, searches several columns and customises the record select', function (): void {
    $course = Course::query()->create(['name' => 'Maths']);
    $ada = Student::query()->create(['name' => 'Ada', 'email' => 'countess@example.com']);
    Student::query()->create(['name' => 'Hidden', 'email' => 'countess2@example.com']);
    $course->students()->attach($ada->id);
    $token = rmPivotToken($this, $course);

    $modal = rmPivotModal($this, $token, 'enrolAgain');
    $select = collect($modal['form']['components'])->firstWhere('name', 'recordId');

    expect($select['placeholder'] ?? null)->toBe('Pick a student');

    $manager = StudentsRelationManager::make($course, app(AlfaSix\Alfakit\Tests\Fixtures\Pivot\CourseResource\Pages\EditCourse::class));
    $action = collect($manager->getTable()->getHeaderActions())->first(fn ($action) => $action->getName() === 'enrolAgain');
    $results = $action->getRecordSelect()->getSearchResults('countess');

    // Found by email, already attached (duplicates allowed), and filtered by the options query.
    expect($results)->toBe([$ada->id => 'Ada']);

    rmPivotRun($this, $token, 'enrolAgain', ['data' => ['recordId' => $ada->id]])->assertRedirect();

    expect($course->students()->where('pivot_students.id', $ada->id)->count())->toBe(2);
});

it('allows duplicates for every attach action of a manager with $allowsDuplicates', function (): void {
    $course = Course::query()->create(['name' => 'Maths']);
    $ada = Student::query()->create(['name' => 'Ada']);
    $course->students()->attach($ada->id);
    $page = app(AlfaSix\Alfakit\Tests\Fixtures\Pivot\CourseResource\Pages\EditCourse::class);

    $attach = fn (StudentsRelationManager $manager) => collect($manager->getTable()->getHeaderActions())->first(fn ($action) => $action->getName() === 'attach');

    expect($attach(StudentsRelationManager::make($course, $page))->getRecordSelect()->getSearchResults('Ada'))->toBe([]);

    $duplicates = new class extends StudentsRelationManager
    {
        protected bool $allowsDuplicates = true;
    };

    expect($attach($duplicates::make($course, $page))->getRecordSelect()->getSearchResults('Ada'))->toBe([$ada->id => 'Ada']);
});

it('lets a custom schema reuse the public record select', function (): void {
    $course = Course::query()->create(['name' => 'Maths']);
    $grace = Student::query()->create(['name' => 'Grace']);
    $token = rmPivotToken($this, $course);

    $modal = rmPivotModal($this, $token, 'attachCustom');

    expect(collect($modal['form']['components'])->pluck('name')->all())->toBe(['recordId', 'seat']);

    rmPivotRun($this, $token, 'attachCustom', ['data' => ['recordId' => $grace->id, 'seat' => '4']])->assertRedirect();

    expect((int) $course->students()->whereKey($grace->id)->sole()->pivot->seat)->toBe(4);
});

it('preloads and narrows the associate record select', function (): void {
    $course = Course::query()->create(['name' => 'Maths']);
    $published = Lesson::query()->create(['title' => 'Algebra', 'code' => 'ALG-1']);
    $draft = Lesson::query()->create(['title' => 'Draft', 'code' => 'ALG-2', 'is_published' => false]);
    Lesson::query()->create(['title' => 'Owned', 'course_id' => Course::query()->create(['name' => 'Other'])->id]);
    $component = 'relationManager:'.LessonsRelationManager::class;
    $token = rmPivotToken($this, $course);

    $modal = rmPivotModal($this, $token, 'associate', component: $component);
    $select = collect($modal['form']['components'])->firstWhere('name', 'recordId');

    expect(json_encode($select))->toContain('Algebra')
        ->and(json_encode($select))->not->toContain('Draft')
        ->and(json_encode($select))->not->toContain('Owned');

    rmPivotRun($this, $token, 'associate', ['data' => ['recordId' => $draft->id]], $component)->assertStatus(422);
    rmPivotRun($this, $token, 'associate', ['data' => ['recordId' => $published->id]], $component)->assertRedirect();

    expect($published->fresh()->course_id)->toBe($course->id);
});

it('creates related records of a has-many manager through the relationship', function (): void {
    $course = Course::query()->create(['name' => 'Maths']);

    rmPivotRun($this, rmPivotToken($this, $course), 'create', ['data' => ['title' => 'Geometry']], 'relationManager:'.LessonsRelationManager::class)->assertRedirect();

    expect(Lesson::query()->where('title', 'Geometry')->sole()->course_id)->toBe($course->id);
});
