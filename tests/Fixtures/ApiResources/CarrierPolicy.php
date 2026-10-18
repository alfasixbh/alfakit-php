<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ApiResources;

use AlfaSix\Alfakit\Resources\Api\ApiRecord;
use Illuminate\Contracts\Auth\Authenticatable;

class CarrierPolicy
{
    public static bool $allowViewAny = true;

    public function viewAny(Authenticatable $user): bool
    {
        return static::$allowViewAny;
    }

    public function create(Authenticatable $user): bool
    {
        return true;
    }

    public function update(Authenticatable $user, ApiRecord $record): bool
    {
        return $record['code'] !== 'LOCK';
    }

    public function delete(Authenticatable $user, ApiRecord $record): bool
    {
        return true;
    }
}
