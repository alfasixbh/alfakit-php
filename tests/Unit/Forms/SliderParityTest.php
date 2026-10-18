<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\Slider;
use AlfaSix\Alfakit\Forms\Components\Slider\Enums\Behavior;
use AlfaSix\Alfakit\Forms\Components\Slider\Enums\PipsMode;
use Illuminate\Validation\ValidationException;

/**
 * @return array<string, mixed>
 */
function sliderProps(Slider $slider, mixed $state = null): array
{
    return schema([$slider])->fill($state === null ? [] : [$slider->getName() => $state])->toSchema()[0];
}

/**
 * @param  array<int, array{value: int|float, percent: float, type: int}>  $pips
 * @return array<int, int|float>
 */
function pipValues(array $pips, ?int $type = null): array
{
    return array_values(array_map(static fn (array $pip): int|float => $pip['value'], array_filter($pips, static fn (array $pip): bool => $type === null || $pip['type'] === $type)));
}

it('keeps the default pips: every step, at most 11 labelled', function (): void {
    $pips = sliderProps(Slider::make('volume')->range(0, 10)->pips())['pipList'];

    expect(pipValues($pips))->toBe([0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10])
        ->and(pipValues($pips, 1))->toHaveCount(11)
        ->and($pips[5]['percent'])->toEqual(50);

    $dense = sliderProps(Slider::make('volume')->range(0, 100)->pips(density: 5))['pipList'];

    expect(pipValues($dense))->toHaveCount(21)
        ->and(pipValues($dense, 1))->toBe([0, 10, 20, 30, 40, 50, 60, 70, 80, 90, 100]);
});

it('places pips by mode with pipsValues and density', function (): void {
    $positions = sliderProps(Slider::make('s')->range(0, 200)->pips(PipsMode::Positions)->pipsValues([0, 25, 50, 100]))['pipList'];
    $count = sliderProps(Slider::make('s')->range(0, 100)->pips(PipsMode::Count)->pipsValues(5))['pipList'];
    $values = sliderProps(Slider::make('s')->range(0, 100)->pips(PipsMode::Values, density: 25)->pipsValues([5, 15]))['pipList'];
    $steps = sliderProps(Slider::make('s')->range(0, 4)->pips(PipsMode::Steps))['pipList'];

    expect(pipValues($positions))->toBe([0, 50, 100, 200])
        ->and(pipValues($count))->toBe([0, 25, 50, 75, 100])
        ->and(pipValues($values, 1))->toBe([5, 15])
        // Density in a mode: unlabelled ticks every 25% of the track.
        ->and(pipValues($values, 0))->toBe([0, 25, 50, 75, 100])
        ->and(pipValues($steps, 1))->toBe([0, 4])
        ->and(pipValues($steps, 2))->toBe([1, 2, 3]);
});

it('filters pips on the server and rounds them to selectable values', function (): void {
    $filtered = sliderProps(Slider::make('s')->range(0, 100)->pips(density: 5)
        ->pipsFilter(fn (int|float $value): int => $value % 50 === 0 ? 1 : ($value % 10 === 0 ? 2 : ($value % 25 === 0 ? 0 : -1))))['pipList'];

    expect(pipValues($filtered, 1))->toBe([0, 50, 100])
        ->and(pipValues($filtered, 2))->toBe([10, 20, 30, 40, 60, 70, 80, 90])
        ->and(pipValues($filtered, 0))->toBe([25, 75])
        ->and(pipValues($filtered))->not->toContain(5);

    $stepped = Slider::make('price')->range(0, 10000)
        ->nonLinearPoints(['10%' => [500, 500], '50%' => [4000, 1000]])
        ->pips(PipsMode::Positions)->pipsValues([0, 25, 50, 75, 100]);

    expect(pipValues(sliderProps($stepped)['pipList']))->toBe([0, 1812.5, 4000, 7000, 10000])
        ->and(pipValues(sliderProps($stepped->steppedPips())['pipList']))->toBe([0, 2000, 4000, 7000, 10000]);
});

it('maps values on a non-linear track', function (): void {
    $slider = Slider::make('s')->range(0, 100)->nonLinearPoints(['20%' => 50, '50%' => [75, 5]]);
    schema([$slider])->fill();

    expect($slider->valueToPercent(50))->toEqual(20)
        ->and($slider->valueToPercent(25))->toEqual(10)
        ->and($slider->percentToValue(35))->toEqual(62.5)
        ->and($slider->percentToValue(35, snap: true))->toEqual(63)
        // From 50% on the step is 5.
        ->and($slider->percentToValue(62))->toEqual(81)
        ->and($slider->percentToValue(62, snap: true))->toEqual(80)
        ->and(sliderProps($slider)['nonLinearPoints'])->toBe([
            ['percent' => 20.0, 'value' => 50],
            ['percent' => 50.0, 'value' => 75, 'step' => 5],
        ]);
});

it('serialises padding, direction and behaviour', function (): void {
    $props = sliderProps(Slider::make('s')->range(0, 100)->rangePadding([10, 20])->rtl()->behavior([Behavior::Tap, Behavior::Drag, 'fixed']), [30, 60]);
    $none = sliderProps(Slider::make('s')->behavior(null)->rtl(false)->rangePadding(fn (): int => 5));
    $default = sliderProps(Slider::make('s'));

    expect($props['rangePadding'])->toBe([10, 20])
        ->and($props['rtl'])->toBeTrue()
        ->and($props['behavior'])->toBe(['tap', 'drag', 'fixed'])
        ->and($none['behavior'])->toBe([])
        ->and($none['rtl'])->toBeFalse()
        ->and($none['rangePadding'])->toBe([5, 5])
        ->and($default)->not->toHaveKeys(['behavior', 'rtl', 'rangePadding', 'pipList', 'nonLinearPoints']);
});

it('validates values inside the range padding', function (): void {
    $form = schema([Slider::make('s')->range(0, 100)->rangePadding([10, 20])])->fill();

    $form->rawState(['s' => 85]);
    expect(fn () => $form->validate())->toThrow(ValidationException::class);

    $form->rawState(['s' => 5]);
    expect(fn () => $form->validate())->toThrow(ValidationException::class);

    $form->rawState(['s' => 80]);
    expect($form->validate())->toBe(['s' => 80]);

    $range = schema([Slider::make('r')->range(0, 100)->rangePadding(10)->default([20, 50])])->fill();
    $range->rawState(['r' => [5, 50]]);

    expect(fn () => $range->validate())->toThrow(ValidationException::class);
});
