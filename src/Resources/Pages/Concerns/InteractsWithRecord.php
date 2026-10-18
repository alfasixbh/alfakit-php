<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Resources\Pages\Concerns;

use AlfaSix\Alfakit\Support\SchemaContext;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

trait InteractsWithRecord
{
    protected ?Model $record = null;

    protected function resolveRecord(mixed $key): Model
    {
        if ($key instanceof Model) {
            return $key;
        }

        $record = static::getResource()::resolveRecordRouteBinding($key);

        if ($record === null) {
            throw new NotFoundHttpException;
        }

        return $record;
    }

    public function getRecord(): ?Model
    {
        return $this->record;
    }

    public function getRecordTitle(): ?string
    {
        return static::getResource()::getRecordTitle($this->record);
    }

    public function getRouteParameters(): array
    {
        $parameters = parent::getRouteParameters();

        if ($this->record !== null) {
            $parameters['record'] = $this->record->getAttribute(static::getResource()::getRecordRouteKeyName() ?? $this->record->getRouteKeyName());
        }

        return $parameters;
    }

    public static function fromContext(SchemaContext $context): static
    {
        static::activateResourceConfigurationFromContext($context);

        $page = app(static::class);
        $page->routeParameters = $context->parameters;
        $page->bootRouteParameters();
        $page->mount($context->parameters['record'] ?? null);

        return $page;
    }
}
