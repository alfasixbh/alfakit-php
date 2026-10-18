<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Theming;

/**
 * Slot content for every panel, registered from a service provider (Filament's
 * `FilamentView::registerRenderHook()`). Plugins and packages use it to add content
 * without knowing the panels:
 *
 * ```php
 * public function boot(): void
 * {
 *     Slots::register(Slot::TOPBAR_END, fn (): string => view('status-badge')->render());
 *     Slots::register(Slot::PAGE_START, '<p>Billing</p>', scopes: ListInvoices::class, panels: 'admin');
 * }
 * ```
 *
 * Content, scopes and `when` work like `Panel::slot()`; `panels` limits it to panel ids.
 * Registrations are process-wide configuration: register them once at boot.
 */
final class Slots
{
    /** @var array<int, array{name: string, content: mixed, scopes: array<int, string>, panels: array<int, string>|null, when: array<string, mixed>}> */
    private static array $entries = [];

    /**
     * @param  class-string|array<int, class-string>  $scopes  page, resource, relation manager or widget classes
     * @param  string|array<int, string>|null  $panels  panel ids, or null for every panel
     * @param  array<string, scalar|null|array<int, scalar|null>>  $when  slot data the render must match (`['column' => 'title']`)
     */
    public static function register(string $name, mixed $content, string|array $scopes = [], string|array|null $panels = null, array $when = []): void
    {
        self::$entries[] = [
            'name' => $name,
            'content' => $content,
            'scopes' => array_values((array) $scopes),
            'panels' => $panels === null ? null : array_values((array) $panels),
            'when' => $when,
        ];
    }

    /**
     * Entries for one panel, keyed by slot name, in registration order.
     *
     * @return array<string, array<int, array{content: mixed, scopes: array<int, string>, when: array<string, mixed>}>>
     */
    public static function forPanel(string $panelId): array
    {
        $slots = [];

        foreach (self::$entries as $entry) {
            if ($entry['panels'] !== null && ! in_array($panelId, $entry['panels'], true)) {
                continue;
            }

            $slots[$entry['name']][] = ['content' => $entry['content'], 'scopes' => $entry['scopes'], 'when' => $entry['when']];
        }

        return $slots;
    }

    /**
     * Forget every registration (tests).
     */
    public static function flush(): void
    {
        self::$entries = [];
    }
}
