<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Standalone;

use AlfaSix\Alfakit\Contracts\CanAccessPanel;
use AlfaSix\Alfakit\Pages\Dashboard;
use AlfaSix\Alfakit\Panel;

/**
 * `starterKit()` panels: the panel's dashboard widgets on the host application's own
 * dashboard (`/dashboard` of the starter kits), rendered by `<AlfakitWidgets />`.
 *
 * A standalone widget grid bound to the starter kit panel instead of the hidden standalone
 * panel: its context token names the panel, so lazy loading and polling
 * (`/{path}/_alfakit/standalone/components`), table widget actions and every other
 * internal request run under that panel, with its guard, `canAccessPanel()` and auth
 * middleware (`verified`), as on the panel's own pages.
 *
 * The widgets are the panel's main dashboard's when the panel registers one (its
 * `getWidgets()` and `getColumns()`), else the panel's widgets (`widgets()`,
 * `discoverWidgets()`) in two columns. Each widget's `canView()`, sort and column span
 * apply as on a dashboard.
 */
class StarterKitDashboard extends Widgets
{
    /** The id of the `starterKit()` panel the widgets belong to. */
    public ?string $panelId = null;

    /** The panel's main dashboard (false: it has none), resolved once per request. */
    protected Dashboard|false|null $dashboard = null;

    /**
     * The payload for a host page, or null when the user may not see the panel's widgets
     * (a guest, `canAccessPanel()` false, a tenancy panel outside a tenant) or the panel has
     * none. Builds the component with the panel current (resource URLs, policies and
     * closures resolve against it), then restores the previous panel.
     *
     * @return array<string, mixed>|null
     */
    public static function payloadFor(Panel $panel): ?array
    {
        if (! static::canRenderFor($panel)) {
            return null;
        }

        $manager = alfakit();
        $previous = $manager->getCurrentPanel();

        if ($previous !== $panel) {
            $manager->setCurrentPanel($panel);
            $panel->boot();
        }

        try {
            $component = static::make(['panelId' => $panel->getId()]);

            return $component->getAllWidgetInstances() === [] ? null : $component->toPayload();
        } finally {
            if ($previous !== $panel) {
                $manager->setCurrentPanel($previous);
            }
        }
    }

    /** Whether the signed-in user may see the panel's widgets on a host page. */
    protected static function canRenderFor(Panel $panel): bool
    {
        if (! $panel->isStarterKit() || $panel->hasTenancy()) {
            return false;
        }

        $user = $panel->auth()->user();

        if ($user === null) {
            return false;
        }

        return ! $user instanceof CanAccessPanel || ! $panel->requiresPanelAccessCheck() || $user->canAccessPanel($panel);
    }

    public function getPanel(): Panel
    {
        return $this->panelId === null ? alfakit()->getCurrentOrDefaultPanel() : alfakit()->getPanel($this->panelId);
    }

    /**
     * Unlike other standalone components, the widgets are the panel's: a signed-in user who
     * may open the panel. Internal requests also run the panel's auth middleware.
     */
    public static function requiresAuthentication(): bool
    {
        return true;
    }

    /**
     * The token must name the panel serving the request (internal requests are routed to
     * it), and only `starterKit()` panels without tenancy render their widgets on host pages.
     */
    public function authorize(): bool
    {
        return $this->panelId !== null
            && alfakit()->getCurrentPanel()?->getId() === $this->panelId
            && static::canRenderFor($this->getPanel());
    }

    public function widgets(): array
    {
        return $this->getDashboard()?->getWidgets() ?? $this->getPanel()->getWidgets();
    }

    public function widgetColumns(): int|array
    {
        return $this->getDashboard()?->getColumns() ?? 2;
    }

    /**
     * Lazy widgets are placeholders on the host page; the renderer fetches them from the
     * panel's standalone components endpoint.
     */
    public function rendersLazyPlaceholders(): bool
    {
        return ! request()->routeIs($this->getPanel()->generateRouteName('internal.standalone.components'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        if ($this->cachedPayload !== null) {
            return $this->cachedPayload;
        }

        $payload = parent::toPayload();
        $payload['endpoints'] = [
            ...$payload['endpoints'],
            'components' => $this->getPanel()->route('internal.standalone.components'),
        ];

        return $this->cachedPayload = $payload;
    }

    /** The panel's main dashboard, when it registers one. */
    protected function getDashboard(): ?Dashboard
    {
        if ($this->dashboard !== null) {
            return $this->dashboard ?: null;
        }

        foreach ($this->getPanel()->getPages() as $page) {
            if (is_a($page, Dashboard::class, true) && $page::isMainDashboard()) {
                /** @var Dashboard $dashboard */
                $dashboard = app($page);

                return $this->dashboard = $dashboard;
            }
        }

        $this->dashboard = false;

        return null;
    }

    protected function flushCaches(): void
    {
        parent::flushCaches();

        $this->dashboard = null;
    }
}
