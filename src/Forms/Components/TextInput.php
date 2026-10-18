<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components;

use AlfaSix\Alfakit\Forms\Components\Concerns\HasAffixes;
use Closure;

class TextInput extends Field
{
    use Concerns\CanBeTranslatable;
    use Concerns\CanTrimState;
    use HasAffixes;

    /** The pattern `tel()` validates against unless `telRegex()` replaces it (Filament's). */
    public const DEFAULT_TEL_REGEX = '/^[+]*[(]{0,1}[0-9]{1,4}[)]{0,1}[-\s\.\/0-9]*$/';

    protected string|Closure $inputType = 'text';

    protected bool|Closure $isEmail = false;

    protected bool|Closure $isNumeric = false;

    protected bool|Closure $isInteger = false;

    protected bool|Closure $isPassword = false;

    protected bool|Closure $isRevealable = false;

    protected bool|Closure $isTel = false;

    protected bool|Closure $isUrl = false;

    protected int|Closure|null $minLength = null;

    protected int|Closure|null $maxLength = null;

    protected int|float|Closure|null $minValue = null;

    protected int|float|Closure|null $maxValue = null;

    protected int|float|string|Closure|null $step = null;

    protected string|Closure|null $mask = null;

    protected string|bool|Closure|null $autocomplete = null;

    protected string|Closure|null $telRegex = null;

    /** @var array<string>|string|Closure|null */
    protected array|string|Closure|null $stripCharacters = null;

    protected bool|Closure $isCopyable = false;

    protected string|Closure|null $copyMessage = null;

    protected int|Closure|null $copyMessageDuration = null;

    protected string|Closure|null $inputMode = null;

    /** @var array<string>|Closure|null */
    protected array|Closure|null $datalist = null;

    protected bool|string|Closure|null $autocapitalize = null;

    public function type(string $type): static
    {
        $this->inputType = $type;

        return $this;
    }

    public function email(bool|Closure $condition = true): static
    {
        $this->isEmail = $condition;

        return $this;
    }

    public function numeric(bool|Closure $condition = true): static
    {
        $this->isNumeric = $condition;

        return $this;
    }

    public function integer(bool|Closure $condition = true): static
    {
        $this->isInteger = $condition;

        return $this;
    }

    public function password(bool|Closure $condition = true): static
    {
        $this->isPassword = $condition;

        return $this;
    }

    public function revealable(bool|Closure $condition = true): static
    {
        $this->isRevealable = $condition;

        return $this;
    }

    public function tel(bool|Closure $condition = true): static
    {
        $this->isTel = $condition;

        return $this;
    }

    public function url(bool|Closure $condition = true): static
    {
        $this->isUrl = $condition;

        return $this;
    }

    public function minLength(int|Closure|null $length): static
    {
        $this->minLength = $length;

        return $this;
    }

    public function maxLength(int|Closure|null $length): static
    {
        $this->maxLength = $length;

        return $this;
    }

    public function length(int|Closure $length): static
    {
        return $this->minLength($length)->maxLength($length);
    }

    public function minValue(int|float|Closure|null $value): static
    {
        $this->minValue = $value;

        return $this;
    }

    public function maxValue(int|float|Closure|null $value): static
    {
        $this->maxValue = $value;

        return $this;
    }

    public function step(int|float|string|Closure|null $step): static
    {
        $this->step = $step;

        return $this;
    }

    /**
     * Input mask pattern using `9` (digit), `a` (letter) and `*` (any) placeholders.
     */
    public function mask(string|Closure|null $mask): static
    {
        $this->mask = $mask;

        return $this;
    }

    /**
     * The `autocomplete` attribute. `false` is a shortcut for `off`, `true` for `on`.
     */
    public function autocomplete(string|bool|Closure|null $autocomplete = true): static
    {
        $this->autocomplete = $autocomplete;

        return $this;
    }

    public function getAutocomplete(): ?string
    {
        $autocomplete = $this->evaluate($this->autocomplete);

        return match ($autocomplete) {
            false => 'off',
            true => 'on',
            default => $autocomplete,
        };
    }

    /**
     * Replace the pattern a `tel()` input is validated against.
     */
    public function telRegex(string|Closure|null $regex): static
    {
        $this->telRegex = $regex;

        return $this;
    }

    public function getTelRegex(): string
    {
        return $this->evaluate($this->telRegex) ?? self::DEFAULT_TEL_REGEX;
    }

    /**
     * Characters removed from the value before it is validated and saved, e.g. the
     * thousands separators a `mask()` adds: `->stripCharacters(',')`.
     *
     * @param  array<string>|string|Closure|null  $characters
     */
    public function stripCharacters(array|string|Closure|null $characters): static
    {
        $this->stripCharacters = $characters;

        return $this;
    }

    /**
     * @return array<string>
     */
    public function getStripCharacters(): array
    {
        $characters = $this->evaluate($this->stripCharacters);

        return array_values(array_filter(
            is_array($characters) ? $characters : [(string) $characters],
            static fn (mixed $character): bool => is_string($character) && $character !== '',
        ));
    }

    /**
     * Show a button in the input that copies its value to the clipboard.
     *
     * @param  int|Closure|null  $copyMessageDuration  Milliseconds the confirmation stays visible
     */
    public function copyable(bool|Closure $condition = true, string|Closure|null $copyMessage = null, int|Closure|null $copyMessageDuration = null): static
    {
        $this->isCopyable = $condition;
        $this->copyMessage = $copyMessage ?? $this->copyMessage;
        $this->copyMessageDuration = $copyMessageDuration ?? $this->copyMessageDuration;

        return $this;
    }

    public function copyMessage(string|Closure|null $message): static
    {
        $this->copyMessage = $message;

        return $this;
    }

    /**
     * @param  int|Closure|null  $duration  Milliseconds the confirmation stays visible
     */
    public function copyMessageDuration(int|Closure|null $duration): static
    {
        $this->copyMessageDuration = $duration;

        return $this;
    }

    public function isCopyable(): bool
    {
        return (bool) $this->evaluate($this->isCopyable);
    }

    public function inputMode(string|Closure|null $mode): static
    {
        $this->inputMode = $mode;

        return $this;
    }

    /**
     * @param  array<string>|Closure|null  $options
     */
    public function datalist(array|Closure|null $options): static
    {
        $this->datalist = $options;

        return $this;
    }

    /**
     * The HTML `autocapitalize` hint for virtual keyboards: `'none'`/`'off'`,
     * `'sentences'`/`'on'`, `'words'` or `'characters'`. `true` means `'on'`
     * and `false` means `'off'`.
     */
    public function autocapitalize(bool|string|Closure|null $autocapitalize = 'on'): static
    {
        $this->autocapitalize = $autocapitalize;

        return $this;
    }

    public function getAutocapitalize(): ?string
    {
        $value = $this->evaluate($this->autocapitalize);

        return match (true) {
            $value === true => 'on',
            $value === false => 'off',
            is_string($value) && $value !== '' => $value,
            default => null,
        };
    }

    public function getInputType(): string
    {
        return match (true) {
            (bool) $this->evaluate($this->isEmail) => 'email',
            (bool) $this->evaluate($this->isPassword) => 'password',
            (bool) $this->evaluate($this->isNumeric), (bool) $this->evaluate($this->isInteger) => 'number',
            (bool) $this->evaluate($this->isTel) => 'tel',
            (bool) $this->evaluate($this->isUrl) => 'url',
            default => $this->evaluate($this->inputType),
        };
    }

    protected function getTypeValidationRules(): array
    {
        $rules = [];

        if ($this->evaluate($this->isEmail)) {
            $rules[] = 'email';
        }

        if ($this->evaluate($this->isInteger)) {
            $rules[] = 'integer';
        } elseif ($this->evaluate($this->isNumeric)) {
            $rules[] = 'numeric';
        } else {
            $rules[] = 'string';
        }

        if ($this->evaluate($this->isTel)) {
            $rules[] = 'regex:'.$this->getTelRegex();
        }

        if ($this->evaluate($this->isUrl)) {
            $rules[] = 'url';
        }

        $isNumber = in_array($this->getInputType(), ['number'], true);

        if (($min = $this->evaluate($this->minLength)) !== null && ! $isNumber) {
            $rules[] = "min:{$min}";
        }

        if (($max = $this->evaluate($this->maxLength)) !== null && ! $isNumber) {
            $rules[] = "max:{$max}";
        }

        if (($minValue = $this->evaluate($this->minValue)) !== null) {
            $rules[] = "min:{$minValue}";
        }

        if (($maxValue = $this->evaluate($this->maxValue)) !== null) {
            $rules[] = "max:{$maxValue}";
        }

        return $rules;
    }

    /**
     * Remove `stripCharacters()` and surrounding whitespace (`trim()`) from a string value.
     */
    protected function cleanState(mixed $state): mixed
    {
        if (is_string($state) && ($characters = $this->getStripCharacters()) !== []) {
            $state = str_replace($characters, '', $state);
        }

        return $this->trimState($state);
    }

    protected function shouldMutateStateForValidation(): bool
    {
        return parent::shouldMutateStateForValidation() || $this->isTrimmed() || $this->getStripCharacters() !== [];
    }

    protected function mutateStateForValidation(mixed $state): mixed
    {
        return $this->cleanState($state);
    }

    protected function mutateDehydratedState(mixed $state): mixed
    {
        $state = $this->cleanState($state);

        if ($state === '' || $state === null) {
            return null;
        }

        if ($this->evaluate($this->isInteger) && is_numeric($state)) {
            return (int) $state;
        }

        if ($this->evaluate($this->isNumeric) && is_numeric($state)) {
            return $state + 0;
        }

        return $state;
    }

    protected function getSchemaProps(): array
    {
        return [
            'inputType' => $this->getInputType(),
            'revealable' => $this->evaluate($this->isRevealable) ?: null,
            'minLength' => $this->evaluate($this->minLength),
            'maxLength' => $this->evaluate($this->maxLength),
            'min' => $this->evaluate($this->minValue),
            'max' => $this->evaluate($this->maxValue),
            'step' => $this->evaluate($this->step),
            'mask' => $this->evaluate($this->mask),
            'autocomplete' => $this->getAutocomplete(),
            'copyable' => $this->isCopyable() ?: null,
            'copyMessage' => $this->isCopyable() ? $this->evaluate($this->copyMessage) : null,
            'copyMessageDuration' => $this->isCopyable() ? $this->evaluate($this->copyMessageDuration) : null,
            'inputMode' => $this->evaluate($this->inputMode),
            'datalist' => $this->evaluate($this->datalist),
            'autocapitalize' => $this->getAutocapitalize(),
            ...$this->getAffixes(),
        ];
    }
}
