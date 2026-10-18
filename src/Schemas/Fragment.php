<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas;

use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Schemas\Components\Group;

/**
 * A reusable piece of schema: a named class whose `define()` returns components, usable
 * inside any `components([...])` like a single component. Generate one with
 * `php artisan make:alfakit-schema AddressFields`.
 *
 * ```php
 * class AddressFields extends Fragment
 * {
 *     public function define(): array
 *     {
 *         return [TextInput::make('street'), TextInput::make('city'), Select::make('country_id')->relationship()];
 *     }
 * }
 *
 * $schema->components([
 *     AddressFields::make()->statePath('billing'),          // billing.street, billing.city, …
 *     AddressFields::make()->relationship('shippingAddress'), // edits the related record
 *     AddressFields::make()->hidden(fn (Get $get) => ! $get('ships')),
 * ]);
 * ```
 *
 * A fragment is an invisible `Group`, so it serialises as one: `statePath()`,
 * `relationship()`, `hidden()`, `visible()`, `columns()` and `columnSpan()` all work.
 * `define()` runs once per instance; pass constructor arguments through `make()` to
 * parameterise it.
 */
abstract class Fragment extends Group
{
    protected ?string $type = 'Group';

    public function __construct()
    {
        parent::__construct([]);
    }

    /**
     * The components this fragment contributes.
     *
     * @return array<Component>
     */
    abstract public function define(): array;

    /**
     * @return array<Component>
     */
    public function getChildComponents(): array
    {
        $components = parent::getChildComponents();

        if ($components === []) {
            $this->childComponents = $components = $this->define();
        }

        return $components;
    }
}
