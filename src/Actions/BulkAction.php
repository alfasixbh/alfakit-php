<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions;

use AlfaSix\Alfakit\Actions\Concerns\InteractsWithSelectedRecords;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Enumerable;
use Illuminate\Support\LazyCollection;

/**
 * An action that runs against the records selected in a table.
 */
class BulkAction extends Action
{
    use InteractsWithSelectedRecords;

    /** @var SupportCollection<array-key, mixed>|Closure|null Models, or array rows of custom `records()`. */
    protected SupportCollection|Closure|null $records = null;

    protected bool|Closure $shouldDeselectRecordsAfterCompletion = true;

    /**
     * @param  SupportCollection<array-key, mixed>|Closure|null  $records
     */
    public function records(SupportCollection|Closure|null $records): static
    {
        $this->records = $records;
        $this->resolvedRecords = null;

        return $this;
    }

    public function deselectRecordsAfterCompletion(bool|Closure $condition = true): static
    {
        $this->shouldDeselectRecordsAfterCompletion = $condition;

        return $this;
    }

    public function shouldDeselectRecordsAfterCompletion(): bool
    {
        return (bool) $this->evaluate($this->shouldDeselectRecordsAfterCompletion);
    }

    public function toSchema(): array
    {
        return ['type' => 'BulkAction', ...parent::toSchema()];
    }

    /**
     * @return array<mixed>
     */
    protected function resolveDefaultClosureDependencyForEvaluationByName(string $parameterName): array
    {
        return match ($parameterName) {
            'records' => [$this->getRecords()],
            // Counts for `failureNotificationTitle()` / `successNotificationTitle()` closures.
            'successCount' => [$this->getBulkProcessingSuccessCount()],
            'failureCount' => [$this->getBulkProcessingFailureCount()],
            'totalCount' => [$this->getBulkProcessingTotalCount()],
            default => parent::resolveDefaultClosureDependencyForEvaluationByName($parameterName),
        };
    }

    /**
     * @return array<mixed>
     */
    protected function resolveDefaultClosureDependencyForEvaluationByType(string $parameterType): array
    {
        return match ($parameterType) {
            // Chunked records are lazy; a closure that asks for a Collection gets them all.
            Collection::class => [($records = $this->getRecords()) instanceof Collection ? $records : new Collection($records->all())],
            // `fetchSelectedRecords(false)`: the keys, as a plain collection.
            SupportCollection::class => [($records = $this->getRecords()) instanceof SupportCollection ? $records : collect($records->all())],
            LazyCollection::class => [($records = $this->getRecords()) instanceof LazyCollection ? $records : LazyCollection::make($records->all())],
            Enumerable::class => [$this->getRecords()],
            Model::class => [null],
            default => parent::resolveDefaultClosureDependencyForEvaluationByType($parameterType),
        };
    }
}
