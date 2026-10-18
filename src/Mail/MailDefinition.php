<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Mail;

use AlfaSix\Alfakit\Mail\Concerns\UsesEditableTemplate;
use Closure;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage as BaseMailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;

/**
 * A mail AlfaKit knows about (`Mails::register()`): listed on the mail preview page with
 * sample data and, when `editable()`, editable by admins with the mail templates plugin.
 *
 * ```php
 * Mails::register(
 *     MailDefinition::make('orders.shipped')
 *         ->label(fn () => __('Order shipped'))
 *         ->group(fn () => __('Orders'))
 *         ->preview(fn () => new OrderShipped(Order::factory()->make()))
 *         ->editable(
 *             mergeTags: ['name' => 'Customer name', 'number' => 'Order number', 'button' => 'Track button'],
 *             subject: 'Order {{ number }} is on its way',
 *             body: '<p>Hi '.MailDefinition::tag('name').',</p>'.MailDefinition::tag('button'),
 *         ),
 * );
 * ```
 */
final class MailDefinition
{
    private string|Closure|null $label = null;

    private string|Closure|null $group = null;

    private string|Closure|null $description = null;

    private ?Closure $preview = null;

    private bool $isEditable = false;

    /** @var array<string, string>|Closure */
    private array|Closure $mergeTags = [];

    private string|Closure|null $defaultSubject = null;

    private string|Closure|null $defaultBody = null;

    private ?Closure $sampleData = null;

    public function __construct(private readonly string $key)
    {
        if (preg_match('/^[a-z0-9][a-z0-9_.\-]*$/', $key) !== 1) {
            throw new InvalidArgumentException("Mail keys are lower-case letters, digits, dots, dashes and underscores; [{$key}] isn't.");
        }
    }

    public static function make(string $key): self
    {
        return new self($key);
    }

    /**
     * The editor marker of a merge tag, for default bodies: `MailDefinition::tag('name')`.
     */
    public static function tag(string $id): string
    {
        return '<span data-type="mergeTag" data-id="'.e($id).'">'.e('{{ '.$id.' }}').'</span>';
    }

    /**
     * A default body from translated lines: each line is escaped and wrapped in `<p>`, and
     * its `{{ id }}` placeholders become merge tags.
     *
     * ```php
     * MailDefinition::paragraphs(__('Hi :name,', ['name' => '{{ name }}']), '{{ button }}');
     * ```
     */
    public static function paragraphs(string ...$lines): string
    {
        $html = '';

        foreach ($lines as $line) {
            $html .= '<p>'.preg_replace_callback(
                '/\{\{\s*([A-Za-z0-9_.\-]+)\s*\}\}/',
                static fn (array $match): string => self::tag($match[1]),
                e($line),
            ).'</p>';
        }

        return $html;
    }

    public function label(string|Closure $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function group(string|Closure|null $group): self
    {
        $this->group = $group;

        return $this;
    }

    public function description(string|Closure|null $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * The sample mail: a `Mailable`, a notification (sent to an example address, or
     * `[$notification, $notifiable]`) or a `MailMessage`. Build it from unsaved models
     * (`new Ticket([...])`, `Model::factory()->make()`): previews never send or save.
     *
     * @param  Closure(): (Mailable|Notification|BaseMailMessage|array{0: Notification, 1: object})  $factory
     */
    public function preview(Closure $factory): self
    {
        $this->preview = $factory;

        return $this;
    }

    /**
     * Let admins edit the subject and body per locale. `mergeTags` (id => label) are the
     * placeholders they may use, filled from the mail's `templateData()`; `subject` and
     * `body` are the defaults the editor starts from.
     *
     * @param  array<string, string>|Closure  $mergeTags
     */
    public function editable(array|Closure $mergeTags, string|Closure|null $subject = null, string|Closure|null $body = null): self
    {
        $this->isEditable = true;
        $this->mergeTags = $mergeTags;
        $this->defaultSubject = $subject;
        $this->defaultBody = $body;

        return $this;
    }

    /**
     * Merge tag values for previews and test sends; by default the preview mail's own
     * `templateData()`.
     *
     * @param  Closure(): array<string, mixed>  $data
     */
    public function sampleData(Closure $data): self
    {
        $this->sampleData = $data;

        return $this;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getLabel(): string
    {
        return $this->resolveString($this->label) ?? $this->key;
    }

    public function getGroup(): ?string
    {
        return $this->resolveString($this->group);
    }

    public function getDescription(): ?string
    {
        return $this->resolveString($this->description);
    }

    /**
     * Whether the mail can be previewed: it has a `preview()`, or an editable default body.
     */
    public function hasPreview(): bool
    {
        return $this->preview !== null || ($this->isEditable && $this->defaultBody !== null);
    }

    public function isEditable(): bool
    {
        return $this->isEditable;
    }

    /**
     * @return array<string, string> id => label
     */
    public function getMergeTags(): array
    {
        $tags = $this->mergeTags instanceof Closure ? ($this->mergeTags)() : $this->mergeTags;
        $resolved = [];

        foreach (is_array($tags) ? $tags : [] as $id => $label) {
            $resolved[(string) $id] = is_string($label) ? $label : (string) $id;
        }

        return $resolved;
    }

    public function getDefaultSubject(): ?string
    {
        return $this->resolveString($this->defaultSubject);
    }

    public function getDefaultBody(): ?string
    {
        return $this->resolveString($this->defaultBody);
    }

    /**
     * The sample mail, or null without a preview.
     *
     * @return Mailable|Notification|BaseMailMessage|array{0: Notification, 1: object}|null
     */
    public function makePreview(): Mailable|Notification|BaseMailMessage|array|null
    {
        if ($this->preview === null) {
            return null;
        }

        $mail = ($this->preview)();

        if (! $mail instanceof Mailable && ! $mail instanceof Notification && ! $mail instanceof BaseMailMessage && ! is_array($mail)) {
            throw new InvalidArgumentException("The preview of mail [{$this->key}] must return a Mailable, a Notification or a MailMessage.");
        }

        return $mail;
    }

    /**
     * Sample merge tag values: `sampleData()`, else the preview's `templateData()`, else
     * the tag labels in brackets.
     *
     * @return array<string, mixed>
     */
    public function getSampleData(): array
    {
        if ($this->sampleData !== null) {
            return ($this->sampleData)();
        }

        $mail = $this->makePreview();
        $mail = is_array($mail) ? $mail[0] : $mail;

        if (is_object($mail) && in_array(UsesEditableTemplate::class, class_uses_recursive($mail), true) && method_exists($mail, 'templateData')) {
            /** @var array<string, mixed> */
            return $mail->templateData();
        }

        return array_map(static fn (string $label): string => '['.$label.']', $this->getMergeTags());
    }

    /**
     * Render the preview mail as it would be sent: its subject and HTML.
     *
     * @return array{subject: string, html: HtmlString}
     */
    public function render(): array
    {
        $mail = $this->makePreview();

        if ($mail === null) {
            if ($this->isEditable && ($body = $this->getDefaultBody()) !== null) {
                $template = EditableTemplates::render($this->key, $this->getDefaultSubject(), $body, $this->getSampleData());

                return ['subject' => $template->subject, 'html' => $template->html];
            }

            throw new InvalidArgumentException("Mail [{$this->key}] has no preview.");
        }

        [$mail, $notifiable] = is_array($mail) ? [$mail[0], $mail[1]] : [$mail, null];

        if ($mail instanceof Notification) {
            $notifiable ??= (new AnonymousNotifiable)->route('mail', 'preview@example.com');
            $mail = method_exists($mail, 'toMail') ? $mail->toMail($notifiable) : null;
        }

        if ($mail instanceof Mailable) {
            $html = $mail->render();

            return ['subject' => $mail->subject, 'html' => new HtmlString($html)];
        }

        if ($mail instanceof BaseMailMessage) {
            return ['subject' => (string) $mail->subject, 'html' => new HtmlString((string) $mail->render())];
        }

        throw new InvalidArgumentException("The preview of mail [{$this->key}] has no mail representation.");
    }

    private function resolveString(string|Closure|null $value): ?string
    {
        $value = $value instanceof Closure ? $value() : $value;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
