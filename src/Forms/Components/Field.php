<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components;

use AlfaSix\Alfakit\Forms\Components\Concerns\CanBeAutofocused;
use AlfaSix\Alfakit\Forms\Components\Concerns\CanBeReadOnly;
use AlfaSix\Alfakit\Forms\Components\Concerns\CanMutateStateForValidation;
use AlfaSix\Alfakit\Forms\Components\Concerns\HasErrorMessageSlots;
use AlfaSix\Alfakit\Forms\Components\Concerns\HasExtraFieldWrapperAttributes;
use AlfaSix\Alfakit\Forms\Components\Concerns\HasExtraInputAttributes;
use AlfaSix\Alfakit\Forms\Components\Concerns\HasHelperText;
use AlfaSix\Alfakit\Forms\Components\Concerns\HasName;
use AlfaSix\Alfakit\Forms\Components\Concerns\HasPlaceholder;
use AlfaSix\Alfakit\Forms\Components\Concerns\HasState;
use AlfaSix\Alfakit\Forms\Components\Concerns\HasValidationRules;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Schemas\Components\Concerns\HasContentSlots;
use AlfaSix\Alfakit\Schemas\Components\Concerns\HasFieldActions;
use AlfaSix\Alfakit\Support\Serializable;

/**
 * Base class for form inputs. A field owns a slice of the schema state identified by
 * its state path and contributes validation rules for it.
 */
abstract class Field extends Component
{
    use CanBeAutofocused;
    use CanBeReadOnly;
    use CanMutateStateForValidation;
    use HasContentSlots, HasErrorMessageSlots {
        HasErrorMessageSlots::getContentSlotNames insteadof HasContentSlots;
    }
    use HasExtraFieldWrapperAttributes;
    use HasExtraInputAttributes;
    use HasFieldActions;
    use HasHelperText;
    use HasName;
    use HasPlaceholder;
    use HasState;
    use HasValidationRules;

    final public function __construct(string $name)
    {
        $this->name($name);
    }

    public static function make(mixed ...$arguments): static
    {
        return parent::make(...$arguments);
    }

    public function toSchema(): array
    {
        return [
            ...parent::toSchema(),
            ...Serializable::compact([
                'name' => $this->getName(),
                'statePath' => $this->getStatePath(),
                'required' => $this->isMarkedAsRequired() ?: null,
                'readOnly' => $this->isReadOnly() ?: null,
                'autofocus' => $this->isAutofocused() ?: null,
                'placeholder' => $this->getPlaceholder(),
                'helperText' => $this->getHelperText(),
                'hint' => $this->getHint(),
                'hintIcon' => $this->getHintIcon(),
                'hintColor' => $this->getHintColor(),
                'inlineLabel' => $this->hasInlineLabel() ?: null,
                'live' => $this->makesLiveRequests() ? ['onBlur' => $onBlur = $this->isLiveOnBlur(), 'debounce' => $this->getLiveDebounce() ?? ($onBlur ? null : $this->getDefaultLiveDebounce())] : null,
                'extraInputAttributes' => $this->getExtraInputAttributes() ?: null,
                'extraFieldWrapperAttributes' => $this->getExtraFieldWrapperAttributes() ?: null,
                'allowHtmlValidationMessages' => $this->areHtmlValidationMessagesAllowed() ?: null,
                'slots' => $this->getContentSlotsSchema(),
                ...$this->getFieldActionsSchema(),
            ]),
        ];
    }
}
