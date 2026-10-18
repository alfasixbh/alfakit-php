<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\SchemaActions;

use AlfaSix\Alfakit\Tests\Fixtures\FormsB\FormsBPage;
use Closure;

/**
 * FormsBPage with header actions each test defines, for `Actions::make()` inside an
 * action's modal form.
 */
class SchemaActionsPage extends FormsBPage
{
    /** @var (Closure(): array<\AlfaSix\Alfakit\Actions\Action>)|null */
    public static ?Closure $headerActionsUsing = null;

    protected function getHeaderActions(): array
    {
        return static::$headerActionsUsing === null ? [] : (static::$headerActionsUsing)();
    }

    public static function reset(): void
    {
        parent::reset();
        static::$headerActionsUsing = null;
    }
}
