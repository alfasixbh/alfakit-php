<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\RichContent;

use AlfaSix\Alfakit\Forms\Components\RichEditor\Models\Concerns\InteractsWithRichContent;
use AlfaSix\Alfakit\Forms\Components\RichEditor\Models\Contracts\HasRichContent;
use AlfaSix\Alfakit\Tests\Fixtures\FormsB\Blocks\HeroBlock;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;

/**
 * A post whose `content` is configured on the model (`registerRichContent()`).
 */
class RichPost extends Post implements HasRichContent
{
    use InteractsWithRichContent;

    protected $table = 'posts';

    public static int $setUps = 0;

    public function setUpRichContent(): void
    {
        static::$setUps++;

        $this->registerRichContent('content')
            ->fileAttachmentsDisk('attachments')
            ->fileAttachmentsDirectory('posts')
            ->fileAttachmentsVisibility('private')
            ->customBlocks(['Marketing' => [HeroBlock::class]])
            ->mergeTags(['name' => fn (): string => 'Jane <Doe>', 'today'])
            ->mergeTagLabels(['name' => 'Full name'])
            ->plugins([HighlightPlugin::make()]);
    }
}
