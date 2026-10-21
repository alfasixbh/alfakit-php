<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Tests\Support\DocsExampleChecker;

/*
 * Users and agents paste the PHP examples of the docs and the Boost skills as they are.
 * Complete examples (a class, enum, interface or trait, a namespace or `<?php`) must pass
 * `php -l`, import every AlfaKit class they use, and only call AlfaKit methods that exist;
 * every `AlfaSix\…` name in any example must exist. See DocsExampleChecker for the rules.
 */

$repo = dirname(__DIR__, 4);

$exampleFiles = (static function (string $repo): array {
    $files = [...glob($repo.'/packages/alfakit/resources/boost/skills/*/SKILL.md') ?: [], $repo.'/packages/alfakit/resources/boost/guidelines/core.blade.php'];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repo.'/docs/content', FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'md') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return array_combine(
        array_map(static fn (string $file): string => str_replace($repo.'/', '', $file), $files),
        array_map(static fn (string $file): array => [$file], $files),
    );
})($repo);

it('has PHP examples that compile against the current API', function (string $file): void {
    $problems = DocsExampleChecker::strayCode((string) file_get_contents($file));

    foreach (DocsExampleChecker::blocks((string) file_get_contents($file)) as $block) {
        foreach (DocsExampleChecker::check($block['code']) as $problem) {
            $problems[] = sprintf('line %d (%s): %s', $block['line'], $block['heading'] ?: 'top', $problem);
        }
    }

    expect($problems)->toBe([], basename(dirname($file)).'/'.basename($file).":\n".implode("\n", $problems));
})->with($exampleFiles);

it('finds the problems it is meant to find', function (): void {
    $code = <<<'PHP'
        namespace App\Alfakit\Resources;

        use AlfaSix\Alfakit\Forms\Components\Select;
        use AlfaSix\Alfakit\Resources\Resource;
        use AlfaSix\Alfakit\Tables\Columns\Missing;

        class PostResource extends Resource
        {
            protected static string $navigationIcon = 'file-text';

            public function getPages(): array
            {
                Select::make('status')->optionz([]);

                return [TextInput::make('title')->required()];
            }
        }
        PHP;

    expect(DocsExampleChecker::check($code))->toEqualCanonicalizing([
        'unknown class AlfaSix\Alfakit\Tables\Columns\Missing',
        'TextInput is used but not imported (use AlfaSix\Alfakit\Forms\Components\TextInput;)',
        'unknown method AlfaSix\Alfakit\Forms\Components\Select::optionz()',
        'PostResource::getPages() must be static (as in AlfaSix\Alfakit\Resources\Resource)',
        'PostResource::$navigationIcon must be typed ?string (as in AlfaSix\Alfakit\Resources\Resource), not string',
    ]);

    expect(DocsExampleChecker::check("class Broken {\n    public function x() {\n}"))->toHaveCount(1)
        ->and(DocsExampleChecker::check("class Broken {\n    public function x() {\n}")[0])->toStartWith('php -l:');
});

it('finds PHP that fell out of its code block', function (): void {
    $markdown = "1. A field:\n\n    ```php\n\n    ```\n\nuse AlfaSix\\Alfakit\\Forms\\Components\\Field;\n\n    class MapPicker extends Field\n    {\n    }\n    ```\n\n```alfakit-example name=\"table-basic\"\n\n```\n";

    expect(DocsExampleChecker::strayCode($markdown))->toBe([
        'line 3: empty ```php block',
        'line 7: PHP outside a code block: use AlfaSix\\Alfakit\\Forms\\Components\\Field;',
        'line 9: PHP outside a code block: class MapPicker extends Field',
    ])->and(DocsExampleChecker::strayCode("```php\nuse App\\Models\\Post;\n```\n"))->toBe([]);
});

it('leaves fragments alone except for unknown AlfaKit names', function (): void {
    expect(DocsExampleChecker::check("\$panel->colors(['primary' => '#000'])->whatever([...]);"))->toBe([])
        ->and(DocsExampleChecker::check("use AlfaSix\\Alfakit\\Nope;\n\$panel->x();"))->toBe(['unknown class AlfaSix\Alfakit\Nope']);
});
