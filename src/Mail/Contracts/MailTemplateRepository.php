<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Mail\Contracts;

/**
 * Where edited mail templates are stored (`alfasix/alfakit-mail-templates` binds a
 * database repository). AlfaKit's default finds none, so mails keep their own content.
 */
interface MailTemplateRepository
{
    /**
     * The edited subject and body (RichEditor HTML with merge tags) of `$key` in exactly
     * `$locale`, or null when it isn't edited. A null subject or body keeps the default.
     *
     * @return array{subject: ?string, body: ?string}|null
     */
    public function find(string $key, string $locale): ?array;
}
