<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions;

use AlfaSix\Alfakit\Actions\Concerns\InteractsWithResource;
use AlfaSix\Alfakit\Support\Enums\Color;
use Closure;
use Illuminate\Database\Eloquent\Model;

class ReplicateAction extends Action
{
    use InteractsWithResource;

    /** @var array<string>|Closure|null */
    protected array|Closure|null $excludedAttributes = null;

    protected ?Closure $beforeReplicaSaved = null;

    protected ?Closure $afterReplicaSaved = null;

    public static function make(string $name = 'replicate'): static
    {
        return parent::make($name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('alfakit::actions.replicate.label'));
        $this->icon('alfakit::actions.replicate');
        $this->color(Color::Gray);
        $this->requiresConfirmation();
        $this->modalHeading(fn (): string => __('alfakit::actions.replicate.modal.heading', ['label' => $this->getRecordTitle()]));
        $this->modalSubmitActionLabel(__('alfakit::actions.replicate.modal.submit'));
        $this->successNotificationTitle(__('alfakit::actions.replicate.notification'));

        $this->authorize(fn (?Model $record): bool => $this->resourceAuthorizes('replicate', $record));

        $this->action(function (Model $record, array $data, ReplicateAction $action): Model {
            $replica = $record->replicate($action->evaluate($action->excludedAttributes));
            $replica->fill($data);

            if ($action->beforeReplicaSaved !== null) {
                $action->evaluate($action->beforeReplicaSaved, ['replica' => $replica, 'data' => $data]);
            }

            $replica->save();

            if ($action->afterReplicaSaved !== null) {
                $action->evaluate($action->afterReplicaSaved, ['replica' => $replica, 'data' => $data]);
            }

            return $replica;
        });

        $this->after(fn () => $this->sendSuccessNotification());
    }

    /**
     * @param  array<string>|Closure|null  $attributes
     */
    public function excludeAttributes(array|Closure|null $attributes): static
    {
        $this->excludedAttributes = $attributes;

        return $this;
    }

    public function beforeReplicaSaved(?Closure $callback): static
    {
        $this->beforeReplicaSaved = $callback;

        return $this;
    }

    public function afterReplicaSaved(?Closure $callback): static
    {
        $this->afterReplicaSaved = $callback;

        return $this;
    }
}
