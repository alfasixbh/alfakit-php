<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Mail\Concerns;

use AlfaSix\Alfakit\Mail\EditableTemplates;
use AlfaSix\Alfakit\Mail\MailMessage;
use AlfaSix\Alfakit\Mail\RenderedMailTemplate;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Notifications\Messages\MailMessage as BaseMailMessage;

/**
 * A mail admins can edit (`MailDefinition::editable()`, the mail templates plugin). The
 * mail names its template and the merge tag values; it keeps its own content until an
 * admin edits the template for the locale it is sent in.
 *
 * Mailables wrap their envelope subject and content:
 *
 * ```php
 * public function envelope(): Envelope
 * {
 *     return new Envelope(subject: $this->templateSubject(__('Order :number shipped', [...])));
 * }
 *
 * public function content(): Content
 * {
 *     return $this->templateContent(new Content(markdown: 'mail.orders.shipped'));
 * }
 * ```
 *
 * Notifications wrap their message: `return $this->templateMailMessage((new MailMessage)->...);`
 */
trait UsesEditableTemplate
{
    /**
     * The key of the template (`MailDefinition::make($key)`).
     */
    abstract public function templateKey(): string;

    /**
     * Merge tag values: strings are escaped, `Htmlable` values (`EditableTemplates::button()`)
     * are inserted as they are.
     *
     * @return array<string, mixed>
     */
    abstract public function templateData(): array;

    /**
     * The edited template rendered with this mail's data, or null when it isn't edited.
     */
    public function editedTemplate(?string $poweredBy = null, ?string $defaultSubject = null): ?RenderedMailTemplate
    {
        return EditableTemplates::renderEdited($this->templateKey(), $this->templateData(), $poweredBy, defaultSubject: $defaultSubject);
    }

    /**
     * The edited subject (merge tags filled), else `$default`.
     */
    protected function templateSubject(string $default): string
    {
        return EditableTemplates::subject($this->templateKey(), $this->templateData(), $default);
    }

    /**
     * The edited body as the mail content, else `$default`.
     */
    protected function templateContent(Content $default, ?string $poweredBy = null): Content
    {
        $template = $this->editedTemplate($poweredBy);

        if ($template === null) {
            return $default;
        }

        return new Content(view: 'alfakit::mail.raw', text: 'alfakit::mail.raw-text', with: [
            'alfakitHtml' => $template->html,
            'alfakitText' => $template->text,
        ]);
    }

    /**
     * The edited template as the notification email, else `$default` (its subject is
     * still replaced when only the subject is edited).
     */
    protected function templateMailMessage(BaseMailMessage $default, ?string $poweredBy = null): BaseMailMessage
    {
        $template = $this->editedTemplate($poweredBy, $default->subject);

        if ($template === null) {
            return $default->subject($this->templateSubject((string) $default->subject));
        }

        if ($default instanceof MailMessage) {
            return $default->rendered($template);
        }

        return $default->subject($template->subject)->view(['html' => 'alfakit::mail.raw', 'text' => 'alfakit::mail.raw-text'], [
            'alfakitHtml' => $template->html,
            'alfakitText' => $template->text,
        ]);
    }
}
