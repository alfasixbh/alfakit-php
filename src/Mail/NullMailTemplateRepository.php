<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Mail;

use AlfaSix\Alfakit\Mail\Contracts\MailTemplateRepository;

/**
 * No edited templates: every mail sends its own content.
 */
final class NullMailTemplateRepository implements MailTemplateRepository
{
    public function find(string $key, string $locale): ?array
    {
        return null;
    }
}
