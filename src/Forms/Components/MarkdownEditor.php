<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components;

use AlfaSix\Alfakit\Support\Html;
use Closure;

class MarkdownEditor extends RichEditor
{
    /** @var array<string|RichEditor\ToolbarButtonGroup>|Closure */
    protected array|Closure $toolbarButtons = [
        'bold', 'italic', 'strike', 'link', '|',
        'heading', 'blockquote', 'codeBlock', '|',
        'bulletList', 'orderedList', 'table', '|',
        'attachFiles', 'undo', 'redo',
    ];

    /**
     * The editor works on HTML, so it is loaded from the Markdown rendered here (raw HTML
     * in the Markdown is escaped); edits are converted back to Markdown in the browser. A
     * translatable editor renders one HTML document per language.
     */
    protected function getSchemaProps(): array
    {
        $state = $this->getState();

        if ($this->isTranslatable()) {
            $html = [];

            foreach (is_array($state) ? $state : [] as $code => $markdown) {
                $html[(string) $code] = is_string($markdown) && trim($markdown) !== '' ? Html::markdown($markdown) : null;
            }

            return [...parent::getSchemaProps(), 'html' => $html === [] ? null : $html];
        }

        return [
            ...parent::getSchemaProps(),
            'html' => is_string($state) && trim($state) !== '' ? Html::markdown($state) : null,
        ];
    }

    protected function mutateDehydratedState(mixed $state): mixed
    {
        if (is_string($state)) {
            $state = $this->storeFileAttachments($state);
        }

        return $state === '' ? null : $state;
    }
}
