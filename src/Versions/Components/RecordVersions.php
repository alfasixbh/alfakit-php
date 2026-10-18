<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Versions\Components;

use AlfaSix\Alfakit\Schemas\Components\Component;
use Closure;

/**
 * The version list of the history slide-over (`HistoryAction`): each version with its
 * event, author, time and field-by-field changes, and a "Restore this version" button
 * that runs the nested restore action (`actionPath`, e.g. `['history']`).
 */
class RecordVersions extends Component
{
    /** @var array<int, array<string, mixed>>|Closure */
    protected array|Closure $versions = [];

    /** @var array<string, mixed>|Closure|null */
    protected array|Closure|null $restoreAction = null;

    /** @var array<int, string> */
    protected array $actionPath = [];

    protected string $actionComponent = 'page';

    public function __construct(string $key = 'versions')
    {
        $this->key($key);
        $this->type('RecordVersions');
    }

    public static function make(mixed ...$arguments): static
    {
        return parent::make(...$arguments);
    }

    /**
     * @param  array<int, array<string, mixed>>|Closure  $versions
     */
    public function versions(array|Closure $versions): static
    {
        $this->versions = $versions;

        return $this;
    }

    /**
     * The serialised restore action (null when the user may not restore).
     *
     * @param  array<string, mixed>|Closure|null  $action
     */
    public function restoreAction(array|Closure|null $action): static
    {
        $this->restoreAction = $action;

        return $this;
    }

    /**
     * The names of the actions whose modal holds this list, outermost first, and the
     * action endpoint component they belong to.
     *
     * @param  array<int, string>  $path
     */
    public function actionPath(array $path, string $component = 'page'): static
    {
        $this->actionPath = array_values($path);
        $this->actionComponent = $component;

        return $this;
    }

    protected function getSchemaProps(): array
    {
        $versions = $this->evaluate($this->versions);
        $restore = $this->evaluate($this->restoreAction);

        return [
            'versions' => is_array($versions) ? array_values($versions) : [],
            'restoreAction' => is_array($restore) ? $restore : null,
            'actionPath' => $this->actionPath ?: null,
            'actionComponent' => $this->actionComponent === 'page' ? null : $this->actionComponent,
            'emptyLabel' => __('alfakit::actions.history.empty'),
        ];
    }
}
