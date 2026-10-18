<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Pivot\CourseResource\Pages;

use AlfaSix\Alfakit\Resources\Pages\EditRecord;
use AlfaSix\Alfakit\Tests\Fixtures\Pivot\CourseResource;

class EditCourse extends EditRecord
{
    protected static string $resource = CourseResource::class;
}
