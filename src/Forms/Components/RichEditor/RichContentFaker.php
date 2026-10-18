<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\RichEditor;

use AlfaSix\Alfakit\Forms\Components\RichEditor\Contracts\CanGenerateFakeConfiguration;
use Faker\Factory;
use Faker\Generator;
use Illuminate\Support\Traits\Macroable;
use JsonException;
use LogicException;
use RuntimeException;

/**
 * Fake RichEditor content for factories and tests, built as a Tiptap document:
 *
 *     RichContentFaker::make()->heading()->paragraphs(3)->bulletList()->toHtml();
 *     RichContentFaker::make()->paragraphs(2, links: true)->image()->toJson();   // `json()` editors
 *     RichContentFaker::make()->heading()->paragraphs()->toMarkdown();          // MarkdownEditor
 *
 * `toHtml()` is the HTML the editor stores (merge tags, mentions and custom blocks kept as
 * editor markers); `toRenderedHtml()` is what `RichContentRenderer` displays. Pass a seeded
 * `Faker\Generator` for reproducible content. Macroable, so plugins can add their nodes
 * (`block()` / `inline()` insert raw Tiptap nodes).
 *
 * Also on Faker: `fake()->alfakitRichContent()` (alias `filamentRichContent()`). Pass a
 * `RichContentAttribute` to pick custom blocks from it and store in its format (`toValue()`).
 */
final class RichContentFaker
{
    use Macroable;

    private const COLORS = ['#dc2626', '#ea580c', '#16a34a', '#2563eb', '#9333ea'];

    /** @var list<array<string, mixed>> */
    private array $content = [];

    private readonly Generator $faker;

    public function __construct(?Generator $faker = null, private readonly ?RichContentAttribute $attribute = null)
    {
        if ($faker === null && ! class_exists(Factory::class)) {
            throw new RuntimeException('RichContentFaker needs fakerphp/faker (composer require --dev fakerphp/faker).');
        }

        $this->faker = $faker ?? (function_exists('fake') ? fake() : Factory::create());
    }

    public static function make(?Generator $faker = null, ?RichContentAttribute $attribute = null): self
    {
        return new self($faker, $attribute);
    }

    public function heading(?string $text = null, int $level = 2): self
    {
        return $this->block([
            'type' => 'heading',
            'attrs' => ['level' => max(1, min(6, $level))],
            'content' => [$this->text($text ?? $this->sentence(4))],
        ]);
    }

    /**
     * @param  bool  $links  Link a few words
     * @param  bool  $bold  Make a few words bold (likewise `italic`, `underline`, `strike`,
     *                      `code`, `highlight`, `subscript`, `superscript`)
     */
    public function paragraphs(
        int $count = 1,
        bool $links = false,
        bool $bold = false,
        bool $italic = false,
        bool $underline = false,
        bool $strike = false,
        bool $code = false,
        bool $highlight = false,
        bool $subscript = false,
        bool $superscript = false,
    ): self {
        $marks = array_keys(array_filter(compact('bold', 'italic', 'underline', 'strike', 'code', 'highlight', 'subscript', 'superscript')));

        for ($i = 0; $i < max(1, $count); $i++) {
            $inline = [$this->text($this->sentence(10).' ')];

            foreach ($marks as $mark) {
                $inline[] = $this->text($this->words(2), [['type' => $mark]]);
                $inline[] = $this->text(' '.$this->sentence(6).' ');
            }

            if ($links) {
                $inline[] = $this->text($this->words(2), [['type' => 'link', 'attrs' => ['href' => $this->faker->url()]]]);
                $inline[] = $this->text(' '.$this->sentence(6));
            }

            $this->block(['type' => 'paragraph', 'content' => $this->mergeText($inline)]);
        }

        return $this;
    }

    public function paragraph(?string $text = null): self
    {
        return $this->block(['type' => 'paragraph', 'content' => [$this->text($text ?? $this->sentence(12))]]);
    }

    public function bulletList(int $items = 3): self
    {
        return $this->block(['type' => 'bulletList', 'content' => $this->listItems($items)]);
    }

    public function orderedList(int $items = 3): self
    {
        return $this->block(['type' => 'orderedList', 'content' => $this->listItems($items)]);
    }

    public function blockquote(?string $text = null): self
    {
        return $this->block(['type' => 'blockquote', 'content' => [['type' => 'paragraph', 'content' => [$this->text($text ?? $this->sentence(12))]]]]);
    }

    public function codeBlock(?string $code = null, ?string $language = null): self
    {
        $node = ['type' => 'codeBlock', 'content' => [$this->text($code ?? "echo 'Hello';")]];

        if ($language !== null) {
            $node['attrs'] = ['language' => $language];
        }

        return $this->block($node);
    }

    public function horizontalRule(): self
    {
        return $this->block(['type' => 'horizontalRule']);
    }

    /**
     * A table with a header row.
     */
    public function table(int $rows = 3, int $columns = 3): self
    {
        $cell = fn (string $type, string $text): array => ['type' => $type, 'content' => [['type' => 'paragraph', 'content' => [$this->text($text)]]]];
        $tableRows = [['type' => 'tableRow', 'content' => array_map(fn (): array => $cell('tableHeader', $this->words(1)), range(1, max(1, $columns)))]];

        for ($row = 1; $row < max(2, $rows); $row++) {
            $tableRows[] = ['type' => 'tableRow', 'content' => array_map(fn (): array => $cell('tableCell', $this->words(2)), range(1, max(1, $columns)))];
        }

        return $this->block(['type' => 'table', 'content' => $tableRows]);
    }

    /**
     * A collapsible section.
     */
    public function details(?string $summary = null, int $paragraphs = 1): self
    {
        return $this->block(['type' => 'details', 'content' => [
            ['type' => 'detailsSummary', 'content' => [$this->text($summary ?? $this->sentence(4))]],
            ['type' => 'detailsContent', 'content' => array_map(fn (): array => ['type' => 'paragraph', 'content' => [$this->text($this->sentence(12))]], range(1, max(1, $paragraphs)))],
        ]]);
    }

    public function image(?string $url = null, ?string $alt = null, ?int $width = null): self
    {
        $attrs = array_filter([
            'src' => $url ?? 'https://picsum.photos/seed/'.$this->faker->lexify('????????').'/800/400',
            'alt' => $alt ?? $this->words(3),
            'width' => $width,
        ], static fn (mixed $value): bool => $value !== null);

        return $this->block(['type' => 'paragraph', 'content' => [['type' => 'image', 'attrs' => $attrs]]]);
    }

    /**
     * A paragraph with a merge tag (`{{ id }}`).
     */
    public function mergeTag(string $id): self
    {
        return $this->inline(['type' => 'mergeTag', 'attrs' => ['id' => $id]]);
    }

    /**
     * @param  array<string>  $ids
     */
    public function mergeTags(array $ids): self
    {
        foreach ($ids as $id) {
            $this->mergeTag($id);
        }

        return $this;
    }

    public function mention(string $id, ?string $label = null, string $char = '@'): self
    {
        return $this->inline(['type' => 'mention', 'attrs' => ['id' => $id, 'label' => $label ?? $id, 'char' => $char]]);
    }

    /**
     * A paragraph with a few coloured words.
     */
    public function textColor(?string $color = null): self
    {
        $color ??= (string) $this->faker->randomElement(self::COLORS);

        return $this->block(['type' => 'paragraph', 'content' => [
            $this->text($this->sentence(6).' '),
            $this->text($this->words(2), [['type' => 'textColor', 'attrs' => ['color' => $color]]]),
            $this->text(' '.$this->sentence(4)),
        ]]);
    }

    /**
     * An aligned paragraph (`start`, `center`, `end`, `justify`).
     */
    public function textAlignment(string $alignment = 'center'): self
    {
        return $this->block(['type' => 'paragraph', 'attrs' => ['textAlign' => $alignment], 'content' => [$this->text($this->sentence(10))]]);
    }

    public function hardBreak(): self
    {
        return $this->block(['type' => 'paragraph', 'content' => [
            $this->text($this->sentence(5)),
            ['type' => 'hardBreak'],
            $this->text($this->sentence(5)),
        ]]);
    }

    /**
     * A custom block. Without a block, one of the attribute's custom blocks that can make
     * up its configuration (`CanGenerateFakeConfiguration`) is picked; without a
     * configuration, such a block generates one (`generateFakeConfiguration()`).
     *
     * @param  class-string<RichContentCustomBlock>|string|null  $block  A block class or id
     * @param  array<string, mixed>|null  $config
     */
    public function customBlock(?string $block = null, ?array $config = null): self
    {
        if ($block === null) {
            $candidates = array_values(array_filter(
                $this->getAttributeCustomBlocks(),
                static fn (string $class): bool => is_a($class, CanGenerateFakeConfiguration::class, true),
            ));

            if ($candidates === []) {
                throw new LogicException('No registered custom blocks can generate fake configuration (implement CanGenerateFakeConfiguration).');
            }

            $block = $this->faker->randomElement($candidates);
        }

        $class = is_subclass_of($block, RichContentCustomBlock::class) ? $block : $this->findAttributeCustomBlock($block);

        if ($config === null && $class !== null && is_a($class, CanGenerateFakeConfiguration::class, true)) {
            $config = $class::generateFakeConfiguration($this->faker);
        }

        $id = $class !== null ? $class::getId() : $block;

        return $this->block(['type' => 'customBlock', 'attrs' => ['id' => $id, 'config' => $config ?? []]]);
    }

    /**
     * @return list<class-string<RichContentCustomBlock>>
     */
    private function getAttributeCustomBlocks(): array
    {
        $blocks = [];
        $registered = $this->attribute?->getCustomBlocks() ?? [];

        array_walk_recursive($registered, static function (mixed $block) use (&$blocks): void {
            if (is_string($block) && is_subclass_of($block, RichContentCustomBlock::class)) {
                $blocks[] = $block;
            }
        });

        return $blocks;
    }

    /**
     * @return class-string<RichContentCustomBlock>|null
     */
    private function findAttributeCustomBlock(string $id): ?string
    {
        foreach ($this->getAttributeCustomBlocks() as $block) {
            if ($block::getId() === $id) {
                return $block;
            }
        }

        return null;
    }

    /**
     * Append a raw Tiptap block node.
     *
     * @param  array<string, mixed>  $node
     */
    public function block(array $node): self
    {
        $this->content[] = $node;

        return $this;
    }

    /**
     * Append a paragraph holding a raw inline node between some text.
     *
     * @param  array<string, mixed>  $node
     */
    public function inline(array $node): self
    {
        return $this->block(['type' => 'paragraph', 'content' => [
            $this->text($this->sentence(4).' '),
            $node,
            $this->text(' '.$this->sentence(4)),
        ]]);
    }

    /**
     * The Tiptap document (`RichEditor::json()` state).
     *
     * @return array{type: string, content: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return ['type' => 'doc', 'content' => $this->content];
    }

    /**
     * @throws JsonException
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The HTML a RichEditor stores (editor markers kept).
     */
    public function toHtml(): string
    {
        return RichContentRenderer::make($this->toArray())->toEditorHtml();
    }

    /**
     * The value to store: the JSON document for a `json()` rich content attribute, else
     * the editor HTML.
     *
     * @return string|array{type: string, content: list<array<string, mixed>>}
     */
    public function toValue(): string|array
    {
        return $this->attribute?->isJson() ? $this->toArray() : $this->toHtml();
    }

    /**
     * The HTML `RichContentRenderer` displays.
     */
    public function toRenderedHtml(?RichContentRenderer $renderer = null): string
    {
        return ($renderer ?? RichContentRenderer::make($this->toArray()))->toHtml();
    }

    /**
     * Markdown (MarkdownEditor state). Nodes Markdown has no syntax for keep their text.
     */
    public function toMarkdown(): string
    {
        return trim(implode("\n\n", array_filter(array_map(fn (array $node): string => $this->markdownBlock($node), $this->content), static fn (string $block): bool => $block !== '')))."\n";
    }

    public function toText(): string
    {
        return trim(html_entity_decode(strip_tags(str_replace(['</p>', '</h1>', '</h2>', '</h3>', '</li>', '<br>'], "\n", RichContentRenderer::make($this->toArray())->toHtml())), ENT_QUOTES | ENT_HTML5));
    }

    public function __toString(): string
    {
        return $this->toHtml();
    }

    // ---------------------------------------------------------------- Internals

    /**
     * @param  list<array<string, mixed>>  $marks
     * @return array<string, mixed>
     */
    private function text(string $text, array $marks = []): array
    {
        return $marks === [] ? ['type' => 'text', 'text' => $text] : ['type' => 'text', 'text' => $text, 'marks' => $marks];
    }

    /**
     * Drop empty text nodes (Tiptap rejects them).
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private function mergeText(array $nodes): array
    {
        return array_values(array_filter($nodes, static fn (array $node): bool => ($node['type'] ?? null) !== 'text' || ($node['text'] ?? '') !== ''));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listItems(int $count): array
    {
        return array_map(fn (): array => ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [$this->text($this->sentence(6))]]]], range(1, max(1, $count)));
    }

    private function sentence(int $words): string
    {
        return rtrim($this->faker->sentence($words), '.').'.';
    }

    private function words(int $count): string
    {
        $words = $this->faker->words($count);

        return implode(' ', is_array($words) ? $words : [$words]);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function markdownBlock(array $node, int $depth = 0): string
    {
        $content = is_array($node['content'] ?? null) ? $node['content'] : [];
        $inline = fn (): string => $this->markdownInline($content);

        return match ($node['type'] ?? null) {
            'heading' => str_repeat('#', (int) ($node['attrs']['level'] ?? 2)).' '.$inline(),
            'paragraph' => $inline(),
            'blockquote' => implode("\n", array_map(static fn (string $line): string => '> '.$line, explode("\n", implode("\n\n", array_map(fn (array $child): string => $this->markdownBlock($child), $content))))),
            'bulletList', 'orderedList' => implode("\n", array_map(
                fn (array $item, int $index): string => str_repeat('  ', $depth).(($node['type'] === 'orderedList') ? ($index + 1).'.' : '-').' '.implode(' ', array_map(fn (array $child): string => $this->markdownBlock($child, $depth + 1), is_array($item['content'] ?? null) ? $item['content'] : [])),
                $content,
                array_keys($content),
            )),
            'codeBlock' => '```'.(is_string($node['attrs']['language'] ?? null) ? $node['attrs']['language'] : '')."\n".$this->plainText($content)."\n```",
            'horizontalRule' => '---',
            'table' => $this->markdownTable($content),
            'details' => implode("\n\n", array_map(fn (array $child): string => $this->markdownBlock($child), $content)),
            'detailsSummary' => '**'.$inline().'**',
            'detailsContent' => implode("\n\n", array_map(fn (array $child): string => $this->markdownBlock($child), $content)),
            default => $inline(),
        };
    }

    /**
     * @param  array<mixed>  $nodes
     */
    private function markdownInline(array $nodes): string
    {
        $markdown = '';

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];

            $markdown .= match ($node['type'] ?? null) {
                'text' => $this->markdownMarks((string) ($node['text'] ?? ''), is_array($node['marks'] ?? null) ? $node['marks'] : []),
                'image' => '!['.($attrs['alt'] ?? '').']('.($attrs['src'] ?? '').')',
                'hardBreak' => "  \n",
                'mergeTag' => '{{ '.($attrs['id'] ?? '').' }}',
                'mention' => ($attrs['char'] ?? '@').($attrs['label'] ?? $attrs['id'] ?? ''),
                default => $this->markdownInline(is_array($node['content'] ?? null) ? $node['content'] : []),
            };
        }

        return $markdown;
    }

    /**
     * @param  array<mixed>  $marks
     */
    private function markdownMarks(string $text, array $marks): string
    {
        foreach ($marks as $mark) {
            $text = match (is_array($mark) ? ($mark['type'] ?? null) : null) {
                'bold' => '**'.$text.'**',
                'italic' => '*'.$text.'*',
                'strike' => '~~'.$text.'~~',
                'code' => '`'.$text.'`',
                'link' => '['.$text.']('.($mark['attrs']['href'] ?? '').')',
                default => $text,
            };
        }

        return $text;
    }

    /**
     * @param  array<mixed>  $rows
     */
    private function markdownTable(array $rows): string
    {
        $lines = [];

        foreach (array_values($rows) as $index => $row) {
            $cells = array_map(fn (mixed $cell): string => is_array($cell) ? str_replace('|', '\|', $this->plainText(is_array($cell['content'] ?? null) ? $cell['content'] : [])) : '', is_array($row['content'] ?? null) ? $row['content'] : []);
            $lines[] = '| '.implode(' | ', $cells).' |';

            if ($index === 0) {
                $lines[] = '| '.implode(' | ', array_fill(0, count($cells), '---')).' |';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<mixed>  $nodes
     */
    private function plainText(array $nodes): string
    {
        $text = '';

        foreach ($nodes as $node) {
            if (is_array($node)) {
                $text .= ($node['type'] ?? null) === 'text' ? (string) ($node['text'] ?? '') : $this->plainText(is_array($node['content'] ?? null) ? $node['content'] : []);
            }
        }

        return $text;
    }
}
