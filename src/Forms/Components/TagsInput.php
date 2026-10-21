<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components;

use Closure;
use Illuminate\Support\Arr;

class TagsInput extends Field
{
    use Concerns\CanTrimState;

    protected string|Closure|null $separator = null;

    /** @var array<string>|Closure */
    protected array|Closure $suggestions = [];

    /** @var array<string>|Closure */
    protected array|Closure $splitKeys = ['Enter', ','];

    /** @var array<mixed> */
    protected array $nestedRecursiveRules = [];

    protected string|Closure|null $tagColor = null;

    protected bool|Closure $isReorderable = false;

    protected string|Closure|null $tagPrefix = null;

    protected string|Closure|null $tagSuffix = null;

    /**
     * Store the tags as a single delimited string instead of an array.
     */
    public function separator(string|Closure|null $separator): static
    {
        $this->separator = $separator;

        return $this;
    }

    /**
     * @param  array<string>|Closure  $suggestions
     */
    public function suggestions(array|Closure $suggestions): static
    {
        $this->suggestions = $suggestions;

        return $this;
    }

    /**
     * @param  array<string>|Closure  $keys
     */
    public function splitKeys(array|Closure $keys): static
    {
        $this->splitKeys = $keys;

        return $this;
    }

    /**
     * Validation rules applied to each individual tag.
     *
     * @param  string|array<mixed>  $rules
     */
    public function nestedRecursiveRules(string|array $rules): static
    {
        $this->nestedRecursiveRules = is_string($rules) ? explode('|', $rules) : $rules;

        return $this;
    }

    public function color(string|Closure|null $color): static
    {
        $this->tagColor = $color;

        return $this;
    }

    public function reorderable(bool|Closure $condition = true): static
    {
        $this->isReorderable = $condition;

        return $this;
    }

    public function tagPrefix(string|Closure|null $prefix): static
    {
        $this->tagPrefix = $prefix;

        return $this;
    }

    public function tagSuffix(string|Closure|null $suffix): static
    {
        $this->tagSuffix = $suffix;

        return $this;
    }

    protected function getDefaultStateWhenEmpty(): mixed
    {
        return [];
    }

    public function hydrateState(bool $useDefaults): void
    {
        parent::hydrateState($useDefaults);

        $separator = $this->evaluate($this->separator);
        $state = $this->getState();

        if ($separator !== null && is_string($state)) {
            $this->state($state === '' ? [] : explode($separator, $state));
        } elseif ($state === null) {
            $this->state([]);
        }
    }

    protected function shouldMutateStateForValidation(): bool
    {
        return parent::shouldMutateStateForValidation() || $this->isTrimmed();
    }

    /**
     * `trim()` trims every tag.
     */
    protected function mutateStateForValidation(mixed $state): mixed
    {
        return $this->trimState($state);
    }

    protected function mutateDehydratedState(mixed $state): mixed
    {
        $tags = array_values(array_filter((array) $this->trimState(Arr::wrap($state ?? [])), static fn (mixed $tag): bool => filled($tag)));
        $separator = $this->evaluate($this->separator);

        return $separator !== null ? implode($separator, $tags) : $tags;
    }

    protected function getTypeValidationRules(): array
    {
        return ['array'];
    }

    public function getValidationRulesByPath(): array
    {
        $rules = parent::getValidationRulesByPath();

        if ($rules !== [] && $this->nestedRecursiveRules !== []) {
            $rules[$this->getStatePath().'.*'] = $this->nestedRecursiveRules;
        }

        return $rules;
    }

    /**
     * A refused tag (`labels.1`) is named like the field ("The Labels field …"), not by its
     * path; the renderers show the message on the field.
     */
    public function getValidationAttributesByPath(): array
    {
        $attributes = parent::getValidationAttributesByPath();

        if ($this->nestedRecursiveRules !== []) {
            $attributes[$this->getStatePath().'.*'] = $this->getValidationAttribute();
        }

        return $attributes;
    }

    protected function getSchemaProps(): array
    {
        return [
            'suggestions' => $this->evaluate($this->suggestions) ?: null,
            'splitKeys' => $this->evaluate($this->splitKeys),
            'color' => $this->evaluate($this->tagColor),
            'reorderable' => $this->evaluate($this->isReorderable) ?: null,
            'tagPrefix' => $this->evaluate($this->tagPrefix),
            'tagSuffix' => $this->evaluate($this->tagSuffix),
        ];
    }
}
