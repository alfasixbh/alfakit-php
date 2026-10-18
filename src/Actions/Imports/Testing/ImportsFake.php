<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Imports\Testing;

use AlfaSix\Alfakit\Actions\Imports\Importer;
use AlfaSix\Alfakit\Actions\Imports\Models\Import;
use Closure;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Assert;

/**
 * `ImportAction::fake()`: imports started by an ImportAction are recorded instead of
 * processed. The `Import` is still created (the action validated the file and mapping);
 * its chunk jobs are not run.
 *
 * ```php
 * $imports = ImportAction::fake();
 *
 * // ... run the action (PageTester::callAction('import', ...)) ...
 *
 * $imports->assertDispatched(ProductImporter::class, fn (Import $import, array $columnMap, array $options): bool => $columnMap['name'] === 'Name');
 * ```
 */
class ImportsFake
{
    /** @var array<int|string, array{import: Import, columnMap: array<string, string>, options: array<string, mixed>}> */
    protected array $captured = [];

    protected int|string $lastImportId;

    final public function __construct()
    {
        $this->lastImportId = Import::query()->max('id') ?? 0;
    }

    public static function fake(): static
    {
        $fake = new static;

        // Only import chunk jobs are held back; everything else dispatches as usual.
        Bus::fake([static fn (mixed $job): bool => $fake->capture($job)]);

        return $fake;
    }

    /**
     * Record an import chunk job (any job carrying the run as a public `$import`).
     */
    public function capture(mixed $job): bool
    {
        // Public properties only, as seen from outside the job.
        $properties = is_object($job) ? get_object_vars($job) : [];
        $import = $properties['import'] ?? null;

        if (! $import instanceof Import) {
            return false;
        }

        $this->captured[$import->getKey()] ??= [
            'import' => $import,
            'columnMap' => is_array($properties['columnMap'] ?? null) ? $properties['columnMap'] : [],
            'options' => is_array($properties['options'] ?? null) ? $properties['options'] : [],
        ];

        return true;
    }

    /**
     * Every import started since `fake()`, including imports of an empty file (which
     * queue no job: their column map and options are empty).
     *
     * @return array<int, array{import: Import, columnMap: array<string, string>, options: array<string, mixed>}>
     */
    public function imports(): array
    {
        $imports = $this->captured;

        foreach (Import::query()->where('id', '>', $this->lastImportId)->orderBy('id')->get() as $import) {
            $imports[$import->getKey()] ??= ['import' => $import, 'columnMap' => [], 'options' => []];
        }

        ksort($imports);

        return array_values($imports);
    }

    /**
     * @param  class-string<Importer>  $importer
     * @param  (Closure(Import, array<string, string>, array<string, mixed>): bool)|null  $callback
     * @return array<int, array{import: Import, columnMap: array<string, string>, options: array<string, mixed>}>
     */
    public function dispatched(string $importer, ?Closure $callback = null): array
    {
        return array_values(array_filter(
            $this->imports(),
            static fn (array $run): bool => $run['import']->importer === $importer && ($callback === null || $callback($run['import'], $run['columnMap'], $run['options'])),
        ));
    }

    /**
     * @param  class-string<Importer>  $importer
     * @param  (Closure(Import, array<string, string>, array<string, mixed>): bool)|null  $callback
     */
    public function assertDispatched(string $importer, ?Closure $callback = null): static
    {
        Assert::assertNotEmpty($this->dispatched($importer, $callback), "Failed asserting that an import with [{$importer}] was dispatched".($callback !== null ? ' matching the callback.' : '.'));

        return $this;
    }

    /**
     * @param  class-string<Importer>  $importer
     */
    public function assertDispatchedTimes(string $importer, int $times = 1): static
    {
        $count = count($this->dispatched($importer));

        Assert::assertSame($times, $count, "Failed asserting that an import with [{$importer}] was dispatched {$times} time(s); it was dispatched {$count} time(s).");

        return $this;
    }

    /**
     * @param  class-string<Importer>  $importer
     * @param  (Closure(Import, array<string, string>, array<string, mixed>): bool)|null  $callback
     */
    public function assertNotDispatched(string $importer, ?Closure $callback = null): static
    {
        Assert::assertEmpty($this->dispatched($importer, $callback), "Failed asserting that no import with [{$importer}] was dispatched".($callback !== null ? ' matching the callback.' : '.'));

        return $this;
    }

    public function assertNothingDispatched(): static
    {
        Assert::assertEmpty($this->imports(), 'Failed asserting that no import was dispatched.');

        return $this;
    }
}
