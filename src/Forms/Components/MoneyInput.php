<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components;

use AlfaSix\Alfakit\Forms\Components\Concerns\HasAffixes;
use AlfaSix\Alfakit\Support\Currency;
use Closure;
use Throwable;

/**
 * An amount of money in any ISO 4217 currency.
 *
 * The input shows the currency symbol where the user's locale puts it, accepts the
 * locale's decimal separator, and limits decimals to the currency's precision (BHD 3,
 * USD 2, JPY 0). Store major units (`12.500`) or integer minor units (`12500`):
 *
 * ```php
 * MoneyInput::make('price')->currency('EUR')->minValue(0);
 * MoneyInput::make('total')->currency(fn (Get $get) => $get('currency'))->minorUnits();
 * ```
 */
class MoneyInput extends Field
{
    use HasAffixes;

    protected string|Closure|null $currency = null;

    protected bool|Closure $isStoredInMinorUnits = false;

    protected int|float|Closure|null $minValue = null;

    protected int|float|Closure|null $maxValue = null;

    /** The currency code; defaults to the panel currency. Closures receive `$get`, `$record`, … */
    public function currency(string|Closure|null $currency): static
    {
        $this->currency = $currency;

        return $this;
    }

    /** Read and write integers of the smallest unit (cents, fils, yen). */
    public function minorUnits(bool|Closure $condition = true): static
    {
        $this->isStoredInMinorUnits = $condition;

        return $this;
    }

    /** Minimum amount, in major units. */
    public function minValue(int|float|Closure|null $value): static
    {
        $this->minValue = $value;

        return $this;
    }

    /** Maximum amount, in major units. */
    public function maxValue(int|float|Closure|null $value): static
    {
        $this->maxValue = $value;

        return $this;
    }

    public function getCurrency(): string
    {
        return Currency::normalize((string) ($this->evaluate($this->currency) ?? alfakit()->getCurrency()));
    }

    public function isStoredInMinorUnits(): bool
    {
        return (bool) $this->evaluate($this->isStoredInMinorUnits);
    }

    public function hydrateState(bool $useDefaults): void
    {
        parent::hydrateState($useDefaults);

        $state = $this->getState();

        if (! is_numeric($state)) {
            return;
        }

        try {
            $this->state($this->isStoredInMinorUnits()
                ? Currency::fromMinorUnits((int) $state, $this->getCurrency())
                : number_format((float) $state, Currency::decimals($this->getCurrency()), '.', ''));
        } catch (Throwable) {
            // Unknown currency: keep the raw value so validation can report it.
        }
    }

    protected function mutateDehydratedState(mixed $state): mixed
    {
        if ($state === null || $state === '' || ! is_numeric($state)) {
            return $state === '' ? null : $state;
        }

        $currency = $this->getCurrency();

        if ($this->isStoredInMinorUnits()) {
            return Currency::toMinorUnits($state, $currency);
        }

        return number_format(round((float) $state, Currency::decimals($currency)), Currency::decimals($currency), '.', '');
    }

    protected function getTypeValidationRules(): array
    {
        $rules = ['numeric'];

        try {
            $rules[] = 'decimal:0,'.Currency::decimals($this->getCurrency());
        } catch (Throwable) {
            // An invalid currency code is a developer error; don't hide it behind validation.
        }

        if (($min = $this->evaluate($this->minValue)) !== null) {
            $rules[] = "min:{$min}";
        }

        if (($max = $this->evaluate($this->maxValue)) !== null) {
            $rules[] = "max:{$max}";
        }

        return $rules;
    }

    protected function getSchemaProps(): array
    {
        $currency = $this->getCurrency();

        return [
            'currency' => $currency,
            'decimals' => Currency::decimals($currency),
            'min' => $this->evaluate($this->minValue),
            'max' => $this->evaluate($this->maxValue),
            ...$this->getAffixes(),
        ];
    }
}
