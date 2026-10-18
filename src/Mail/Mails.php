<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Mail;

/**
 * The mails AlfaKit and its plugins send, for the mail preview page
 * (`Pages\MailPreview`) and editable templates. Register your own in a service
 * provider's `boot()`:
 *
 * ```php
 * Mails::register(MailDefinition::make('orders.shipped')->label('Order shipped')->preview(fn () => new OrderShipped($order)));
 * ```
 */
final class Mails
{
    /** @var array<string, MailDefinition> */
    private static array $definitions = [];

    public static function register(MailDefinition ...$definitions): void
    {
        foreach ($definitions as $definition) {
            self::$definitions[$definition->getKey()] = $definition;
        }
    }

    public static function forget(string $key): void
    {
        unset(self::$definitions[$key]);
    }

    public static function has(string $key): bool
    {
        return isset(self::$definitions[$key]);
    }

    public static function get(string $key): ?MailDefinition
    {
        return self::$definitions[$key] ?? null;
    }

    /**
     * @return array<string, MailDefinition>
     */
    public static function all(): array
    {
        return self::$definitions;
    }

    /**
     * The definitions admins may edit (`MailDefinition::editable()`).
     *
     * @return array<string, MailDefinition>
     */
    public static function editable(): array
    {
        return array_filter(self::$definitions, static fn (MailDefinition $definition): bool => $definition->isEditable());
    }
}
