<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands\Support;

/**
 * Curated sets of first-party plugins for `alfakit:setup --bundle=…`. Each plugin is added
 * like `alfakit:add` adds it (Composer, registration, migrations), with the bundle's
 * defaults chained onto the registration: a navigation group, so a bundle lands tidy.
 */
final class PluginBundles
{
    /**
     * @var array<string, array{label: string, description: string, plugins: array<string, string>}>
     */
    public const BUNDLES = [
        'saas' => [
            'label' => 'SaaS starter',
            'description' => 'Teams, subscriptions, roles, feature flags and product analytics',
            'plugins' => [
                'teams' => '',
                'billing' => '',
                'shield' => "->navigationGroup('Settings')",
                'feature-flags' => "->navigationGroup('Settings')",
                'analytics' => "->navigationGroup('Insights')",
            ],
        ],
        'content' => [
            'label' => 'Content site',
            'description' => 'Pages, public forms, media, analytics and translations',
            'plugins' => [
                'cms' => "->navigationGroup('Content')",
                'forms' => "->navigationGroup('Content')",
                'media-library' => '',
                'analytics' => "->navigationGroup('Insights')",
                'translation-manager' => "->navigationGroup('Settings')",
            ],
        ],
        'backoffice' => [
            'label' => 'Back office',
            'description' => 'Invoices, approvals, boards, files and an audit log',
            'plugins' => [
                'invoices' => "->navigationGroup('Finance')",
                'workflows' => "->navigationGroup('Operations')",
                'kanban' => '',
                'file-manager' => "->navigationGroup('Operations')",
                'activity-log' => "->navigationGroup('Settings')",
            ],
        ],
        'support' => [
            'label' => 'Customer support',
            'description' => 'A helpdesk with live chat, comments on records and AI assistance',
            'plugins' => [
                'helpdesk' => "->navigationGroup('Support')",
                'comments' => '',
                'ai' => "->navigationGroup('Settings')",
            ],
        ],
        'ops' => [
            'label' => 'Ops and safety',
            'description' => 'Queues and logs, backups, health checks and outgoing webhooks',
            'plugins' => [
                'ops' => "->navigationGroup('System')",
                'backup' => "->navigationGroup('System')",
                'health' => "->navigationGroup('System')",
                'webhooks' => "->navigationGroup('System')",
            ],
        ],
    ];

    public static function has(string $bundle): bool
    {
        return isset(self::BUNDLES[$bundle]);
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::BUNDLES);
    }

    /**
     * The plugin slugs of one bundle, in install order.
     *
     * @return list<string>
     */
    public static function plugins(string $bundle): array
    {
        return array_keys(self::BUNDLES[$bundle]['plugins'] ?? []);
    }

    /**
     * The default calls chained onto a plugin's registration when a bundle adds it.
     */
    public static function defaults(string $bundle, string $slug): string
    {
        return self::BUNDLES[$bundle]['plugins'][$slug] ?? '';
    }

    /**
     * `bundle => "Label: description (plugins)"` for prompts.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::BUNDLES as $name => $bundle) {
            $options[$name] = "{$bundle['label']}: {$bundle['description']} (".implode(', ', array_keys($bundle['plugins'])).')';
        }

        return $options;
    }
}
