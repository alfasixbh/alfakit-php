<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\FormsB\Blocks;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Forms\Components\RichEditor\RichContentCustomBlock;
use AlfaSix\Alfakit\Forms\Components\TextInput;

class HeroBlock extends RichContentCustomBlock
{
    public static function getIcon(): ?string
    {
        return 'image';
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action->schema([TextInput::make('heading')->required()]);
    }

    public static function toPreviewHtml(array $config): string
    {
        return '<h2>'.e($config['heading'] ?? '').'</h2>';
    }

    public static function toHtml(array $config, array $data): string
    {
        return '<section class="hero"><h2>'.e($config['heading'] ?? '').'</h2>'.e($data['suffix'] ?? '').'</section>';
    }
}
