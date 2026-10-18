<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Migration;

use PhpToken;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

/**
 * Rewrites one Filament PHP file to AlfaKit without Rector, working on `PhpToken`s (never on
 * the raw string): imports, fully-qualified names, `extends`/`implements`, docblocks, class
 * strings (`'App\Filament\...'`, `app_path('Filament/...')`), Heroicon and Color references,
 * property declarations that must match the AlfaKit parent, Livewire-isms on pages
 * (`$this->form->fill()`, `statePath('data')`, `$view`), panel middleware and plugins, and
 * `toolbarActions()` / `BulkActionGroup`. Whatever it cannot convert goes to the report
 * with its line number and is left in place.
 */
final class TokenRewriter
{
    /** @var array<int, PhpToken> */
    private array $tokens = [];

    /** @var array<int, string> */
    private array $out = [];

    private int $count = 0;

    private string $file = '';

    private ?string $namespace = null;

    /**
     * Imports keyed by their original alias.
     *
     * @var array<string, array{old: string, new: string|null, newAlias: string|null, slot: int, removed: bool, namespace: bool}>
     */
    private array $imports = [];

    /** @var array<int, array<int, array{alias: string, text: string}>> */
    private array $importSlots = [];

    /** @var array<int, string> */
    private array $importIndent = [];

    /** @var array<int, true> */
    private array $importTokenIndexes = [];

    private ?string $parent = null;

    private int $classOpen = -1;

    private int $classClose = -1;

    public function __construct(private readonly MigrationReport $report) {}

    /**
     * Rewrite `$code` (the contents of `$file`) and return the new contents. The file path is
     * only used for the report.
     */
    public function rewrite(string $file, string $code, bool $force = false): string
    {
        // `$force`: Rector already renamed the Filament classes; the remaining rules still apply.
        if (! $force && ! FilamentClassMap::mentionsFilament($code)) {
            return $code;
        }

        $this->file = $file;
        $this->tokens = PhpToken::tokenize($code);
        $this->count = count($this->tokens);
        $this->out = array_map(static fn (PhpToken $token): string => $token->text, $this->tokens);
        $this->namespace = null;
        $this->imports = [];
        $this->importSlots = [];
        $this->importIndent = [];
        $this->importTokenIndexes = [];
        $this->parent = null;
        $this->classOpen = -1;
        $this->classClose = -1;

        $this->rewriteNamespaceAndImports();
        $this->countConstructs(counted: ! $force);
        $this->findClass();
        $this->replaceUnsupportedParent();
        $this->renameIdentifiers();
        $this->rewriteStrings();
        $this->rewriteIconsAndColors();
        $this->rewriteEnumTypes();
        $this->rewriteMethodCalls();
        $this->rewriteClassMembers();
        $this->removeUnusedImports();
        $this->reportLeftovers();
        $this->insertTodoMarkers();

        return implode('', $this->out);
    }

    // ----------------------------------------------------------------------------------
    // Construct counting and TODO markers
    // ----------------------------------------------------------------------------------

    /**
     * Count the Filament (and plugin) class references in the code, the constructs the report
     * measures; and suggest the first-party plugins that replace imported plugin classes.
     */
    private function countConstructs(bool $counted = true): void
    {
        $sites = 0;

        for ($i = 0; $i < $this->count; $i++) {
            if (isset($this->importTokenIndexes[$i])) {
                continue;
            }

            $token = $this->tokens[$i];

            if ($token->is(T_STRING) && isset($this->imports[$token->text]) && FilamentClassMap::isFilament($this->imports[$token->text]['old']) && $this->isClassReference($i)) {
                $sites++;
            } elseif ($token->is([T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED]) && FilamentClassMap::isFilament($this->resolve($token->text))) {
                $sites++;
            } elseif ($token->is(T_CONSTANT_ENCAPSED_STRING) && preg_match('/^[\'"]heroicon-[osmc]-/', $token->text) === 1) {
                $sites++;
            }
        }

        if ($counted) {
            $this->report->converted($this->file, $sites);
        }

        foreach ($this->imports as $import) {
            $plugin = FilamentClassMap::pluginClasses()[$import['old']] ?? null;

            if ($plugin !== null && $plugin[1] !== null) {
                $this->report->suggestPlugin($plugin[1], '`'.self::basename($import['old']).'` became `'.$plugin[0].'`.');
            }
        }
    }

    /**
     * Write a `// TODO(alfakit-migration): …` line above every line of this file that got a
     * manual report entry (from this rewrite or, after Rector, from its rules). A marker that is
     * already there (a second run) is not repeated.
     */
    private function insertTodoMarkers(): void
    {
        $todos = [];

        foreach ($this->report->entries() as $section => $entries) {
            foreach ($entries as $entry) {
                if (! $entry['manual'] || $entry['line'] === null) {
                    continue;
                }

                if (! str_ends_with(str_replace('\\', '/', $this->file), $entry['file'])) {
                    continue;
                }

                $todos[$entry['line']][] = MigrationReport::todoText($entry);
            }
        }

        $written = 0;
        $existing = implode('', array_map(static fn (PhpToken $token): string => $token->text, $this->tokens));

        foreach ($todos as $line => $texts) {
            $index = $this->firstTokenOnLine($line);

            if ($index === null) {
                continue;
            }

            $indent = '';

            if ($index > 0 && $this->tokens[$index - 1]->is(T_WHITESPACE) && str_contains($this->tokens[$index - 1]->text, "\n")) {
                $indent = (string) substr((string) strrchr($this->tokens[$index - 1]->text, "\n"), 1);
            }

            $markers = '';

            foreach (array_unique($texts) as $text) {
                if (str_contains($existing, '// '.$text)) {
                    continue;
                }

                $markers .= '// '.$text."\n".$indent;
                $written++;
            }

            $this->out[$index] = $markers.$this->out[$index];
        }

        $this->report->markers($this->file, $written);
    }

    /**
     * The first token that starts line `$line` and can carry a comment before it (not inside a
     * string, an import that was removed, or a blanked call), or the next one after it.
     */
    private function firstTokenOnLine(int $line): ?int
    {
        for ($i = 0; $i < $this->count; $i++) {
            $token = $this->tokens[$i];

            if ($token->line < $line || $token->is(T_WHITESPACE)) {
                continue;
            }

            if ($token->is([T_ENCAPSED_AND_WHITESPACE, T_END_HEREDOC]) || $this->out[$i] === '') {
                continue;
            }

            $previous = $i > 0 ? $this->tokens[$i - 1] : null;

            if ($i === 0 || ($previous !== null && ($previous->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG]) && str_contains($previous->text, "\n")))) {
                return $i;
            }
        }

        return null;
    }

    // ----------------------------------------------------------------------------------
    // Plugin parents, enums used as values or types
    // ----------------------------------------------------------------------------------

    /**
     * `class OrdersBoard extends KanbanBoard` (a plugin page with no drop-in equivalent): give it
     * an AlfaKit base so the app keeps booting and report how to rebuild it.
     */
    private function replaceUnsupportedParent(): void
    {
        if ($this->classOpen < 0 || $this->parent === null) {
            return;
        }

        $old = $this->resolve($this->parentToken() === null ? '' : $this->tokens[(int) $this->parentToken()]->text);

        if (! FilamentClassMap::isFilament($old) || FilamentClassMap::target($old) !== null) {
            return;
        }

        $known = FilamentClassMap::pluginParents()[$old] ?? null;
        $base = $known[0] ?? self::fallbackBase($old, $this->namespace ?? '');
        $index = (int) $this->parentToken();

        $this->out[$index] = '\\'.$base;
        $this->parent = $base;

        if ($known !== null && $known[1] !== null) {
            $this->report->suggestPlugin($known[1], '`'.self::basename($old).'` subclasses need rebuilding.');
        }

        $this->report->add(MigrationReport::SECTION_CLASSES, $this->file, $this->tokens[$index]->line, '`'.$old.'` (parent class) has no AlfaKit equivalent; the class now extends `'.$base.'`', $known[2] ?? 'Move its logic onto the AlfaKit base class or a first-party plugin; see https://alfakit.dev/docs/getting-started/filament-comparison.', true);
    }

    private function parentToken(): ?int
    {
        for ($i = 0; $i < $this->classOpen; $i++) {
            if ($this->tokens[$i]->is(T_EXTENDS)) {
                return $this->nextSignificant($i);
            }
        }

        return null;
    }

    /**
     * The AlfaKit base for a subclass of an unknown Filament or plugin class, from the names.
     */
    private static function fallbackBase(string $parent, string $namespace): string
    {
        $haystack = $parent.'\\'.$namespace;
        $a = FilamentClassMap::ALFAKIT;

        return match (true) {
            str_contains($haystack, 'RelationManager') => $a.'Resources\\RelationManagers\\RelationManager',
            str_contains($haystack, 'Resources\\Pages') => $a.'Resources\\Pages\\Page',
            str_contains($haystack, 'Widget') => $a.'Widgets\\Widget',
            str_contains($haystack, 'Action') => $a.'Actions\\Action',
            str_contains($haystack, 'Column') => $a.'Tables\\Columns\\Column',
            str_contains($haystack, 'Entry') || str_contains($haystack, 'Infolist') => $a.'Infolists\\Components\\Entry',
            str_contains($haystack, 'Field') || str_contains($haystack, 'Forms') => $a.'Forms\\Components\\Field',
            str_contains($haystack, 'Resource') => $a.'Resources\\Resource',
            default => $a.'Pages\\Page',
        };
    }

    /**
     * `FontWeight::Bold` → `'bold'` (AlfaKit takes strings) and `Heroicon` as a parameter or
     * return type → `string`.
     */
    private function rewriteEnumTypes(): void
    {
        $enums = FilamentClassMap::enumStrings();

        for ($i = 0; $i < $this->count; $i++) {
            $token = $this->tokens[$i];

            if (isset($this->importTokenIndexes[$i]) || ! $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) || $this->out[$i] === '') {
                continue;
            }

            $class = $this->resolve($token->text);
            $next = $this->nextSignificant($i);

            if (isset($enums[$class]) && $next !== null && $this->tokens[$next]->is(T_DOUBLE_COLON)) {
                $member = $this->nextSignificant($next);
                $case = $member === null ? null : $this->tokens[$member]->text;

                if ($member !== null && $case !== null && isset($enums[$class][$case])) {
                    $this->blank($i, $member);
                    $this->out[$member] = "'{$enums[$class][$case]}'";
                }

                continue;
            }

            // A Filament enum (or Heroicon) left as a type: `getIcon(): Heroicon`, `FontWeight $weight`.
            if ((isset($enums[$class]) || $class === 'Filament\\Support\\Icons\\Heroicon') && $this->isTypePosition($i)) {
                $this->out[$i] = 'string';
            }
        }

        foreach (FilamentClassMap::pluginDocblockFixes()[$this->parent ?? ''] ?? [] as $pattern => $replacement) {
            for ($i = $this->classOpen + 1; $i > 0 && $i < $this->classClose; $i++) {
                if ($this->tokens[$i]->is(T_DOC_COMMENT)) {
                    $this->out[$i] = (string) preg_replace($pattern, $replacement, $this->out[$i]);
                }
            }
        }

        // `getFilamentAvatarUrl()` (HasAvatar) → `getAlfakitAvatarUrl()`, declarations and calls.
        $renamed = FilamentClassMap::renamedContractMethods();

        for ($i = 0; $i < $this->count; $i++) {
            if (! $this->tokens[$i]->is(T_STRING) || ! isset($renamed[$this->tokens[$i]->text])) {
                continue;
            }

            $previous = $this->previousSignificant($i);

            if ($previous !== null && $this->tokens[$previous]->is([T_FUNCTION, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON])) {
                $this->out[$i] = $renamed[$this->tokens[$i]->text];
                $this->report->converted($this->file);
            }
        }
    }

    private function isTypePosition(int $i): bool
    {
        $previous = $this->previousSignificant($i);
        $next = $this->nextSignificant($i);

        if ($previous === null || $next === null) {
            return false;
        }

        $before = $this->tokens[$previous]->text;
        $after = $this->tokens[$next];

        // Return type `): Heroicon`, union `string|Heroicon`, nullable `?Heroicon`, parameter `(Heroicon $icon`.
        if ($this->tokens[$previous]->is([T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_READONLY, T_VAR]) && $after->is(T_VARIABLE)) {
            return true;
        }

        return ($before === ':' && $this->tokens[(int) $this->previousSignificant($previous)]->text === ')')
            || in_array($before, ['|', '?', '(', ','], true) && ($after->is(T_VARIABLE) || in_array($after->text, ['|', '{', ';', ')'], true) || $after->is(T_ELLIPSIS))
            || ($before === ':' && in_array($after->text, ['{', ';'], true));
    }

    // ----------------------------------------------------------------------------------
    // Namespace and imports
    // ----------------------------------------------------------------------------------

    private function rewriteNamespaceAndImports(): void
    {
        $depth = 0;

        for ($i = 0; $i < $this->count; $i++) {
            $token = $this->tokens[$i];

            if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;

                continue;
            }

            if ($token->text === '}') {
                $depth--;

                continue;
            }

            if ($token->is(T_NAMESPACE) && $depth === 0) {
                $name = $this->nextSignificant($i);

                if ($name !== null && $this->tokens[$name]->is([T_STRING, T_NAME_QUALIFIED])) {
                    $this->namespace = $this->tokens[$name]->text;
                    $this->out[$name] = self::renameAppNamespace($this->namespace);
                }

                continue;
            }

            if ($token->is(T_USE) && $depth === 0) {
                $i = $this->rewriteImport($i);
            }
        }
    }

    /**
     * Rewrite one top-level `use` statement starting at `$start`; returns the index of its `;`.
     */
    private function rewriteImport(int $start): int
    {
        $end = $start;

        while ($end < $this->count && $this->tokens[$end]->text !== ';') {
            $end++;
        }

        $next = $this->nextSignificant($start);

        if ($next !== null && $this->tokens[$next]->is([T_FUNCTION, T_CONST])) {
            return $end;
        }

        $text = '';

        for ($i = $start + 1; $i < $end; $i++) {
            if (! $this->tokens[$i]->is([T_COMMENT, T_DOC_COMMENT])) {
                $text .= $this->tokens[$i]->text;
            }
        }

        $items = [];

        if (preg_match('/^\s*(.*?)\\\\?\s*\{(.*)\}\s*$/s', $text, $matches) === 1) {
            $prefix = rtrim(trim($matches[1]), '\\');

            foreach (explode(',', $matches[2]) as $part) {
                $part = trim($part);

                if ($part !== '') {
                    $items[] = self::parseImportItem($prefix.'\\'.$part);
                }
            }
        } else {
            foreach (explode(',', $text) as $part) {
                $part = trim($part);

                if ($part !== '') {
                    $items[] = self::parseImportItem($part);
                }
            }
        }

        $indent = '';

        if ($start > 0 && $this->tokens[$start - 1]->is(T_WHITESPACE)) {
            $indent = (string) preg_replace('/^.*\n/s', '', $this->tokens[$start - 1]->text);
        }

        $this->importIndent[$start] = $indent;
        $this->importSlots[$start] = [];

        foreach ($items as [$fqcn, $alias, $explicitAlias]) {
            $fqcn = ltrim($fqcn, '\\');
            $new = null;
            $removed = false;

            $isNamespace = false;

            if (FilamentClassMap::isFilament($fqcn)) {
                $new = FilamentClassMap::target($fqcn);
                $removed = $new === null;
                $isNamespace = $removed && self::isFilamentNamespace($fqcn);
            } elseif (self::renameAppNamespace($fqcn) !== $fqcn) {
                $new = self::renameAppNamespace($fqcn);
            }

            $newAlias = $new === null ? $alias : ($explicitAlias ? $alias : self::basename($new));

            // Two imports that map to the same class (v3 `Form` and `Infolist` → `Schema`) keep one statement.
            $duplicate = false;

            if ($new !== null) {
                foreach ($this->imports as $existing) {
                    if ($existing['new'] === $new && ! $existing['removed']) {
                        $duplicate = true;
                        $newAlias = $existing['newAlias'];
                    }
                }
            }

            $this->imports[$alias] = ['old' => $fqcn, 'new' => $new, 'newAlias' => $newAlias, 'slot' => $start, 'removed' => $removed, 'namespace' => $isNamespace];

            if ($duplicate) {
                continue;
            }

            $target = $new ?? $fqcn;
            $statement = 'use '.$target.($explicitAlias && $alias !== self::basename($target) ? ' as '.$alias : '').';';

            $this->importSlots[$start][] = ['alias' => $alias, 'text' => $statement];
        }

        for ($i = $start; $i <= $end; $i++) {
            $this->out[$i] = '';
            $this->importTokenIndexes[$i] = true;
        }

        $this->renderImportSlot($start);

        return $end;
    }

    /**
     * @return array{0: string, 1: string, 2: bool} FQCN, alias, whether the alias was explicit
     */
    private static function parseImportItem(string $item): array
    {
        $item = preg_replace('/\s+/', ' ', trim($item)) ?? $item;

        if (preg_match('/^(.+?)\s+as\s+(\w+)$/i', $item, $matches) === 1) {
            return [trim($matches[1]), $matches[2], true];
        }

        return [$item, self::basename($item), false];
    }

    private function renderImportSlot(int $slot): void
    {
        $statements = array_column($this->importSlots[$slot], 'text');

        if ($statements === []) {
            $this->out[$slot] = '';
            $this->eatNewlineAfterStatement($slot);

            return;
        }

        $this->out[$slot] = implode("\n".$this->importIndent[$slot], $statements);
    }

    /**
     * After blanking a statement, drop the line break that followed it so no empty line remains.
     */
    private function eatNewlineAfterStatement(int $start): void
    {
        $end = $start;

        while ($end < $this->count && $this->tokens[$end]->text !== ';') {
            $end++;
        }

        if ($end + 1 < $this->count && $this->tokens[$end + 1]->is(T_WHITESPACE) && str_starts_with($this->out[$end + 1], "\n")) {
            $this->out[$end + 1] = substr($this->out[$end + 1], 1);
        }
    }

    /**
     * Whether `$fqcn` is a Filament namespace that classes in the map live in
     * (`use Filament\Forms;` then `Forms\Components\TextInput::make()`).
     */
    private static function isFilamentNamespace(string $fqcn): bool
    {
        foreach (array_keys(FilamentClassMap::classes()) as $class) {
            if (str_starts_with($class, $fqcn.'\\')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add `use $fqcn;` to the import slot of the namespace import at `$alias`, once.
     */
    private function addImport(string $alias, string $fqcn): void
    {
        $slot = $this->imports[$alias]['slot'];
        $short = self::basename($fqcn);

        foreach ($this->importSlots[$slot] as $statement) {
            if ($statement['text'] === "use {$fqcn};") {
                return;
            }
        }

        $this->imports[$short] ??= ['old' => $fqcn, 'new' => $fqcn, 'newAlias' => $short, 'slot' => $slot, 'removed' => false, 'namespace' => false];
        $this->importSlots[$slot][] = ['alias' => $short, 'text' => "use {$fqcn};"];
        $this->renderImportSlot($slot);
    }

    public static function renameAppNamespace(string $name): string
    {
        foreach (['App\\Providers\\Filament' => 'App\\Providers\\Alfakit', 'App\\Filament' => 'App\\Alfakit'] as $from => $to) {
            if ($name === $from || str_starts_with($name, $from.'\\')) {
                return $to.substr($name, strlen($from));
            }
        }

        return $name;
    }

    private static function basename(string $fqcn): string
    {
        $position = strrpos($fqcn, '\\');

        return $position === false ? $fqcn : substr($fqcn, $position + 1);
    }

    /**
     * Resolve a name as written in the code to the FQCN it referred to before the rewrite.
     */
    private function resolve(string $name): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        if (str_starts_with($name, 'namespace\\')) {
            return ($this->namespace === null ? '' : $this->namespace.'\\').substr($name, 10);
        }

        $first = $name;
        $rest = '';

        if (str_contains($name, '\\')) {
            [$first, $rest] = explode('\\', $name, 2);
            $rest = '\\'.$rest;
        }

        if (isset($this->imports[$first])) {
            return $this->imports[$first]['old'].$rest;
        }

        return ($this->namespace === null ? '' : $this->namespace.'\\').$name;
    }

    /**
     * The AlfaKit (or unchanged) FQCN a name refers to after the rewrite.
     */
    private function resolveNew(string $name): string
    {
        $old = $this->resolve($name);

        if (FilamentClassMap::isFilament($old)) {
            return FilamentClassMap::target($old) ?? $old;
        }

        return self::renameAppNamespace($old);
    }

    // ----------------------------------------------------------------------------------
    // Class context
    // ----------------------------------------------------------------------------------

    private function findClass(): void
    {
        for ($i = 0; $i < $this->count; $i++) {
            if (! $this->tokens[$i]->is([T_CLASS, T_ENUM, T_TRAIT, T_INTERFACE])) {
                continue;
            }

            $previous = $this->previousSignificant($i);

            if ($previous !== null && $this->tokens[$previous]->is([T_DOUBLE_COLON, T_NEW])) {
                continue;
            }

            $j = $i;

            while ($j < $this->count && $this->tokens[$j]->text !== '{') {
                if ($this->tokens[$j]->is(T_EXTENDS)) {
                    $name = $this->nextSignificant($j);

                    if ($name !== null) {
                        $this->parent = $this->resolveNew($this->tokens[$name]->text);
                    }
                }

                $j++;
            }

            $this->classOpen = $j;
            $this->classClose = $this->matchClose($j);

            return;
        }
    }

    private function parentIsAlfakit(string ...$classes): bool
    {
        if ($this->parent === null) {
            return false;
        }

        foreach ($classes as $class) {
            $class = FilamentClassMap::ALFAKIT.$class;

            if ($this->parent === $class || (class_exists($this->parent) && class_exists($class) && is_subclass_of($this->parent, $class))) {
                return true;
            }
        }

        return false;
    }

    // ----------------------------------------------------------------------------------
    // Identifiers, strings, icons and colours
    // ----------------------------------------------------------------------------------

    private function renameIdentifiers(): void
    {
        for ($i = 0; $i < $this->count; $i++) {
            if (isset($this->importTokenIndexes[$i])) {
                continue;
            }

            $token = $this->tokens[$i];

            if ($token->is(T_NAME_FULLY_QUALIFIED)) {
                $old = ltrim($token->text, '\\');

                if (FilamentClassMap::isFilament($old)) {
                    $new = FilamentClassMap::target($old);

                    if ($new !== null) {
                        $this->out[$i] = '\\'.$new;
                    }
                } elseif (self::renameAppNamespace($old) !== $old) {
                    $this->out[$i] = '\\'.self::renameAppNamespace($old);
                }

                continue;
            }

            if ($token->is(T_NAME_QUALIFIED)) {
                [$first, $rest] = explode('\\', $token->text, 2);

                if (isset($this->imports[$first]) && $this->imports[$first]['namespace']) {
                    $target = FilamentClassMap::target($this->imports[$first]['old'].'\\'.$rest);

                    if ($target !== null) {
                        $this->out[$i] = self::basename($target);
                        $this->addImport($first, $target);
                    }
                } elseif (isset($this->imports[$first]) && $this->imports[$first]['newAlias'] !== null && $this->imports[$first]['newAlias'] !== $first) {
                    $this->out[$i] = $this->imports[$first]['newAlias'].'\\'.$rest;
                } elseif (self::renameAppNamespace($token->text) !== $token->text) {
                    $this->out[$i] = self::renameAppNamespace($token->text);
                }

                continue;
            }

            if ($token->is(T_STRING) && isset($this->imports[$token->text]) && $this->isClassReference($i)) {
                $import = $this->imports[$token->text];

                if ($import['newAlias'] !== null && $import['newAlias'] !== $token->text) {
                    $this->out[$i] = $import['newAlias'];
                }

                continue;
            }

            if ($token->is([T_DOC_COMMENT, T_COMMENT])) {
                $this->out[$i] = $this->rewriteComment($token->text);
            }
        }
    }

    /**
     * Whether the T_STRING at `$i` names a class (not a method, property, constant, function or named argument).
     */
    private function isClassReference(int $i): bool
    {
        $previous = $this->previousSignificant($i);
        $next = $this->nextSignificant($i);

        if ($previous !== null && $this->tokens[$previous]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST, T_NAMESPACE, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_GOTO, T_INSTEADOF])) {
            return false;
        }

        if ($previous !== null && $this->tokens[$previous]->text === '$') {
            return false;
        }

        if ($next !== null && $this->tokens[$next]->text === ':') {
            return false;
        }

        if ($next !== null && $this->tokens[$next]->text === '(' && ($previous === null || ! $this->tokens[$previous]->is(T_NEW))) {
            return false;
        }

        return true;
    }

    private function rewriteComment(string $comment): string
    {
        $comment = (string) preg_replace_callback('/\\\\?Filament\\\\[A-Za-z0-9_\\\\]+/', static function (array $matches): string {
            $leading = str_starts_with($matches[0], '\\') ? '\\' : '';
            $new = FilamentClassMap::target(ltrim($matches[0], '\\'));

            return $new === null ? $matches[0] : $leading.$new;
        }, $comment);

        $comment = (string) preg_replace_callback('/\\\\?App\\\\(?:Providers\\\\)?Filament(?:\\\\[A-Za-z0-9_\\\\]+)?/', static fn (array $matches): string => (str_starts_with($matches[0], '\\') ? '\\' : '').self::renameAppNamespace(ltrim($matches[0], '\\')), $comment);

        foreach ($this->imports as $alias => $import) {
            if ($import['newAlias'] !== null && $import['newAlias'] !== $alias) {
                $comment = (string) preg_replace('/(?<![\w\\\\$])'.preg_quote($alias, '/').'(?![\w\\\\])/', $import['newAlias'], $comment);
            }
        }

        return $comment;
    }

    private function rewriteStrings(): void
    {
        for ($i = 0; $i < $this->count; $i++) {
            $token = $this->tokens[$i];

            if (! $token->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE])) {
                continue;
            }

            $text = $this->out[$i];

            // Namespaces in strings: 'App\Filament\Resources' and "App\\Filament\\Resources".
            $text = (string) preg_replace_callback('/App(\\\\{1,2})(Providers\1)?Filament(?=\1|[\'"]|$)/', static fn (array $matches): string => 'App'.$matches[1].($matches[2] ?? '').'Alfakit', $text);

            // Filament class names in strings ('Filament\Pages\Dashboard').
            $text = (string) preg_replace_callback('/Filament(\\\\{1,2})([A-Za-z0-9_]+(?:\1[A-Za-z0-9_]+)*)/', static function (array $matches): string {
                $separator = $matches[1];
                $old = 'Filament\\'.str_replace($separator, '\\', $matches[2]);
                $new = FilamentClassMap::target($old);

                return $new === null ? $matches[0] : str_replace('\\', $separator, $new);
            }, $text);

            // Directory names: app_path('Filament/Resources') → app_path('Alfakit/Resources').
            $text = (string) preg_replace('/^([\'"])Filament\//', '$1Alfakit/', $text);
            $text = (string) preg_replace('/^([\'"])app\/Filament\//', '$1app/Alfakit/', $text);

            // Panel route names: route('filament.admin.resources.posts.edit') → 'alfakit.admin.resources.posts.edit'.
            $routed = (string) preg_replace('/^([\'"])filament\.([\w-]+)\.(resources|pages|auth|tenant|home)\b/', '$1alfakit.$2.$3', $text);

            if ($routed !== $text) {
                $text = $routed;
                $this->report->converted($this->file);
            }

            // Heroicon names: 'heroicon-o-users' → 'users'.
            if (preg_match('/^([\'"])heroicon-[osmc]-([a-z0-9-]+)\1$/', $text, $matches) === 1) {
                [$lucide, $known] = FilamentClassMap::icon($matches[2]);
                $text = $matches[1].$lucide.$matches[1];

                if (! $known) {
                    $this->report->add(MigrationReport::SECTION_ICONS, $this->file, $token->line, "Icon `heroicon-?-{$matches[2]}` became `{$lucide}`", 'Check the name exists at https://lucide.dev/icons and adjust it if needed.');
                }
            }

            $this->out[$i] = $text;
        }
    }

    private function rewriteIconsAndColors(): void
    {
        for ($i = 0; $i < $this->count; $i++) {
            $token = $this->tokens[$i];

            if (! $token->is([T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED])) {
                continue;
            }

            $colon = $this->nextSignificant($i);

            if ($colon === null || ! $this->tokens[$colon]->is(T_DOUBLE_COLON)) {
                continue;
            }

            $member = $this->nextSignificant($colon);

            if ($member === null || ! $this->tokens[$member]->is(T_STRING)) {
                continue;
            }

            $class = $this->resolve($token->text);

            // PanelsRenderHook::TOPBAR_END → \AlfaSix\Alfakit\Theming\Slot::TOPBAR_END
            if (isset(FilamentClassMap::renderHooks()[$class])) {
                $slot = FilamentClassMap::slotFor($class, $this->tokens[$member]->text);

                if ($slot !== null) {
                    $this->blank($i, $member);
                    $this->out[$member] = '\\'.FilamentClassMap::ALFAKIT.'Theming\\Slot::'.$slot;
                }

                continue;
            }

            // FilamentView::registerRenderHook(…) → \AlfaSix\Alfakit\Theming\Slots::register(…)
            if ($class === 'Filament\\Support\\Facades\\FilamentView' && $this->tokens[$member]->text === 'registerRenderHook') {
                $this->blank($i, $member);
                $this->out[$member] = '\\'.FilamentClassMap::ALFAKIT.'Theming\\Slots::register';
                $this->report->add(MigrationReport::SECTION_PANEL, $this->file, $token->line, '`FilamentView::registerRenderHook()` became `Slots::register()`', 'Slot content is HTML, a Blade view or schema components; `scopes:` takes page, resource, relation manager or widget classes (docs: design/slots).');

                continue;
            }

            if ($class === 'Filament\\Support\\Icons\\Heroicon') {
                [$lucide, $known] = FilamentClassMap::icon($this->tokens[$member]->text);
                $this->blank($i, $member);
                $this->out[$member] = "'{$lucide}'";

                if (! $known) {
                    $this->report->add(MigrationReport::SECTION_ICONS, $this->file, $token->line, "Icon `Heroicon::{$this->tokens[$member]->text}` became `{$lucide}`", 'Check the name exists at https://lucide.dev/icons and adjust it if needed.');
                }

                continue;
            }

            if ($class === 'Filament\\Support\\Colors\\Color') {
                $case = $this->tokens[$member]->text;
                $after = $this->nextSignificant($member);

                if (in_array($case, ['hex', 'rgb', 'generatePalette', 'generateV3Palette'], true) && $after !== null && $this->tokens[$after]->text === '(') {
                    $close = $this->matchClose($after);
                    $argument = trim($this->text($after + 1, $close - 1));
                    $this->blank($i, $close);
                    $this->out[$close] = $argument;

                    continue;
                }

                $colors = FilamentClassMap::colors();

                if (isset($colors[$case])) {
                    $this->blank($i, $member);
                    $this->out[$member] = "'{$colors[$case]}'";
                } else {
                    $this->report->add(MigrationReport::SECTION_PANEL, $this->file, $token->line, "`Color::{$case}` has no palette", 'Use a CSS colour string, e.g. `\'#2563eb\'`.');
                }
            }
        }
    }

    // ----------------------------------------------------------------------------------
    // Method calls
    // ----------------------------------------------------------------------------------

    private function rewriteMethodCalls(): void
    {
        $isPanelProvider = $this->parentIsAlfakit('PanelProvider');
        $isPage = $this->parentIsAlfakit('Pages\\Page', 'Widgets\\Widget', 'Resources\\RelationManagers\\RelationManager');
        $renamed = FilamentClassMap::renamedMethods();
        $removed = FilamentClassMap::removedMethods();
        $removedPanel = FilamentClassMap::removedPanelMethods();

        for ($i = 0; $i < $this->count; $i++) {
            if (! $this->tokens[$i]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                continue;
            }

            $name = $this->nextSignificant($i);

            if ($name === null || ! $this->tokens[$name]->is(T_STRING)) {
                continue;
            }

            $open = $this->nextSignificant($name);
            $method = $this->tokens[$name]->text;
            $line = $this->tokens[$name]->line;

            // $this->form->fill([...]) → $this->formData = [...]; $this->form->getState() → $this->getForm()->getState()
            if ($method === 'form' && $open !== null && $this->tokens[$open]->is(T_OBJECT_OPERATOR)) {
                $previous = $this->previousSignificant($i);
                $call = $this->nextSignificant($open);

                if ($previous !== null && $this->tokens[$previous]->text === '$this' && $call !== null && $this->tokens[$call]->is(T_STRING)) {
                    $paren = $this->nextSignificant($call);

                    if ($paren !== null && $this->tokens[$paren]->text === '(') {
                        $close = $this->matchClose($paren);

                        if ($this->tokens[$call]->text === 'fill' && $isPage) {
                            $arguments = trim($this->text($paren + 1, $close - 1));
                            $this->blank($i, $close);
                            $this->out[$i] = '->formData = '.($arguments === '' ? '[]' : $arguments);
                        } elseif ($isPage) {
                            $this->out[$name] = 'getForm()';
                            $this->report->add(MigrationReport::SECTION_METHODS, $this->file, $line, "`\$this->form->{$this->tokens[$call]->text}()` became `\$this->getForm()->{$this->tokens[$call]->text}()`", 'Pages hold the form state in `$this->formData`; actions receive the validated `$data`.');
                        } else {
                            $this->report->add(MigrationReport::SECTION_VIEWS, $this->file, $line, "Livewire form call `\$this->form->{$this->tokens[$call]->text}()`", 'Move this component to a `Standalone\\Form` (`make:alfakit-form`) or a page.');
                        }
                    }
                }

                continue;
            }

            if ($open === null || $this->tokens[$open]->text !== '(') {
                continue;
            }

            $close = $this->matchClose($open);
            $previous = $this->previousSignificant($i);
            $inChain = $previous !== null && in_array($this->tokens[$previous]->text, [')', ']'], true);
            $onThis = $previous !== null && $this->tokens[$previous]->text === '$this';

            // Livewire events on pages and widgets: `$this->dispatch('refresh')` has no AlfaKit
            // equivalent; the call becomes `null` (the code keeps running) and gets a TODO.
            if ($onThis && $isPage && in_array($method, ['dispatch', 'dispatchSelf', 'dispatchTo', 'js', 'skipRender', 'stream'], true)) {
                $call = trim(preg_replace('/\s+/', ' ', $this->text($previous, $close)) ?? '');
                $this->blank($previous, $close);
                $this->out[$previous] = 'null';
                $this->report->add(MigrationReport::SECTION_METHODS, $this->file, $line, 'Livewire call `'.mb_strimwidth($call, 0, 60, '…').'` was removed', 'Livewire events and JavaScript are not available: refresh data with an action (pages re-render after actions), `->poll()` on widgets and tables, or a frontend component.', true);

                continue;
            }

            // `Action::make('save')->submit('save')` (form actions of a custom page) → call the method.
            if ($method === 'submit' && $inChain && $isPage && ($argument = $this->nextSignificant($open)) !== null && $this->tokens[$argument]->is(T_CONSTANT_ENCAPSED_STRING) && $this->nextSignificant($argument) === $close) {
                $target = trim($this->tokens[$argument]->text, '\'"');

                if (preg_match('/^[A-Za-z_]\w*$/', $target) === 1) {
                    $this->out[$name] = 'action';
                    $this->blank($open + 1, $close - 1);
                    $this->out[$open + 1] = "fn () => \$this->{$target}()";
                    $this->report->note(MigrationReport::SECTION_METHODS, $this->file, $line, "`->submit('{$target}')` became `->action(fn () => \$this->{$target}())`", 'The page validates the form when the method calls `$this->getForm()->getState()`.');

                    continue;
                }
            }

            // `->recordTitleAttribute()` on a relation manager's table: AlfaKit reads the static property.
            if ($method === 'recordTitleAttribute' && ($inChain || $this->tokens[(int) $previous]->is(T_VARIABLE)) && $this->parentIsAlfakit('Resources\\RelationManagers\\RelationManager')) {
                $this->removeChainCall($i, $close);
                $this->report->note(MigrationReport::SECTION_METHODS, $this->file, $line, '`->recordTitleAttribute()` on the table was removed', 'Relation managers read `protected static ?string $recordTitleAttribute`; set it when it is missing.');

                continue;
            }

            if ($inChain && ($root = $this->chainRoot($i)) !== null && ! $this->tokens[$root]->is(T_VARIABLE)) {
                $classRenames = FilamentClassMap::classMethodRenames()[self::basename($this->resolveNew($this->tokens[$root]->text))] ?? [];

                if (isset($classRenames[$method])) {
                    $this->out[$name] = $classRenames[$method];
                    $this->report->note(MigrationReport::SECTION_METHODS, $this->file, $line, "`->{$method}()` became `->{$classRenames[$method]}()`");

                    continue;
                }
            }

            if ($method === 'toolbarActions' && $inChain) {
                $this->rewriteToolbarActions($i, $open, $close);

                continue;
            }

            if (in_array($method, ['bulkActions', 'groupedBulkActions'], true) && $inChain) {
                $this->out[$name] = 'bulkActions';
                $this->unwrapBulkActionGroups($open, $close);

                continue;
            }

            if ($method === 'statePath' && $isPage && trim($this->text($open + 1, $close - 1), " \n'\"") === 'data') {
                $this->removeChainCall($i, $close);

                continue;
            }

            $firstArgument = $this->nextSignificant($open);

            if ($method === 'view' && $inChain && $firstArgument !== null && $this->tokens[$firstArgument]->is(T_CONSTANT_ENCAPSED_STRING)) {
                $root = $this->chainRoot($i);
                $rootClass = $root === null ? '' : $this->resolveNew($this->tokens[$root]->text);

                if (! in_array(self::basename($rootClass), ['View', 'ViewColumn', 'ViewEntry', 'ViewField'], true)) {
                    $view = trim($this->tokens[$firstArgument]->text, '\'"');
                    $this->report->add(MigrationReport::SECTION_VIEWS, $this->file, $line, "Blade view `{$view}` on a component", 'Blade is not rendered: register a frontend component in `createAlfakit({ components })` and use `View::make(\'name\')`, `ViewColumn::make()->view(\'name\')` or a custom component (`make:alfakit-field|column|entry`).');
                }

                continue;
            }

            // ->renderHook(Slot::X, …) once the hook constant was converted: a panel slot.
            if ($isPanelProvider && $method === 'renderHook' && $inChain && $this->isSlotArgument(trim($this->text($open + 1, $close - 1)))) {
                $this->out[$name] = 'slot';
                $this->report->add(MigrationReport::SECTION_PANEL, $this->file, $line, '`->renderHook()` became `->slot()`', 'Slot content is HTML, a Blade view or schema components; check the slot renders where you expect (docs: design/slots).');

                continue;
            }

            if ($isPanelProvider && isset($removedPanel[$method]) && $inChain) {
                if (in_array($method, ['plugins', 'plugin'], true)) {
                    $this->reportPlugins($open, $close, $line);
                } else {
                    $argument = trim(preg_replace('/\s+/', ' ', $this->text($open + 1, $close - 1)) ?? '');
                    $this->report->add(MigrationReport::SECTION_PANEL, $this->file, $line, "`->{$method}(".mb_strimwidth($argument, 0, 60, '…').')`', $removedPanel[$method]);
                }

                $this->removeChainCall($i, $close);

                continue;
            }

            if ($isPanelProvider && $method === 'middleware' && $inChain) {
                $this->rewriteMiddleware($open, $close, $line);

                continue;
            }

            if ($isPanelProvider && $method === 'authMiddleware' && $inChain) {
                $this->removeArrayElements($open, $close, static fn (string $class): bool => in_array($class, ['Filament\\Http\\Middleware\\Authenticate', FilamentClassMap::ALFAKIT.'Http\\Middleware\\Authenticate'], true), null);

                if (trim($this->text($open + 1, $close - 1), " \n[]") === '') {
                    $this->removeChainCall($i, $close);
                }

                continue;
            }

            if ($isPanelProvider && in_array($method, ['widgets', 'pages', 'resources'], true) && $inChain) {
                $unsupported = FilamentClassMap::unsupported();
                $this->removeArrayElements($open, $close, static fn (string $class): bool => isset($unsupported[$class]), function (string $class) use ($unsupported, $line): void {
                    $this->report->add(MigrationReport::SECTION_PANEL, $this->file, $line, "`{$class}` removed from the panel", $unsupported[$class]);
                });

                continue;
            }

            if (isset($renamed[$method]) && $inChain) {
                [$newName, $arguments] = $renamed[$method];
                $this->out[$name] = $newName;

                if ($arguments !== null) {
                    $this->blank($open + 1, $close - 1);
                    $this->out[$close] = $arguments.')';
                }

                $this->report->add(MigrationReport::SECTION_METHODS, $this->file, $line, "`->{$method}()` became `->{$newName}(".($arguments ?? '').')`', '');

                continue;
            }

            if (isset($removed[$method]) && $inChain) {
                $this->removeChainCall($i, $close);
                $this->report->add(MigrationReport::SECTION_METHODS, $this->file, $line, "`->{$method}()`", ucfirst($removed[$method]).'.');
            }
        }
    }

    /**
     * `->toolbarActions([CreateAction::make(), BulkActionGroup::make([DeleteBulkAction::make()])])`
     * → `->headerActions([CreateAction::make()])->bulkActions([DeleteBulkAction::make()])`.
     */
    private function rewriteToolbarActions(int $arrow, int $open, int $close): void
    {
        $array = $this->nextSignificant($open);

        if ($array === null || $this->tokens[$array]->text !== '[') {
            $this->out[$this->nextSignificant($arrow) ?? $arrow] = 'headerActions';

            return;
        }

        $arrayClose = $this->matchClose($array);
        $header = [];
        $bulk = [];

        foreach ($this->arrayElements($array, $arrayClose) as [$from, $to]) {
            $text = trim($this->text($from, $to));

            if ($this->isBulkActionGroup($from)) {
                foreach ($this->innerElements($from, $to) as $inner) {
                    $bulk[] = $inner;
                }
            } elseif (str_contains($text, 'Bulk')) {
                $bulk[] = $text;
            } else {
                $header[] = $text;
            }
        }

        $indent = $this->indentBefore($arrow);
        $replacement = '';

        if ($header !== []) {
            $replacement .= '->headerActions('.$this->renderArray($header, $indent).')';
        }

        if ($bulk !== []) {
            $replacement .= ($replacement === '' ? '' : "\n".$indent).'->bulkActions('.$this->renderArray($bulk, $indent).')';
        }

        $this->blank($arrow, $close);
        $this->out[$arrow] = $replacement;
    }

    /**
     * `->bulkActions([BulkActionGroup::make([A, B]), C])` → `->bulkActions([A, B, C])`.
     */
    private function unwrapBulkActionGroups(int $open, int $close): void
    {
        $array = $this->nextSignificant($open);

        if ($array === null || $this->tokens[$array]->text !== '[') {
            return;
        }

        $arrayClose = $this->matchClose($array);
        $elements = [];
        $changed = false;

        foreach ($this->arrayElements($array, $arrayClose) as [$from, $to]) {
            if ($this->isBulkActionGroup($from)) {
                $changed = true;
                array_push($elements, ...$this->innerElements($from, $to));
            } else {
                $elements[] = trim($this->text($from, $to));
            }
        }

        if (! $changed) {
            return;
        }

        $indent = $this->indentBefore($open);
        $this->blank($array, $arrayClose);
        $this->out[$array] = $this->renderArray($elements, $indent);
    }

    private function isBulkActionGroup(int $index): bool
    {
        $token = $this->tokens[$index];

        if (! $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            return false;
        }

        return self::basename($this->resolve($token->text)) === 'BulkActionGroup';
    }

    /**
     * The elements of the array passed to `X::make([...])` starting at `$from`.
     *
     * @return array<int, string>
     */
    private function innerElements(int $from, int $to): array
    {
        for ($i = $from; $i <= $to; $i++) {
            if ($this->tokens[$i]->text === '[') {
                $close = $this->matchClose($i);

                return array_map(fn (array $range): string => trim($this->text($range[0], $range[1])), $this->arrayElements($i, $close));
            }
        }

        return [];
    }

    /**
     * @param  array<int, string>  $elements
     */
    private function renderArray(array $elements, string $indent): string
    {
        if ($elements === []) {
            return '[]';
        }

        $inner = $indent.'    ';

        return "[\n".implode('', array_map(static fn (string $element): string => $inner.$element.",\n", $elements)).$indent.']';
    }

    /**
     * Replace the standard Filament middleware stack with `['web']`, drop middleware that no longer exists.
     */
    private function rewriteMiddleware(int $open, int $close, int $line): void
    {
        $array = $this->nextSignificant($open);

        if ($array === null || $this->tokens[$array]->text !== '[') {
            return;
        }

        $arrayClose = $this->matchClose($array);
        $standard = FilamentClassMap::standardWebMiddleware();
        $allStandard = true;

        foreach ($this->arrayElements($array, $arrayClose) as [$from, $to]) {
            $class = $this->elementClass($from, $to);

            if ($class === null || ! in_array($class, $standard, true)) {
                $allStandard = false;
            }
        }

        if ($allStandard) {
            $this->blank($array, $arrayClose);
            $this->out[$array] = "['web']";
            $this->report->add(MigrationReport::SECTION_PANEL, $this->file, $line, 'Panel middleware stack', "Replaced Filament's explicit session/cookie/CSRF middleware with the `web` group; AlfaKit adds `SetUpPanel`, locale and Inertia middleware itself.");

            return;
        }

        $unsupported = FilamentClassMap::unsupported();
        $this->removeArrayElements($open, $close, static fn (string $class): bool => isset($unsupported[$class]), null);
    }

    /**
     * Remove `X::class` elements from the array argument whose class matches.
     *
     * @param  callable(string): bool  $matches
     * @param  (callable(string): void)|null  $onRemove
     */
    private function removeArrayElements(int $open, int $close, callable $matches, ?callable $onRemove): void
    {
        $array = $this->nextSignificant($open);

        if ($array === null || $this->tokens[$array]->text !== '[') {
            return;
        }

        $arrayClose = $this->matchClose($array);

        foreach ($this->arrayElements($array, $arrayClose) as [$from, $to]) {
            $class = $this->elementClass($from, $to);

            if ($class === null || ! $matches($class)) {
                continue;
            }

            $end = $to;

            // Also remove the trailing comma and the whitespace before the element.
            $next = $this->nextSignificant($to);

            if ($next !== null && $this->tokens[$next]->text === ',') {
                $end = $next;
            }

            $start = $from;

            while ($start > $array + 1 && $this->tokens[$start - 1]->is(T_WHITESPACE)) {
                $start--;
            }

            $this->blank($start, $end);

            if ($onRemove !== null) {
                $onRemove($class);
            }
        }

        if (trim($this->text($array + 1, $arrayClose - 1)) === '') {
            $this->blank($array + 1, $arrayClose - 1);
        }
    }

    /**
     * The class behind an `X::class` array element (resolved to its original FQCN), or null.
     */
    private function elementClass(int $from, int $to): ?string
    {
        $first = $this->tokens[$from];

        if (! $first->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            return null;
        }

        $colon = $this->nextSignificant($from);
        $member = $colon === null ? null : $this->nextSignificant($colon);

        if ($colon === null || $member === null || $member > $to || ! $this->tokens[$colon]->is(T_DOUBLE_COLON) || $this->tokens[$member]->text !== 'class') {
            return null;
        }

        return $this->resolve($first->text);
    }

    private function reportPlugins(int $open, int $close, int $line): void
    {
        $array = $this->nextSignificant($open);
        $elements = $array !== null && $this->tokens[$array]->text === '['
            ? $this->arrayElements($array, $this->matchClose($array))
            : [[$array ?? $open + 1, $this->previousSignificant($close) ?? $close - 1]];

        foreach ($elements as [$from, $to]) {
            $text = trim(preg_replace('/\s+/', ' ', $this->text($from, $to)) ?? '');

            if ($text === '') {
                continue;
            }

            $class = $this->tokens[$from]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) ? $this->resolve($this->tokens[$from]->text) : $text;

            if (($slug = FilamentClassMap::pluginSlugFor($class)) !== null) {
                $this->report->suggestPlugin($slug, '`'.self::basename($class).'` was registered on the panel.');
            }

            $this->report->add(MigrationReport::SECTION_PLUGINS, $this->file, $line, "Plugin `{$class}` removed from the panel", self::pluginSuggestion($class));
        }
    }

    public static function pluginSuggestion(string $class): string
    {
        $slug = FilamentClassMap::pluginSlugFor($class);

        if ($slug !== null) {
            return "Replaced by the first-party `{$slug}` plugin: `php artisan alfakit:add {$slug}` installs it and registers it on the panel (alfasix/alfakit-{$slug}).";
        }

        $lower = strtolower($class);

        foreach ([
            'shield' => 'bezhansalleh/filament-shield',
            'impersonat' => 'stechstudio/filament-impersonate',
            'breezy' => 'jeffgreco13/filament-breezy',
            'editprofile' => 'joaopaulolndev/filament-edit-profile',
            'translatable' => 'filament/spatie-laravel-translatable-plugin',
            'medialibrary' => 'filament/spatie-laravel-media-library-plugin',
            'excel' => 'pxlrbt/filament-excel',
            'import' => 'konnco/filament-import',
            'tiptap' => 'awcodes/filament-tiptap-editor',
            'curator' => 'awcodes/filament-curator',
            'apexcharts' => 'leandrocfe/filament-apex-charts',
            'fullcalendar' => 'saade/filament-fullcalendar',
            'activitylog' => 'filament/spatie-laravel-activitylog-plugin',
            'logger' => 'z3d0x/filament-logger',
            'googlefonts' => 'filament/spatie-laravel-google-fonts-plugin',
            'settings' => 'filament/spatie-laravel-settings-plugin',
            'spark' => 'filament/spark-billing-provider',
        ] as $needle => $package) {
            if (str_contains($lower, $needle)) {
                return FilamentClassMap::plugins()[$package].' (`'.$package.'`)';
            }
        }

        return 'No AlfaKit equivalent yet: implement `AlfaSix\\Alfakit\\Contracts\\Plugin` (`make:alfakit-plugin`) or use the built-in feature it wrapped.';
    }

    // ----------------------------------------------------------------------------------
    // Class members
    // ----------------------------------------------------------------------------------

    private function rewriteClassMembers(): void
    {
        if ($this->classOpen < 0) {
            return;
        }

        $reflection = $this->parent !== null && class_exists($this->parent) ? new ReflectionClass($this->parent) : null;
        $isPage = $this->parentIsAlfakit('Pages\\Page', 'Widgets\\Widget', 'Resources\\RelationManagers\\RelationManager');
        $isPlainPage = $this->parentIsAlfakit('Pages\\Page');
        $isChart = $this->parentIsAlfakit('Widgets\\ChartWidget');
        $depth = 0;

        for ($i = $this->classOpen + 1; $i < $this->classClose; $i++) {
            $token = $this->tokens[$i];

            if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;

                continue;
            }

            if ($token->text === '}') {
                $depth--;

                continue;
            }

            if ($depth !== 0) {
                continue;
            }

            if ($token->is(T_FUNCTION)) {
                $name = $this->nextSignificant($i);

                if ($name === null || ! $this->tokens[$name]->is(T_STRING)) {
                    continue;
                }

                $open = $this->nextSignificant($name);
                $close = $open === null ? null : $this->matchClose($open);

                if ($open === null || $close === null) {
                    continue;
                }

                if ($isPlainPage && $this->tokens[$name]->text === 'mount') {
                    if (trim($this->text($open + 1, $close - 1)) === '') {
                        $this->out[$close] = 'mixed ...$parameters)';
                    } else {
                        $this->report->add(MigrationReport::SECTION_METHODS, $this->file, $token->line, '`mount()` with parameters', 'AlfaKit pages receive route parameters as `mount(mixed ...$parameters)`; read them with `request()->route()` or from `$parameters`.');
                    }
                }

                // Filament's `ChartWidget::getType()` returns the chart type ('line'); AlfaKit's
                // `getType()` is the schema type, so the override becomes `getChartType()`.
                if ($isChart && $this->tokens[$name]->text === 'getType') {
                    $this->out[$name] = 'getChartType';

                    continue;
                }

                if ($reflection !== null) {
                    $this->alignMethod($reflection, $i, $name, $close);
                }

                continue;
            }

            if (! $token->is(T_VARIABLE)) {
                continue;
            }

            $declaration = $this->propertyDeclaration($i);

            if ($declaration === null) {
                continue;
            }

            [$start, $modifiers, $typeStart, $typeEnd, $end] = $declaration;
            $property = substr($token->text, 1);
            $isStatic = in_array('static', $modifiers, true);
            $type = $typeStart === null ? null : trim($this->text($typeStart, $typeEnd));

            // Livewire view and state holders.
            if ($property === 'view' && ($isPage || $reflection === null)) {
                $value = $this->propertyValue($i, $end);
                $this->removeMember($start, $end);
                $this->report->add(MigrationReport::SECTION_VIEWS, $this->file, $token->line, 'Custom Blade view '.($value === null ? '' : "`{$value}` ")."(`\${$property}`)", 'Blade is not rendered. Set `protected static ?string $component = \'my-component\';` and register a frontend component for your renderer in `createAlfakit({ components })` (`make:alfakit-widget --type=custom`, `make:alfakit-page`), or compose the page from schema components (`form()`, `infolist()`, widgets).');

                continue;
            }

            // `$navigationGroup = NavigationGroup::Shop` (an enum case): AlfaKit keeps the
            // property `?string`, so the enum moves into `getNavigationGroup()`.
            if ($property === 'navigationGroup' && ($value = $this->propertyExpression($i, $end)) !== null
                && ! preg_match('/^(null|\'[^\']*\'|"[^"]*")$/i', $value)) {
                $this->blank($start, $end);
                $this->out[$start] = "public static function getNavigationGroup(): string|\\UnitEnum|null\n    {\n        return {$value};\n    }";

                continue;
            }

            if ($property === 'data' && $isPage && in_array('public', $modifiers, true)) {
                $this->removeMember($start, $end);

                continue;
            }

            $expectedStatic = null;
            $expectedType = null;

            if ($reflection?->hasProperty($property)) {
                $parentProperty = $reflection->getProperty($property);
                $expectedStatic = $parentProperty->isStatic();
                $parentType = $parentProperty->getType();

                if ($parentType instanceof ReflectionNamedType) {
                    $expectedType = ($parentType->allowsNull() && $parentType->getName() !== 'mixed' && $parentType->getName() !== 'null' ? '?' : '').($parentType->isBuiltin() ? $parentType->getName() : self::basename($parentType->getName()));
                } elseif ($parentType instanceof ReflectionUnionType) {
                    $expectedType = implode('|', array_map(static fn (ReflectionType $named): string => $named instanceof ReflectionNamedType && ! $named->isBuiltin() ? self::basename($named->getName()) : (string) $named, $parentType->getTypes()));
                }
            } elseif (in_array($property, ['navigationIcon', 'activeNavigationIcon', 'navigationGroup', 'navigationLabel', 'navigationParentItem', 'title', 'slug'], true) && $reflection === null) {
                $expectedStatic = true;
                $expectedType = '?string';
            }

            if ($expectedStatic !== null && $expectedStatic !== $isStatic) {
                $this->setStatic($start, $i, $expectedStatic);
            }

            if ($expectedType !== null && $type !== null && self::normalizeType($type) !== self::normalizeType($expectedType) && self::isScalarType($expectedType)) {
                $this->blank($typeStart, $typeEnd);
                $this->out[$typeEnd] = $expectedType;
            } elseif ($expectedType !== null && $type === null && self::isScalarType($expectedType)) {
                $this->out[$i] = $expectedType.' '.$this->out[$i];
            }
        }
    }

    /**
     * Match the visibility and a scalar return type of a method the AlfaKit parent declares
     * (`getHeading()` returns `string|Htmlable` in Filament and `?string` here).
     *
     * @param  ReflectionClass<object>  $parent
     */
    private function alignMethod(ReflectionClass $parent, int $function, int $name, int $close): void
    {
        $method = $this->tokens[$name]->text;

        if (! $parent->hasMethod($method)) {
            return;
        }

        $parentMethod = $parent->getMethod($method);

        if ($parentMethod->isPublic()) {
            for ($j = $function - 1; $j >= $this->classOpen; $j--) {
                if ($this->tokens[$j]->is([T_PROTECTED, T_PRIVATE])) {
                    $this->out[$j] = 'public';

                    break;
                }

                if (! $this->tokens[$j]->is([T_WHITESPACE, T_STATIC, T_FINAL, T_ABSTRACT, T_PUBLIC])) {
                    break;
                }
            }
        }

        $colon = $this->nextSignificant($close);

        if ($colon === null || $this->tokens[$colon]->text !== ':') {
            return;
        }

        $typeStart = $this->nextSignificant($colon);
        $typeEnd = $typeStart;

        while ($typeEnd !== null && $typeEnd + 1 < $this->count && ! in_array($this->tokens[$typeEnd + 1]->text, ['{', ';'], true) && ! $this->tokens[$typeEnd + 1]->is(T_WHITESPACE)) {
            $typeEnd++;
        }

        $parentType = $parentMethod->getReturnType();

        if ($typeStart === null || $typeEnd === null || ! $parentType instanceof ReflectionNamedType || ! $parentType->isBuiltin()) {
            return;
        }

        $expected = ($parentType->allowsNull() && ! in_array($parentType->getName(), ['mixed', 'null'], true) ? '?' : '').$parentType->getName();
        $actual = trim($this->text($typeStart, $typeEnd));

        if (self::normalizeType($actual) === self::normalizeType($expected) || in_array($expected, ['void', 'mixed'], true)) {
            return;
        }

        $this->blank($typeStart, $typeEnd);
        $this->out[$typeEnd] = $expected;
        $this->report->add(MigrationReport::SECTION_METHODS, $this->file, $this->tokens[$name]->line, "`{$method}()` return type `{$actual}` became `{$expected}`", 'Return a plain value here; AlfaKit sends it to the renderer as JSON (no `Htmlable`).');
    }

    /**
     * The declaration around the property variable at `$variable`:
     * `[start, modifiers, typeStart|null, typeEnd|null, end (the `;`)]`, or null when `$variable`
     * is not a class property.
     *
     * @return array{0: int, 1: array<int, string>, 2: int|null, 3: int|null, 4: int}|null
     */
    private function propertyDeclaration(int $variable): ?array
    {
        $modifiers = [];
        $typeStart = null;
        $typeEnd = null;
        $start = $variable;
        $j = $variable - 1;

        while ($j > $this->classOpen) {
            $token = $this->tokens[$j];

            if ($token->is([T_WHITESPACE, T_COMMENT])) {
                $j--;

                continue;
            }

            if ($token->is([T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_READONLY, T_VAR, T_FINAL])) {
                $modifiers[] = strtolower($token->text);
                $start = $j;
                $j--;

                continue;
            }

            if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_ARRAY, T_CALLABLE]) || in_array($token->text, ['?', '|', '(', ')', '&'], true)) {
                if ($modifiers !== []) {
                    return null;
                }

                $typeEnd ??= $j;
                $typeStart = $j;
                $start = $j;
                $j--;

                continue;
            }

            break;
        }

        if ($modifiers === [] || ! ($this->tokens[$j]->is([T_DOC_COMMENT, T_COMMENT]) || in_array($this->tokens[$j]->text, [';', '}', '{', ']'], true))) {
            return null;
        }

        $end = $variable;

        while ($end < $this->classClose && $this->tokens[$end]->text !== ';') {
            if ($this->tokens[$end]->text === '(' || $this->tokens[$end]->text === '[') {
                $end = $this->matchClose($end);
            }

            $end++;
        }

        // Include a docblock directly above.
        $before = $this->previousSignificantIncludingComments($start);

        if ($before !== null && $this->tokens[$before]->is(T_DOC_COMMENT)) {
            $start = $before;
        }

        return [$start, $modifiers, $typeStart, $typeEnd, $end];
    }

    private function propertyValue(int $variable, int $end): ?string
    {
        for ($i = $variable; $i <= $end; $i++) {
            if ($this->tokens[$i]->is(T_CONSTANT_ENCAPSED_STRING)) {
                return trim($this->tokens[$i]->text, '\'"');
            }
        }

        return null;
    }

    /**
     * The default value expression of a property declaration (`= …;`), or null.
     */
    private function propertyExpression(int $variable, int $end): ?string
    {
        for ($i = $variable + 1; $i <= $end; $i++) {
            if ($this->tokens[$i]->text === '=') {
                $value = trim($this->text($i + 1, $end));

                return rtrim($value, "; \t\n") ?: null;
            }
        }

        return null;
    }

    private function removeMember(int $start, int $end): void
    {
        if ($start > 0 && $this->tokens[$start - 1]->is(T_WHITESPACE)) {
            $this->out[$start - 1] = '';
        }

        $this->blank($start, $end);
    }

    private function setStatic(int $start, int $variable, bool $static): void
    {
        for ($i = $start; $i < $variable; $i++) {
            if ($this->tokens[$i]->is(T_STATIC)) {
                if (! $static) {
                    $this->out[$i] = '';

                    if ($i + 1 < $variable && $this->tokens[$i + 1]->is(T_WHITESPACE)) {
                        $this->out[$i + 1] = '';
                    }
                }

                return;
            }
        }

        if ($static) {
            for ($i = $start; $i < $variable; $i++) {
                if ($this->tokens[$i]->is([T_PUBLIC, T_PROTECTED, T_PRIVATE])) {
                    $this->out[$i] .= ' static';

                    return;
                }
            }
        }
    }

    private static function normalizeType(string $type): string
    {
        $type = str_replace(' ', '', $type);

        if (str_starts_with($type, '?')) {
            $type = substr($type, 1).'|null';
        }

        $parts = array_map(static fn (string $part): string => self::basename(ltrim($part, '\\')), explode('|', $type));
        sort($parts);

        return implode('|', $parts);
    }

    private static function isScalarType(string $type): bool
    {
        foreach (explode('|', ltrim($type, '?')) as $part) {
            if (! in_array(strtolower($part), ['string', 'int', 'float', 'bool', 'array', 'null', 'mixed', 'iterable', 'callable', 'object'], true)) {
                return false;
            }
        }

        return true;
    }

    // ----------------------------------------------------------------------------------
    // Unused imports and leftovers
    // ----------------------------------------------------------------------------------

    private function removeUnusedImports(): void
    {
        foreach ($this->importSlots as $slot => $statements) {
            $kept = [];

            foreach ($statements as $statement) {
                $import = $this->imports[$statement['alias']];
                $name = $import['removed'] ? $statement['alias'] : ($import['newAlias'] ?? $statement['alias']);

                if ($this->isIdentifierUsed($name)) {
                    $kept[] = $statement;
                }
            }

            $this->importSlots[$slot] = $kept;
            $this->renderImportSlot($slot);
        }
    }

    private function isIdentifierUsed(string $name): bool
    {
        for ($i = 0; $i < $this->count; $i++) {
            if (isset($this->importTokenIndexes[$i]) || $this->out[$i] === '') {
                continue;
            }

            $text = $this->out[$i];

            if ($text === $name || str_starts_with($text, $name.'\\')) {
                return true;
            }

            // Comments, and text synthesized by the rewrite (e.g. the split toolbarActions()).
            if (($text !== $this->tokens[$i]->text || $this->tokens[$i]->is([T_DOC_COMMENT, T_COMMENT])) && preg_match('/(?<![\w\\\\$])'.preg_quote($name, '/').'(?![\w])/', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private function reportLeftovers(): void
    {
        $unsupported = FilamentClassMap::unsupported();
        $reported = [];

        for ($i = 0; $i < $this->count; $i++) {
            if (isset($this->importTokenIndexes[$i]) || $this->out[$i] === '') {
                continue;
            }

            $token = $this->tokens[$i];
            $class = null;

            if ($token->is(T_STRING) && $this->out[$i] !== $token->text) {
                // Rewritten in place (a converted type or parent class).
                continue;
            }

            if ($token->is(T_STRING) && isset($this->imports[$token->text]) && $this->imports[$token->text]['removed'] && $this->isClassReference($i)) {
                $class = $this->imports[$token->text]['old'];
            } elseif ($token->is([T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED]) && str_contains($this->out[$i], 'Filament\\')) {
                $class = ltrim($this->resolve($token->text), '\\');
            } elseif ($token->is([T_CONSTANT_ENCAPSED_STRING, T_DOC_COMMENT]) && preg_match('/Filament\\\\{1,2}[A-Za-z0-9_\\\\]+/', $this->out[$i], $matches) === 1) {
                $class = str_replace('\\\\', '\\', $matches[0]);
            }

            if ($class === null || ! FilamentClassMap::isFilament($class)) {
                continue;
            }

            $key = $class.':'.$token->line;

            if (isset($reported[$key])) {
                continue;
            }

            $reported[$key] = true;
            $this->report->add(MigrationReport::SECTION_CLASSES, $this->file, $token->line, "`{$class}`", $unsupported[$class] ?? 'No AlfaKit equivalent: see https://alfakit.dev/docs/getting-started/filament-comparison and remove or replace it.');
        }
    }

    // ----------------------------------------------------------------------------------
    // Token helpers
    // ----------------------------------------------------------------------------------

    private function nextSignificant(int $i): ?int
    {
        for ($j = $i + 1; $j < $this->count; $j++) {
            if (! $this->tokens[$j]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                return $j;
            }
        }

        return null;
    }

    private function previousSignificant(int $i): ?int
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (! $this->tokens[$j]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                return $j;
            }
        }

        return null;
    }

    private function previousSignificantIncludingComments(int $i): ?int
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (! $this->tokens[$j]->is(T_WHITESPACE)) {
                return $j;
            }
        }

        return null;
    }

    /**
     * Index of the bracket closing the one at `$open`.
     */
    private function matchClose(int $open): int
    {
        $depth = 0;

        for ($i = $open; $i < $this->count; $i++) {
            $token = $this->tokens[$i];

            if (in_array($token->text, ['(', '[', '{'], true) || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif (in_array($token->text, [')', ']', '}'], true)) {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return $this->count - 1;
    }

    /**
     * Ranges `[from, to]` (significant tokens) of the elements of the array literal `[$open … $close]`.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    private function arrayElements(int $open, int $close): array
    {
        $elements = [];
        $depth = 0;
        $from = null;
        $to = null;

        for ($i = $open + 1; $i < $close; $i++) {
            $token = $this->tokens[$i];

            if (in_array($token->text, ['(', '[', '{'], true) || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif (in_array($token->text, [')', ']', '}'], true)) {
                $depth--;
            }

            if ($depth === 0 && $token->text === ',') {
                if ($from !== null && $to !== null) {
                    $elements[] = [$from, $to];
                }

                $from = null;
                $to = null;

                continue;
            }

            if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }

            $from ??= $i;
            $to = $i;
        }

        if ($from !== null && $to !== null) {
            $elements[] = [$from, $to];
        }

        return $elements;
    }

    /**
     * The first identifier of the fluent chain that the `->` at `$arrow` belongs to.
     */
    private function chainRoot(int $arrow): ?int
    {
        $i = $this->previousSignificant($arrow);

        while ($i !== null) {
            $token = $this->tokens[$i];

            if (in_array($token->text, [')', ']'], true)) {
                $i = $this->matchOpen($i);
                $i = $i === null ? null : $this->previousSignificant($i);

                continue;
            }

            if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_VARIABLE])) {
                $previous = $this->previousSignificant($i);

                if ($previous !== null && $this->tokens[$previous]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON])) {
                    $i = $this->previousSignificant($previous);

                    continue;
                }

                return $i;
            }

            return null;
        }

        return null;
    }

    private function matchOpen(int $close): ?int
    {
        $depth = 0;

        for ($i = $close; $i >= 0; $i--) {
            $token = $this->tokens[$i];

            if (in_array($token->text, [')', ']', '}'], true)) {
                $depth++;
            } elseif (in_array($token->text, ['(', '[', '{'], true) || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * Whether call arguments start with a `Slot` constant (`\AlfaSix\Alfakit\Theming\Slot::X`,
     * or an imported `Slot::X` after Rector converted the hook).
     */
    private function isSlotArgument(string $arguments): bool
    {
        if (str_starts_with(ltrim($arguments, '\\'), FilamentClassMap::ALFAKIT.'Theming\\Slot::')) {
            return true;
        }

        return preg_match('/^Slot::[A-Z_]+\b/', $arguments) === 1 && ltrim($this->resolve('Slot'), '\\') === FilamentClassMap::ALFAKIT.'Theming\\Slot';
    }

    private function text(int $from, int $to): string
    {
        return implode('', array_slice($this->out, $from, $to - $from + 1));
    }

    /**
     * Remove `->method(...)` from a fluent chain together with the line break before it.
     */
    private function removeChainCall(int $arrow, int $close): void
    {
        if ($arrow > 0 && $this->tokens[$arrow - 1]->is(T_WHITESPACE)) {
            $this->out[$arrow - 1] = '';
        }

        $this->blank($arrow, $close);
    }

    private function blank(int $from, int $to): void
    {
        for ($i = $from; $i <= $to; $i++) {
            $this->out[$i] = '';
        }
    }

    private function indentBefore(int $i): string
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if ($this->tokens[$j]->is(T_WHITESPACE) && str_contains($this->tokens[$j]->text, "\n")) {
                return (string) preg_replace('/^.*\n/s', '', $this->tokens[$j]->text);
            }
        }

        return '            ';
    }
}
