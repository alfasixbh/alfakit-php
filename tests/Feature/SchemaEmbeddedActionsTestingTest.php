<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Schemas\Components\Actions;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Set;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\Panels;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\SchemaActions\SchemaActionsPage;

/*
 * The page tester runs actions embedded in a schema: `Actions::make()` components with
 * `callFormComponentAction()`, section header actions with `callAction()`, as documented in
 * advanced/testing.md.
 */

beforeEach(function (): void {
    SchemaActionsPage::reset();
    Panels::register('schemaacts', fn (Panel $panel): Panel => $panel->pages([SchemaActionsPage::class]));
    $this->actingAs(User::query()->create(['name' => 'A', 'email' => 'sa'.uniqid().'@example.com', 'password' => 'password']));
});

afterEach(fn () => SchemaActionsPage::reset());

it('runs schema-embedded actions in page tests', function (): void {
    SchemaActionsPage::$formUsing = fn (): array => [
        Section::make('Rating')->key('ratingSection')
            ->headerActions([Action::make('publish')->action(fn () => Notification::make()->title('Published')->send())])
            ->schema([
                TextInput::make('rating'),
                Actions::make([
                    Action::make('star')->action(fn (Set $set) => $set('rating', '5')),
                ])->key('ratingActions'),
            ]),
    ];

    alfakit()->testPage(SchemaActionsPage::class, panel: 'schemaacts')
        ->assertSchemaComponentExists('ratingActions')
        // An `Actions::make()` component: addressed by its key, like a field's actions.
        ->callFormComponentAction('ratingActions', 'star')
        ->assertFormSet(['rating' => '5'])
        // Section header / footer actions run as page actions.
        ->assertActionVisible('publish')
        ->callAction('publish')
        ->assertNotified('Published');
});

it('keeps the page action when an embedded schema action has the same name (W-S2-10)', function (): void {
    SchemaActionsPage::$headerActionsUsing = fn (): array => [
        Action::make('create')->action(fn () => Notification::make()->title('Page create')->send()),
    ];
    SchemaActionsPage::$formUsing = fn (): array => [
        AlfaSix\Alfakit\Schemas\Components\EmptyState::make('No orders yet')
            ->actions([Action::make('create')->action(fn () => Notification::make()->title('Empty state create')->send())]),
    ];

    alfakit()->testPage(SchemaActionsPage::class, panel: 'schemaacts')
        ->callAction('create')
        ->assertNotified('Page create');
});

it('sends a date set with $set() in the picker format and panel timezone (W-S1-05)', function (): void {
    alfakit()->getPanel('schemaacts')->timezone('Asia/Karachi');
    Illuminate\Support\Carbon::setTestNow(Illuminate\Support\Carbon::parse('2026-10-25 12:18:17.322610', 'UTC'));

    SchemaActionsPage::$formUsing = fn (): array => [
        AlfaSix\Alfakit\Forms\Components\Select::make('status')->options(['draft' => 'Draft', 'published' => 'Published'])->live()
            ->afterStateUpdated(fn (Set $set, ?string $state) => $state === 'published' ? $set('published_at', now()) : null),
        AlfaSix\Alfakit\Forms\Components\DateTimePicker::make('published_at'),
    ];

    alfakit()->testPage(SchemaActionsPage::class, panel: 'schemaacts')
        ->fillForm(['status' => 'published'])
        ->assertFormSet(['published_at' => '2026-10-25 17:18']);

    Illuminate\Support\Carbon::setTestNow();
});
