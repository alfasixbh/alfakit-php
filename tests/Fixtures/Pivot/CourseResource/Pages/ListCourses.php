<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Pivot\CourseResource\Pages;

use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\Alfakit\Tests\Fixtures\Pivot\CourseResource;

class ListCourses extends ListRecords
{
    protected static string $resource = CourseResource::class;
}
