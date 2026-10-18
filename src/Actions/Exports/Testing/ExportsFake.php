<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Exports\Testing;

use AlfaSix\Alfakit\Actions\Exports\Exporter;
use AlfaSix\Alfakit\Actions\Exports\Models\Export;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Assert;

/**
 * `ExportAction::fake()` / `ExportBulkAction::fake()`: exports started by an export
 * action are recorded instead of written. The `Export` is still created; its jobs don't run.
 *
 * ```php
 * $exports = ExportAction::fake();
 *
 * // ... run the action ...
 *
 * $exports->assertDispatched(ProductExporter::class, function (Export $export, Builder $query, array $columnMap, array $options, array $formats, ?array $records): bool {
 *     return $records === [$product->getKey()];
 * });
 * ```
 *
 * The callback's `$query` is a fresh query over the exporter's model (`modifyQuery()`
 * applied), not limited to the export; `$records` are the keys of the exported records in
 * export order (AlfaKit resolves the selection when the export starts, for every export).
 */
class ExportsFake
{
    /** @var array<int|string, array{export: Export, columnMap: array<string, string>, options: array<string, mixed>, formats: array<int, string>, records: array<int, int|string>}> */
    protected array $captured = [];

    protected int|string $lastExportId;

    final public function __construct()
    {
        $this->lastExportId = Export::query()->max('id') ?? 0;
    }

    public static function fake(): static
    {
        $fake = new static;

        // Only export jobs are held back; everything else dispatches as usual.
        Bus::fake([static fn (mixed $job): bool => $fake->capture($job)]);

        return $fake;
    }

    /**
     * Record an export job (any job carrying the run as a public `$export`).
     */
    public function capture(mixed $job): bool
    {
        // Public properties only, as seen from outside the job.
        $properties = is_object($job) ? get_object_vars($job) : [];
        $export = $properties['export'] ?? null;

        if (! $export instanceof Export) {
            return false;
        }

        $run = $this->captured[$export->getKey()] ?? [
            'export' => $export,
            'columnMap' => is_array($properties['columnMap'] ?? null) ? $properties['columnMap'] : [],
            'options' => is_array($properties['options'] ?? null) ? $properties['options'] : [],
            'formats' => is_array($properties['formats'] ?? null) ? array_values($properties['formats']) : [],
            'records' => [],
        ];

        if (is_array($properties['keys'] ?? null)) {
            $run['records'] = [...$run['records'], ...array_values($properties['keys'])];
        }

        $this->captured[$export->getKey()] = $run;

        return true;
    }

    /**
     * @return array<int, array{export: Export, columnMap: array<string, string>, options: array<string, mixed>, formats: array<int, string>, records: array<int, int|string>}>
     */
    public function exports(): array
    {
        $exports = $this->captured;

        foreach (Export::query()->where('id', '>', $this->lastExportId)->orderBy('id')->get() as $export) {
            $exports[$export->getKey()] ??= ['export' => $export, 'columnMap' => [], 'options' => [], 'formats' => [], 'records' => []];
        }

        ksort($exports);

        return array_values($exports);
    }

    /**
     * @param  class-string<Exporter>  $exporter
     * @return array<int, array{export: Export, columnMap: array<string, string>, options: array<string, mixed>, formats: array<int, string>, records: array<int, int|string>}>
     */
    public function dispatched(string $exporter, ?Closure $callback = null): array
    {
        return array_values(array_filter($this->exports(), static function (array $run) use ($exporter, $callback): bool {
            if ($run['export']->exporter !== $exporter) {
                return false;
            }

            if ($callback === null) {
                return true;
            }

            $model = $exporter::getModel();
            $query = $exporter::modifyQuery($model::query());

            return (bool) $callback($run['export'], $query, $run['columnMap'], $run['options'], $run['formats'], $run['records']);
        }));
    }

    /**
     * @param  class-string<Exporter>  $exporter
     */
    public function assertDispatched(string $exporter, ?Closure $callback = null): static
    {
        Assert::assertNotEmpty($this->dispatched($exporter, $callback), "Failed asserting that an export with [{$exporter}] was dispatched".($callback !== null ? ' matching the callback.' : '.'));

        return $this;
    }

    /**
     * @param  class-string<Exporter>  $exporter
     */
    public function assertDispatchedTimes(string $exporter, int $times = 1): static
    {
        $count = count($this->dispatched($exporter));

        Assert::assertSame($times, $count, "Failed asserting that an export with [{$exporter}] was dispatched {$times} time(s); it was dispatched {$count} time(s).");

        return $this;
    }

    /**
     * @param  class-string<Exporter>  $exporter
     */
    public function assertNotDispatched(string $exporter, ?Closure $callback = null): static
    {
        Assert::assertEmpty($this->dispatched($exporter, $callback), "Failed asserting that no export with [{$exporter}] was dispatched".($callback !== null ? ' matching the callback.' : '.'));

        return $this;
    }

    public function assertNothingDispatched(): static
    {
        Assert::assertEmpty($this->exports(), 'Failed asserting that no export was dispatched.');

        return $this;
    }
}
