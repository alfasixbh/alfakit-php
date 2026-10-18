<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Panel\Concerns\HasDatabaseNotificationsPosition;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\Alfakit\Support\Enums\DatabaseNotificationsPosition;

function notificationsPositionHost(bool $topbar = true): object
{
    return new class($topbar)
    {
        use EvaluatesClosures;
        use HasDatabaseNotificationsPosition;

        public function __construct(public bool $topbar) {}

        public function hasTopbar(): bool
        {
            return $this->topbar;
        }

        /**
         * @return array<string, string>
         */
        public function schema(): array
        {
            return $this->getDatabaseNotificationsPositionSchema();
        }
    };
}

it('keeps the bell in the top bar by default and moves it to the sidebar on request', function (): void {
    $host = notificationsPositionHost();

    expect($host->getDatabaseNotificationsPosition())->toBe(DatabaseNotificationsPosition::Topbar)
        ->and($host->schema())->toBe([]);

    $host->databaseNotificationsPosition('sidebar');
    expect($host->getDatabaseNotificationsPosition())->toBe(DatabaseNotificationsPosition::Sidebar)
        ->and($host->schema())->toBe(['position' => 'sidebar']);

    $host->databaseNotificationsPosition(fn (): DatabaseNotificationsPosition => DatabaseNotificationsPosition::Topbar);
    expect($host->schema())->toBe([]);
});

it('moves the bell to the sidebar without a top bar unless a position is set', function (): void {
    $host = notificationsPositionHost(topbar: false);

    expect($host->schema())->toBe(['position' => 'sidebar']);

    $host->databaseNotificationsPosition(DatabaseNotificationsPosition::Topbar);
    expect($host->schema())->toBe([]);
});

it('rejects an unknown position', function (): void {
    notificationsPositionHost()->databaseNotificationsPosition('footer')->getDatabaseNotificationsPosition();
})->throws(ValueError::class);

it('serialises the position in the panel schema', function (): void {
    $panel = alfakit()->getPanel('admin');
    $panel->databaseNotifications(position: DatabaseNotificationsPosition::Sidebar);

    $this->actingAs(admin());
    $schema = inertiaProps(inertiaGet($this, '/admin'))['alfakit']['databaseNotifications'];

    expect($schema['position'])->toBe('sidebar');

    $panel->databaseNotifications(position: DatabaseNotificationsPosition::Topbar);
    expect(inertiaProps(inertiaGet($this, '/admin'))['alfakit']['databaseNotifications'])->not->toHaveKey('position');

    $panel->databaseNotificationsPosition(null)->topbar(false);
    expect(inertiaProps(inertiaGet($this, '/admin'))['alfakit']['databaseNotifications']['position'])->toBe('sidebar');

    $panel->databaseNotificationsPosition(null)->topbar();
});
