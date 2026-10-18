<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Concerns;

use AlfaSix\Alfakit\Support\EnumState;
use AlfaSix\Alfakit\Support\Icons;
use BackedEnum;
use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use UnitEnum;

/**
 * Shared option handling for selects, radios, checkbox lists and select filters.
 *
 * Options may be a flat `value => label` array, a grouped `group => [value => label]`
 * array, an Arrayable, a backed enum class name, or a Closure returning any of these.
 */
trait HasOptions
{
    /** @var array<mixed>|Arrayable|string|Closure|null */
    protected array|Arrayable|string|Closure|null $options = null;

    /** @var array<string, string|Closure> */
    protected array $optionDescriptions = [];

    /** @var array<string, string|Closure> */
    protected array $optionIcons = [];

    /** @var array<string, string|Closure> */
    protected array $optionColors = [];

    /** @var array<string>|Closure */
    protected array|Closure $disabledOptions = [];

    /**
     * @param  array<mixed>|Arrayable|class-string<BackedEnum>|Closure|null  $options
     */
    public function options(array|Arrayable|string|Closure|null $options): static
    {
        $this->options = $options;

        return $this;
    }

    /**
     * ISO 4217 currencies in circulation, named in the user's language:
     * `Select::make('currency')->currencies()->searchable()`.
     *
     * @param  array<int, string>|null  $only  restrict (and order) the list
     */
    public function currencies(?array $only = null): static
    {
        return $this->options(static fn (): array => \AlfaSix\Alfakit\Support\Currency::options($only));
    }

    /**
     * Languages, named in the user's language and in their own:
     * `Select::make('language')->languages(['en', 'ar', 'fr'])`.
     *
     * @param  array<int, string>|null  $only  restrict (and order) the list; all languages by default
     */
    public function languages(?array $only = null): static
    {
        return $this->options(static fn (): array => \AlfaSix\Alfakit\Localization\Languages::options($only));
    }

    /**
     * The languages the current panel offers, e.g. for a "preferred language" profile field.
     */
    public function panelLocales(): static
    {
        return $this->options(static fn (): array => array_map(
            static fn (\AlfaSix\Alfakit\Localization\Locale $locale): string => $locale->getLabel(),
            alfakit()->getCurrentOrDefaultPanel()->getLocales(),
        ));
    }

    /**
     * IANA timezones with their current UTC offset, ordered by offset.
     *
     * @param  array<int, string>|null  $only
     */
    public function timezones(?array $only = null): static
    {
        return $this->options(static fn (): array => \AlfaSix\Alfakit\Localization\Languages::timezones($only));
    }

    /**
     * @param  array<string, string|Closure>  $descriptions
     */
    public function descriptions(array $descriptions): static
    {
        $this->optionDescriptions = $descriptions;

        return $this;
    }

    /**
     * @param  array<int|string, string|Closure>  $icons
     */
    public function icons(array $icons): static
    {
        $this->optionIcons = $icons;

        return $this;
    }

    /**
     * @param  array<int|string, string|Closure>  $colors
     */
    public function colors(array $colors): static
    {
        $this->optionColors = $colors;

        return $this;
    }

    /**
     * @param  array<string>|Closure  $options
     */
    public function disableOptionWhen(array|Closure $options): static
    {
        $this->disabledOptions = $options;

        return $this;
    }

    /**
     * Options normalised to `value => label` (possibly grouped).
     *
     * @return array<mixed>
     */
    public function getOptions(): array
    {
        $options = $this->evaluate($this->options);

        if ($options instanceof Arrayable) {
            $options = $options->toArray();
        }

        if (is_string($options) && enum_exists($options)) {
            // Labels from `HasLabel::getLabel()`, else the headlined case name.
            $options = EnumState::options($options);
        }

        return $options ?? [];
    }

    /**
     * The enum the options were built from (`options(Status::class)`), if any.
     *
     * @return class-string<UnitEnum>|null
     */
    public function getOptionsEnum(): ?string
    {
        $options = $this->options instanceof Closure ? $this->evaluate($this->options) : $this->options;

        return is_string($options) && enum_exists($options) ? $options : null;
    }

    /**
     * Which option details this component takes from an enum built with `options(Status::class)`:
     * any of `description` (`HasDescription`), `icon` (`HasIcon`) and `color` (`HasColor`).
     * Explicit `descriptions()`, `icons()` and `colors()` maps win over the enum.
     *
     * @return array<int, 'description'|'icon'|'color'>
     */
    protected function getEnumOptionDetails(): array
    {
        return [];
    }

    public function getOptionLabel(mixed $value): ?string
    {
        $flat = $this->getFlatOptions();

        return $flat[(string) $value] ?? null;
    }

    /**
     * Options flattened across groups.
     *
     * @return array<string, string>
     */
    public function getFlatOptions(): array
    {
        $flat = [];

        foreach ($this->getOptions() as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $groupedKey => $groupedValue) {
                    $flat[(string) $groupedKey] = (string) $groupedValue;
                }
            } else {
                $flat[(string) $key] = (string) $value;
            }
        }

        return $flat;
    }

    /**
     * Renderer-friendly list of option objects, preserving groups.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSerializedOptions(): array
    {
        $disabled = $this->evaluate($this->disabledOptions) ?? [];
        $enum = ($details = $this->getEnumOptionDetails()) === [] ? null : $this->getOptionsEnum();
        $serializeOption = function (mixed $value, mixed $label) use ($disabled, $enum, $details): array {
            $value = (string) $value;
            $case = $enum === null ? null : EnumState::resolve($value, $enum);

            return array_filter([
                'value' => $value,
                'label' => (string) $label,
                'description' => isset($this->optionDescriptions[$value])
                    ? $this->evaluate($this->optionDescriptions[$value])
                    : ($case !== null && in_array('description', $details, true) ? EnumState::description($case) : null),
                'icon' => isset($this->optionIcons[$value])
                    ? Icons::resolve($this->evaluate($this->optionIcons[$value]))
                    : ($case !== null && in_array('icon', $details, true) ? EnumState::icon($case) : null),
                'color' => isset($this->optionColors[$value])
                    ? $this->evaluate($this->optionColors[$value])
                    : ($case !== null && in_array('color', $details, true) ? EnumState::color($case) : null),
                'disabled' => in_array($value, Arr::wrap($disabled), true) ?: null,
            ], static fn (mixed $v): bool => $v !== null);
        };

        $options = [];

        foreach ($this->getOptions() as $key => $value) {
            if (is_array($value)) {
                $options[] = [
                    'label' => (string) $key,
                    'options' => collect($value)->map(fn ($l, $v): array => $serializeOption($v, $l))->values()->all(),
                ];
            } else {
                $options[] = $serializeOption($key, $value);
            }
        }

        return $options;
    }
}
