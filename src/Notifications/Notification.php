<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Notifications;

use AlfaSix\Alfakit\Notifications\Actions\Action;
use AlfaSix\Alfakit\Notifications\Testing\NotificationsFake;
use AlfaSix\Alfakit\Support\Concerns\Configurable;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\Alfakit\Support\Enums\Color;
use AlfaSix\Alfakit\Support\Icons;
use AlfaSix\Alfakit\Support\Serializable;
use BackedEnum;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Notifications\DatabaseNotification as DatabaseNotificationModel;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Support\SessionKey;

/**
 * A toast (flashed through Inertia) and/or a persisted database notification.
 *
 * ```php
 * Notification::make()->title('Saved')->success()->send();
 * ```
 */
class Notification
{
    use Concerns\BelongsToTenant;
    use Concerns\CanBeBroadcast;
    use Configurable;
    use EvaluatesClosures;

    public const FLASH_KEY = 'alfakitNotifications';

    protected string $id;

    protected string|Htmlable|Closure|null $title = null;

    protected string|Htmlable|Closure|null $body = null;

    protected string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $icon = null;

    protected Color|string|Closure|null $iconColor = null;

    protected Color|string|Closure|null $status = null;

    protected Color|string|Closure|null $color = null;

    protected int|string|Closure|null $duration = 6000;

    /** @var array<Action> */
    protected array $actions = [];

    protected ?string $view = null;

    /** @var array<string, mixed> */
    protected array $viewData = [];

    protected ?string $date = null;

    /** @var array{token: string, seconds: int, url: string|null}|null */
    protected ?array $undo = null;

    final public function __construct(?string $id = null)
    {
        $this->id = $id ?? (string) Str::uuid();
    }

    public static function make(?string $id = null): static
    {
        $static = new static($id);
        $static->configure();

        return $static;
    }

    protected function getEvaluationIdentifier(): string
    {
        return 'notification';
    }

    public function id(string $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function title(string|Htmlable|Closure|null $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function body(string|Htmlable|Closure|null $body): static
    {
        $this->body = $body;

        return $this;
    }

    public function icon(string|BackedEnum|Htmlable|Closure|null $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function iconColor(Color|string|Closure|null $color): static
    {
        $this->iconColor = $color;

        return $this;
    }

    /**
     * Tint the notification's background (toast and bell menu) with a colour; `status()`
     * and `iconColor()` are unaffected.
     */
    public function color(Color|string|Closure|null $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function getColor(): ?string
    {
        $color = $this->evaluate($this->color);

        return $color instanceof Color ? $color->value : $color;
    }

    public function status(Color|string|Closure|null $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function success(): static
    {
        return $this->status(Color::Success)->icon('alfakit::notifications.success');
    }

    public function warning(): static
    {
        return $this->status(Color::Warning)->icon('alfakit::notifications.warning');
    }

    public function danger(): static
    {
        return $this->status(Color::Danger)->icon('alfakit::notifications.danger');
    }

    public function info(): static
    {
        return $this->status(Color::Info)->icon('alfakit::notifications.info');
    }

    /**
     * Milliseconds, or `'persistent'`.
     */
    public function duration(int|string|Closure|null $duration): static
    {
        $this->duration = $duration;

        return $this;
    }

    public function seconds(int $seconds): static
    {
        return $this->duration($seconds * 1000);
    }

    public function persistent(): static
    {
        return $this->duration('persistent');
    }

    /**
     * Notification buttons. Panel actions (`AlfaSix\Alfakit\Actions\Action::make('view')->url(...)`,
     * as Filament 4+ code passes them) are converted to notification actions: name, label,
     * URL, colour and icon.
     *
     * @param  array<Action|\AlfaSix\Alfakit\Actions\Action>  $actions
     */
    public function actions(array $actions): static
    {
        $this->actions = array_values(array_map(static fn (Action|\AlfaSix\Alfakit\Actions\Action $action): Action => $action instanceof Action ? $action : Action::fromAction($action), $actions));

        return $this;
    }

    /**
     * Render a custom frontend component (registered in the renderer) instead of the default toast.
     *
     * @param  array<string, mixed>  $data
     */
    public function view(?string $component, array $data = []): static
    {
        $this->view = $component;
        $this->viewData = $data;

        return $this;
    }

    /**
     * `Action::undoable()`: an "Undo" button with a countdown (the toast stays as long).
     *
     * @param  array{token: string, seconds: int, url: string|null}|null  $undo
     */
    public function undo(?array $undo): static
    {
        $this->undo = $undo;

        return $this;
    }

    /**
     * @return array{token: string, seconds: int, url: string|null}|null
     */
    public function getUndo(): ?array
    {
        return $this->undo;
    }

    public function date(?string $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getTitle(): ?string
    {
        $title = $this->evaluate($this->title);

        return $title instanceof Htmlable ? $title->toHtml() : $title;
    }

    public function getBody(): ?string
    {
        return \AlfaSix\Alfakit\Support\Html::from($this->evaluate($this->body));
    }

    public function getStatus(): ?string
    {
        $status = $this->evaluate($this->status);

        return $status instanceof Color ? $status->value : $status;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $iconColor = $this->evaluate($this->iconColor);

        return Serializable::compact([
            'id' => $this->id,
            'title' => $this->getTitle(),
            'body' => $this->getBody(),
            'status' => $this->getStatus(),
            'icon' => Icons::resolve($this->evaluate($this->icon)),
            'iconColor' => $iconColor instanceof Color ? $iconColor->value : ($iconColor ?? $this->getStatus()),
            'color' => $this->getColor(),
            'duration' => $this->evaluate($this->duration),
            'actions' => array_map(static fn (Action $action): array => $action->toArray(), $this->actions) ?: null,
            'view' => $this->view,
            'viewData' => $this->viewData ?: null,
            'date' => $this->date,
            'undo' => $this->undo,
            'format' => 'alfakit',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        $static = static::make($data['id'] ?? null)
            ->title($data['title'] ?? null)
            // Stored bodies were escaped when they were serialised.
            ->body(isset($data['body']) && is_string($data['body']) ? new \Illuminate\Support\HtmlString($data['body']) : null)
            ->status($data['status'] ?? null)
            ->icon($data['icon'] ?? null)
            ->iconColor($data['iconColor'] ?? null)
            ->color($data['color'] ?? null)
            ->duration($data['duration'] ?? null)
            ->date($data['date'] ?? null)
            ->view($data['view'] ?? null, $data['viewData'] ?? []);

        $static->actions(array_map(static fn (array $action): Action => Action::fromArray($action), $data['actions'] ?? []));

        return $static;
    }

    public static function fromDatabase(DatabaseNotificationModel $record): static
    {
        $static = static::fromArray((array) $record->getAttribute('data'));
        $static->id((string) $record->getKey());
        $static->date($record->getAttribute('created_at')?->toIso8601String());

        return $static;
    }

    /**
     * Flash the notification so it appears as a toast on the next Inertia response.
     */
    public function send(): static
    {
        // `Notification::fake()`: recorded for `assertNotified()` instead of flashed.
        // (Undo buttons are added by `attachUndoToSent()`.)
        if (app()->bound(NotificationsFake::class)) {
            app(NotificationsFake::class)->record($this->toArray());

            return $this;
        }

        Inertia::flash(self::FLASH_KEY, [...static::getFlashed(), $this->toArray()]);

        return $this;
    }

    /**
     * How many notifications this request sent so far (flashed, or recorded by the fake).
     */
    public static function countSent(): int
    {
        return count(static::getSentForAssertions());
    }

    /**
     * Add an undo button to the last notification sent after the first `$since`
     * (preferring a success one); false when none was sent.
     *
     * @param  array{token: string, seconds: int, url: string|null}  $undo
     */
    public static function attachUndoToSent(array $undo, int $since): bool
    {
        $sent = static::getSentForAssertions();
        $candidates = array_slice(array_keys($sent), $since);

        if ($candidates === []) {
            return false;
        }

        $index = null;

        foreach (array_reverse($candidates) as $candidate) {
            if (($sent[$candidate]['status'] ?? null) === 'success') {
                $index = $candidate;

                break;
            }
        }

        $index ??= end($candidates);
        $notification = $sent[$index];
        $duration = $notification['duration'] ?? null;

        $notification['undo'] = $undo;
        // The toast stays at least as long as the countdown.
        $notification['duration'] = $duration === 'persistent' ? 'persistent' : max(is_numeric($duration) ? (int) $duration : 0, $undo['seconds'] * 1000);

        if (app()->bound(NotificationsFake::class)) {
            app(NotificationsFake::class)->replace($index, $notification);

            return true;
        }

        $sent[$index] = $notification;
        Inertia::flash(self::FLASH_KEY, array_values($sent));

        return true;
    }

    /**
     * In tests: record notifications sent with `send()` instead of flashing them, for
     * `Notification::assertNotified()`. Each test starts without the fake.
     */
    public static function fake(): NotificationsFake
    {
        $fake = new NotificationsFake;
        app()->instance(NotificationsFake::class, $fake);

        return $fake;
    }

    /**
     * In tests: a notification was sent with `send()` during this test (any, or one with
     * this title, or this notification's title and body). Reads the fake after
     * `Notification::fake()`, else the notifications flashed to the session.
     */
    public static function assertNotified(self|string|null $notification = null): void
    {
        NotificationsFake::assertSentIn(static::getSentForAssertions(), $notification);
    }

    public static function assertNotNotified(self|string|null $notification = null): void
    {
        NotificationsFake::assertNotSentIn(static::getSentForAssertions(), $notification);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected static function getSentForAssertions(): array
    {
        return app()->bound(NotificationsFake::class) ? app(NotificationsFake::class)->sent() : static::getFlashed();
    }

    /**
     * Persist the notification for the given user(s) using Laravel's database channel.
     *
     * @param  Authenticatable|iterable<Authenticatable>  $users
     */
    public function sendToDatabase(Authenticatable|iterable $users, bool $isEventDispatched = false): static
    {
        $users = $users instanceof Authenticatable ? [$users] : (is_array($users) ? $users : iterator_to_array($users, false));

        foreach ($users as $user) {
            if (method_exists($user, 'notify')) {
                $user->notify($this->toDatabase());
            }
        }

        // With realtime notifications, open tabs reload the bell menu right away.
        // `realtime()` panels already push every stored database notification.
        if ($isEventDispatched && ! \AlfaSix\Alfakit\Realtime\Realtime::pushesDatabaseNotifications()) {
            static::dispatchDatabaseNotificationsSent($users);
        }

        return $this;
    }

    public function toDatabase(): DatabaseNotification
    {
        return new DatabaseNotification($this->toDatabaseData());
    }

    /**
     * The data of a database notification, for a custom Laravel notification's
     * `toDatabase()`: the bell menu shows it like one sent with `sendToDatabase()`.
     *
     * ```php
     * public function toDatabase(User $notifiable): array
     * {
     *     return Notification::make()->title('Saved successfully')->getDatabaseMessage();
     * }
     * ```
     *
     * @return array<string, mixed>
     */
    public function getDatabaseMessage(): array
    {
        return $this->toDatabaseData();
    }

    /**
     * The message of a broadcast notification, for a custom Laravel notification's
     * `toBroadcast()`: open panel tabs show it as a toast (with `realtimeNotifications()`).
     */
    public function getBroadcastMessage(): \Illuminate\Notifications\Messages\BroadcastMessage
    {
        return new \Illuminate\Notifications\Messages\BroadcastMessage($this->toArray());
    }

    /**
     * Notifications flashed for the current request (used by the Inertia middleware).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getFlashed(): array
    {
        if (! app()->bound('session')) {
            return [];
        }

        return session()->get(SessionKey::FLASH_DATA, [])[self::FLASH_KEY] ?? [];
    }

    /**
     * @param  Collection<int, DatabaseNotificationModel>  $records
     * @return array<int, array<string, mixed>>
     */
    public static function serializeDatabaseNotifications(Collection $records): array
    {
        return $records->map(static fn (DatabaseNotificationModel $record): array => [
            ...static::fromDatabase($record)->toArray(),
            'readAt' => $record->getAttribute('read_at')?->toIso8601String(),
        ])->values()->all();
    }
}
