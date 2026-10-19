<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tenancy\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Support\Enums\Color;
use Illuminate\Database\Eloquent\Model;

/**
 * Extend, override `form()` and implement `getLabel()` to let users edit the current tenant.
 */
abstract class EditTenantProfile extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    // Registered by `tenantRegistration()` / `tenantProfile()` only, never discovered as a page.
    protected static bool $isDiscovered = false;

    public static function getRelativeRouteName(): string
    {
        return 'tenant.profile';
    }

    abstract public static function getLabel(): string;

    public function getTitle(): string
    {
        return static::getLabel();
    }

    public function getRecord(): ?Model
    {
        return $this->getPanel()->getTenant();
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label(__('alfakit::forms.actions.save'))
                ->color(Color::Primary)
                ->button()
                ->keyBindings(['mod+s'])
                ->form(fn (): array => $this->getForm()?->getComponents(withHidden: true) ?? [])
                ->action(fn (array $data) => $this->save($data)),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(array $data): mixed
    {
        $tenant = $this->getRecord();

        abort_if($tenant === null, 404);

        $tenant->fill($this->mutateFormDataBeforeSave($data))->save();

        $this->getActions()['save']->getForm()?->saveRelationships();

        Notification::make()->success()->title(__('alfakit::forms.actions.saved'))->send();

        return back();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $data;
    }

    protected function getContentBlocks(): array
    {
        $form = $this->getForm();

        return $form === null ? [] : [$this->serializeForm($form)];
    }
}
