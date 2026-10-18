<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components;

use AlfaSix\Alfakit\Support\Html as HtmlSanitizer;
use Closure;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Raw HTML. An `Htmlable` (`new HtmlString(...)`, a view) is markup written in code and
 * is rendered as-is; a plain string may come from data, so it is sanitised
 * (`Support\Html::sanitize()`). `->sanitize()` forces sanitising either way,
 * `->sanitize(false)` turns it off for trusted strings.
 */
class Html extends Component
{
    protected string|Htmlable|Closure $content;

    protected bool|Closure|null $shouldSanitize = null;

    public function __construct(string|Htmlable|Closure $content)
    {
        $this->content = $content;
    }

    public static function make(mixed ...$arguments): static
    {
        return parent::make(...$arguments);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->hiddenLabel();
        $this->columnSpanFull();
    }

    public function sanitize(bool|Closure|null $condition = true): static
    {
        $this->shouldSanitize = $condition;

        return $this;
    }

    protected function getSchemaProps(): array
    {
        $content = $this->evaluate($this->content);
        $sanitize = $this->evaluate($this->shouldSanitize) ?? ! $content instanceof Htmlable;
        $html = $content instanceof Htmlable ? $content->toHtml() : (string) $content;

        return ['content' => $sanitize ? HtmlSanitizer::sanitize($html) : $html];
    }
}
