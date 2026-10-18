<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Resources\Pages\Enums;

/**
 * Where the form / infolist tab sits among the relation manager tabs
 * (`hasCombinedRelationManagerTabsWithContent()`).
 */
enum ContentTabPosition: string
{
    case Before = 'before';
    case After = 'after';
}
