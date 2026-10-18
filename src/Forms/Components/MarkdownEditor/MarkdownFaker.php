<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\MarkdownEditor;

use AlfaSix\Alfakit\Support\Html;
use Faker\Factory;
use Faker\Generator;
use Illuminate\Support\Traits\Macroable;
use RuntimeException;
use Stringable;

/**
 * Fake MarkdownEditor content for factories and tests:
 *
 *     MarkdownFaker::make()->heading()->paragraphs(3)->bulletList()->codeBlock('php')->toMarkdown();
 *
 * Pass a seeded `Faker\Generator` for reproducible content. Macroable.
 */
final class MarkdownFaker implements Stringable
{
    use Macroable;

    /** @var list<string> */
    private array $blocks = [];

    private readonly Generator $faker;

    public function __construct(?Generator $faker = null)
    {
        if ($faker === null && ! class_exists(Factory::class)) {
            throw new RuntimeException('MarkdownFaker needs fakerphp/faker (composer require --dev fakerphp/faker).');
        }

        $this->faker = $faker ?? (function_exists('fake') ? fake() : Factory::create());
    }

    public static function make(?Generator $faker = null): self
    {
        return new self($faker);
    }

    public function heading(?string $text = null, int $level = 2): self
    {
        return $this->block(str_repeat('#', max(1, min(6, $level))).' '.($text ?? $this->sentence(4)));
    }

    /**
     * @param  bool  $links  Add a link to each paragraph (likewise `bold`, `italic`, `strike`, `code`)
     */
    public function paragraphs(int $count = 1, bool $links = false, bool $bold = false, bool $italic = false, bool $strike = false, bool $code = false): self
    {
        for ($i = 0; $i < max(1, $count); $i++) {
            $parts = [$this->sentence(10)];

            foreach ([['**', $bold], ['*', $italic], ['~~', $strike], ['`', $code]] as [$syntax, $enabled]) {
                if ($enabled) {
                    $parts[] = $syntax.$this->words(2).$syntax.' '.$this->sentence(5);
                }
            }

            if ($links) {
                $parts[] = '['.$this->words(2).']('.$this->faker->url().') '.$this->sentence(5);
            }

            $this->block(implode(' ', $parts));
        }

        return $this;
    }

    public function paragraph(?string $text = null): self
    {
        return $this->block($text ?? $this->sentence(12));
    }

    public function bulletList(int $items = 3): self
    {
        return $this->block(implode("\n", array_map(fn (): string => '- '.$this->sentence(6), range(1, max(1, $items)))));
    }

    public function orderedList(int $items = 3): self
    {
        return $this->block(implode("\n", array_map(fn (int $index): string => $index.'. '.$this->sentence(6), range(1, max(1, $items)))));
    }

    public function blockquote(?string $text = null): self
    {
        return $this->block('> '.($text ?? $this->sentence(12)));
    }

    public function codeBlock(?string $language = null, ?string $code = null): self
    {
        return $this->block('```'.($language ?? '')."\n".($code ?? "echo 'Hello';")."\n```");
    }

    public function horizontalRule(): self
    {
        return $this->block('---');
    }

    public function table(int $rows = 3, int $columns = 3): self
    {
        $columns = max(1, $columns);
        $line = static fn (array $cells): string => '| '.implode(' | ', $cells).' |';
        $lines = [
            $line(array_map(fn (): string => $this->words(1), range(1, $columns))),
            $line(array_fill(0, $columns, '---')),
        ];

        for ($row = 1; $row < max(2, $rows); $row++) {
            $lines[] = $line(array_map(fn (): string => $this->words(2), range(1, $columns)));
        }

        return $this->block(implode("\n", $lines));
    }

    public function image(?string $url = null, ?string $alt = null): self
    {
        return $this->block('!['.($alt ?? $this->words(3)).']('.($url ?? 'https://picsum.photos/seed/'.$this->faker->lexify('????????').'/800/400').')');
    }

    /**
     * Append raw Markdown as a block.
     */
    public function block(string $markdown): self
    {
        $this->blocks[] = trim($markdown);

        return $this;
    }

    public function toMarkdown(): string
    {
        return implode("\n\n", $this->blocks)."\n";
    }

    /**
     * The Markdown rendered as display HTML (raw HTML escaped, sanitised).
     */
    public function toHtml(): string
    {
        return Html::markdown($this->toMarkdown());
    }

    public function __toString(): string
    {
        return $this->toMarkdown();
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
}
