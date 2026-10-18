<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Mail;

use Illuminate\Notifications\Messages\MailMessage as BaseMailMessage;
use Illuminate\Support\HtmlString;

/**
 * A notification email in the AlfaKit mail theme (`MailTheme`): use it instead of
 * Laravel's `MailMessage` in `toMail()`. Lines, actions, greetings and `markdown()`
 * views work as usual; `view()` still sends your own Blade view untouched.
 *
 * ```php
 * public function toMail(object $notifiable): MailMessage
 * {
 *     return (new \AlfaSix\Alfakit\Mail\MailMessage)
 *         ->subject('Your export is ready')
 *         ->line('Download it within 24 hours.')
 *         ->action('Download', $url);
 * }
 * ```
 */
class MailMessage extends BaseMailMessage
{
    /** The Markdown view AlfaKit renders notifications with (Laravel's, with a branded salutation). */
    public const VIEW = 'alfakit::mail.notification';

    /** Pre-rendered HTML and text (an edited template), sent instead of the Markdown view. */
    protected ?RenderedMailTemplate $rendered = null;

    public function __construct()
    {
        $this->markdown = self::VIEW;
        $this->useTheme();
    }

    /**
     * @param  string  $view
     * @param  array<string, mixed>  $data
     * @return $this
     */
    public function markdown($view, array $data = [])
    {
        parent::markdown($view, $data);
        $this->useTheme();

        return $this;
    }

    /**
     * Send a rendered edited template (`UsesEditableTemplate`) instead of the lines.
     */
    /**
     * @return $this
     */
    public function rendered(RenderedMailTemplate $template): static
    {
        $this->rendered = $template;
        $this->subject($template->subject);
        $this->view = [
            'html' => static fn (): HtmlString => $template->html,
            'text' => static fn (): HtmlString => new HtmlString($template->text),
        ];

        return $this;
    }

    public function getRendered(): ?RenderedMailTemplate
    {
        return $this->rendered;
    }

    /**
     * The plain-text part, as sent.
     */
    public function renderText(): HtmlString
    {
        if ($this->rendered !== null) {
            return new HtmlString($this->rendered->text);
        }

        return MailTheme::renderText((string) $this->markdown, $this->data());
    }

    /**
     * Route the Markdown through `MailTheme` (the mail channel sends `$view` when set).
     */
    protected function useTheme(): void
    {
        if (! MailTheme::enabled()) {
            return;
        }

        $this->view = [
            'html' => fn (array $data): HtmlString => MailTheme::render((string) $this->markdown, [...$data, ...$this->data()]),
            'text' => fn (array $data): HtmlString => MailTheme::renderText((string) $this->markdown, [...$data, ...$this->data()]),
        ];
    }
}
