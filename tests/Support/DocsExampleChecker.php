<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Support;

use FilesystemIterator;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;
use Throwable;

/**
 * Checks the PHP examples in docs/content against the current API.
 *
 * - Complete examples (a block that declares a namespace, opens with `<?php` or declares a
 *   class, interface, trait or enum) must pass `php -l`.
 * - Every `AlfaSix\…` name used in any example (imports, `new`, `::`, `extends`, type hints,
 *   attributes) must be a class, interface, trait or enum.
 * - In complete examples, methods called on AlfaKit classes must exist: static calls on an
 *   AlfaKit class, the fluent chains that follow them (followed through the declared return
 *   types: `static`/`self` stay on the class, a class return type switches to it, anything
 *   else stops), `$this->…()` / `parent::…()` in a class that extends an AlfaKit class, and
 *   redeclared methods and properties of that parent (static-ness, visibility, property type).
 */
final class DocsExampleChecker
{
    /** @var array<string, bool> */
    private static array $lint = [];

    /**
     * @return list<array{heading: string, line: int, code: string}>
     */
    public static function blocks(string $markdown): array
    {
        // Boost guidelines wrap examples in <code-snippet lang="php"> instead of fences.
        $markdown = (string) preg_replace('/^\s*<code-snippet[^>]*lang="php"[^>]*>\s*\n(.*?)\n\s*<\/code-snippet>/ms', "```php\n$1\n```", $markdown);
        $blocks = [];
        $heading = '';
        $lines = explode("\n", $markdown);

        for ($i = 0, $count = count($lines); $i < $count; $i++) {
            if (preg_match('/^#{1,6} (.*)$/', $lines[$i], $match) === 1) {
                $heading = trim($match[1]);
            }

            if (preg_match('/^(\s*)(`{3,})php\b/', $lines[$i], $open) !== 1) {
                continue;
            }

            $start = $i + 1;
            $body = [];

            for ($i++; $i < $count && ! preg_match('/^\s*'.preg_quote($open[2], '/').'\s*$/', $lines[$i]); $i++) {
                $body[] = $open[1] !== '' && str_starts_with($lines[$i], $open[1]) ? substr($lines[$i], strlen($open[1])) : $lines[$i];
            }

            $blocks[] = ['heading' => $heading, 'line' => $start + 1, 'code' => implode("\n", $body)];
        }

        return $blocks;
    }

    /**
     * Fence mistakes that leave PHP outside its block: an empty ```php block, or `use`,
     * `namespace`, `class … extends` and `<?php` lines in the prose (an indented block
     * whose first lines lost their indentation renders as an empty block and loose text).
     *
     * @return list<string>
     */
    public static function strayCode(string $markdown): array
    {
        $markdown = (string) preg_replace('/^\s*<code-snippet[^>]*lang="php"[^>]*>\s*\n(.*?)\n\s*<\/code-snippet>/ms', "```php\n$1\n```", $markdown);
        $problems = [];
        $fence = null;
        $opened = 0;
        $empty = true;

        foreach (explode("\n", $markdown) as $index => $line) {
            if (preg_match('/^\s*(`{3,}|~{3,})(.*)$/', $line, $match) === 1) {
                if ($fence === null) {
                    $fence = [$match[1], trim($match[2])];
                    $opened = $index + 1;
                    $empty = true;
                } elseif (str_starts_with($match[1], $fence[0]) && trim($match[2]) === '') {
                    if ($empty && $fence[1] === 'php') {
                        $problems[] = sprintf('line %d: empty ```php block', $opened);
                    }

                    $fence = null;
                }

                continue;
            }

            if ($fence !== null) {
                $empty = $empty && trim($line) === '';

                continue;
            }

            if (preg_match('/^\s*(use [A-Z][\w\\\\]*\\\\[\w\\\\]+;|namespace [A-Z][\w\\\\]*;|(abstract |final )?class \w+ (extends|implements) |<\?php)/', $line) === 1) {
                $problems[] = sprintf('line %d: PHP outside a code block: %s', $index + 1, trim($line));
            }
        }

        return $problems;
    }

    public static function isComplete(string $code): bool
    {
        return preg_match('/^\s*(<\?php|namespace\s+[\w\\\\]+\s*;|(final\s+|abstract\s+|readonly\s+)*(class|interface|trait|enum)\s+\w+)/m', $code) === 1;
    }

    /**
     * @return list<string> Problems found in the block.
     */
    public static function check(string $code): array
    {
        $complete = self::isComplete($code);
        $source = str_starts_with(ltrim($code), '<?php') ? $code : "<?php\n".$code;

        if ($complete && ($lint = self::lint($source)) !== null) {
            return ['php -l: '.$lint];
        }

        try {
            $ast = (new ParserFactory)->createForHostVersion()->parse($source) ?? [];
        } catch (Error) {
            // Fragments (a method body, `[...]` placeholders) are not standalone PHP.
            return [];
        }

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false]));
        $ast = $traverser->traverse($ast);

        $problems = self::missingClasses($ast);

        if ($complete) {
            array_push($problems, ...self::missingImports($ast), ...self::missingMethods($ast));
        }

        return array_values(array_unique($problems));
    }

    private static function lint(string $source): ?string
    {
        $key = sha1($source);

        if (isset(self::$lint[$key])) {
            return null;
        }

        $file = tempnam(sys_get_temp_dir(), 'alfakit-docs-').'.php';
        file_put_contents($file, $source);
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file).' 2>&1', $output, $code);
        @unlink($file);

        if ($code === 0) {
            self::$lint[$key] = true;

            return null;
        }

        $message = trim(implode(' ', array_filter($output, static fn (string $line): bool => str_contains($line, 'error'))));

        return preg_replace('/ in \S+ on line (\d+)/', ' on line $1', $message) ?: 'syntax error';
    }

    private static function exists(string $name): bool
    {
        try {
            return class_exists($name) || interface_exists($name) || trait_exists($name) || enum_exists($name);
        } catch (Throwable) {
            return false;
        }
    }

    private static function isAlfakit(string $name): bool
    {
        return str_starts_with(ltrim($name, '\\'), 'AlfaSix\\');
    }

    /**
     * @param  array<Node>  $ast
     * @return list<string>
     */
    private static function missingClasses(array $ast): array
    {
        $problems = [];
        $finder = new NodeFinder;

        foreach ($finder->find($ast, static fn (Node $node): bool => $node instanceof Stmt\UseUse || $node instanceof Name) as $node) {
            if ($node instanceof Stmt\UseUse) {
                $name = $node->name->toString();
                $type = $node->type;
            } else {
                /** @var Name $node */
                $resolved = $node->getAttribute('resolvedName');

                if (! $resolved instanceof Name || $node->isSpecialClassName()) {
                    continue;
                }

                $name = $resolved->toString();
                $type = Stmt\Use_::TYPE_NORMAL;
            }

            if ($type !== Stmt\Use_::TYPE_NORMAL && $type !== Stmt\Use_::TYPE_UNKNOWN) {
                continue;
            }

            if (self::isAlfakit($name) && ! self::exists($name) && ! self::declaredIn($ast, $name) && ! self::isNamespacePrefix($name)) {
                $problems[] = "unknown class {$name}";
            }
        }

        return $problems;
    }

    /**
     * Short names of AlfaKit classes used without an import (`TextInput::make()` with no
     * `use AlfaSix\Alfakit\Forms\Components\TextInput;`): pasted as is, they don't resolve.
     *
     * @param  array<Node>  $ast
     * @return list<string>
     */
    private static function missingImports(array $ast): array
    {
        $problems = [];
        $declared = [];

        foreach ((new NodeFinder)->findInstanceOf($ast, Stmt\ClassLike::class) as $class) {
            if ($class->name !== null) {
                $declared[$class->name->toString()] = true;
            }
        }

        foreach ((new NodeFinder)->findInstanceOf($ast, Stmt\UseUse::class) as $use) {
            $declared[$use->getAlias()->toString()] = true;
        }

        foreach ((new NodeFinder)->findInstanceOf($ast, Name::class) as $name) {
            if (! $name->isUnqualified() || $name->isSpecialClassName() || isset($declared[$name->toString()])) {
                continue;
            }

            $resolved = $name->getAttribute('resolvedName');

            // Imported names resolve to their import; the rest to the current namespace.
            if (! $resolved instanceof Name || self::isAlfakit($resolved->toString()) || self::exists($resolved->toString())) {
                continue;
            }

            $candidates = self::alfakitClassesNamed($name->toString());

            if ($candidates !== []) {
                $problems[] = sprintf('%s is used but not imported (use %s;)', $name->toString(), implode(' or ', $candidates));
            }
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private static function alfakitClassesNamed(string $short): array
    {
        static $index = null;

        if ($index === null) {
            $index = [];
            $roots = [dirname(__DIR__, 2).'/src' => 'AlfaSix\\Alfakit\\'];

            foreach (glob(dirname(__DIR__, 3).'/plugins/*/composer.json') ?: [] as $composer) {
                $autoload = json_decode((string) file_get_contents($composer), true)['autoload']['psr-4'] ?? [];

                foreach ($autoload as $namespace => $path) {
                    if (! str_contains($namespace, '\\Tests\\')) {
                        $roots[dirname($composer).'/'.rtrim((string) $path, '/')] = $namespace;
                    }
                }
            }

            foreach ($roots as $root => $namespace) {
                if (! is_dir($root)) {
                    continue;
                }

                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
                    if ($file->getExtension() !== 'php') {
                        continue;
                    }

                    $relative = substr($file->getPathname(), strlen($root) + 1, -4);
                    $index[basename($relative)][] = $namespace.str_replace('/', '\\', $relative);
                }
            }
        }

        // Names that usually mean an app model or a Laravel class (`Builder $query`) are left out.
        if (in_array($short, ['Builder', 'Collection', 'Model', 'Attribute'], true)) {
            return [];
        }

        return array_values(array_filter(
            $index[$short] ?? [],
            static fn (string $class): bool => ! str_contains($class, '\\Models\\') && self::exists($class),
        ));
    }

    /** `use AlfaSix\Alfakit\Forms\Components;` followed by `Components\TextInput`. */
    private static function isNamespacePrefix(string $name): bool
    {
        static $namespaces = null;

        if ($namespaces === null) {
            $namespaces = [];

            foreach (array_keys(require dirname(__DIR__, 4).'/vendor/composer/autoload_classmap.php') as $class) {
                $namespaces[substr($class, 0, (int) strrpos($class, '\\'))] = true;
            }
        }

        if (isset($namespaces[$name])) {
            return true;
        }

        foreach ([dirname(__DIR__, 2).'/src', dirname(__DIR__, 3).'/plugins'] as $root) {
            $relative = preg_replace('/^AlfaSix\\\\Alfakit\\\\/', '', $name);

            if ($relative !== null && is_dir($root.'/'.str_replace('\\', '/', $relative))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<Node>  $ast
     */
    private static function declaredIn(array $ast, string $name): bool
    {
        return (new NodeFinder)->findFirst($ast, static fn (Node $node): bool => $node instanceof Stmt\ClassLike
            && $node->namespacedName !== null && $node->namespacedName->toString() === $name) !== null;
    }

    /**
     * @param  array<Node>  $ast
     * @return list<string>
     */
    private static function missingMethods(array $ast): array
    {
        $problems = [];
        $finder = new NodeFinder;

        // Fluent chains that start at a static call on an AlfaKit class, and `new` expressions.
        foreach ($finder->find($ast, static fn (Node $node): bool => $node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall || $node instanceof Expr\NullsafeMethodCall) as $call) {
            /** @var Expr\MethodCall|Expr\StaticCall|Expr\NullsafeMethodCall $call */
            if (! $call->name instanceof Node\Identifier) {
                continue;
            }

            $method = $call->name->toString();

            if ($call instanceof Expr\StaticCall) {
                if (! $call->class instanceof Name) {
                    continue;
                }

                $class = self::classOfName($call->class, $call, $ast);

                if ($class === null) {
                    continue;
                }

                // `parent::method()` / `self::method()` may hit methods the example declares.
                if ($call->class->isSpecialClassName() && self::declaresMethod($call, $ast, $method)) {
                    continue;
                }
            } else {
                $class = self::typeOf($call->var, $ast);

                if ($class === null) {
                    continue;
                }

                if ($call->var instanceof Expr\Variable && $call->var->name === 'this' && self::declaresMethod($call, $ast, $method)) {
                    continue;
                }
            }

            if (! self::hasMethod($class, $method, $call instanceof Expr\StaticCall && ! $call->class->isSpecialClassName())) {
                $problems[] = "unknown method {$class}::{$method}()";
            }
        }

        // Redeclared members of an AlfaKit parent.
        foreach ($finder->findInstanceOf($ast, Stmt\Class_::class) as $class) {
            $parent = self::resolved($class->extends);

            if ($parent === null || ! self::isAlfakit($parent) || ! class_exists($parent)) {
                continue;
            }

            $reflection = new ReflectionClass($parent);

            foreach ($class->getMethods() as $method) {
                $name = $method->name->toString();

                if (! $reflection->hasMethod($name)) {
                    continue;
                }

                $inherited = $reflection->getMethod($name);

                if ($inherited->isPrivate()) {
                    continue;
                }

                if ($inherited->isStatic() !== $method->isStatic()) {
                    $problems[] = sprintf('%s::%s() must %sbe static (as in %s)', $class->name, $name, $inherited->isStatic() ? '' : 'not ', $inherited->getDeclaringClass()->getName());
                }

                if (($inherited->isPublic() && ! $method->isPublic()) || ($inherited->isProtected() && $method->isPrivate())) {
                    $problems[] = sprintf('%s::%s() must be %s (as in %s)', $class->name, $name, $inherited->isPublic() ? 'public' : 'protected or public', $inherited->getDeclaringClass()->getName());
                }

                if ($inherited->isFinal()) {
                    $problems[] = sprintf('%s::%s() overrides a final method', $class->name, $name);
                }
            }

            foreach ($class->getProperties() as $property) {
                foreach ($property->props as $prop) {
                    $name = $prop->name->toString();

                    if (! $reflection->hasProperty($name)) {
                        continue;
                    }

                    $inherited = $reflection->getProperty($name);

                    if ($inherited->isPrivate()) {
                        continue;
                    }

                    if ($inherited->isStatic() !== $property->isStatic()) {
                        $problems[] = sprintf('%s::$%s must %sbe static (as in %s)', $class->name, $name, $inherited->isStatic() ? '' : 'not ', $inherited->getDeclaringClass()->getName());
                    }

                    $expected = $inherited->getType();
                    $declared = $property->type;

                    if ($expected !== null && self::typeString($declared) !== self::normalise((string) $expected)) {
                        $problems[] = sprintf('%s::$%s must be typed %s (as in %s), not %s', $class->name, $name, $expected, $inherited->getDeclaringClass()->getName(), self::typeString($declared) ?? 'untyped');
                    }
                }
            }
        }

        return $problems;
    }

    private static function resolved(?Name $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $resolved = $name->getAttribute('resolvedName');

        return $resolved instanceof Name ? $resolved->toString() : $name->toString();
    }

    private static function normalise(string $type): string
    {
        $parts = explode('|', str_replace('?', 'null|', ltrim($type, '\\')));
        $parts = array_map(static fn (string $part): string => strtolower(ltrim($part, '\\')), $parts);
        sort($parts);

        return implode('|', array_unique($parts));
    }

    private static function typeString(?Node $type): ?string
    {
        if ($type === null) {
            return null;
        }

        $string = match (true) {
            $type instanceof Node\NullableType => '?'.self::typeString($type->type),
            $type instanceof Node\UnionType => implode('|', array_map(static fn (Node $part): string => (string) self::typeString($part), $type->types)),
            $type instanceof Name => (($resolved = $type->getAttribute('resolvedName')) instanceof Name ? $resolved : $type)->toString(),
            $type instanceof Node\Identifier => $type->toString(),
            default => '',
        };

        return self::normalise($string);
    }

    private static function hasMethod(string $class, string $method, bool $static): bool
    {
        if (! class_exists($class) && ! interface_exists($class) && ! trait_exists($class) && ! enum_exists($class)) {
            return true;
        }

        if (method_exists($class, $method)) {
            return true;
        }

        $reflection = new ReflectionClass($class);
        $magic = $static ? '__callStatic' : '__call';

        if ($reflection->hasMethod($magic)) {
            $declaring = $reflection->getMethod($magic)->getDeclaringClass()->getName();
            $traits = class_uses_recursive($class);

            // Macroable's __call only answers registered macros; anything else proxies.
            if (! in_array('Illuminate\Support\Traits\Macroable', $traits, true) || ! in_array($declaring, [$class, ...class_parents($class)], true)) {
                return true;
            }

            return method_exists($class, 'hasMacro') && $class::hasMacro($method);
        }

        // Static calls to instance methods (`Foo::make()` on a non-static `make`) still fail.
        return false;
    }

    /**
     * @param  array<Node>  $ast
     */
    private static function classOfName(Name $name, Node $context, array $ast): ?string
    {
        if ($name->isSpecialClassName()) {
            $class = self::enclosingClass($context, $ast);

            if ($class === null) {
                return null;
            }

            return $name->toLowerString() === 'parent' ? self::resolved($class->extends) : self::alfakitAncestor($class);
        }

        $resolved = $name->getAttribute('resolvedName');
        $resolved = $resolved instanceof Name ? $resolved->toString() : $name->toString();

        return self::isAlfakit($resolved) ? $resolved : null;
    }

    /**
     * The AlfaKit class an expression evaluates to, when the example makes that knowable.
     *
     * @param  array<Node>  $ast
     */
    private static function typeOf(Expr $expr, array $ast): ?string
    {
        if ($expr instanceof Expr\New_ && $expr->class instanceof Name) {
            return self::classOfName($expr->class, $expr, $ast);
        }

        if ($expr instanceof Expr\Variable && $expr->name === 'this') {
            $class = self::enclosingClass($expr, $ast);

            return $class === null ? null : self::alfakitAncestor($class);
        }

        if ($expr instanceof Expr\StaticCall && $expr->class instanceof Name && $expr->name instanceof Node\Identifier) {
            $class = self::classOfName($expr->class, $expr, $ast);

            return $class === null ? null : self::returnType($class, $expr->name->toString());
        }

        if (($expr instanceof Expr\MethodCall || $expr instanceof Expr\NullsafeMethodCall) && $expr->name instanceof Node\Identifier) {
            if ($expr->var instanceof Expr\Variable && $expr->var->name === 'this' && self::declaresMethod($expr, $ast, $expr->name->toString())) {
                return null;
            }

            $class = self::typeOf($expr->var, $ast);

            return $class === null ? null : self::returnType($class, $expr->name->toString());
        }

        return null;
    }

    private static function returnType(string $class, string $method): ?string
    {
        if (! class_exists($class) || ! method_exists($class, $method)) {
            return null;
        }

        $type = (new ReflectionMethod($class, $method))->getReturnType();

        if ($type instanceof ReflectionUnionType || ! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return $type instanceof ReflectionNamedType && $type->getName() === 'static' ? $class : null;
        }

        $name = $type->getName();

        if ($name === 'self' || $name === 'static') {
            return $class;
        }

        // A declared class may be a subclass at runtime (`Component` holding a `TextInput`), so
        // only final AlfaKit classes carry the chain on; interfaces and other classes end it.
        return self::isAlfakit($name) && class_exists($name) && (new ReflectionClass($name))->isFinal() ? $name : null;
    }

    /**
     * @param  array<Node>  $ast
     */
    private static function enclosingClass(Node $node, array $ast): ?Stmt\Class_
    {
        foreach ((new NodeFinder)->findInstanceOf($ast, Stmt\Class_::class) as $class) {
            if ((new NodeFinder)->findFirst($class->stmts, static fn (Node $candidate): bool => $candidate === $node) !== null) {
                return $class;
            }
        }

        return null;
    }

    /**
     * The nearest AlfaKit class the example class inherits from.
     */
    private static function alfakitAncestor(Stmt\Class_ $class): ?string
    {
        $parent = self::resolved($class->extends);

        return $parent !== null && self::isAlfakit($parent) && class_exists($parent) ? $parent : null;
    }

    /**
     * Whether the class around the call declares the method itself (or uses a trait we can't see).
     *
     * @param  array<Node>  $ast
     */
    private static function declaresMethod(Node $call, array $ast, string $method): bool
    {
        $class = self::enclosingClass($call, $ast);

        if ($class === null) {
            return true;
        }

        if ($class->getMethod($method) !== null) {
            return true;
        }

        foreach ($class->getTraitUses() as $use) {
            foreach ($use->traits as $trait) {
                $name = self::resolved($trait) ?? '';

                if (! trait_exists($name) || method_exists($name, $method)) {
                    return true;
                }
            }
        }

        return false;
    }
}
