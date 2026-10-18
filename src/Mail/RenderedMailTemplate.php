<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Mail;

use Illuminate\Support\HtmlString;

/**
 * An edited mail template with its merge tags replaced: the subject, the themed HTML
 * and the plain-text part, ready to send.
 */
final readonly class RenderedMailTemplate
{
    public function __construct(
        public string $key,
        public string $locale,
        public string $subject,
        public HtmlString $html,
        public string $text,
    ) {}
}
