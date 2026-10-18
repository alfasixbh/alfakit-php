<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components;

use AlfaSix\Alfakit\Forms\Components\CodeEditor\Enums\Language;
use Closure;

/**
 * A plain-text code editor with syntax highlighting. The state is the code as a string.
 * The editor's code loads in the browser the first time it is shown; the server render
 * shows the code read-only.
 */
class CodeEditor extends Field
{
    protected Language|string|Closure|null $language = null;

    protected bool|Closure $hasLineNumbers = true;

    protected bool|Closure $isWrapped = false;

    protected int|Closure $tabSize = 4;

    protected int|Closure|null $minHeight = null;

    protected int|Closure|null $maxHeight = null;

    protected int|Closure|null $maxLength = null;

    public function language(Language|string|Closure|null $language): static
    {
        $this->language = $language;

        return $this;
    }

    public function lineNumbers(bool|Closure $condition = true): static
    {
        $this->hasLineNumbers = $condition;

        return $this;
    }

    /**
     * Wrap long lines instead of scrolling horizontally.
     */
    public function wrap(bool|Closure $condition = true): static
    {
        $this->isWrapped = $condition;

        return $this;
    }

    public function tabSize(int|Closure $size): static
    {
        $this->tabSize = $size;

        return $this;
    }

    /**
     * Minimum editor height in pixels.
     */
    public function minHeight(int|Closure|null $pixels): static
    {
        $this->minHeight = $pixels;

        return $this;
    }

    /**
     * Maximum editor height in pixels; taller code scrolls.
     */
    public function maxHeight(int|Closure|null $pixels): static
    {
        $this->maxHeight = $pixels;

        return $this;
    }

    public function maxLength(int|Closure|null $length): static
    {
        $this->maxLength = $length;

        return $this;
    }

    public function getLanguage(): ?string
    {
        $language = $this->evaluate($this->language);

        return $language instanceof Language ? $language->value : $language;
    }

    protected function getTypeValidationRules(): array
    {
        $rules = ['string'];

        if (($max = $this->evaluate($this->maxLength)) !== null) {
            $rules[] = "max:{$max}";
        }

        return $rules;
    }

    protected function getSchemaProps(): array
    {
        return [
            'language' => $this->getLanguage(),
            'lineNumbers' => (bool) $this->evaluate($this->hasLineNumbers),
            'wrap' => $this->evaluate($this->isWrapped) ?: null,
            'tabSize' => $this->evaluate($this->tabSize),
            'minHeight' => $this->evaluate($this->minHeight),
            'maxHeight' => $this->evaluate($this->maxHeight),
            'maxLength' => $this->evaluate($this->maxLength),
        ];
    }
}
