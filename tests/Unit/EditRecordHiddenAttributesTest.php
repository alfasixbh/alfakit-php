<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\Pivot\CourseResource\Pages\EditCourse;

it('keeps a hidden attribute whose field was left blank, and saves a typed value', function (): void {
    $page = new EditCourse;
    (fn () => $this->record = new User)->call($page);
    $keep = fn (array $data): array => (fn (): array => $this->keepHiddenAttributesLeftBlank($data))->call($page);

    // `password` and `remember_token` are hidden on the fixture User: they load blank.
    expect($keep(['name' => 'Ada', 'password' => null, 'remember_token' => '']))->toBe(['name' => 'Ada'])
        ->and($keep(['name' => '', 'password' => 'new-secret']))->toBe(['name' => '', 'password' => 'new-secret']);
});
