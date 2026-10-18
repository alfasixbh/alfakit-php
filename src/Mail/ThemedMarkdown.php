<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Mail;

use Illuminate\Mail\Markdown;
use Illuminate\Support\HtmlString;

/**
 * Laravel's Markdown renderer with AlfaKit's components (`MailTheme::renderer()`); each
 * render resolves the mail brand once.
 */
class ThemedMarkdown extends Markdown
{
    /**
     * @param  string  $view
     * @param  array<string, mixed>  $data
     * @param  \TijsVerkoyen\CssToInlineStyles\CssToInlineStyles|null  $inliner
     */
    public function render($view, array $data = [], $inliner = null): HtmlString
    {
        return MailTheme::rendering(fn (): HtmlString => parent::render($view, $data, $inliner));
    }

    /**
     * @param  string  $view
     * @param  array<string, mixed>  $data
     */
    public function renderText($view, array $data = []): HtmlString
    {
        return MailTheme::rendering(fn (): HtmlString => parent::renderText($view, $data));
    }
}
