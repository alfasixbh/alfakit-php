<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\AppPages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\Exceptions\Halt;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Pages\Attributes\Expose;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\TemporaryUpload;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A custom app page for the page method endpoint and the named schema pieces.
 */
class BoardPage extends Page
{
    protected static ?string $slug = 'board';

    protected static ?string $component = 'Apps/Board';

    protected static string $layout = 'fullscreen';

    /** @var array<int, array<string, mixed>> */
    public static array $calls = [];

    /** @return Builder<Project> */
    protected function projects(): Builder
    {
        return Project::query()->where('team_id', alfakit()->getTenant()?->getKey());
    }

    protected function getViewData(): array
    {
        return ['projects' => $this->projects()->orderBy('id')->pluck('name', 'id')->all()];
    }

    #[Expose]
    public function rename(int $project, string $name): array
    {
        $record = $this->projects()->findOrFail($project);
        $record->update(['name' => $name]);

        Notification::make()->success()->title('Renamed')->send();
        $this->reloadProps('projects');

        return ['id' => $record->getKey(), 'name' => $record->name];
    }

    #[Expose(rules: ['name' => 'required|string|min:3', 'priority' => 'nullable|in:low,high'])]
    public function create(string $name, ?Priority $priority = null, ?string $unvalidated = null): array
    {
        $record = Project::query()->create(['team_id' => alfakit()->getTenant()?->getKey(), 'name' => $name]);

        return ['id' => $record->getKey(), 'priority' => $priority?->value, 'unvalidated' => $unvalidated];
    }

    #[Expose(rules: 'renameRules')]
    public function renameAll(string $prefix): int
    {
        return $this->projects()->count();
    }

    /** @return array<string, mixed> */
    public function renameRules(array $arguments): array
    {
        return ['prefix' => ['required', 'string', 'not_in:'.($arguments['forbidden'] ?? 'x')]];
    }

    #[Expose(rateLimit: 2)]
    public function ping(): string
    {
        return 'pong';
    }

    #[Expose(authorize: 'manage-projects')]
    public function purge(): bool
    {
        return true;
    }

    #[Expose]
    public function leave(): RedirectResponse
    {
        Notification::make()->title('Bye')->send();

        return redirect()->to('/apps/alpha');
    }

    #[Expose]
    public function details(): array
    {
        return $this->formState('details');
    }

    #[Expose]
    public function inject(Request $request, Priority $priority, bool $flag = false, ?TemporaryUpload $file = null, float $amount = 0.0): array
    {
        return ['method' => $request->method(), 'priority' => $priority->value, 'flag' => $flag, 'file' => $file?->originalName(), 'amount' => $amount];
    }

    #[Expose(transaction: true)]
    public function failing(): void
    {
        Project::query()->create(['team_id' => alfakit()->getTenant()?->getKey(), 'name' => 'Rolled back']);

        throw new RuntimeException('Boom');
    }

    #[Expose]
    public function stop(): void
    {
        Notification::make()->title('Halted')->warning()->send();

        throw new Halt;
    }

    public function secret(): string
    {
        return 'never';
    }

    #[Expose]
    public static function staticMethod(): string
    {
        return 'never';
    }

    #[Expose]
    protected function hidden(): string
    {
        return 'never';
    }

    public function detailsForm(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('email')->email()->required(),
            Select::make('project_id')
                ->options(fn (): array => $this->projects()->pluck('name', 'id')->all())
                ->live()
                ->afterStateUpdated(fn ($set, $state) => $set('email', "project{$state}@example.com")),
        ]);
    }

    public function projectsTable(Table $table): Table
    {
        return $table
            ->query($this->projects())
            ->columns([TextColumn::make('name')->searchable()])
            ->actions([
                Action::make('touch')->action(function (Project $record): void {
                    self::$calls[] = ['touch' => $record->name];
                }),
            ]);
    }

    public function summaryInfolist(Schema $schema): Schema
    {
        return $schema->components([TextEntry::make('count')->state(fn (): int => $this->projects()->count())]);
    }
}
