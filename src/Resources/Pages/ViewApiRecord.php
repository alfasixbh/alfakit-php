<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Resources\Pages;

use AlfaSix\Alfakit\Resources\Pages\Concerns\InteractsWithApiRecord;

/**
 * The view page of an `ApiResource`: the resource's infolist (or its form, disabled)
 * over the record from the data source's `find()`.
 */
class ViewApiRecord extends ViewRecord
{
    use InteractsWithApiRecord;
}
