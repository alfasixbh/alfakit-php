<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\RichEditor\Models\Contracts;

use AlfaSix\Alfakit\Forms\Components\RichEditor\RichContentAttribute;

/**
 * A model whose attributes hold rich editor content configured on the model
 * (`setUpRichContent()`). Use the `InteractsWithRichContent` trait to implement it.
 */
interface HasRichContent
{
    /**
     * Register the attributes with `$this->registerRichContent('content')->...`.
     */
    public function setUpRichContent(): void;

    public function getRichContentAttribute(string $attribute): ?RichContentAttribute;

    public function hasRichContentAttribute(string $attribute): bool;

    /**
     * The attribute's content as display HTML (sanitised, custom blocks and merge tags
     * rendered, private images signed).
     */
    public function renderRichContent(string $attribute): string;
}
