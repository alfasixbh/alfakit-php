<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\RichEditor;

/**
 * The current labels of the mentions in rich content: each provider's `getLabels()`
 * (`getLabelsUsing()`, or its `items()`) is asked once for all of its mentioned ids. A
 * mention whose provider returns no label keeps the label it was stored with, else its id.
 *
 * Used by `RichContentRenderer` and by the RichEditor when it loads content, so renamed
 * users show their new name.
 */
final class MentionLabels
{
    private const HTML_MENTION = '/<span\b[^>]*\bdata-type="mention"[^>]*>(.*?)<\/span>/is';

    /**
     * @param  array<string, MentionProvider>  $providers  Keyed by trigger character
     * @param  array<string, array<string, string>>  $labels  Trigger character => id => label
     */
    private function __construct(private readonly array $providers, private array $labels = []) {}

    /**
     * @param  array<MentionProvider>  $providers
     * @param  string|array<mixed>|null  $content  HTML or a Tiptap JSON document
     */
    public static function for(array $providers, string|array|null $content): self
    {
        $keyed = [];

        foreach ($providers as $provider) {
            $keyed[$provider->getChar()] = $provider;
        }

        $labels = new self($keyed);

        if ($keyed === [] || $content === null || $content === '' || $content === []) {
            return $labels;
        }

        $ids = [];

        foreach (self::mentions($content) as [$char, $id]) {
            if (isset($keyed[$char]) && $id !== '') {
                $ids[$char][$id] = $id;
            }
        }

        foreach ($ids as $char => $charIds) {
            $labels->labels[$char] = $keyed[$char]->getLabels(array_values($charIds));
        }

        return $labels;
    }

    /**
     * The label to show: the provider's current one, else the stored one, else the id.
     */
    public function label(string $char, string $id, ?string $stored): string
    {
        $current = $this->labels[$char][$id] ?? null;

        if ($current !== null && $current !== '') {
            return $current;
        }

        return $stored !== null && $stored !== '' ? $stored : $id;
    }

    public function hasProviders(): bool
    {
        return $this->providers !== [];
    }

    /**
     * Content with every mention's label (`data-label` and text, or the JSON `label`)
     * replaced by its current label. For the editor: the markup is kept as is otherwise.
     *
     * @param  string|array<mixed>|null  $content
     * @return string|array<mixed>|null
     */
    public function apply(string|array|null $content): string|array|null
    {
        if (! $this->hasProviders() || $content === null) {
            return $content;
        }

        if (is_array($content)) {
            return $this->applyJson($content);
        }

        return (string) preg_replace_callback(self::HTML_MENTION, function (array $match): string {
            [$char, $id, $stored] = self::parseHtmlMention($match[0], $match[1]);

            if (! isset($this->providers[$char]) || $id === '') {
                return $match[0];
            }

            $label = $this->label($char, $id, $stored);
            $open = (string) preg_replace('/>.*$/s', '>', $match[0]);
            $open = preg_match('/\bdata-label\s*=\s*"[^"]*"/i', $open) === 1
                ? (string) preg_replace('/\bdata-label\s*=\s*"[^"]*"/i', 'data-label="'.e($label).'"', $open)
                : substr($open, 0, -1).' data-label="'.e($label).'">';

            return $open.e($char.$label).'</span>';
        }, $content);
    }

    /**
     * @param  array<mixed>  $node
     * @return array<mixed>
     */
    private function applyJson(array $node): array
    {
        if (($node['type'] ?? null) === 'mention' && is_array($node['attrs'] ?? null)) {
            $char = (string) ($node['attrs']['char'] ?? '@');
            $id = is_scalar($node['attrs']['id'] ?? null) ? (string) $node['attrs']['id'] : '';

            if (isset($this->providers[$char]) && $id !== '') {
                $stored = is_scalar($node['attrs']['label'] ?? null) ? (string) $node['attrs']['label'] : null;
                $node['attrs']['label'] = $this->label($char, $id, $stored);
            }

            return $node;
        }

        foreach ($node as $key => $value) {
            if (is_array($value) && $key !== 'attrs') {
                $node[$key] = $this->applyJson($value);
            }
        }

        return $node;
    }

    /**
     * Trigger character, id and stored label of every mention in the content.
     *
     * @param  string|array<mixed>  $content
     * @return list<array{0: string, 1: string, 2: ?string}>
     */
    private static function mentions(string|array $content): array
    {
        if (is_string($content)) {
            preg_match_all(self::HTML_MENTION, $content, $matches, PREG_SET_ORDER);

            return array_map(static fn (array $match): array => self::parseHtmlMention($match[0], $match[1]), $matches);
        }

        $found = [];

        if (($content['type'] ?? null) === 'mention' && is_array($content['attrs'] ?? null)) {
            $found[] = [
                (string) ($content['attrs']['char'] ?? '@'),
                is_scalar($content['attrs']['id'] ?? null) ? (string) $content['attrs']['id'] : '',
                is_scalar($content['attrs']['label'] ?? null) ? (string) $content['attrs']['label'] : null,
            ];
        }

        foreach ($content as $key => $value) {
            if (is_array($value) && $key !== 'attrs') {
                array_push($found, ...self::mentions($value));
            }
        }

        return $found;
    }

    /**
     * @return array{0: string, 1: string, 2: ?string}
     */
    private static function parseHtmlMention(string $tag, string $inner): array
    {
        $attribute = static function (string $name) use ($tag): ?string {
            $open = (string) preg_replace('/>.*$/s', '>', $tag);

            return preg_match('/\b'.preg_quote($name, '/').'\s*=\s*"([^"]*)"/i', $open, $match) === 1
                ? html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5)
                : null;
        };

        $text = html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5);
        $char = $attribute('data-char') ?? mb_substr($text, 0, 1);
        $stored = $attribute('data-label') ?? (str_starts_with($text, $char) ? mb_substr($text, mb_strlen($char)) : $text);

        return [$char === '' ? '@' : $char, (string) $attribute('data-id'), $stored];
    }
}
