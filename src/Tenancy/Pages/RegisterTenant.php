<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tenancy\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Support\Enums\Color;
use Illuminate\Database\Eloquent\Model;

/**
 * Extend, override `form()`, and implement `getLabel()` and `handleRegistration()` to let users create tenants.
 */
abstract class RegisterTenant extends Page
{
    protected static string $layout = 'auth';

    protected static bool $shouldRegisterNavigation = false;

    public static function getRelativeRouteName(): string
    {
        return 'tenant.registration';
    }

    abstract public static function getLabel(): string;

    /**
     * @param  array<string, mixed>  $data
     */
    abstract protected function handleRegistration(array $data): Model;

    public function getTitle(): string
    {
        return static::getLabel();
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('register')
                ->label(static::getLabel())
                ->color(Color::Primary)
                ->button()
                ->form(fn (): array => $this->getForm()?->getComponents(withHidden: true) ?? [])
                ->action(fn (array $data) => $this->register($data)),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function register(array $data): mixed
    {
        $tenant = $this->handleRegistration($data);

        $this->getActions()['register']->getForm()?->record($tenant)->saveRelationships();

        return redirect()->to($this->getRedirectUrl($tenant));
    }

    protected function getRedirectUrl(Model $tenant): string
    {
        return $this->getPanel()->getUrl($tenant);
    }

    protected function getContentBlocks(): array
    {
        $form = $this->getForm();

        return $form === null ? [] : [$this->serializeForm($form)];
    }
}
