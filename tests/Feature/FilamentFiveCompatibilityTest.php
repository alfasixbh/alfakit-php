<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\Exports\Models\Export;
use AlfaSix\Alfakit\Forms\Components\RichEditor;
use AlfaSix\Alfakit\Notifications\Actions\Action as NotificationAction;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\Alfakit\Tests\Fixtures\Models\TenantUser;

/*
 * Filament 4/5 APIs the migrated Filament 5 corpus uses and AlfaKit accepts as written.
 */

it('accepts panel actions as notification buttons', function (): void {
    $notification = Notification::make()
        ->title('New order')
        ->actions([
            Action::make('view')->label('View order')->url('https://acme.test/orders/1', shouldOpenInNewTab: true)->color('primary'),
            NotificationAction::make('dismiss'),
        ]);

    $actions = $notification->toArray()['actions'];

    expect($actions)->toHaveCount(2)
        ->and($actions[0])->toMatchArray(['name' => 'view', 'label' => 'View order', 'url' => 'https://acme.test/orders/1', 'openUrlInNewTab' => true, 'color' => 'primary'])
        ->and($actions[1]['name'])->toBe('dismiss');
});

it('takes the per-page options from paginated([...])', function (): void {
    $table = Table::make()->paginated([5, 15]);

    $paginated = (fn (): mixed => $this->isPaginated)->call($table);
    $options = (fn (): mixed => $this->paginationPageOptions)->call($table);

    expect($paginated)->toBeTrue()->and($options)->toBe([5, 15]);
    expect((fn (): mixed => $this->isPaginated)->call(Table::make()->paginated(false)))->toBeFalse();
});

it('separates nested toolbar button groups', function (): void {
    $editor = RichEditor::make('body')->toolbarButtons([['bold', 'italic'], ['h2', 'h3'], 'link']);

    $groups = [];
    $buttons = (fn (): array => $this->serializeToolbarButtons($this->toolbarButtons, [], $groups))->call($editor);

    expect($buttons)->toBe(['bold', 'italic', '|', 'h2', 'h3', 'link']);
});

it('counts the failed rows of an export', function (): void {
    $export = new Export(['total_rows' => 10, 'successful_rows' => 7]);

    expect($export->getFailedRowsCount())->toBe(3);
});

it('makes the current tenant the URL default for {tenant}', function (): void {
    $user = TenantUser::query()->create(['name' => 'Ada', 'email' => 'ada'.uniqid().'@example.com', 'password' => 'secret']);
    $team = Team::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
    $user->teams()->attach([$team->id]);

    inertiaGet($this->actingAs($user), '/app/alpha')->assertOk();

    // `route()` inside the panel no longer needs the tenant, as in Filament.
    expect(route('alfakit.app.pages.dashboard'))->toBe('http://localhost/app/alpha');
});
