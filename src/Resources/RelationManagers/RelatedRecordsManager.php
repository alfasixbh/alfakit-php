<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Resources\RelationManagers;

use AlfaSix\Alfakit\Resources\Pages\ManageRelatedRecords;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Table;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The relation manager behind a `ManageRelatedRecords` page: the page defines the form
 * and table, this gives its actions the relation-manager behaviour (create through the
 * relationship, attach/detach, read-only when the owner can't be edited).
 *
 * @internal
 */
final class RelatedRecordsManager extends RelationManager
{
    protected static string $relationship = '';

    protected static bool $isLazy = false;

    private ManageRelatedRecords $ownerPage;

    public static function forPage(ManageRelatedRecords $page): self
    {
        $manager = self::make($page->getOwnerRecord(), $page);
        $manager->ownerPage = $page;

        // A related resource nested under the page's resource links under the owner record.
        $related = $page::getRelatedResource();

        if ($related !== null && $related::getParentResource() === $page::getResource()) {
            \AlfaSix\Alfakit\Resources\ParentRecords::set($page::getResource(), $page->getOwnerRecord());
        }

        return $manager;
    }

    public function getRelationship(): Relation
    {
        return $this->ownerPage->getRelationship();
    }

    public function getResourceClass(): ?string
    {
        return isset($this->ownerPage) ? $this->ownerPage::getRelatedResource() : null;
    }

    public function getModelLabel(): string
    {
        return $this->ownerPage->getRelatedModelLabel() ?? parent::getModelLabel();
    }

    public function getPluralModelLabel(): string
    {
        return $this->ownerPage->getRelatedPluralModelLabel() ?? parent::getPluralModelLabel();
    }

    public function getRecordTitle(?\Illuminate\Database\Eloquent\Model $record): ?string
    {
        $attribute = $this->ownerPage::getRelatedRecordTitleAttribute();

        if ($record === null || $attribute === null) {
            return parent::getRecordTitle($record);
        }

        return (string) data_get($record, $attribute);
    }

    public function form(Schema $schema): Schema
    {
        return $this->ownerPage->form($schema);
    }

    public function table(Table $table): Table
    {
        return $this->ownerPage->table($table);
    }

    // The page's table owns the query string, like a list page.
    protected function getTableQueryStringIdentifier(): ?string
    {
        return null;
    }

    protected function getTableHeading(): ?string
    {
        return null;
    }
}
