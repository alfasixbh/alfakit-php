<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\PageParity\Imports;

trait TracksImportHooks
{
    /** @var array<int, string> */
    public static array $traitHooks = [];

    protected function beforeSaveTracksImportHooks(): void
    {
        static::$traitHooks[] = 'beforeSave:'.$this->data['slug'];
    }

    protected function afterCreateTracksImportHooks(): void
    {
        static::$traitHooks[] = 'afterCreate:'.$this->data['slug'];
    }
}
