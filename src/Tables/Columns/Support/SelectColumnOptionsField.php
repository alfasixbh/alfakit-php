<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Columns\Support;

use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Support\Html;
use Closure;
use Illuminate\Validation\Rules\In;

/**
 * The select field behind a `SelectColumn`: it lists, searches, labels and validates the
 * column's options (static, relationship or custom search). It is never rendered; the
 * table's filters schema carries it so the schema options endpoint can search it.
 *
 * @internal
 */
class SelectColumnOptionsField extends Select
{
    protected bool $allowsOptionsHtml = false;

    public function allowsOptionsHtml(bool $condition): static
    {
        $this->allowsOptionsHtml = $condition;

        return $this;
    }

    public function getSearchResults(string $search): array
    {
        return $this->sanitizeLabels(parent::getSearchResults($search));
    }

    public function getOptionLabelsFor(array $values): array
    {
        return $this->sanitizeLabels(parent::getOptionLabelsFor($values));
    }

    /**
     * The rule a submitted value must pass: `exists` against the relationship query, the
     * label lookup of custom options, or `in` the static options.
     */
    public function getColumnOptionRule(): Closure|In|null
    {
        return $this->getOptionValidationRule();
    }

    /**
     * Labels with HTML allowed are sanitised; otherwise they are plain text and escaped by
     * the renderer.
     *
     * @param  array<array-key, mixed>  $labels
     * @return array<string, string>
     */
    public function sanitizeLabels(array $labels): array
    {
        $result = [];

        foreach ($labels as $value => $label) {
            $result[(string) $value] = $this->allowsOptionsHtml ? Html::sanitize((string) $label) : (string) $label;
        }

        return $result;
    }
}
