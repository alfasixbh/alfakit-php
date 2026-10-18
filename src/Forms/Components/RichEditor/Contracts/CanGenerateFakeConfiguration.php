<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\RichEditor\Contracts;

use Faker\Generator;

/**
 * A custom block that can make up a valid configuration for `RichContentFaker`:
 * `customBlock()` without arguments only picks blocks that implement this.
 */
interface CanGenerateFakeConfiguration
{
    /**
     * @return array<string, mixed>
     */
    public static function generateFakeConfiguration(Generator $faker): array;
}
