<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Contracts;

interface HasCurrentTenantLabel
{
    public function getCurrentTenantLabel(): string;
}
