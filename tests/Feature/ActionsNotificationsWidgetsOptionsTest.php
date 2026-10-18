<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Notifications\Actions\Action as NotificationAction;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Notifications\Testing\NotificationsFake;
use AlfaSix\Alfakit\Support\Enums\Color;
use AlfaSix\Alfakit\Support\Enums\IconPosition;
use AlfaSix\Alfakit\Support\Enums\Size;
use AlfaSix\Alfakit\Tests\Fixtures\PageParity\ParityChartWidget;
use AlfaSix\Alfakit\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Http\Request;
use PHPUnit\Framework\ExpectationFailedException;

/*
 * Stat description icon position, notification colour / action size, the badge trigger
 * style, chart filter buttons and the static notification test helpers.
 */

class StyledFiltersChartWidget extends ParityChartWidget
{
    public static bool $hideReset = false;

    public function filtersApplyAction(Action $action): Action
    {
        return $action->label('Update chart')->icon('refresh-cw')->color(Color::Success)->size(Size::Large);
    }

    public function filtersResetAction(Action $action): Action
    {
        return $action->hidden(fn (): bool => static::$hideReset);
    }
}

// ---------------------------------------------------------------- Stat::descriptionIcon()

it('stores and serialises the description icon position', function (): void {
    expect(Stat::make('A', 1)->description('Up')->descriptionIcon('trending-up', IconPosition::After)->toSchema()['descriptionIconPosition'])->toBe('after')
        ->and(Stat::make('A', 1)->descriptionIcon('trending-up', 'after')->getDescriptionIconPosition())->toBe(IconPosition::After)
        ->and(Stat::make('A', 1)->descriptionIcon('trending-up', fn (): IconPosition => IconPosition::After)->getDescriptionIconPosition())->toBe(IconPosition::After)
        // Before is the default and isn't sent.
        ->and(Stat::make('A', 1)->descriptionIcon('trending-up', IconPosition::Before)->toSchema())->not->toHaveKey('descriptionIconPosition')
        ->and(Stat::make('A', 1)->descriptionIcon('trending-up')->toSchema())->not->toHaveKey('descriptionIconPosition');
});

// ---------------------------------------------------------------- Notifications

it('serialises a notification colour and keeps it in the database payload', function (): void {
    $notification = Notification::make()->title('Saved')->color(Color::Success);

    expect($notification->toArray()['color'])->toBe('success')
        ->and(Notification::fromArray($notification->toArray())->getColor())->toBe('success')
        ->and(Notification::make()->color(fn (): string => 'brand')->toArray()['color'])->toBe('brand')
        ->and(Notification::make()->title('x')->toArray())->not->toHaveKey('color');
});

it('serialises the size of notification actions', function (): void {
    $action = NotificationAction::make('view')->size(Size::Small);

    expect($action->toArray()['size'])->toBe('sm')
        ->and(NotificationAction::fromArray($action->toArray())->getSize())->toBe(Size::Small)
        ->and(NotificationAction::make('view')->toArray())->not->toHaveKey('size');
});

it('fakes notifications and asserts on them statically', function (): void {
    $fake = Notification::fake();

    Notification::assertNotNotified();

    Notification::make()->title('Saved')->body('All good')->send();

    expect($fake->sent())->toHaveCount(1)
        ->and(Notification::getFlashed())->toBe([]);

    Notification::assertNotified();
    Notification::assertNotified('Saved');
    Notification::assertNotified(Notification::make()->title('Saved')->body('All good'));
    Notification::assertNotNotified('Deleted');

    expect(fn () => Notification::assertNotified('Deleted'))->toThrow(ExpectationFailedException::class)
        ->and(fn () => Notification::assertNotified(Notification::make()->title('Saved')->body('Other')))->toThrow(ExpectationFailedException::class);
});

it('asserts on flashed notifications without the fake', function (): void {
    expect(app()->bound(NotificationsFake::class))->toBeFalse();

    Notification::make()->title('Flashed')->send();

    Notification::assertNotified('Flashed');
    Notification::assertNotNotified('Other');
});

// ---------------------------------------------------------------- Action::badge()

it('renders the trigger as a badge with badge() and keeps badge($count)', function (): void {
    expect(Action::make('edit')->badge()->toSchema()['variant'])->toBe('badge')
        ->and(Action::make('edit')->badge()->toSchema())->not->toHaveKey('badge')
        ->and(Action::make('edit')->badge()->isBadgeTrigger())->toBeTrue()
        ->and(Action::make('filter')->iconButton()->badge(5)->toSchema())->toMatchArray(['variant' => 'icon-button', 'badge' => 5])
        // Another trigger style replaces the badge style.
        ->and(Action::make('edit')->badge()->button()->toSchema()['variant'])->toBe('button');
});

// ---------------------------------------------------------------- Chart filter buttons

it('serialises customised chart filter buttons only', function (): void {
    app()->instance('request', Request::create('/admin'));

    expect(ParityChartWidget::make()->toSchema()['filtersForm']['triggerActions'] ?? null)->toBeNull();

    $triggers = StyledFiltersChartWidget::make()->toSchema()['filtersForm']['triggerActions'];

    expect($triggers['filtersApply'])->toMatchArray(['label' => 'Update chart', 'icon' => 'refresh-cw', 'color' => 'success', 'variant' => 'button', 'size' => 'lg'])
        ->and($triggers['filtersReset'])->toMatchArray(['variant' => 'ghost'])
        ->and($triggers['filtersReset'])->not->toHaveKey('hidden');

    StyledFiltersChartWidget::$hideReset = true;

    expect(StyledFiltersChartWidget::make()->toSchema()['filtersForm']['triggerActions']['filtersReset'])->toBe(['hidden' => true]);

    StyledFiltersChartWidget::$hideReset = false;
});
