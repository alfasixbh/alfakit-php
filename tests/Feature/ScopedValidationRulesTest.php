<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Project;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Validation\ValidationException;

/**
 * @return array<string, array<string>>
 */
function scopedErrors(Schema $schema): array
{
    try {
        $schema->validate();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

it('checks scopedUnique() through the model, so soft-deleted rows do not count', function (): void {
    Post::query()->create(['title' => 'Live', 'slug' => 'live']);
    Post::query()->create(['title' => 'Gone', 'slug' => 'gone'])->delete();

    $form = fn (string $slug, ?Closure $modify = null): Schema => schema([TextInput::make('slug')->scopedUnique(modifyQueryUsing: $modify)], model: Post::class)
        ->fill()->applyClientState(['slug' => $slug]);

    expect(scopedErrors($form('live')))->toHaveKey('slug')
        ->and(scopedErrors($form('gone')))->toBe([])
        ->and(scopedErrors($form('new')))->toBe([])
        // The query can be changed, e.g. to count soft-deleted rows again.
        ->and(scopedErrors($form('gone', fn (Builder $query): Builder => $query->withoutGlobalScope(SoftDeletingScope::class))))->toHaveKey('slug');

    // Plain unique() checks the table, trashed rows included.
    $plain = schema([TextInput::make('slug')->unique()], model: Post::class)->fill()->applyClientState(['slug' => 'gone']);

    expect(scopedErrors($plain))->toHaveKey('slug');
});

it('ignores the bound record in scopedUnique() unless told otherwise', function (): void {
    $post = Post::query()->create(['title' => 'Hello', 'slug' => 'hello']);
    $other = Post::query()->create(['title' => 'Other', 'slug' => 'other']);

    $edit = fn (TextInput $field): Schema => schema([$field], record: $post, operation: 'edit')->fill()->applyClientState(['slug' => 'hello']);

    expect(scopedErrors($edit(TextInput::make('slug')->scopedUnique())))->toBe([])
        ->and(scopedErrors($edit(TextInput::make('slug')->scopedUnique(ignoreRecord: false))))->toHaveKey('slug')
        ->and(scopedErrors($edit(TextInput::make('slug')->scopedUnique(Post::class, ignorable: $other))))->toHaveKey('slug')
        ->and(scopedErrors($edit(TextInput::make('slug')->scopedUnique(Post::class, 'slug', ignorable: fn () => $post))))->toBe([]);
});

it('checks scopedExists() through the model, multiple values included', function (): void {
    $live = Post::query()->create(['title' => 'Live', 'slug' => 'live']);
    $gone = Post::query()->create(['title' => 'Gone', 'slug' => 'gone']);
    $gone->delete();

    $single = fn (mixed $id, ?Closure $modify = null): Schema => schema([TextInput::make('post_id')->scopedExists(Post::class, 'id', modifyQueryUsing: $modify)])
        ->fill()->applyClientState(['post_id' => (string) $id]);
    $multiple = fn (array $ids): Schema => schema([Select::make('posts')->multiple()->options(fn (): array => Post::withTrashed()->pluck('title', 'id')->all())->scopedExists(Post::class, 'id')])
        ->fill()->applyClientState(['posts' => $ids]);

    expect(scopedErrors($single($live->id)))->toBe([])
        ->and(scopedErrors($single($gone->id)))->toHaveKey('post_id')
        ->and(scopedErrors($single($gone->id, fn (Builder $query) => $query->withTrashed())))->toBe([])
        ->and(scopedErrors($multiple([$live->id])))->toBe([])
        ->and(scopedErrors($multiple([$live->id, $gone->id])))->toHaveKey('posts');
});

it('scopes scoped rules to the current tenant', function (): void {
    $alpha = Team::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
    $beta = Team::query()->create(['name' => 'Beta', 'slug' => 'beta']);
    Project::query()->create(['team_id' => $alpha->id, 'name' => 'Website']);
    $betaProject = Project::query()->create(['team_id' => $beta->id, 'name' => 'Shared name']);

    alfakit()->setCurrentPanel(alfakit()->getPanel('app'));
    alfakit()->setTenant($alpha);

    $unique = fn (string $name): Schema => schema([TextInput::make('name')->scopedUnique()], model: Project::class)->fill()->applyClientState(['name' => $name]);
    $exists = schema([TextInput::make('project_id')->scopedExists(Project::class, 'id')])->fill()->applyClientState(['project_id' => (string) $betaProject->id]);

    expect(scopedErrors($unique('Website')))->toHaveKey('name')
        // Another tenant's row doesn't make the name taken, nor its key valid.
        ->and(scopedErrors($unique('Shared name')))->toBe([])
        ->and(scopedErrors($exists))->toHaveKey('project_id');
});
