<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Concerns;

use AlfaSix\Alfakit\Notifications\Notification;
use Closure;

trait HasNotifications
{
    protected Notification|Closure|null $successNotification = null;

    protected string|Closure|null $successNotificationTitle = null;

    protected Notification|Closure|null $failureNotification = null;

    protected string|Closure|null $failureNotificationTitle = null;

    public function successNotification(Notification|Closure|null $notification): static
    {
        $this->successNotification = $notification;

        return $this;
    }

    public function successNotificationTitle(string|Closure|null $title): static
    {
        $this->successNotificationTitle = $title;

        return $this;
    }

    public function failureNotification(Notification|Closure|null $notification): static
    {
        $this->failureNotification = $notification;

        return $this;
    }

    public function failureNotificationTitle(string|Closure|null $title): static
    {
        $this->failureNotificationTitle = $title;

        return $this;
    }

    public function getSuccessNotification(): ?Notification
    {
        $notification = $this->evaluate($this->successNotification, [
            'notification' => fn (): Notification => Notification::make()->success()->title($this->evaluate($this->successNotificationTitle)),
        ]);

        if ($notification instanceof Notification) {
            return $notification;
        }

        $title = $this->evaluate($this->successNotificationTitle);

        return $title === null ? null : Notification::make()->success()->title($title);
    }

    public function getFailureNotification(): ?Notification
    {
        $notification = $this->evaluate($this->failureNotification, [
            'notification' => fn (): Notification => Notification::make()->danger()->title($this->evaluate($this->failureNotificationTitle)),
        ]);

        if ($notification instanceof Notification) {
            return $notification;
        }

        $title = $this->evaluate($this->failureNotificationTitle);

        return $title === null ? null : Notification::make()->danger()->title($title);
    }

    public function sendSuccessNotification(): void
    {
        $this->getSuccessNotification()?->send();
    }

    public function sendFailureNotification(): void
    {
        $this->getFailureNotification()?->send();
    }
}
