<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Infolists\Components;

use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Schemas\Components\Concerns\HasContentSlots;
use AlfaSix\Alfakit\Schemas\Components\Concerns\HasFieldActions;
use AlfaSix\Alfakit\Support\Enums\Alignment;
use AlfaSix\Alfakit\Support\Icons;
use AlfaSix\Alfakit\Support\RecordState;
use AlfaSix\Alfakit\Support\Serializable;
use BackedEnum;
use Closure;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Read-only display of a record attribute. Unlike form fields, entries carry their
 * (formatted) value inside the serialised component.
 */
abstract class Entry extends Component
{
    use HasContentSlots;
    use HasFieldActions;

    protected string $name;

    protected ?Closure $getStateUsing = null;

    protected mixed $defaultState = null;

    protected string|Closure|null $url = null;

    protected bool|Closure $shouldOpenUrlInNewTab = false;

    protected string|Closure|null $tooltip = null;

    protected string|Htmlable|Closure|null $helperText = null;

    protected string|Htmlable|Closure|null $hint = null;

    protected string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $hintIcon = null;

    protected string|Closure|null $hintColor = null;

    protected string|Closure|null $entryPlaceholder = null;

    /** @var array<array<string, mixed>|Closure> */
    protected array $extraEntryWrapperAttributes = [];

    protected Alignment|string|Closure|null $alignment = null;

    final public function __construct(string $name)
    {
        $this->name = $name;
    }

    public static function make(mixed ...$arguments): static
    {
        return parent::make(...$arguments);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getStateUsing(?Closure $callback): static
    {
        $this->getStateUsing = $callback;

        return $this;
    }

    public function state(mixed $state): static
    {
        // Keep the closure itself (evaluated by getState() on the current instance): a
        // wrapper would stay bound to this instance, so copies of the entry (one per item of
        // a RepeatableEntry) would resolve `$record` to the parent record.
        return $this->getStateUsing($state instanceof Closure ? $state : fn (): mixed => $state);
    }

    public function default(mixed $state): static
    {
        $this->defaultState = $state;

        return $this;
    }

    public function url(string|Closure|null $url, bool|Closure $shouldOpenInNewTab = false): static
    {
        $this->url = $url;
        $this->shouldOpenUrlInNewTab = $shouldOpenInNewTab;

        return $this;
    }

    /**
     * The entry's link, sanitised (no `javascript:` URLs).
     */
    public function getUrl(): ?string
    {
        return \AlfaSix\Alfakit\Support\Url::sanitize($this->evaluate($this->url));
    }

    public function openUrlInNewTab(bool|Closure $condition = true): static
    {
        $this->shouldOpenUrlInNewTab = $condition;

        return $this;
    }

    public function tooltip(string|Closure|null $tooltip): static
    {
        $this->tooltip = $tooltip;

        return $this;
    }

    public function helperText(string|Htmlable|Closure|null $text): static
    {
        $this->helperText = $text;

        return $this;
    }

    public function hint(string|Htmlable|Closure|null $hint): static
    {
        $this->hint = $hint;

        return $this;
    }

    public function hintIcon(string|BackedEnum|Htmlable|Closure|null $icon): static
    {
        $this->hintIcon = $icon;

        return $this;
    }

    /**
     * The hint icon, with registered aliases (`Icons::register()`, `alfakit::*`) resolved.
     */
    public function getHintIcon(): ?string
    {
        return Icons::resolve($this->evaluate($this->hintIcon));
    }

    public function hintColor(string|Closure|null $color): static
    {
        $this->hintColor = $color;

        return $this;
    }

    /**
     * HTML attributes for the element wrapping the entry's label and value
     * (`data-slot="entry"`), merged with `extraAttributes()` (classes are joined).
     *
     * @param  array<string, mixed>|Closure  $attributes
     */
    public function extraEntryWrapperAttributes(array|Closure $attributes, bool $merge = false): static
    {
        if ($merge) {
            $this->extraEntryWrapperAttributes[] = $attributes;
        } else {
            $this->extraEntryWrapperAttributes = [$attributes];
        }

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtraEntryWrapperAttributes(): array
    {
        $attributes = [];

        foreach ($this->extraEntryWrapperAttributes as $extraAttributes) {
            $attributes = array_merge($attributes, $this->evaluate($extraAttributes) ?? []);
        }

        return $attributes;
    }

    public function placeholder(string|Closure|null $placeholder): static
    {
        $this->entryPlaceholder = $placeholder;

        return $this;
    }

    public function getState(): mixed
    {
        if ($this->getStateUsing !== null) {
            $state = $this->evaluate($this->getStateUsing);
        } else {
            $record = $this->getRecord();
            $state = $record !== null
                ? RecordState::get($record, $this->name)
                : data_get($this->getContainer()->getRawState(), $this->getStatePath());
        }

        if ($state === null || $state === '' || $state === []) {
            $state = $this->evaluate($this->defaultState) ?? $state;
        }

        return $state;
    }

    /**
     * The value before Eloquent casts, `getStateUsing()` and `default()` (injected into
     * closures as `$rawState`): the record's raw attribute, or the schema state when the
     * entry has no record.
     */
    public function getRawState(): mixed
    {
        $record = $this->getRecord();

        if ($record === null) {
            return data_get($this->getContainer()->getRawState(), $this->getStatePath());
        }

        $attributes = $record->getAttributes();

        return array_key_exists($this->name, $attributes)
            ? $attributes[$this->name]
            : RecordState::get($record, $this->name);
    }

    public function getStatePath(bool $isAbsolute = true): string
    {
        $containerPath = $this->getContainer()->getStatePath();

        return filled($containerPath) && $isAbsolute ? "{$containerPath}.{$this->name}" : $this->name;
    }

    /**
     * Horizontal alignment of the value: `start`, `center`, `end` or `justify`.
     */
    public function alignment(Alignment|string|Closure|null $alignment): static
    {
        $this->alignment = $alignment;

        return $this;
    }

    public function alignStart(bool|Closure $condition = true): static
    {
        return $this->alignment(fn (): ?Alignment => $this->evaluate($condition) ? Alignment::Start : null);
    }

    public function alignCenter(bool|Closure $condition = true): static
    {
        return $this->alignment(fn (): ?Alignment => $this->evaluate($condition) ? Alignment::Center : null);
    }

    public function alignEnd(bool|Closure $condition = true): static
    {
        return $this->alignment(fn (): ?Alignment => $this->evaluate($condition) ? Alignment::End : null);
    }

    public function alignJustify(bool|Closure $condition = true): static
    {
        return $this->alignment(fn (): ?Alignment => $this->evaluate($condition) ? Alignment::Justify : null);
    }

    public function getAlignment(): ?string
    {
        $alignment = $this->evaluate($this->alignment);

        if ($alignment instanceof Alignment) {
            $alignment = $alignment->value;
        }

        // `left` / `right` follow the reading direction like the logical `start` / `end`.
        return match ($alignment) {
            'left' => 'start',
            'right' => 'end',
            default => is_string($alignment) && $alignment !== '' ? $alignment : null,
        };
    }

    /**
     * The value shown to the user. Override for type-specific formatting.
     */
    protected function getFormattedState(): mixed
    {
        return Serializable::value($this->getState());
    }

    public function toSchema(): array
    {
        $helperText = $this->evaluate($this->helperText);
        $hint = $this->evaluate($this->hint);

        return [
            ...parent::toSchema(),
            ...Serializable::compact([
                'name' => $this->name,
                'state' => $this->getFormattedState(),
                'url' => $this->getUrl(),
                'openUrlInNewTab' => $this->evaluate($this->shouldOpenUrlInNewTab) ?: null,
                'tooltip' => $this->evaluate($this->tooltip),
                'helperText' => \AlfaSix\Alfakit\Support\Html::from($helperText),
                'hint' => \AlfaSix\Alfakit\Support\Html::from($hint),
                'hintIcon' => $this->getHintIcon(),
                'hintColor' => $this->evaluate($this->hintColor),
                'inlineLabel' => $this->hasInlineLabel() ?: null,
                'placeholder' => $this->evaluate($this->entryPlaceholder),
                'extraEntryWrapperAttributes' => $this->getExtraEntryWrapperAttributes() ?: null,
                'alignment' => $this->getAlignment(),
                'slots' => $this->getContentSlotsSchema(),
                ...$this->getFieldActionsSchema(),
            ]),
        ];
    }

    /**
     * @return array<mixed>
     */
    protected function resolveDefaultClosureDependencyForEvaluationByName(string $parameterName): array
    {
        return match ($parameterName) {
            'state' => [$this->getState()],
            default => parent::resolveDefaultClosureDependencyForEvaluationByName($parameterName),
        };
    }
}
