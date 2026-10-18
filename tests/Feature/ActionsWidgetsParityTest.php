<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\ActionGroup;
use AlfaSix\Alfakit\Actions\CreateAction;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Schemas\Components\View;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\Panels;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Tag;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;

/*
 * Custom modal content with data, the "Create & create another" button, and chart widget
 * empty-state actions and views.
 */

class ParityEmptyChart extends ChartWidget
{
    public static int $runs = 0;

    public static bool $empty = true;

    public static bool $hideSecond = false;

    protected ?string $emptyStateHeading = 'No orders';

    protected function getChartType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        return self::$empty ? [] : ['datasets' => [['data' => [1, 2]]], 'labels' => ['a', 'b']];
    }

    protected function getEmptyStateActions(): array
    {
        return [
            Action::make('importOrders')->label('Import')->action(function (): void {
                self::$runs++;
            }),
            ActionGroup::make([
                Action::make('second')->hidden(fn (): bool => self::$hideSecond)->action(fn () => null),
            ]),
        ];
    }
}

class ParityEmptyViewChart extends ParityEmptyChart
{
    protected ?string $emptyStateView = 'orders-empty';

    protected function getEmptyStateViewData(): array
    {
        return ['cta' => 'Import orders'];
    }
}

class ParityChartPage extends Page
{
    protected static ?string $slug = 'chart-empty';

    protected function getHeaderWidgets(): array
    {
        return [ParityEmptyChart::class];
    }
}

function parityChartPanel(): Panel
{
    return Panels::register('cw', fn (Panel $panel): Panel => $panel->pages([ParityChartPage::class]));
}

function parityChartUser(): User
{
    return User::query()->create(['name' => 'Cw', 'email' => 'cw'.uniqid().'@example.com', 'password' => 'secret']);
}

beforeEach(function (): void {
    ParityEmptyChart::$runs = 0;
    ParityEmptyChart::$empty = true;
    ParityEmptyChart::$hideSecond = false;
});

// ---------------------------------------------------------------- modalContent()

it('renders a registered frontend component as modal content with data', function (): void {
    $post = Post::query()->create(['title' => 'Hello', 'slug' => 'hello']);

    $modal = Action::make('preview')
        ->record($post)
        ->modalContent('post-preview', ['title' => fn (Post $record): string => $record->title, 'static' => 1])
        ->toModalSchema();

    expect($modal['content'])->toHaveCount(1)
        ->and($modal['content'][0])->toMatchArray([
            'type' => 'View',
            'component' => 'post-preview',
            'props' => ['title' => 'Hello', 'static' => 1],
        ]);
});

it('resolves the data closure itself with the action injections', function (): void {
    $post = Post::query()->create(['title' => 'Data', 'slug' => 'data']);

    $modal = Action::make('preview')
        ->record($post)
        ->arguments(['tab' => 'stats'])
        ->modalContentFooter('post-footer', fn (Model $record, array $arguments): array => ['id' => $record->getKey(), 'tab' => $arguments['tab']])
        ->toModalSchema();

    expect($modal)->not->toHaveKey('content')
        ->and($modal['contentFooter'][0]['props'])->toBe(['id' => $post->getKey(), 'tab' => 'stats']);
});

it('accepts a closure returning a component name, a single component or an Htmlable', function (): void {
    $name = Action::make('a')->modalContent(fn (): string => 'from-closure')->toModalSchema();
    $single = Action::make('b')->modalContent(View::make('single')->props(['x' => 1]))->toModalSchema();
    $html = Action::make('c')->modalContent(new HtmlString('<p data-x="1">Trusted</p>'))->toModalSchema();
    $entries = Action::make('d')->modalContent([TextEntry::make('title')->state('Plain')])->toModalSchema();

    expect($name['content'][0]['component'])->toBe('from-closure')
        ->and($name['content'][0])->not->toHaveKey('props')
        ->and($single['content'][0]['props'])->toBe(['x' => 1])
        ->and($html['content'][0]['type'])->toBe('Html')
        ->and($html['content'][0]['content'])->toBe('<p data-x="1">Trusted</p>')
        ->and($entries['content'][0]['type'])->toBe('TextEntry');
});

it('renders a Blade view as trusted modal content', function (): void {
    $directory = sys_get_temp_dir().'/alfakit-views-'.uniqid();
    mkdir($directory);
    file_put_contents($directory.'/advance.blade.php', '<strong>{{ $title }}</strong>');
    app('view')->addNamespace('paritytest', $directory);

    $modal = Action::make('advance')
        ->modalContent(fn (): Illuminate\Contracts\View\View => view('paritytest::advance', ['title' => 'Ship <it>']))
        ->toModalSchema();

    expect($modal['content'][0]['type'])->toBe('Html')
        ->and($modal['content'][0]['content'])->toBe('<strong>Ship &lt;it&gt;</strong>');
});

it('opens a modal for custom content without a form', function (): void {
    expect(Action::make('a')->modalContent('anything')->hasModal())->toBeTrue()
        ->and(Action::make('b')->hasModal())->toBeFalse();
});

// ---------------------------------------------------------------- createAnotherAction()

function parityCreateAction(): CreateAction
{
    return CreateAction::make()->model(Tag::class)->form([TextInput::make('name'), TextInput::make('slug')]);
}

it('customises the "Create & create another" button', function (): void {
    $modal = parityCreateAction()
        ->createAnotherAction(fn (Action $action): Action => $action->label('Save and add another')->color('success')->icon('plus'))
        ->toModalSchema();

    expect($modal['createAnotherLabel'])->toBe('Save and add another')
        ->and($modal['createAnotherColor'])->toBe('success')
        ->and($modal['createAnotherIcon'])->toBe('plus');
});

it('removes the button when createAnotherAction() returns false or hides it', function (): void {
    expect(parityCreateAction()->createAnotherAction(fn (): bool => false)->toModalSchema())->not->toHaveKey('createAnotherLabel')
        ->and(parityCreateAction()->createAnotherAction(fn (Action $action): Action => $action->hidden())->toModalSchema())->not->toHaveKey('createAnotherLabel')
        ->and(parityCreateAction()->createAnotherAction(fn (Action $action): Action => $action)->createAnother(false)->getCreateAnotherAction())->toBeNull();
});

it('keeps the default button without a customisation', function (): void {
    $modal = parityCreateAction()->toModalSchema();

    expect($modal['createAnotherLabel'])->toBe(__('alfakit::actions.create.create_another'))
        ->and($modal)->not->toHaveKey('createAnotherColor')
        ->and($modal)->not->toHaveKey('createAnotherIcon');
});

it('reads the preserved fields from a closure returning a $data subset', function (): void {
    $subset = parityCreateAction()
        ->preserveFormDataWhenCreatingAnother(fn (array $data): array => Arr::only($data, ['slug']))
        ->toModalSchema();
    $all = parityCreateAction()
        ->preserveFormDataWhenCreatingAnother(fn (array $data): array => $data)
        ->toModalSchema();

    expect($subset['preserveFormData'])->toBe(['slug'])
        ->and($all['preserveFormData'])->toBe(['name', 'slug']);
});

// ---------------------------------------------------------------- Chart empty state

it('serialises empty-state actions and leaves them out while the chart has data', function (): void {
    $schema = ParityEmptyChart::make()->toSchema();

    expect($schema['emptyState']['heading'])->toBe('No orders')
        ->and($schema['emptyState']['actions'])->toHaveCount(2)
        ->and($schema['emptyState']['actions'][0]['name'])->toBe('importOrders')
        ->and($schema['emptyState']['actions'][0]['label'])->toBe('Import')
        ->and($schema['emptyState'])->not->toHaveKey('view');

    ParityEmptyChart::$empty = false;

    expect(ParityEmptyChart::make()->toSchema())->not->toHaveKey('emptyState');
});

it('serialises a custom empty-state view with its data', function (): void {
    $state = ParityEmptyViewChart::make()->toSchema()['emptyState'];

    expect($state['view'])->toBe('orders-empty')
        ->and($state['viewData'])->toBe(['cta' => 'Import orders']);
});

it('runs an empty-state action through the widget context', function (): void {
    parityChartPanel();
    $this->actingAs(parityChartUser());

    $props = inertiaProps(inertiaGet($this, '/cw/chart-empty'));
    $token = $props['page']['token'];
    $id = ParityEmptyChart::make()->getId();

    inertiaPost($this, '/cw/_alfakit/actions', ['token' => $token, 'component' => "widget:{$id}", 'action' => 'importOrders'])
        ->assertRedirect();

    expect(ParityEmptyChart::$runs)->toBe(1);

    // Actions inside a group resolve too; hidden ones don't run.
    ParityEmptyChart::$hideSecond = true;

    $this->postJson('/cw/_alfakit/actions', ['token' => $token, 'component' => "widget:{$id}", 'action' => 'second'])
        ->assertForbidden();

    // Unknown actions and bulk requests are not resolved from the widget.
    $this->postJson('/cw/_alfakit/actions', ['token' => $token, 'component' => "widget:{$id}", 'action' => 'nope'])
        ->assertNotFound();
    $this->postJson('/cw/_alfakit/actions', ['token' => $token, 'component' => "widget:{$id}", 'action' => 'importOrders', 'bulk' => 1])
        ->assertStatus(404);

    expect(ParityEmptyChart::$runs)->toBe(1);
});
