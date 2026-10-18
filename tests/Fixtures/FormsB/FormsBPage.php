<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\FormsB;

use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\SchemaContext;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A page whose form and infolist each test defines (field actions, select option forms,
 * partial refreshes, relationship groups). It isn't registered with the panel: tests
 * address it through a context token, exactly as the renderers do.
 */
class FormsBPage extends Page
{
    /** @var (Closure(): array<\AlfaSix\Alfakit\Schemas\Components\Component>)|null */
    public static ?Closure $formUsing = null;

    /** @var (Closure(): array<\AlfaSix\Alfakit\Schemas\Components\Component>)|null */
    public static ?Closure $infolistUsing = null;

    protected ?Post $record = null;

    public function mount(mixed ...$parameters): void
    {
        $record = $parameters[0] ?? null;
        $this->record = $record === null ? null : Post::query()->find($record);
    }

    public function getRecord(): ?Post
    {
        return $this->record;
    }

    /**
     * @return class-string<Post>
     */
    public function getModel(): string
    {
        return Post::class;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(static::$formUsing === null ? [] : (static::$formUsing)());
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components(static::$infolistUsing === null ? [] : (static::$infolistUsing)());
    }

    public static function reset(): void
    {
        static::$formUsing = null;
        static::$infolistUsing = null;
    }

    public static function token(Authenticatable $user, ?Post $record = null): string
    {
        return (new SchemaContext('admin', static::class, $record === null ? [] : ['record' => $record->getKey()], null, (string) $user->getAuthIdentifier()))->toToken();
    }
}
