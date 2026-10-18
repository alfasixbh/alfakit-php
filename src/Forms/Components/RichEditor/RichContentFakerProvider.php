<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\RichEditor;

use AlfaSix\Alfakit\Forms\Components\MarkdownEditor\MarkdownFaker;
use Faker\Provider\Base;

/**
 * Faker provider registered on every `Faker\Generator` the container resolves, so
 * factories can write `fake()->alfakitRichContent()->heading()->paragraphs(3)->toHtml()`.
 * `filamentRichContent()` / `filamentMarkdown()` are aliases for code migrated from Filament.
 */
class RichContentFakerProvider extends Base
{
    public function alfakitRichContent(?RichContentAttribute $attribute = null): RichContentFaker
    {
        return RichContentFaker::make($this->generator, $attribute);
    }

    public function filamentRichContent(?RichContentAttribute $attribute = null): RichContentFaker
    {
        return $this->alfakitRichContent($attribute);
    }

    public function alfakitMarkdown(): MarkdownFaker
    {
        return MarkdownFaker::make($this->generator);
    }

    public function filamentMarkdown(): MarkdownFaker
    {
        return $this->alfakitMarkdown();
    }
}
