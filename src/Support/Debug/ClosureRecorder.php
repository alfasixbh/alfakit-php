<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Debug;

use Closure;
use ReflectionFunction;
use Throwable;

/**
 * Records which configuration closures `evaluate()` ran during a request, for the debug
 * panel. Off unless the debug panel is active for the request, so production requests
 * pay for one static boolean check per evaluation.
 */
final class ClosureRecorder
{
    public static bool $enabled = false;

    /** @var array<string, array{owner: string, file: string, line: int, count: int}> */
    private static array $records = [];

    /**
     * Set while the owner of a closure is described: naming it (`getKey()`, `getName()`) may
     * evaluate closures itself (e.g. a `NavigationItem` without a key falls back to its label
     * closure), and recording those would describe the owner again, endlessly.
     */
    private static bool $isRecording = false;

    public static function start(): void
    {
        self::$records = [];
        self::$enabled = true;
    }

    public static function stop(): void
    {
        self::$enabled = false;
    }

    public static function record(object $owner, Closure $closure): void
    {
        if (self::$isRecording) {
            return;
        }

        try {
            $reflection = new ReflectionFunction($closure);
        } catch (Throwable) {
            return;
        }

        $file = (string) $reflection->getFileName();
        $line = (int) $reflection->getStartLine();

        self::$isRecording = true;

        try {
            $name = self::describe($owner);
        } finally {
            self::$isRecording = false;
        }

        $key = "{$name}@{$file}:{$line}";

        if (isset(self::$records[$key])) {
            self::$records[$key]['count']++;

            return;
        }

        self::$records[$key] = [
            'owner' => $name,
            'file' => self::relativePath($file),
            'line' => $line,
            'count' => 1,
        ];
    }

    /**
     * @return array<int, array{owner: string, file: string, line: int, count: int}>
     */
    public static function all(): array
    {
        return array_values(self::$records);
    }

    public static function flush(): void
    {
        self::$records = [];
        self::$enabled = false;
    }

    /**
     * `TextInput[title]`, `Action[delete]`, `Panel`, …
     */
    public static function describe(object $owner): string
    {
        $name = class_basename($owner);

        foreach (['getName', 'getKey', 'getId'] as $method) {
            if (! method_exists($owner, $method)) {
                continue;
            }

            try {
                $identifier = $owner->{$method}();
            } catch (Throwable) {
                continue;
            }

            if (is_string($identifier) && $identifier !== '') {
                return "{$name}[{$identifier}]";
            }
        }

        return $name;
    }

    private static function relativePath(string $file): string
    {
        $base = rtrim(base_path(), '/').'/';

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }
}
