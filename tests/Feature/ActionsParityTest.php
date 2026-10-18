<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Tables\Filters\BaseFilter;
use AlfaSix\Alfakit\Tests\Fixtures\ActionsParity\ParityActionsPage;
use AlfaSix\Alfakit\Tests\Fixtures\ActionsParity\ParityDashboard;
use AlfaSix\Alfakit\Tests\Fixtures\ActionsParity\ParityPanelProvider;
use AlfaSix\Alfakit\Tests\Fixtures\ActionsParity\ParityTablePage;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use PHPUnit\Framework\AssertionFailedError;

/*
 * Nice-to-have parity: modal options, nested parent data, chaining, button groups,
 * dashboard filter actions, and the page tester's table/action/schema assertions.
 */

beforeEach(function (): void {
    ParityPanelProvider::registerPanel();
    ParityActionsPage::$runs = [];
});

function parityUser(): User
{
    return User::query()->create(['name' => 'Parity', 'email' => 'parity'.uniqid().'@example.com', 'password' => 'password']);
}

function parityPost(array $attributes = []): Post
{
    static $i = 0;
    $i++;

    return Post::query()->create(['title' => "post {$i}", 'slug' => "parity-{$i}-".uniqid(), ...$attributes]);
}

// ---------------------------------------------------------------- Modal options

it('serialises the modal options', function (): void {
    $this->actingAs(parityUser());

    $modal = alfakit()->testPage(ParityActionsPage::class, panel: 'parity')
        ->mountAction('edit')
        ->getMountedActionModal();

    expect($modal)->toMatchArray([
        'stickyHeader' => true,
        'slideOver' => true,
        'slideOverPosition' => 'start',
        'footerActionsAlignment' => 'start',
        'windowAttributes' => ['class' => 'edit-modal', 'data-test' => 'edit'],
        'overlayAttributes' => ['class' => 'backdrop'],
    ])
        ->and(collect($modal['extraFooterActions'])->firstWhere('name', 'saveAndPublish'))
        ->toMatchArray(['submitsModal' => true, 'arguments' => ['publish' => true], 'label' => 'Save & publish'])
        ->and(collect($modal['extraFooterActions'])->firstWhere('name', 'saveAndPublish'))
        ->not->toHaveKey('hasAction');
});

it('turns click-through modals into backdrop-less modals that ignore clicks away', function (): void {
    $this->actingAs(parityUser());

    $modal = alfakit()->testPage(ParityActionsPage::class, panel: 'parity')->mountAction('finish')->getMountedActionModal();

    expect($modal['clickThrough'])->toBeTrue()->and($modal['closeByClickingAway'])->toBeFalse();
});

it('serialises nested modal options of children only', function (): void {
    $this->actingAs(parityUser());

    $modal = alfakit()->testPage(ParityActionsPage::class, panel: 'parity')
        ->mountAction('edit')
        ->mountAction('edit>suggest')
        ->getMountedActionModal();

    expect($modal)->toMatchArray(['overlayParent' => true, 'cancelParentActionsOnClose' => true]);
});

it('hides the parent modal by default while a nested modal is open', function (): void {
    $this->actingAs(parityUser());

    $modal = alfakit()->testPage(ParityActionsPage::class, panel: 'parity')
        ->mountAction('edit')
        ->mountAction('edit>peek')
        ->getMountedActionModal();

    expect($modal)->not->toHaveKey('overlayParent');
});

it('serialises and runs action groups in extraModalFooterActions()', function (): void {
    $this->actingAs(parityUser());

    $tester = alfakit()->testPage(ParityActionsPage::class, panel: 'parity')->mountAction('edit');
    $group = collect($tester->getMountedActionModal()['extraFooterActions'])->firstWhere('type', 'ActionGroup');

    expect($group['label'])->toBe('More')
        ->and(collect($group['actions'])->pluck('name')->all())->toBe(['archive']);

    $tester->callAction(['edit', 'archive'])->assertActionMounted('edit');

    expect(ParityActionsPage::$runs)->toBe([['archive' => 'edit']]);
});

it('runs the parent action with the arguments of a modal submit action', function (): void {
    $this->actingAs(parityUser());

    alfakit()->testPage(ParityActionsPage::class, panel: 'parity')
        ->mountAction('edit')
        ->fillForm(['title' => 'Hello'])
        ->callMountedAction(['publish' => true])
        ->assertActionSucceeded('edit');

    expect(ParityActionsPage::$runs[0]['arguments'])->toBe(['publish' => true])
        ->and(ParityActionsPage::$runs[0]['edit']['title'])->toBe('Hello');
});

// ---------------------------------------------------------------- Parent data

it('injects $mountedActions with the parent form state', function (): void {
    $this->actingAs(parityUser());

    alfakit()->testPage(ParityActionsPage::class, panel: 'parity')
        ->mountAction('edit')
        ->fillForm(['title' => 'Typed so far'])
        ->callAction(['edit', 'peek'])
        ->assertActionMounted('edit');

    expect(ParityActionsPage::$runs)->toBe([['peek' => [2, 'Typed so far']]]);
});

it('validates the parent data and shows its errors on the parent modal', function (): void {
    $this->actingAs(parityUser());

    alfakit()->testPage(ParityActionsPage::class, panel: 'parity')
        ->mountAction('edit')
        ->fillForm(['title' => ''])
        ->callAction(['edit', 'check'])
        ->assertHasActionErrors(['title'])
        ->assertActionMounted('edit');

    expect(ParityActionsPage::$runs)->toBe([]);
});

it('fills the parent form from a nested action, ignoring unknown keys', function (): void {
    $this->actingAs(parityUser());

    $tester = alfakit()->testPage(ParityActionsPage::class, panel: 'parity')
        ->mountAction('edit')
        ->mountAction('edit>suggest')
        ->fillForm(['name' => 'Ada'])
        ->callMountedAction()
        ->assertActionMounted('edit')
        ->assertFormSet(['author' => 'Ada', 'title' => 'Draft']);

    expect(array_key_exists('unknown', $tester->getFormState()))->toBeFalse();
});

it('closes the named parent with cancelParentActions(name)', function (): void {
    $this->actingAs(parityUser());

    alfakit()->testPage(ParityActionsPage::class, panel: 'parity')
        ->mountAction('edit')
        ->callAction(['edit', 'discard'])
        ->assertActionNotMounted();
});

// ---------------------------------------------------------------- Chaining

it('replaces the mounted action with replaceMountedAction()', function (): void {
    $this->actingAs(parityUser());

    alfakit()->testPage(ParityActionsPage::class, panel: 'parity')
        ->callAction('start')
        ->assertActionMounted('finish')
        ->assertMountedActionModalSee('Almost done & dusted')
        ->assertMountedActionModalSeeHtml('<em>finish</em>')
        ->assertMountedActionModalDontSee('Nothing like this')
        ->assertMountedActionModalDontSeeHtml('<script>')
        ->callMountedAction()
        ->assertActionNotMounted();

    expect(ParityActionsPage::$runs)->toBe([['start' => true], ['finish' => ['step' => 2]]]);
});

// ---------------------------------------------------------------- Buttons

it('serialises labeledFrom, client-side buttons and group layouts', function (): void {
    $this->actingAs(parityUser());

    $page = alfakit()->testPage(ParityActionsPage::class, panel: 'parity')->getPage();
    $actions = collect($page['headerActions']);

    expect($actions->firstWhere('name', 'docs'))->toMatchArray(['labeledFrom' => 'md'])
        ->and($actions->firstWhere('name', 'inbox'))->toMatchArray(['opensDatabaseNotifications' => true])
        ->and($actions->firstWhere('name', 'inbox'))->not->toHaveKey('hasAction')
        ->and($actions->where('type', 'ActionGroup')->values()[0])->toMatchArray(['buttonGroup' => true, 'label' => 'View'])
        ->and($actions->where('type', 'ActionGroup')->values()[1])->toMatchArray(['dropdownWidth' => 'xs', 'dropdownOffset' => 8, 'maxHeight' => '200px'])
        ->and($actions->where('type', 'ActionGroup')->values()[1]['actions'][0])->toMatchArray(['type' => 'ActionGroup', 'dropdown' => false]);
});

it('asserts action labels, icons, colours, URLs and order', function (): void {
    $this->actingAs(parityUser());

    alfakit()->testPage(ParityActionsPage::class, panel: 'parity')
        ->assertActionHasLabel('docs', 'Documentation')
        ->assertActionDoesNotHaveLabel('docs', 'Docs')
        ->assertActionHasIcon('docs', 'book')
        ->assertActionDoesNotHaveIcon('docs', 'trash')
        ->assertActionHasColor('docs', 'info')
        ->assertActionDoesNotHaveColor('docs', 'danger')
        ->assertActionHasUrl('docs', 'https://example.com/docs')
        ->assertActionDoesNotHaveUrl('docs', 'https://example.com')
        ->assertActionShouldOpenUrlInNewTab('docs')
        ->assertActionShouldNotOpenUrlInNewTab('start')
        ->assertActionListInOrder(['edit', 'start', 'docs', 'copy', 'archive']);
});

it('fails the order assertion for a wrong order', function (): void {
    $this->actingAs(parityUser());

    alfakit()->testPage(ParityActionsPage::class, panel: 'parity')->assertActionListInOrder(['docs', 'edit']);
})->throws(AssertionFailedError::class);

// ---------------------------------------------------------------- Dashboard filter action

it('stores dashboard filters from the filter action and fills them back', function (): void {
    $this->actingAs(parityUser());

    $tester = alfakit()->testPage(ParityDashboard::class, panel: 'parity')
        ->callAction('filter', ['region' => 'north'])
        ->assertActionSucceeded('filter');

    $tester->refresh()
        ->mountAction('filter')
        ->assertFormSet(['region' => 'north']);

    expect(collect($tester->getPage()['headerActions'])->firstWhere('name', 'filter')['badge'] ?? null)->toBe(1);
});

it('validates dashboard filters', function (): void {
    $this->actingAs(parityUser());

    alfakit()->testPage(ParityDashboard::class, panel: 'parity')
        ->callAction('filter', ['region' => str_repeat('x', 20)])
        ->assertHasActionErrors(['region']);
});

// ---------------------------------------------------------------- Table assertions

it('asserts raw and formatted column state, descriptions and attributes', function (): void {
    $this->actingAs(parityUser());
    $post = parityPost(['title' => 'hello', 'rating' => 4]);

    alfakit()->testPage(ParityTablePage::class, panel: 'parity')
        ->assertTableColumnStateSet('title', 'hello', $post)
        ->assertTableColumnStateNotSet('title', 'HELLO', $post)
        ->assertTableColumnFormattedStateSet('title', 'HELLO', $post)
        ->assertTableColumnFormattedStateNotSet('title', 'hello', $post)
        ->assertTableColumnHasDescription('title', "Slug {$post->slug}", $post)
        ->assertTableColumnDoesNotHaveDescription('title', "Slug {$post->slug}", $post, 'above')
        ->assertTableColumnHasExtraAttributes('title', ['class' => 'font-bold', 'data-kind' => 'title'], $post)
        ->assertTableColumnDoesNotHaveExtraAttributes('title', ['class' => 'text-danger'], $post)
        ->assertTableSelectColumnHasOptions('status', ['draft' => 'Draft'], $post)
        ->assertTableSelectColumnDoesNotHaveOptions('status', ['archived' => 'Archived'], $post);
});

it('asserts renderable, visible and hidden columns and toggles them all', function (): void {
    $this->actingAs(parityUser());
    parityPost();

    alfakit()->testPage(ParityTablePage::class, panel: 'parity')
        ->assertCanRenderTableColumn('slug')
        ->assertCanNotRenderTableColumn('content')
        ->assertTableColumnVisible('title')
        ->assertTableColumnHidden('slug')
        ->toggleAllTableColumns()
        ->assertTableColumnVisible('slug')
        ->toggleAllTableColumns(false)
        ->assertTableColumnHidden('slug');
});

it('asserts filters and removes them all', function (): void {
    $this->actingAs(parityUser());
    parityPost(['status' => 'draft']);
    $live = parityPost(['status' => 'live']);

    alfakit()->testPage(ParityTablePage::class, panel: 'parity')
        ->assertTableFilterExists('status', fn (BaseFilter $filter): bool => $filter->getName() === 'status')
        ->assertTableFilterVisible('published')
        ->assertTableFilterHidden('secret')
        ->assertCanNotSeeTableRecords([$live])
        ->removeTableFilters()
        ->assertCanSeeTableRecords([$live]);
});

it('fails the filter existence check for an unknown filter', function (): void {
    $this->actingAs(parityUser());

    alfakit()->testPage(ParityTablePage::class, panel: 'parity')->assertTableFilterExists('nope');
})->throws(AssertionFailedError::class);

it('asserts summaries of all records and of the current page', function (): void {
    $this->actingAs(parityUser());
    parityPost(['rating' => 1]);
    parityPost(['rating' => 2]);
    parityPost(['rating' => 10]);

    alfakit()->testPage(ParityTablePage::class, panel: 'parity')
        ->assertTableColumnSummarySet('rating', 'total', 13)
        ->assertTableColumnSummarySet('rating', 'total', 3, isCurrentPaginationPageOnly: true)
        ->assertTableColumnSummarySet('rating', 'range', [1, 10]);
});

// ---------------------------------------------------------------- Schemas

it('asserts that a schema exists', function (): void {
    $this->actingAs(parityUser());

    alfakit()->testPage(ParityActionsPage::class, panel: 'parity')
        ->mountAction('edit')
        ->assertSchemaExists('form');
});

it('fails assertSchemaExists without a form', function (): void {
    $this->actingAs(parityUser());

    alfakit()->testPage(ParityTablePage::class, panel: 'parity')->assertSchemaExists('form');
})->throws(AssertionFailedError::class);
