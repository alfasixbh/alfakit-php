<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Testing\Concerns;

use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Notifications\Testing\NotificationsFake;
use PHPUnit\Framework\Assert;

/**
 * Flash notifications (`Notification::make()->send()`) sent by the requests of this
 * tester, and where actions redirected to.
 */
trait TestsNotifications
{
    /**
     * Without an argument: any notification was sent. With a title or a notification:
     * one with that title (and body, for a notification with a body) was sent.
     */
    public function assertNotified(Notification|string|null $notification = null): static
    {
        NotificationsFake::assertSentIn($this->notifications, $notification);

        return $this;
    }

    public function assertNotNotified(Notification|string|null $notification = null): static
    {
        NotificationsFake::assertNotSentIn($this->notifications, $notification);

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getNotifications(): array
    {
        return $this->notifications;
    }

    /**
     * The latest action redirected to `$url`, or (without one) to another page.
     */
    public function assertRedirect(?string $url = null): static
    {
        Assert::assertNotNull($this->redirectUrl, "Failed asserting that [{$this->describeLastRequest()}] redirected.");

        if ($url === null) {
            Assert::assertFalse($this->isRedirectToCurrentPage(), "Failed asserting that [{$this->describeLastRequest()}] redirected to another page.");
        } else {
            Assert::assertSame($this->normalizeUrl($url), $this->normalizeUrl((string) $this->redirectUrl));
        }

        return $this;
    }

    /**
     * The latest action stayed on this page: it didn't redirect, or only redirected back.
     */
    public function assertNoRedirect(): static
    {
        Assert::assertTrue($this->redirectUrl === null || $this->isRedirectToCurrentPage(), "Failed asserting that [{$this->describeLastRequest()}] did not redirect. It redirected to [{$this->redirectUrl}].");

        return $this;
    }

    public function getRedirectUrl(): ?string
    {
        return $this->redirectUrl;
    }

    protected function wasNotified(Notification|string $notification): bool
    {
        return NotificationsFake::matches($this->notifications, $notification);
    }

    protected function isRedirectToCurrentPage(): bool
    {
        return $this->redirectUrl !== null && explode('?', $this->normalizeUrl($this->redirectUrl))[0] === explode('?', $this->normalizeUrl($this->url))[0];
    }

    protected function normalizeUrl(string $url): string
    {
        return rtrim(str_contains($url, '://') ? $url : url($url), '/');
    }
}
