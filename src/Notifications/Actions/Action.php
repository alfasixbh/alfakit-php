<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Notifications\Actions;

use AlfaSix\Alfakit\Schemas\Components\Concerns\HasColor;
use AlfaSix\Alfakit\Schemas\Components\Concerns\HasIcon;
use AlfaSix\Alfakit\Support\Concerns\Configurable;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\Alfakit\Support\Enums\ActionVariant;
use AlfaSix\Alfakit\Support\Enums\Size;
use AlfaSix\Alfakit\Support\Serializable;
use Closure;

/**
 * A button inside a notification toast or database notification.
 */
class Action
{
    use Configurable;
    use EvaluatesClosures;
    use HasColor;
    use HasIcon;

    protected string|Closure|null $label = null;

    protected string|Closure|null $url = null;

    protected bool|Closure $shouldOpenUrlInNewTab = false;

    protected bool|Closure $shouldMarkAsRead = false;

    protected bool|Closure $shouldMarkAsUnread = false;

    protected bool|Closure $shouldClose = false;

    protected ActionVariant|Closure|null $variant = null;

    protected Size|Closure|null $size = null;

    /** @var array<string, mixed>|null */
    protected ?array $dispatch = null;

    final public function __construct(protected string $name) {}

    public static function make(string $name): static
    {
        $static = new static($name);
        $static->configure();

        return $static;
    }

    /**
     * A notification button from a panel action: its name, label, URL (and new-tab flag),
     * colour and icon. Server-side behaviour (`->action()`) does not carry over; use `url()`.
     */
    public static function fromAction(\AlfaSix\Alfakit\Actions\Action $action): static
    {
        return static::make($action->getName())
            ->label($action->getLabel())
            ->url($action->getUrl(), $action->shouldOpenUrlInNewTab())
            ->color($action->getColor())
            ->icon($action->getIcon());
    }

    public function label(string|Closure|null $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function url(string|Closure|null $url, bool|Closure $shouldOpenInNewTab = false): static
    {
        $this->url = $url;
        $this->shouldOpenUrlInNewTab = $shouldOpenInNewTab;

        return $this;
    }

    public function openUrlInNewTab(bool|Closure $condition = true): static
    {
        $this->shouldOpenUrlInNewTab = $condition;

        return $this;
    }

    public function markAsRead(bool|Closure $condition = true): static
    {
        $this->shouldMarkAsRead = $condition;

        return $this;
    }

    /**
     * In the database notifications panel: mark the notification as unread again.
     */
    public function markAsUnread(bool|Closure $condition = true): static
    {
        $this->shouldMarkAsUnread = $condition;

        return $this;
    }

    public function close(bool|Closure $condition = true): static
    {
        $this->shouldClose = $condition;

        return $this;
    }

    public function button(): static
    {
        $this->variant = ActionVariant::Button;

        return $this;
    }

    public function link(): static
    {
        $this->variant = ActionVariant::Link;

        return $this;
    }

    /**
     * The button size (`Size::ExtraSmall` when not set).
     */
    public function size(Size|Closure|null $size): static
    {
        $this->size = $size;

        return $this;
    }

    public function getSize(): ?Size
    {
        $size = $this->evaluate($this->size);

        return $size instanceof Size ? $size : null;
    }

    /**
     * Emit a frontend event (`window.dispatchEvent(new CustomEvent(name, {detail}))`).
     *
     * @param  array<string, mixed>  $data
     */
    public function dispatch(string $event, array $data = []): static
    {
        $this->dispatch = ['event' => $event, 'data' => $data];

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return Serializable::compact([
            'name' => $this->name,
            'label' => $this->evaluate($this->label) ?? str($this->name)->headline()->toString(),
            'url' => \AlfaSix\Alfakit\Support\Url::sanitize($this->evaluate($this->url)),
            'openUrlInNewTab' => $this->evaluate($this->shouldOpenUrlInNewTab) ?: null,
            'markAsRead' => $this->evaluate($this->shouldMarkAsRead) ?: null,
            'markAsUnread' => $this->evaluate($this->shouldMarkAsUnread) ?: null,
            'close' => $this->evaluate($this->shouldClose) ?: null,
            'color' => $this->getColor(),
            'icon' => $this->getIcon(),
            'size' => $this->getSize()?->value,
            'variant' => ($this->evaluate($this->variant) ?? ActionVariant::Link)->value,
            'dispatch' => $this->dispatch,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        $static = static::make($data['name'] ?? 'action')->label($data['label'] ?? null)->url($data['url'] ?? null, $data['openUrlInNewTab'] ?? false);
        $static->shouldMarkAsRead = $data['markAsRead'] ?? false;
        $static->shouldMarkAsUnread = $data['markAsUnread'] ?? false;
        $static->shouldClose = $data['close'] ?? false;
        $static->color($data['color'] ?? null);
        $static->icon($data['icon'] ?? null);
        $static->size = Size::tryFrom((string) ($data['size'] ?? ''));
        $static->variant = ActionVariant::tryFrom($data['variant'] ?? '') ?? null;
        $static->dispatch = $data['dispatch'] ?? null;

        return $static;
    }
}
