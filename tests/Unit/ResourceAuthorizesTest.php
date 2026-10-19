<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Resources\PostResource;
use Illuminate\Database\Eloquent\Model;

/*
 * Prebuilt actions, tables and undo authorise through `Resource::authorizes()`, which
 * honours overridden `can*()` methods (Filament's way to lock a resource down) before
 * falling back to `can()` and the policies.
 */

class ResourceAuthorizesTestResource extends PostResource
{
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}

it('checks the can*() methods for the matching abilities', function (): void {
    $this->actingAs(admin());
    $post = new Post(['title' => 'Hello']);

    expect(ResourceAuthorizesTestResource::can('create'))->toBeTrue()
        ->and(ResourceAuthorizesTestResource::authorizes('create'))->toBeFalse()
        ->and(ResourceAuthorizesTestResource::authorizes('delete', $post))->toBeFalse()
        ->and(ResourceAuthorizesTestResource::authorizes('deleteAny'))->toBeFalse()
        // Abilities without an override keep their policy answer.
        ->and(ResourceAuthorizesTestResource::authorizes('update', $post))->toBeTrue()
        ->and(ResourceAuthorizesTestResource::authorizes('viewAny'))->toBeTrue()
        ->and(ResourceAuthorizesTestResource::authorizes('restore', $post))->toBeTrue()
        ->and(PostResource::authorizes('create'))->toBeTrue();
});
