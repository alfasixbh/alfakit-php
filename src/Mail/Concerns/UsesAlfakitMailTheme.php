<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Mail\Concerns;

use AlfaSix\Alfakit\Mail\MailTheme;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Mail\Markdown;

/**
 * For a `Mailable` with a Markdown view (`new Content(markdown: ...)`): render it in the
 * AlfaKit mail theme (`MailTheme`) instead of the application's mail theme.
 *
 * @phpstan-require-extends \Illuminate\Mail\Mailable
 */
trait UsesAlfakitMailTheme
{
    /**
     * @return Markdown
     */
    protected function markdownRenderer()
    {
        if (MailTheme::enabled()) {
            return MailTheme::renderer();
        }

        return tap(Container::getInstance()->make(Markdown::class), function (Markdown $markdown): void {
            $markdown->theme($this->theme ?: Container::getInstance()->get(ConfigRepository::class)->get('mail.markdown.theme', 'default'));
        });
    }
}
