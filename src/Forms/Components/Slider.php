<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components;

use AlfaSix\Alfakit\Forms\Components\Slider\Enums\Behavior;
use AlfaSix\Alfakit\Forms\Components\Slider\Enums\PipsMode;
use Closure;
use Illuminate\Support\Arr;

/**
 * A numeric slider. The state is a number, or a list of numbers when the slider has
 * several thumbs (a range): give it an array default, e.g. `->default([20, 80])`, or
 * call `multiple()`.
 */
class Slider extends Field
{
    /** Pips drawn at most, so a fine step on a long track stays readable. */
    public const MAX_PIPS = 101;

    protected int|float|Closure $minValue = 0;

    protected int|float|Closure $maxValue = 100;

    protected int|float|Closure|null $step = null;

    protected int|Closure|null $decimalPlaces = null;

    protected bool|Closure|null $isMultiple = null;

    /** @var bool|array<bool>|Closure */
    protected bool|array|Closure $hasTooltips = false;

    protected bool|Closure $hasPips = false;

    protected int|float|Closure|null $pipsDensity = null;

    /** @var bool|array<bool>|Closure */
    protected bool|array|Closure $isTrackFilled = false;

    protected bool|Closure $isVertical = false;

    protected bool|Closure $isTopToBottom = false;

    protected int|float|Closure|null $minDifference = null;

    protected int|float|Closure|null $maxDifference = null;

    protected PipsMode|Closure|null $pipsMode = null;

    /** @var array<int|float>|int|Closure|null */
    protected array|int|Closure|null $pipsValues = null;

    protected bool|Closure $hasSteppedPips = false;

    protected ?Closure $pipsFilter = null;

    /** @var array<int|float>|int|float|Closure|null */
    protected array|int|float|Closure|null $rangePadding = null;

    protected bool|Closure|null $isRtl = null;

    /** @var array<Behavior|string>|Behavior|Closure|null */
    protected array|Behavior|Closure|null $behavior = [Behavior::Tap];

    /** @var array<string, int|float|array<int|float>>|Closure */
    protected array|Closure $nonLinearPoints = [];

    public function minValue(int|float|Closure $value): static
    {
        $this->minValue = $value;

        return $this;
    }

    public function maxValue(int|float|Closure $value): static
    {
        $this->maxValue = $value;

        return $this;
    }

    /**
     * The bounds of the track (Filament's `range(minValue:, maxValue:)`).
     */
    public function range(int|float|Closure $minValue, int|float|Closure $maxValue): static
    {
        return $this->minValue($minValue)->maxValue($maxValue);
    }

    public function step(int|float|Closure|null $step): static
    {
        $this->step = $step;

        return $this;
    }

    public function decimalPlaces(int|Closure|null $places): static
    {
        $this->decimalPlaces = $places;

        return $this;
    }

    /**
     * Several thumbs; the state is a sorted list of numbers.
     */
    public function multiple(bool|Closure $condition = true): static
    {
        $this->isMultiple = $condition;

        return $this;
    }

    /**
     * Show each thumb's value above it. An array sets it per thumb (`[true, false]`:
     * only the first thumb shows its value).
     *
     * @param  bool|array<bool>|Closure  $condition
     */
    public function tooltips(bool|array|Closure $condition = true): static
    {
        $this->hasTooltips = $condition;

        return $this;
    }

    /**
     * Tick marks with labels along the track. Without a mode: every `$density` units
     * (every step by default, at most 11 labelled ticks). With a `PipsMode`, the labelled
     * pips follow `pipsValues()` and `$density` is the spacing of unlabelled ticks in
     * percent of the track.
     */
    public function pips(bool|PipsMode|Closure $condition = true, int|float|Closure|null $density = null): static
    {
        if ($condition instanceof PipsMode) {
            $this->pipsMode = $condition;
            $condition = true;
        }

        $this->hasPips = $condition;
        $this->pipsDensity = $density;

        return $this;
    }

    /**
     * The percentages (`PipsMode::Positions`), number of pips (`PipsMode::Count`) or
     * values (`PipsMode::Values`) of the labelled pips.
     *
     * @param  array<int|float>|int|Closure|null  $values
     */
    public function pipsValues(array|int|Closure|null $values): static
    {
        $this->pipsValues = $values;

        return $this;
    }

    public function pipsMode(PipsMode|Closure|null $mode): static
    {
        $this->pipsMode = $mode;

        return $this;
    }

    /**
     * Spacing of the pips: units without a mode, percent of the track with one.
     */
    public function pipsDensity(int|float|Closure|null $density): static
    {
        $this->pipsDensity = $density;

        return $this;
    }

    /**
     * Round labelled pips to values the thumbs can take (the step of their section of a
     * non-linear track), so no label sits where the slider can't stop.
     */
    public function steppedPips(bool|Closure $condition = true): static
    {
        $this->hasSteppedPips = $condition;

        return $this;
    }

    /**
     * Decide per pip, on the server: `fn (int|float $value, int $type): int` returns `1`
     * for a large label, `2` for a small label, `0` for a tick without a label and `-1`
     * to hide the pip. Every step (or `$density` unit) is offered. Filament's `RawJs`
     * filters become a PHP closure (`$value % 50 === 0 ? 1 : …`).
     */
    public function pipsFilter(?Closure $callback): static
    {
        $this->pipsFilter = $callback;

        return $this;
    }

    /**
     * Keep the thumbs this far from the ends of the track (in values); an array gives the
     * start and end padding separately, `[10, 20]`. Also enforced by validation.
     *
     * @param  array<int|float>|int|float|Closure|null  $padding
     */
    public function rangePadding(array|int|float|Closure|null $padding): static
    {
        $this->rangePadding = $padding;

        return $this;
    }

    /**
     * Put the minimum on the right. Sliders follow the panel's text direction by
     * default; `rtl(false)` keeps a slider left to right in a right-to-left panel.
     */
    public function rtl(bool|Closure|null $condition = true): static
    {
        $this->isRtl = $condition;

        return $this;
    }

    /**
     * Pointer behaviour, `[Behavior::Tap, Behavior::Drag]`; default `[Behavior::Tap]`,
     * `null` for none.
     *
     * @param  array<Behavior|string>|Behavior|Closure|null  $behavior
     */
    public function behavior(array|Behavior|Closure|null $behavior): static
    {
        $this->behavior = $behavior;

        return $this;
    }

    /**
     * A non-linear track: `['20%' => 50, '50%' => 75]` puts 50 at a fifth of the track and
     * 75 in the middle. `['20%' => [50, 5]]` also sets the step from that point on. Steps
     * of non-linear sections are not validated.
     *
     * @param  array<string, int|float|array<int|float>>|Closure  $points
     */
    public function nonLinearPoints(array|Closure $points): static
    {
        $this->nonLinearPoints = $points;

        return $this;
    }

    /**
     * Colour the track up to the thumb (or between thumbs). With several thumbs, an array
     * picks the sections to fill, one more than there are thumbs: `[false, true, false]`
     * fills only the part between two thumbs.
     *
     * @param  bool|array<bool>|Closure  $condition
     */
    public function fillTrack(bool|array|Closure $condition = true): static
    {
        $this->isTrackFilled = $condition;

        return $this;
    }

    /**
     * A vertical track, with the minimum at the bottom; `isTopToBottom: true` puts the
     * minimum at the top (as does Filament's `vertical()->rtl(false)`).
     */
    public function vertical(bool|Closure $condition = true, bool|Closure $isTopToBottom = false): static
    {
        $this->isVertical = $condition;
        $this->isTopToBottom = $isTopToBottom;

        return $this;
    }

    public function isVertical(): bool
    {
        return (bool) $this->evaluate($this->isVertical);
    }

    public function isTopToBottom(): bool
    {
        return $this->isVertical() && ((bool) $this->evaluate($this->isTopToBottom) || $this->evaluate($this->isRtl) === false);
    }

    /**
     * @return bool|list<bool>
     */
    protected function evaluateFlags(mixed $value): bool|array
    {
        $value = $this->evaluate($value);

        return is_array($value) ? array_values(array_map(static fn (mixed $flag): bool => (bool) $flag, $value)) : (bool) $value;
    }

    public function minDifference(int|float|Closure|null $difference): static
    {
        $this->minDifference = $difference;

        return $this;
    }

    public function maxDifference(int|float|Closure|null $difference): static
    {
        $this->maxDifference = $difference;

        return $this;
    }

    /**
     * @return array{0: int|float, 1: int|float} Start and end padding, in values
     */
    public function getRangePadding(): array
    {
        $padding = $this->evaluate($this->rangePadding);

        if ($padding === null) {
            return [0, 0];
        }

        $padding = array_values(Arr::wrap($padding));
        $start = is_numeric($padding[0] ?? null) ? max(0, $padding[0] + 0) : 0;
        $end = is_numeric($padding[1] ?? null) ? max(0, $padding[1] + 0) : $start;

        return [$start, $end];
    }

    /**
     * The lowest and highest value a thumb can take (the track minus `rangePadding()`).
     *
     * @return array{0: int|float, 1: int|float}
     */
    public function getSelectableRange(): array
    {
        [$start, $end] = $this->getRangePadding();

        return [$this->getMinValue() + $start, $this->getMaxValue() - $end];
    }

    /**
     * @return list<string>|null The behaviours, or null for the default (`tap` only)
     */
    public function getBehavior(): ?array
    {
        $behavior = $this->evaluate($this->behavior);
        $values = [];

        foreach (Arr::wrap($behavior) as $item) {
            $value = $item instanceof Behavior ? $item : (is_string($item) ? Behavior::tryFrom($item) : null);

            if ($value !== null) {
                $values[] = $value->value;
            }
        }

        $values = array_values(array_unique($values));

        return $values === [Behavior::Tap->value] ? null : $values;
    }

    /**
     * The points of a non-linear track between its ends, sorted by position.
     *
     * @return list<array{percent: float, value: int|float, step?: int|float}>
     */
    public function getNonLinearPoints(): array
    {
        $points = [];

        foreach ((array) $this->evaluate($this->nonLinearPoints) as $position => $point) {
            $percent = is_string($position) ? (float) rtrim(trim($position), '%') : (float) $position;
            $point = array_values(Arr::wrap($point));

            if ($percent <= 0 || $percent >= 100 || ! is_numeric($point[0] ?? null)) {
                continue;
            }

            $entry = ['percent' => $percent, 'value' => $point[0] + 0];

            if (is_numeric($point[1] ?? null) && $point[1] > 0) {
                $entry['step'] = $point[1] + 0;
            }

            $points[] = $entry;
        }

        usort($points, static fn (array $a, array $b): int => $a['percent'] <=> $b['percent']);

        return $points;
    }

    /**
     * Sections of the track: `[startPercent, endPercent, startValue, endValue, step]`.
     *
     * @return list<array{0: float, 1: float, 2: int|float, 3: int|float, 4: int|float}>
     */
    protected function getTrackSections(): array
    {
        $points = [['percent' => 0.0, 'value' => $this->getMinValue(), 'step' => $this->getStep()], ...$this->getNonLinearPoints(), ['percent' => 100.0, 'value' => $this->getMaxValue()]];
        $sections = [];
        $step = $this->getStep();

        for ($i = 0; $i < count($points) - 1; $i++) {
            $step = $points[$i]['step'] ?? $step;
            $sections[] = [$points[$i]['percent'], $points[$i + 1]['percent'], $points[$i]['value'], $points[$i + 1]['value'], $step];
        }

        return $sections;
    }

    /**
     * Where a value sits on the track, 0–100 (non-linear tracks included).
     */
    public function valueToPercent(int|float $value): float
    {
        foreach ($this->getTrackSections() as [$fromPercent, $toPercent, $fromValue, $toValue]) {
            if ($value <= $toValue || $toPercent >= 100) {
                if ($toValue == $fromValue) {
                    return (float) $fromPercent;
                }

                $ratio = max(0, min(1, ($value - $fromValue) / ($toValue - $fromValue)));

                return $fromPercent + $ratio * ($toPercent - $fromPercent);
            }
        }

        return 100.0;
    }

    /**
     * The value at a position on the track, 0–100, optionally rounded to its section's step.
     */
    public function percentToValue(float $percent, bool $snap = false): int|float
    {
        foreach ($this->getTrackSections() as [$fromPercent, $toPercent, $fromValue, $toValue, $step]) {
            if ($percent <= $toPercent || $toPercent >= 100) {
                $ratio = $toPercent == $fromPercent ? 0 : max(0, min(1, ($percent - $fromPercent) / ($toPercent - $fromPercent)));
                $value = $fromValue + $ratio * ($toValue - $fromValue);

                if ($snap && $step > 0) {
                    $value = min($toValue, $fromValue + round(($value - $fromValue) / $step) * $step);
                }

                return $this->roundNumber($value);
            }
        }

        return $this->getMaxValue();
    }

    /**
     * The pips the renderer draws: value, position (0–100) and type (`1` large label,
     * `2` small label, `0` tick only). Null when pips are off.
     *
     * @return list<array{value: int|float, percent: float, type: int}>|null
     */
    public function getPips(): ?array
    {
        if (! $this->evaluate($this->hasPips)) {
            return null;
        }

        $min = $this->getMinValue();
        $max = $this->getMaxValue();

        if (! ($max > $min)) {
            return [];
        }

        $mode = $this->evaluate($this->pipsMode);
        $density = $this->evaluate($this->pipsDensity);
        $density = is_numeric($density) && $density > 0 ? $density + 0 : null;
        $candidates = $mode instanceof PipsMode ? $this->getModePips($mode, $density) : $this->getDefaultPips($density);

        if ($this->evaluate($this->hasSteppedPips)) {
            foreach ($candidates as &$candidate) {
                if ($candidate['type'] !== 0) {
                    $candidate['value'] = $this->percentToValue($this->valueToPercent($candidate['value']), snap: true);
                }
            }

            unset($candidate);
        }

        $pips = [];

        foreach ($candidates as $candidate) {
            $value = $this->roundNumber($candidate['value']);
            $type = $candidate['type'];

            if ($this->pipsFilter !== null) {
                $type = (int) $this->evaluate($this->pipsFilter, ['value' => $value, 'type' => $type]);
            }

            $key = (string) $value;

            // One pip per value; a labelled one wins over a plain tick.
            if ($type < 0 || $value < $min || $value > $max || (isset($pips[$key]) && ($pips[$key]['type'] === 1 || $type === 0))) {
                continue;
            }

            $pips[$key] = ['value' => $value, 'percent' => round($this->valueToPercent($value), 4), 'type' => min(2, $type)];
        }

        $pips = array_values($pips);
        usort($pips, static fn (array $a, array $b): int => $a['percent'] <=> $b['percent']);

        return $pips;
    }

    /**
     * Every `$density` units (every step by default); with a `pipsFilter()` all of them
     * go through it, otherwise at most 11 are labelled.
     *
     * @return list<array{value: int|float, type: int}>
     */
    protected function getDefaultPips(int|float|null $density): array
    {
        $min = $this->getMinValue();
        $max = $this->getMaxValue();
        $interval = $density ?? $this->getStep();

        while (($max - $min) / $interval > self::MAX_PIPS - 1) {
            $interval *= 2;
        }

        $values = [];

        for ($i = 0; $min + $i * $interval < $max + $interval / 1e6; $i++) {
            $values[] = $this->roundNumber(min($min + $i * $interval, $max));
        }

        if (end($values) != $max) {
            $values[] = $max;
        }

        $labelEvery = max(1, (int) ceil((count($values) - 1) / 10));
        $last = count($values) - 1;

        return array_map(static fn (int|float $value, int $index): array => [
            'value' => $value,
            'type' => $index % $labelEvery === 0 || $index === $last ? 1 : 0,
        ], $values, array_keys($values));
    }

    /**
     * @return list<array{value: int|float, type: int}>
     */
    protected function getModePips(PipsMode $mode, int|float|null $density): array
    {
        $values = $this->evaluate($this->pipsValues);
        $pips = [];

        switch ($mode) {
            case PipsMode::Steps:
                $points = [$this->getMinValue(), $this->getMaxValue(), ...array_column($this->getNonLinearPoints(), 'value')];

                foreach ($this->getTrackSections() as [, , $fromValue, $toValue, $step]) {
                    $count = $step > 0 ? (int) floor(($toValue - $fromValue) / $step + 1e-9) : 0;
                    $every = max(1, (int) ceil($count / (self::MAX_PIPS - 1)));

                    for ($i = 0; $i <= $count; $i += $every) {
                        $value = $fromValue + $i * $step;
                        $pips[] = ['value' => $value, 'type' => in_array($value, $points, false) ? 1 : 2];
                    }

                    $pips[] = ['value' => $toValue, 'type' => 1];
                }

                break;

            case PipsMode::Positions:
                foreach (Arr::wrap($values) as $percent) {
                    if (is_numeric($percent)) {
                        $pips[] = ['value' => $this->percentToValue(max(0, min(100, (float) $percent))), 'type' => 1];
                    }
                }

                break;

            case PipsMode::Count:
                $count = is_numeric($values) ? max(2, min(self::MAX_PIPS, (int) $values)) : 2;

                for ($i = 0; $i < $count; $i++) {
                    $pips[] = ['value' => $this->percentToValue($i * 100 / ($count - 1)), 'type' => 1];
                }

                break;

            case PipsMode::Values:
                foreach (Arr::wrap($values) as $value) {
                    if (is_numeric($value)) {
                        $pips[] = ['value' => $value + 0, 'type' => 1];
                    }
                }

                break;
        }

        // `$density`: unlabelled ticks every so many percent of the track.
        if ($density !== null) {
            $density = max($density, 100 / (self::MAX_PIPS - 1));

            for ($percent = 0.0; $percent <= 100 + 1e-9; $percent += $density) {
                $pips[] = ['value' => $this->percentToValue(min(100, $percent)), 'type' => 0];
            }
        }

        return $pips;
    }

    protected function roundNumber(int|float $value): int|float
    {
        $places = $this->evaluate($this->decimalPlaces);

        if ($places !== null) {
            return $places <= 0 ? (int) round($value) : round($value, $places);
        }

        $rounded = round($value, 10);

        return floor($rounded) == $rounded && abs($rounded) < PHP_INT_MAX ? (int) $rounded : $rounded;
    }

    public function getMinValue(): int|float
    {
        return $this->evaluate($this->minValue);
    }

    public function getMaxValue(): int|float
    {
        return $this->evaluate($this->maxValue);
    }

    public function getStep(): int|float
    {
        $step = $this->evaluate($this->step);

        if ($step !== null && $step > 0) {
            return $step;
        }

        $places = $this->evaluate($this->decimalPlaces);

        return $places === null || $places <= 0 ? 1 : 10 ** -$places;
    }

    public function isMultiple(): bool
    {
        $multiple = $this->evaluate($this->isMultiple);

        if ($multiple !== null) {
            return (bool) $multiple;
        }

        if ($this->hasDefault() && is_array($this->getDefaultState())) {
            return true;
        }

        // A list filled by the server (an `array` cast, `formData`, `fill()`) is a range too,
        // so the renderer shows two thumbs instead of one clamped to the minimum. Only the
        // server-filled state counts: a list sent by the browser can't turn a number into a range.
        if (! $this->hasContainer()) {
            return false;
        }

        $container = $this->getContainer();
        $path = $this->getStatePath();
        $server = $container->getServerStateAt('');

        // A number on the server pins the field to one thumb whatever the browser sends; an
        // empty server value (an action form or a create page validating posted data) leaves
        // the shape to the posted value.
        $serverValue = is_array($server) ? Arr::get($server, $path) : null;

        if ($serverValue !== null) {
            return is_array($serverValue);
        }

        return is_array($container->getStateAt($path));
    }

    protected function getDefaultStateWhenEmpty(): mixed
    {
        return $this->isMultiple() ? [$this->getMinValue(), $this->getMaxValue()] : null;
    }

    protected function mutateDehydratedState(mixed $state): mixed
    {
        if ($this->isMultiple()) {
            $values = array_map($this->castNumber(...), array_values(is_array($state) ? $state : []));
            sort($values);

            return $values;
        }

        return $state === null || $state === '' ? null : $this->castNumber($state);
    }

    protected function castNumber(mixed $value): int|float
    {
        $places = $this->evaluate($this->decimalPlaces);
        $number = is_numeric($value) ? $value + 0 : 0;

        if ($places !== null) {
            return $places <= 0 ? (int) round($number) : round($number, $places);
        }

        return $number;
    }

    protected function getTypeValidationRules(): array
    {
        [$min, $max] = $this->getSelectableRange();

        if ($this->isMultiple()) {
            return ['array', 'min:1', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_array($value)) {
                    return;
                }

                $values = array_values($value);
                sort($values);
                $minDifference = $this->evaluate($this->minDifference);
                $maxDifference = $this->evaluate($this->maxDifference);

                for ($i = 1; $i < count($values); $i++) {
                    if (! is_numeric($values[$i]) || ! is_numeric($values[$i - 1])) {
                        return; // Reported by the per-item rules.
                    }

                    $difference = $values[$i] - $values[$i - 1];

                    if (($minDifference !== null && $difference < $minDifference) || ($maxDifference !== null && $difference > $maxDifference)) {
                        $fail(__('alfakit::forms.slider.difference', ['min' => $minDifference ?? 0, 'max' => $maxDifference ?? ($this->getMaxValue() - $this->getMinValue())]));

                        return;
                    }
                }
            }];
        }

        return ['numeric', "min:{$min}", "max:{$max}"];
    }

    public function getValidationRulesByPath(): array
    {
        $rules = parent::getValidationRulesByPath();

        if ($this->isMultiple()) {
            [$min, $max] = $this->getSelectableRange();
            $rules[$this->getStatePath().'.*'] = ['required', 'numeric', "min:{$min}", "max:{$max}"];
        }

        return $rules;
    }

    protected function getSchemaProps(): array
    {
        $hasPips = (bool) $this->evaluate($this->hasPips);
        $padding = $this->getRangePadding();

        return [
            'min' => $this->getMinValue(),
            'max' => $this->getMaxValue(),
            'step' => $this->getStep(),
            'decimalPlaces' => $this->evaluate($this->decimalPlaces),
            'multiple' => $this->isMultiple() ?: null,
            'tooltips' => $this->evaluateFlags($this->hasTooltips) ?: null,
            'pips' => $hasPips ?: null,
            'pipsDensity' => $hasPips ? $this->evaluate($this->pipsDensity) : null,
            // Computed on the server (modes, `steppedPips()`, `pipsFilter()`).
            'pipList' => $this->getPips(),
            'rangePadding' => $padding !== [0, 0] ? $padding : null,
            'rtl' => ($rtl = $this->evaluate($this->isRtl)) === null ? null : (bool) $rtl,
            'behavior' => $this->getBehavior(),
            'nonLinearPoints' => $this->getNonLinearPoints() ?: null,
            'fillTrack' => $this->evaluateFlags($this->isTrackFilled) ?: null,
            'vertical' => $this->isVertical() ?: null,
            'topToBottom' => $this->isTopToBottom() ?: null,
            'minDifference' => $this->evaluate($this->minDifference),
            'maxDifference' => $this->evaluate($this->maxDifference),
        ];
    }
}
