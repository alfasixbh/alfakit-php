<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Panel\Concerns;

use AlfaSix\Alfakit\Support\Html;
use Closure;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * The HTML sanitiser config used for `->html()`, `->markdown()`, the `Html` component and
 * `RichContentRenderer` in this panel. Pass a config, or a closure that receives the
 * default one and returns the config to use:
 *
 *     $panel->htmlSanitizer(fn (HtmlSanitizerConfig $config) => $config->allowElement('iframe', ['src']));
 */
trait HasHtmlSanitizer
{
    protected HtmlSanitizerConfig|Closure|null $htmlSanitizer = null;

    protected ?HtmlSanitizerConfig $resolvedHtmlSanitizer = null;

    public function htmlSanitizer(HtmlSanitizerConfig|Closure|null $config): static
    {
        $this->htmlSanitizer = $config;
        $this->resolvedHtmlSanitizer = null;

        return $this;
    }

    public function getHtmlSanitizerConfig(): ?HtmlSanitizerConfig
    {
        if ($this->htmlSanitizer === null) {
            return null;
        }

        if ($this->resolvedHtmlSanitizer !== null) {
            return $this->resolvedHtmlSanitizer;
        }

        $config = $this->htmlSanitizer instanceof Closure
            ? $this->evaluate($this->htmlSanitizer, ['config' => Html::defaultSanitizerConfig()], [HtmlSanitizerConfig::class => Html::defaultSanitizerConfig()])
            : $this->htmlSanitizer;

        return $this->resolvedHtmlSanitizer = $config instanceof HtmlSanitizerConfig ? $config : null;
    }
}
