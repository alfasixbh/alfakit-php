<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components\Concerns;

use AlfaSix\Alfakit\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

trait BelongsToContainer
{
    protected ?Schema $container = null;

    public function container(Schema $container): static
    {
        $this->container = $container;

        return $this;
    }

    public function getContainer(): Schema
    {
        return $this->container ??= Schema::make();
    }

    public function hasContainer(): bool
    {
        return $this->container !== null;
    }

    public function getRecord(): ?Model
    {
        return $this->getContainer()->getRecord();
    }

    /**
     * @return class-string<Model>|null
     */
    public function getModel(): ?string
    {
        return $this->getContainer()->getModel();
    }

    public function getModelInstance(): ?Model
    {
        return $this->getContainer()->getModelInstance();
    }

    public function getOperation(): ?string
    {
        return $this->getContainer()->getOperation();
    }
}
