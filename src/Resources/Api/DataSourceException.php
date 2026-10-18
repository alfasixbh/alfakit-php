<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Resources\Api;

use AlfaSix\Alfakit\Actions\Exceptions\Halt;
use AlfaSix\Alfakit\Notifications\Notification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * A data source call failed (the API is down, answered 500, refused the request…).
 *
 * Its message is shown to the user in an error notification, so it never contains the
 * API's response body: throw `new DataSourceException('Suppliers are locked for the
 * stock count.')` yourself for a specific message. Validation failures are not data
 * source exceptions: an API answering 422 becomes a `ValidationException` (field errors).
 *
 * It extends `Halt`, so an action that hits it stops without closing its modal or
 * losing the form the user filled in.
 */
class DataSourceException extends Halt
{
    protected bool $isNotified = false;

    public function __construct(string $message = '', protected ?int $status = null, ?Throwable $previous = null)
    {
        parent::__construct($message !== '' ? $message : __('alfakit::panel.resources.api.unreachable'), 0, $previous);
    }

    public static function fromThrowable(Throwable $exception): self
    {
        if ($exception instanceof self) {
            return $exception;
        }

        if ($exception instanceof RequestException) {
            $status = $exception->response->status();

            return new self(__('alfakit::panel.resources.api.error', ['status' => $status]), $status, $exception);
        }

        if ($exception instanceof ConnectionException) {
            return new self(__('alfakit::panel.resources.api.unreachable'), null, $exception);
        }

        return new self(__('alfakit::panel.resources.api.unreachable'), null, $exception);
    }

    /**
     * The HTTP status the API answered with, when there was a response.
     */
    public function getStatus(): ?int
    {
        return $this->status;
    }

    public function isNotFound(): bool
    {
        return $this->status === 404;
    }

    /**
     * Report the underlying error and show the user an error notification (once).
     */
    public function notify(): static
    {
        if ($this->isNotified) {
            return $this;
        }

        $this->isNotified = true;

        if ($this->getPrevious() !== null) {
            report($this->getPrevious());
        }

        Notification::make()
            ->danger()
            ->title(__('alfakit::panel.resources.api.failed'))
            ->body($this->getMessage())
            ->send();

        return $this;
    }
}
