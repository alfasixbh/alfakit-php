<?php

declare(strict_types=1);

namespace App\Livewire;

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class NewsletterSignup extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public bool $subscribed = false;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('email')->email()->required(),
                Checkbox::make('consent')->label('I agree to receive emails')->accepted(),
            ])
            ->statePath('data');
    }

    public function subscribe(): void
    {
        $data = $this->form->getState();

        $this->subscribed = true;

        Notification::make()->title('Subscribed '.$data['email'])->success()->send();
    }

    public function render(): View
    {
        return view('livewire.newsletter-signup');
    }
}
