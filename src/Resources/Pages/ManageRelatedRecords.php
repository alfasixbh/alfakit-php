<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Resources\Pages;

use AlfaSix\Alfakit\Resources\Pages\Concerns\InteractsWithRecord;
use AlfaSix\Alfakit\Resources\RelationManagers\RelatedRecordsManager;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Labels;
use AlfaSix\Alfakit\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use LogicException;

/**
 * A full page for one relationship of a record, instead of a relation manager under its
 * form: `'comments' => ManagePostComments::route('/{record}/comments')`.
 *
 * ```php
 * class ManagePostComments extends ManageRelatedRecords
 * {
 *     protected static string $resource = PostResource::class;
 *
 *     protected static string $relationship = 'comments';
 *
 *     public function form(Schema $schema): Schema { ... }
 *
 *     public function table(Table $table): Table { ... }
 * }
 * ```
 *
 * Table actions behave as in a relation manager: `CreateAction` creates through the
 * relationship, attach/detach and associate/dissociate work, and every action that
 * changes data is hidden when the user can't update the owner record.
 */
abstract class ManageRelatedRecords extends Page
{
    use InteractsWithRecord;

    protected static string $relationship;

    /** @var class-string<resource>|null */
    protected static ?string $relatedResource = null;

    protected static ?string $relatedRecordTitleAttribute = null;

    protected static ?string $relatedModelLabel = null;

    protected static ?string $relatedPluralModelLabel = null;

    protected ?RelatedRecordsManager $cachedRelationManager = null;

    public function mount(mixed ...$parameters): void
    {
        $this->record = $this->resolveRecord($parameters[0] ?? null);

        $resource = static::getResource();

        abort_unless($resource::canView($this->record) || $resource::canEdit($this->record), 403);
    }

    public static function canAccess(): bool
    {
        return static::getResource()::canViewAny();
    }

    public static function getRelationshipName(): string
    {
        return static::$relationship;
    }

    /**
     * @return class-string<resource>|null
     */
    public static function getRelatedResource(): ?string
    {
        return static::$relatedResource;
    }

    public static function getRelatedRecordTitleAttribute(): ?string
    {
        return static::$relatedRecordTitleAttribute;
    }

    public function getRelatedModelLabel(): ?string
    {
        return static::$relatedModelLabel;
    }

    public function getRelatedPluralModelLabel(): ?string
    {
        return static::$relatedPluralModelLabel;
    }

    public function getOwnerRecord(): Model
    {
        return $this->record ?? throw new LogicException('The owner record is resolved in mount().');
    }

    public function getRelationship(): Relation
    {
        return $this->getOwnerRecord()->{static::getRelationshipName()}();
    }

    public function getRelationManager(): RelatedRecordsManager
    {
        return $this->cachedRelationManager ??= RelatedRecordsManager::forPage($this);
    }

    public function isReadOnly(): bool
    {
        return $this->getRelationManager()->isReadOnly();
    }

    /**
     * The form of the related records (create/edit modals).
     */
    public function form(Schema $schema): Schema
    {
        return $schema;
    }

    public function table(Table $table): Table
    {
        return $table;
    }

    public function getTable(): ?Table
    {
        return $this->getRelationManager()->getTable();
    }

    /**
     * The page has no form of its own: `form()` describes the related records.
     */
    public function getForm(): ?Schema
    {
        return null;
    }

    public static function getNavigationLabel(): string
    {
        return static::$navigationLabel ?? static::$title ?? Labels::fromName(static::getRelationshipName());
    }

    public function getTitle(): string
    {
        return static::$title ?? \AlfaSix\Alfakit\Support\Html::toText(static::getNavigationLabel());
    }

    public function getBreadcrumbs(): array
    {
        $resource = static::getResource();
        $record = $this->getOwnerRecord();
        $breadcrumbs = $this->getParentBreadcrumbs();

        if ($resource::hasPage('index')) {
            $breadcrumbs[$resource::getUrl()] = $resource::getTitleCasePluralModelLabel();
        }

        $title = (string) $this->getRecordTitle();

        if ($resource::hasPage('view') && $resource::canView($record)) {
            $breadcrumbs[$resource::getUrl('view', ['record' => $record])] = $title;
        } elseif ($resource::hasPage('edit') && $resource::canEdit($record)) {
            $breadcrumbs[$resource::getUrl('edit', ['record' => $record])] = $title;
        } else {
            $breadcrumbs[] = $title;
        }

        $breadcrumbs[] = $this->getBreadcrumb();

        return $breadcrumbs;
    }

    protected function flushCaches(): void
    {
        parent::flushCaches();

        $this->cachedRelationManager = null;
    }

    protected function getContentBlocks(): array
    {
        $table = $this->getTable();

        return $table === null ? [] : [$this->serializeTable($table)];
    }
}
