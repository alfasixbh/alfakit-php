<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ActionsResources\SettingsResource\Pages;

use AlfaSix\Alfakit\Resources\Pages\ManageSingularRecord;
use AlfaSix\Alfakit\Tests\Fixtures\ActionsResources\SettingsResource;

class ManageSettings extends ManageSingularRecord
{
    protected static string $resource = SettingsResource::class;
}
