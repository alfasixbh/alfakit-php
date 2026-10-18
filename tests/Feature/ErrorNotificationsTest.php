<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Tests\Fixtures\PanelNav\NavPanelProvider;

beforeEach(function (): void {
    $this->panel = NavPanelProvider::registerPanel();
    alfakit()->setCurrentPanel($this->panel);
    config()->set('app.debug', false);
});

afterEach(function (): void {
    $this->panel->errorNotifications();
});

/**
 * @param  Closure(Page): void|null  $setUp
 */
function errorNotificationsPage(?bool $enabled = null, ?Closure $setUp = null): Page
{
    $page = new class extends Page
    {
        public ?Closure $setUp = null;

        public function setEnabled(?bool $enabled): void
        {
            $this->hasErrorNotifications = $enabled;
        }

        /** @return array<string, array<string, mixed>>|false|null */
        public function schema(): array|false|null
        {
            return $this->getErrorNotificationsSchema();
        }

        protected function setUpErrorNotifications(): void
        {
            if ($this->setUp !== null) {
                ($this->setUp)($this);
            }
        }
    };

    $page->setEnabled($enabled);
    $page->setUp = $setUp;

    return $page;
}

it('hides and disables error notifications per status on the panel', function (): void {
    $panel = Panel::make()->id('errors')
        ->registerErrorNotification('Gone', 'It moved.', 410)
        ->hiddenErrorNotification(403)
        ->disabledErrorNotification(503);

    $schema = Panel::serializeErrorNotifications($panel->getErrorNotifications());

    expect($schema['403'])->toBe(['hidden' => true])
        ->and($schema['503'])->toBe(['disabled' => true])
        ->and($schema['410'])->toMatchArray(['title' => 'Gone', 'body' => 'It moved.'])
        ->and($schema['default'])->toHaveKey('icon');
});

it('lets a later registration replace a hidden status', function (): void {
    $panel = Panel::make()->id('errors')
        ->hiddenErrorNotification(404)
        ->registerErrorNotification(fn (): string => 'Missing', null, 404);

    expect($panel->getErrorNotifications()['404'])->toBe(['title' => 'Missing', 'body' => null]);
});

it('can hide every status without its own entry', function (): void {
    $panel = Panel::make()->id('errors')->hiddenErrorNotification();

    expect($panel->getErrorNotifications()['default'])->toBe(['hidden' => true]);
});

it('leaves pages that follow the panel without an override', function (): void {
    expect(errorNotificationsPage()->schema())->toBeNull()
        ->and(errorNotificationsPage(true)->schema())->toBeNull();
});

it('turns error notifications off for one page', function (): void {
    expect(errorNotificationsPage(false)->schema())->toBeFalse();
});

it('turns them on for one page when the panel has them off', function (): void {
    $this->panel->errorNotifications(false);

    $schema = errorNotificationsPage(true)->schema();

    expect($schema['403'])->toMatchArray(['title' => 'Not allowed', 'body' => 'Ask an admin.']);
});

it('merges the notifications a page sets up with the panel ones', function (): void {
    $schema = errorNotificationsPage(setUp: function (Page $page): void {
        $page->registerErrorNotification('Page error', fn (): string => 'From the page')
            ->hiddenErrorNotification(404)
            ->disabledErrorNotification(500);
    })->schema();

    expect($schema['default'])->toMatchArray(['title' => 'Page error', 'body' => 'From the page'])
        ->and($schema['403'])->toMatchArray(['title' => 'Not allowed'])
        ->and($schema['404'])->toBe(['hidden' => true])
        ->and($schema['500'])->toBe(['disabled' => true]);
});

it('sends no page override in debug mode', function (): void {
    config()->set('app.debug', true);

    expect(errorNotificationsPage(false)->schema())->toBeNull();
});

it('does not keep page notifications between requests', function (): void {
    $calls = 0;
    $page = errorNotificationsPage(setUp: function (Page $page) use (&$calls): void {
        $calls++;

        if ($calls === 1) {
            $page->hiddenErrorNotification(404);
        }
    });

    expect($page->schema())->not->toBeNull()
        ->and($page->schema())->toBeNull();
});
