<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions;

use AlfaSix\Alfakit\Actions\Concerns\InteractsWithResource;
use AlfaSix\Alfakit\Contracts\HasName;
use AlfaSix\Alfakit\Support\Enums\Color;
use AlfaSix\Alfakit\Support\Enums\Width;
use AlfaSix\Alfakit\Versions\Components\RecordVersions;
use AlfaSix\Alfakit\Versions\HasRecordVersions;
use AlfaSix\Alfakit\Versions\RecordVersion;
use AlfaSix\Alfakit\Versions\VersionDiff;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * "History": a slide-over listing the record's versions (`HasRecordVersions`) with a
 * field-by-field diff, and "Restore this version" (authorised by the update policy). Added
 * to the edit and view pages of resources with versions; add it yourself elsewhere:
 *
 * ```php
 * protected function getHeaderActions(): array
 * {
 *     return [HistoryAction::make()->attributeLabels(['body' => 'Content'])->limit(30)];
 * }
 * ```
 */
class HistoryAction extends Action
{
    use InteractsWithResource;

    public const DEFAULT_NAME = 'history';

    public const RESTORE_ACTION = 'restoreVersion';

    /** @var array<string, string>|Closure */
    protected array|Closure $attributeLabels = [];

    protected int|Closure $limit = 25;

    protected bool|Closure $canRestore = true;

    public static function make(string $name = self::DEFAULT_NAME): static
    {
        return parent::make($name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('alfakit::actions.history.label'));
        $this->icon('history');
        $this->color(Color::Gray);
        $this->slideOver();
        $this->modalWidth(Width::Large);
        $this->modalHeading(fn (): string => __('alfakit::actions.history.modal.heading', ['label' => (string) $this->getRecordTitle()]));
        $this->modalSubmitAction(false);
        $this->modalCancelActionLabel(__('alfakit::actions.history.modal.close'));

        $this->visible(fn (?Model $record): bool => $record !== null && self::isVersioned($record));
        // Seeing the history needs the view (or update) ability on the record.
        $this->authorize(fn (?Model $record): bool => $record !== null && ($this->resourceAuthorizes('view', $record) || $this->resourceAuthorizes('update', $record)));

        $this->modalContent(fn (): array => [
            RecordVersions::make()
                ->versions(fn (): array => $this->getVersionsSchema())
                ->restoreAction(fn (): ?array => $this->getRestoreActionSchema())
                ->actionPath([...array_map(static fn (Action $parent): string => $parent->getName(), array_slice($this->getMountedActions(), 0, -1)), $this->getName()]),
        ]);

        $this->registerModalActions(fn (): array => [$this->makeRestoreAction()]);
    }

    /**
     * Labels for the attributes in the diff (default: the headline of the attribute name).
     *
     * @param  array<string, string>|Closure  $labels
     */
    public function attributeLabels(array|Closure $labels): static
    {
        $this->attributeLabels = $labels;

        return $this;
    }

    /** How many versions the slide-over lists (newest first). */
    public function limit(int|Closure $limit): static
    {
        $this->limit = $limit;

        return $this;
    }

    /** `false` hides "Restore this version" (the update policy still applies otherwise). */
    public function restorable(bool|Closure $condition = true): static
    {
        $this->canRestore = $condition;

        return $this;
    }

    public static function isVersioned(Model $record): bool
    {
        return in_array(HasRecordVersions::class, class_uses_recursive($record), true);
    }

    /**
     * The record's versions in the current tenant, newest first.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, RecordVersion>
     */
    public function getVersions(int $limit): \Illuminate\Database\Eloquent\Collection
    {
        return $this->versionsQuery()?->with('author')->limit($limit)->get() ?? new \Illuminate\Database\Eloquent\Collection;
    }

    /** A version of this record in the current tenant. */
    public function findVersion(int $id): ?RecordVersion
    {
        return $this->versionsQuery()?->whereKey($id)->first();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<RecordVersion>|null
     */
    protected function versionsQuery(): ?\Illuminate\Database\Eloquent\Builder
    {
        $record = $this->getRecord();

        if (! $record instanceof Model || ! self::isVersioned($record) || ! method_exists($record, 'recordVersions')) {
            return null;
        }

        $panel = alfakit()->getCurrentPanel();
        $tenant = $panel?->hasTenancy() ? $panel->getTenant() : null;

        /** @var \Illuminate\Database\Eloquent\Relations\MorphMany<RecordVersion, Model> $relation */
        $relation = $record->recordVersions();

        /** @var \Illuminate\Database\Eloquent\Builder<RecordVersion> $query */
        $query = $relation->getQuery();

        return $query->forTenant($tenant);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function getVersionsSchema(): array
    {
        $limit = max(1, (int) $this->evaluate($this->limit));
        // One more than shown, so the oldest listed version has something to compare with.
        $versions = $this->getVersions($limit + 1)->values();
        $labels = (array) $this->evaluate($this->attributeLabels);
        $entries = [];

        foreach ($versions->take($limit) as $index => $version) {
            // The oldest stored version is compared with nothing: every value is shown as set.
            $previous = $versions->get($index + 1);

            $entries[] = array_filter([
                'id' => $version->getKey(),
                'event' => $version->event,
                'eventLabel' => $this->getEventLabel($version),
                'author' => $this->getAuthorName($version),
                'createdAt' => $version->created_at?->toIso8601String(),
                'current' => $index === 0 ?: null,
                'changes' => VersionDiff::compare($previous?->getSnapshot(), $version->getSnapshot(), array_map(strval(...), $labels)),
            ], static fn (mixed $value): bool => $value !== null);
        }

        return $entries;
    }

    protected function getEventLabel(RecordVersion $version): string
    {
        return match ($version->event) {
            RecordVersion::EVENT_CREATED => __('alfakit::actions.history.events.created'),
            RecordVersion::EVENT_DELETED => __('alfakit::actions.history.events.deleted'),
            RecordVersion::EVENT_RESTORED => __('alfakit::actions.history.events.restored'),
            RecordVersion::EVENT_REVERTED => __('alfakit::actions.history.events.reverted'),
            RecordVersion::EVENT_BASELINE => __('alfakit::actions.history.events.baseline'),
            default => __('alfakit::actions.history.events.updated'),
        };
    }

    protected function getAuthorName(RecordVersion $version): ?string
    {
        $author = $version->author;

        if (! $author instanceof Model) {
            return null;
        }

        if ($author instanceof HasName) {
            return \AlfaSix\Alfakit\Support\Html::toText($author->getAlfakitName());
        }

        $name = $author->getAttribute('name') ?? $author->getAttribute('email');

        return is_scalar($name) ? (string) $name : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function getRestoreActionSchema(): ?array
    {
        $restore = $this->getModalAction(self::RESTORE_ACTION);

        return $restore !== null && $restore->isVisible() && $restore->isAuthorized() ? $restore->toSchema() : null;
    }

    protected function makeRestoreAction(): Action
    {
        return Action::make(self::RESTORE_ACTION)
            ->label(__('alfakit::actions.history.restore.label'))
            ->icon('rotate-ccw')
            ->color(Color::Gray)
            ->requiresConfirmation()
            ->modalHeading(__('alfakit::actions.history.restore.modal.heading'))
            ->modalDescription(__('alfakit::actions.history.restore.modal.description'))
            ->modalSubmitActionLabel(__('alfakit::actions.history.restore.modal.submit'))
            ->record(fn (): ?Model => $this->getRecord() instanceof Model ? $this->getRecord() : null)
            ->visible(fn (): bool => (bool) $this->evaluate($this->canRestore))
            // Restoring changes the record: the update policy decides.
            ->authorize(fn (): bool => ($record = $this->getRecord()) instanceof Model && $this->resourceAuthorizes('update', $record))
            ->action(function (array $arguments): void {
                $record = $this->getRecord();

                abort_unless($record instanceof Model && self::isVersioned($record), 404);

                $id = $arguments['version'] ?? null;

                abort_unless(is_int($id) || (is_string($id) && ctype_digit($id)), 422);

                // Only a version of this record, in this tenant.
                $version = $this->findVersion((int) $id);

                abort_if($version === null, 404);

                abort_unless(method_exists($record, 'restoreRecordVersion'), 404);

                // In a `scopeToUser()` panel the record keeps its current owner: restoring
                // an older owner would move it into another user's account.
                $resource = $this->getResource();
                $owner = $resource !== null && is_a($record, $resource::getModel()) ? $resource::getUserOwnerAttributes($record) : [];

                $record->restoreRecordVersion($version, $owner);

                \AlfaSix\Alfakit\Notifications\Notification::make()
                    ->success()
                    ->title(__('alfakit::actions.history.restore.notification'))
                    ->send();
            })
            ->cancelParentActions()
            // Reload the page so its form shows the restored values.
            ->successRedirectUrl(fn (): ?string => $this->getRecordPageUrl());
    }

    protected function getRecordPageUrl(): ?string
    {
        $context = $this->getContext();
        $record = $this->getRecord();

        if (! $record instanceof Model || ! $context instanceof \AlfaSix\Alfakit\Resources\Pages\Page) {
            return null;
        }

        $page = $context::getResourcePageName();

        return $page === null ? null : $this->getResourceUrl($page, $record);
    }
}
