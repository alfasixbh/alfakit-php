<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ResourceTabs;

use AlfaSix\Alfakit\Resources\ResourceConfiguration;

class RtArchiveConfiguration extends ResourceConfiguration
{
    protected ?string $status = null;

    protected ?string $navigationLabel = null;

    public function status(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function navigationLabel(string $label): static
    {
        $this->navigationLabel = $label;

        return $this;
    }

    public function getNavigationLabel(): ?string
    {
        return $this->navigationLabel;
    }
}
