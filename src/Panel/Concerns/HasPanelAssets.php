<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Panel\Concerns;

use AlfaSix\Alfakit\Support\Assets\Asset;
use AlfaSix\Alfakit\Support\Assets\Assets;

/**
 * `->assets([Css::make(...), Js::make(...)])`: stylesheets and scripts loaded on this
 * panel's pages only (`Assets::register(..., panel: $id)`).
 */
trait HasPanelAssets
{
    /** @var array<int, array{assets: array<Asset>, package: string|null}> */
    protected array $panelAssets = [];

    /**
     * @param  array<Asset>  $assets
     * @param  string|null  $package  `vendor/name` folder local files are published to by `alfakit:assets` (default `app`)
     */
    public function assets(array $assets, ?string $package = null): static
    {
        $this->panelAssets[] = ['assets' => $assets, 'package' => $package];

        return $this;
    }

    /**
     * @return array<Asset>
     */
    public function getAssets(): array
    {
        return array_merge(...array_map(static fn (array $entry): array => $entry['assets'], $this->panelAssets) ?: [[]]);
    }

    /**
     * Hand the assets to the registry for this panel's id (when routes register and when
     * the panel boots, so the id set after `assets()` is used).
     */
    protected function registerPanelAssets(): void
    {
        foreach ($this->panelAssets as ['assets' => $assets, 'package' => $package]) {
            Assets::register($assets, $package, $this->getId());
        }
    }
}
