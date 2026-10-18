<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\RichEditor\Models\Concerns;

use AlfaSix\Alfakit\Forms\Components\RichEditor\RichContentAttribute;
use AlfaSix\Alfakit\Forms\Components\RichEditor\RichContentRenderer;

/**
 * Implements `HasRichContent`:
 *
 *     class Post extends Model implements HasRichContent
 *     {
 *         use InteractsWithRichContent;
 *
 *         public function setUpRichContent(): void
 *         {
 *             $this->registerRichContent('content')
 *                 ->fileAttachmentsDisk('s3')
 *                 ->fileAttachmentsVisibility('private')
 *                 ->customBlocks([HeroBlock::class])
 *                 ->mergeTags(['name' => fn () => auth()->user()?->name])
 *                 ->mergeTagLabels(['name' => 'Full name']);
 *         }
 *     }
 *
 *     $post->renderRichContent('content');
 */
trait InteractsWithRichContent
{
    /** @var array<string, RichContentAttribute>|null */
    protected ?array $richContentAttributes = null;

    public function registerRichContent(string $attribute): RichContentAttribute
    {
        $this->richContentAttributes ??= [];

        return $this->richContentAttributes[$attribute] = new RichContentAttribute($this, $attribute);
    }

    public function getRichContentAttribute(string $attribute): ?RichContentAttribute
    {
        return $this->getRichContentAttributes()[$attribute] ?? null;
    }

    public function hasRichContentAttribute(string $attribute): bool
    {
        return $this->getRichContentAttribute($attribute) !== null;
    }

    /**
     * @return array<string, RichContentAttribute>
     */
    public function getRichContentAttributes(): array
    {
        if ($this->richContentAttributes === null) {
            $this->richContentAttributes = [];
            $this->setUpRichContent();
        }

        return $this->richContentAttributes;
    }

    public function renderRichContent(string $attribute): string
    {
        $config = $this->getRichContentAttribute($attribute);

        if ($config !== null) {
            return $config->toHtml();
        }

        $content = $this->getAttribute($attribute);

        return RichContentRenderer::make(is_string($content) || is_array($content) ? $content : null)->toHtml();
    }
}
