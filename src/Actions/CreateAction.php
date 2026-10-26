<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions;

use AlfaSix\Alfakit\Actions\Concerns\InteractsWithResource;
use AlfaSix\Alfakit\Support\Enums\Color;
use AlfaSix\Alfakit\Support\ModelLabel;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CreateAction extends Action
{
    use InteractsWithResource;

    /** The argument the "Create & create another" button runs the action with. */
    public const CREATE_ANOTHER_ARGUMENT = 'another';

    protected ?Closure $mutateDataUsing = null;

    protected ?Closure $createUsing = null;

    protected bool|Closure $canCreateAnother = true;

    protected ?Closure $modifyCreateAnotherActionUsing = null;

    /** @var bool|array<int, string>|Closure */
    protected bool|array|Closure $preserveFormDataWhenCreatingAnother = false;

    public static function getDefaultName(): string
    {
        return 'create';
    }

    public static function make(string $name = 'create'): static
    {
        return parent::make($name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(fn (): string => __('alfakit::actions.create.label', ['label' => $this->getModelLabel()]));
        $this->color(Color::Primary);
        $this->button();
        $this->modalHeading(fn (): string => __('alfakit::actions.create.modal.heading', ['label' => $this->getModelLabel()]));
        $this->modalSubmitActionLabel(__('alfakit::actions.create.modal.submit'));
        $this->successNotificationTitle(__('alfakit::actions.create.notification'));

        $this->url(function (): ?string {
            if ($this->hasForm()) {
                return null;
            }

            return $this->getResourceUrl('create');
        });

        $this->authorize(fn (): bool => $this->resourceAuthorizes('create'));

        $this->action(function (array $data, CreateAction $action): Model {
            $resource = $action->getResource();
            $model = $action->getModel()
                ?? ($resource !== null ? $resource::getModel() : null)
                ?? throw new RuntimeException('CreateAction needs a model: call ->model(Post::class) or use it inside a resource.');

            $data = $action->mutateDataUsing !== null ? $action->evaluate($action->mutateDataUsing, ['data' => $data]) : $data;

            return DB::transaction(function () use ($model, $data, $action, $resource): Model {
                if ($action->createUsing !== null) {
                    $record = $action->evaluate($action->createUsing, ['data' => $data, 'model' => $model]);
                } elseif (($relationship = $action->getRelationManagerRelationship()) !== null && method_exists($relationship, 'create')) {
                    // Through the manager's relationship; on a BelongsToMany the `withPivot()`
                    // fields fill the new pivot row.
                    [$attributes, $pivot] = $action->splitPivotData($data);

                    $record = $relationship instanceof BelongsToMany
                        ? $relationship->create($attributes, $pivot)
                        : $relationship->create($attributes);
                } else {
                    $record = new $model;
                    $record->fill($data);

                    // `scopeToUser()` resources: the new record belongs to the signed-in user.
                    if ($resource !== null) {
                        $resource::associateRecordWithUser($record);
                    }

                    // Tenancy panels: the new record belongs to the current tenant, as on create pages.
                    $tenant = alfakit()->getTenant();

                    if ($resource !== null && $tenant !== null && $resource::isScopedToTenant()) {
                        $record = $resource::associateRecordWithTenant($record, $tenant);
                    } else {
                        $record->save();
                    }
                }

                $action->getForm()?->record($record)->saveRelationships();

                return $record;
            });
        });

        $this->after(fn () => $this->sendSuccessNotification());
    }

    public function mutateDataUsing(?Closure $callback): static
    {
        $this->mutateDataUsing = $callback;

        return $this;
    }

    public function using(?Closure $callback): static
    {
        $this->createUsing = $callback;

        return $this;
    }

    public function createAnother(bool|Closure $condition = true): static
    {
        $this->canCreateAnother = $condition;

        return $this;
    }

    public function canCreateAnother(): bool
    {
        return (bool) $this->evaluate($this->canCreateAnother);
    }

    /**
     * Customise the "Create & create another" button: the closure receives it as an
     * `Action` and returns it changed (`fn (Action $action): Action => $action->label('Save
     * and add another')->icon('plus')`), or `false` to remove it.
     */
    public function createAnotherAction(?Closure $callback): static
    {
        $this->modifyCreateAnotherActionUsing = $callback;

        return $this;
    }

    /**
     * The "Create & create another" button (label, colour, icon), or null without one.
     */
    public function getCreateAnotherAction(): ?Action
    {
        if (! $this->hasForm() || ! $this->canCreateAnother()) {
            return null;
        }

        $action = Action::make('createAnother')
            ->label(__('alfakit::actions.create.create_another'))
            ->arguments([self::CREATE_ANOTHER_ARGUMENT => true]);

        return $this->resolveModalFooterAction($action, $this->modifyCreateAnotherActionUsing ?? true);
    }

    /**
     * Keep field values in the modal after "Create & create another": `true` keeps every
     * top-level field, a list keeps the named fields only. A closure may return either, or
     * (as in Filament) the `$data` to keep, `fn (array $data): array => Arr::only($data,
     * ['status'])`: the fields named by its keys are kept.
     *
     * @param  bool|array<int, string>|Closure  $fields
     */
    public function preserveFormDataWhenCreatingAnother(bool|array|Closure $fields = true): static
    {
        $this->preserveFormDataWhenCreatingAnother = $fields;

        return $this;
    }

    /**
     * @return bool|array<int, string>
     */
    public function getPreservedFormDataWhenCreatingAnother(): bool|array
    {
        $fields = $this->evaluate($this->preserveFormDataWhenCreatingAnother);

        if (! is_array($fields)) {
            return (bool) $fields;
        }

        // A `$data` subset (string keys) names the fields by its keys.
        $names = array_is_list($fields) ? $fields : array_keys($fields);

        return array_values(array_map('strval', $names));
    }

    /** Whether this run came from the modal's "Create & create another" button. */
    public function isCreatingAnother(): bool
    {
        return (bool) ($this->getArguments()[self::CREATE_ANOTHER_ARGUMENT] ?? false)
            && $this->hasForm()
            && $this->canCreateAnother();
    }

    /** "Create & create another" keeps the modal open for the next record. */
    public function shouldCloseModal(): bool
    {
        return ! $this->isCreatingAnother() && parent::shouldCloseModal();
    }

    public function getSuccessRedirectUrl(): ?string
    {
        return $this->isCreatingAnother() ? null : parent::getSuccessRedirectUrl();
    }

    /**
     * @return array<string, mixed>
     */
    public function toModalSchema(): array
    {
        $schema = parent::toModalSchema();

        if (($schema['submitLabel'] ?? null) === null || ($createAnother = $this->getCreateAnotherAction()) === null) {
            return $schema;
        }

        $preserve = $this->getPreservedFormDataWhenCreatingAnother();

        $schema['createAnotherLabel'] = $createAnother->getLabel();

        if (($color = $createAnother->getColor()) !== null) {
            $schema['createAnotherColor'] = $color;
        }

        if (($icon = $createAnother->getIcon()) !== null) {
            $schema['createAnotherIcon'] = $icon;
        }

        if ($preserve !== false && $preserve !== []) {
            $schema['preserveFormData'] = $preserve;
        }

        return $schema;
    }

    protected function getModelLabel(): string
    {
        $resource = $this->getResource();

        if ($resource !== null) {
            return $resource::getModelLabel();
        }

        $model = $this->getModel();

        return $model !== null ? ModelLabel::singular($model) : '';
    }
}
