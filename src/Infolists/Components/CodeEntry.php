<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Infolists\Components;

use AlfaSix\Alfakit\Forms\Components\CodeEditor\Enums\Language;
use BackedEnum;
use Closure;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use stdClass;
use Stringable;

/**
 * Highlighted, read-only code. Arrays and objects are shown as pretty-printed JSON.
 *
 * ```php
 * CodeEntry::make('payload')->grammar(Language::Json)->copyable()
 * ```
 *
 * The grammar is highlighted by the same tokenizer as the `CodeEditor` field (json, php,
 * javascript/js, typescript/ts, html, xml, css, sql, yaml, markdown, python, java, go,
 * cpp); other grammars are shown as plain code. Any string-backed enum is accepted, so a
 * Phiki `Grammar` case from migrated Filament code works too.
 */
class CodeEntry extends Entry
{
    protected BackedEnum|string|Closure|null $grammar = null;

    protected BackedEnum|string|Closure|null $lightTheme = null;

    protected BackedEnum|string|Closure|null $darkTheme = null;

    protected bool|Closure $isCopyable = false;

    protected string|Closure|null $copyMessage = null;

    protected int|Closure|null $copyMessageDuration = null;

    protected int|Closure $jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function grammar(BackedEnum|string|Closure|null $grammar): static
    {
        $this->grammar = $grammar;

        return $this;
    }

    /**
     * A theme name for light mode, exposed to CSS as `data-light-theme` on the code block.
     * The built-in colours follow the panel theme; style other names in your theme CSS.
     */
    public function lightTheme(BackedEnum|string|Closure|null $theme): static
    {
        $this->lightTheme = $theme;

        return $this;
    }

    /**
     * A theme name for dark mode, exposed to CSS as `data-dark-theme` on the code block.
     */
    public function darkTheme(BackedEnum|string|Closure|null $theme): static
    {
        $this->darkTheme = $theme;

        return $this;
    }

    public function copyable(bool|Closure $condition = true): static
    {
        $this->isCopyable = $condition;

        return $this;
    }

    public function copyMessage(string|Closure|null $message): static
    {
        $this->copyMessage = $message;

        return $this;
    }

    /**
     * How long the copy confirmation shows, in milliseconds.
     */
    public function copyMessageDuration(int|Closure|null $milliseconds): static
    {
        $this->copyMessageDuration = $milliseconds;

        return $this;
    }

    /**
     * The `JSON_*` flags used to print array state.
     */
    public function jsonFlags(int|Closure $flags): static
    {
        $this->jsonFlags = $flags;

        return $this;
    }

    public function getGrammar(): ?string
    {
        $grammar = $this->evaluate($this->grammar);

        if ($grammar instanceof BackedEnum) {
            $grammar = (string) $grammar->value;
        }

        if ($grammar === null && $this->isStructuredState($this->getState())) {
            return Language::Json->value;
        }

        return $grammar === null ? null : strtolower($grammar);
    }

    protected function getThemeName(BackedEnum|string|Closure|null $theme): ?string
    {
        $theme = $this->evaluate($theme);

        return $theme instanceof BackedEnum ? (string) $theme->value : $theme;
    }

    protected function isStructuredState(mixed $state): bool
    {
        return is_array($state) || $state instanceof Arrayable || $state instanceof JsonSerializable || $state instanceof stdClass;
    }

    protected function getFormattedState(): mixed
    {
        $state = $this->getState();

        if ($state === null || $state === '') {
            return null;
        }

        if ($this->isStructuredState($state)) {
            if ($state instanceof Arrayable) {
                $state = $state->toArray();
            }

            return (string) json_encode($state, (int) $this->evaluate($this->jsonFlags));
        }

        if ($state instanceof BackedEnum) {
            return (string) $state->value;
        }

        return is_scalar($state) || $state instanceof Stringable ? (string) $state : null;
    }

    protected function getSchemaProps(): array
    {
        return [
            'grammar' => $this->getGrammar(),
            'lightTheme' => $this->getThemeName($this->lightTheme),
            'darkTheme' => $this->getThemeName($this->darkTheme),
            'copyable' => $this->evaluate($this->isCopyable) ?: null,
            'copyMessage' => $this->evaluate($this->copyMessage),
            'copyMessageDuration' => $this->evaluate($this->copyMessageDuration),
        ];
    }
}
