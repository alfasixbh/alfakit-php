<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\PageParity\Pages;

use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\Alfakit\Tests\Fixtures\PageParity\ParityWizardResource;

class ListWizardPosts extends ListRecords
{
    protected static string $resource = ParityWizardResource::class;
}
