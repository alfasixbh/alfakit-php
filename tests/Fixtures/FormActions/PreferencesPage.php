<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\FormActions;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\SchemaContext;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A custom page as `alfakit:migrate-from-filament` converts one (FM-B02): mount fills
 * `$formData`, and the "Save" button below the form calls a method that reads
 * `$this->getForm()->validate()`. Not registered with the panel: addressed by token.
 */
class PreferencesPage extends Page
{
    /** @var array<string, mixed>|null */
    public static ?array $saved = null;

    public function mount(mixed ...$parameters): void
    {
        $this->formData = ['courier' => 'Bahrain local courier'];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('courier')->required()->live(onBlur: true)]);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('peek')->action(fn () => static::$saved = $this->getForm()?->getState())];
    }

    protected function getFormActions(): array
    {
        return [Action::make('save')->label('Save preferences')->action(fn () => $this->save())];
    }

    public function save(): void
    {
        static::$saved = $this->getForm()?->validate();

        Notification::make()->success()->title('Warehouse preferences saved')->send();
    }

    public static function token(Authenticatable $user): string
    {
        return (new SchemaContext('admin', static::class, [], null, (string) $user->getAuthIdentifier()))->toToken();
    }
}
