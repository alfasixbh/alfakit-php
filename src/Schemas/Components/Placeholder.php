<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components;

use AlfaSix\Alfakit\Support\Labels;
use Closure;
use Illuminate\Contracts\Support\Htmlable;

/**
 * A labelled read-only value inside a form (e.g. "Created at").
 */
class Placeholder extends Component
{
    protected string|Htmlable|Closure|null $content = null;

    protected string|Closure|null $helperText = null;

    public function __construct(string $name)
    {
        $this->key($name);
        // The same derivation as fields, entries and columns: `created_at` -> 'Created at'.
        $this->label(Labels::fromName($name, isRelationshipPath: false));
    }

    public static function make(mixed ...$arguments): static
    {
        return parent::make(...$arguments);
    }

    public function content(string|Htmlable|Closure|null $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function helperText(string|Closure|null $text): static
    {
        $this->helperText = $text;

        return $this;
    }

    public function getContent(): ?string
    {
        $content = $this->evaluate($this->content);

        return \AlfaSix\Alfakit\Support\Html::from($content);
    }

    protected function getSchemaProps(): array
    {
        return [
            'content' => $this->getContent(),
            'helperText' => $this->evaluate($this->helperText),
        ];
    }
}
