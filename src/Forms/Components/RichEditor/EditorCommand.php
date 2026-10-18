<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\RichEditor;

use JsonSerializable;

/**
 * A Tiptap command the editor runs in the browser, e.g. `EditorCommand::make('setMark',
 * 'highlight', ['color' => '#fde047'])` runs `editor.chain().focus().setMark('highlight',
 * {color: '#fde047'}).run()`. Used by `RichEditorTool::command()` and returned by plugin
 * editor actions (`RichEditor::runCommands()`).
 *
 * Only command names are sent, never code; unknown commands do nothing.
 */
final class EditorCommand implements JsonSerializable
{
    /** @var list<mixed> */
    private readonly array $arguments;

    public function __construct(private readonly string $name, mixed ...$arguments)
    {
        $this->arguments = array_values($arguments);
    }

    public static function make(string $name, mixed ...$arguments): self
    {
        return new self($name, ...$arguments);
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return list<mixed>
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    /**
     * @return list<mixed> `[name, ...arguments]`
     */
    public function toArray(): array
    {
        return [$this->name, ...$this->arguments];
    }

    /**
     * @return list<mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
