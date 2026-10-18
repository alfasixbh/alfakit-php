<?php

declare(strict_types=1);

use AlfaSix\Alfakit\AlfakitManager;

if (! function_exists('alfakit')) {
    /**
     * The Alfakit manager, holding every registered panel and the current one.
     */
    function alfakit(): AlfakitManager
    {
        return app(AlfakitManager::class);
    }
}
