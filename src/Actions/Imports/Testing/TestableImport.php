<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Imports\Testing;

use AlfaSix\Alfakit\Actions\Imports\Exceptions\RowImportFailedException;
use AlfaSix\Alfakit\Actions\Imports\Importer;
use AlfaSix\Alfakit\Actions\Imports\Models\Import;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Assert;

/**
 * Runs an importer on single rows, without a CSV file, a queue or an `Import` record in
 * the database (Filament's `Importer::test()`):
 *
 * ```php
 * ProductImporter::test()->import(['name' => 'Desk', 'price' => '120'])->assertImported();
 * ProductImporter::test()->import(['name' => '', 'price' => 'x'])->assertHasErrors(['price' => ['numeric']]);
 * ```
 *
 * Rows are keyed by CSV header. Without a `columnMap` each column reads the header of its
 * own name; an explicit map replaces that entirely.
 */
class TestableImport
{
    protected Import $import;

    /** @var array<string, string> */
    protected array $columnMap;

    protected ?Importer $importer = null;

    protected bool $completed = false;

    /** @var array<string, array<int, string>> */
    protected array $errors = [];

    /** @var array<string, array<int, string>> Failed rules by field, snake-cased (`numeric`, `min`) */
    protected array $failedRules = [];

    protected ?string $rowFailure = null;

    /**
     * @param  class-string<Importer>  $importerClass
     * @param  array<string, string>|null  $columnMap  Importer column name → CSV header
     * @param  array<string, mixed>  $options  Values of the options form
     */
    public function __construct(
        protected string $importerClass,
        ?array $columnMap = null,
        protected array $options = [],
        ?Import $import = null,
    ) {
        $this->columnMap = $columnMap ?? self::defaultColumnMap($importerClass);
        $this->import = $import ?? self::makeImport($importerClass);
    }

    /**
     * @param  class-string<Importer>  $importerClass
     * @return array<string, string>
     */
    protected static function defaultColumnMap(string $importerClass): array
    {
        $map = [];

        foreach ($importerClass::getColumns() as $column) {
            $map[$column->getName()] = $column->getName();
        }

        return $map;
    }

    /**
     * @param  class-string<Importer>  $importerClass
     */
    protected static function makeImport(string $importerClass): Import
    {
        $panel = alfakit()->getCurrentOrDefaultPanel();

        $import = new Import;
        $import->setOwner($panel->auth()->user(), alfakit()->getTenant(), $panel);
        $import->fill(['importer' => $importerClass, 'file_name' => 'test.csv', 'total_rows' => 1]);

        return $import;
    }

    /**
     * Import one row (keyed by CSV header), as a chunk job would.
     *
     * @param  array<string, mixed>  $row
     */
    public function import(array $row): static
    {
        $this->completed = false;
        $this->errors = [];
        $this->failedRules = [];
        $this->rowFailure = null;
        $this->importer = $this->import->getImporter($this->columnMap, $this->options);

        try {
            ($this->importer)($row);
            $this->completed = true;
        } catch (ValidationException $exception) {
            $this->errors = $exception->errors();

            foreach ($exception->validator->failed() as $field => $rules) {
                $this->failedRules[(string) $field] = array_map(static fn (string $rule): string => Str::snake(class_basename($rule)), array_keys($rules));
            }
        } catch (RowImportFailedException $exception) {
            $this->rowFailure = $exception->getMessage();
        }

        return $this;
    }

    public function getImporter(): ?Importer
    {
        return $this->importer;
    }

    public function getRecord(): ?Model
    {
        return $this->importer?->getRecord();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /** The row ran through and resolved a record. */
    public function assertImported(): static
    {
        $this->assertCompleted();
        Assert::assertNotNull($this->getRecord(), 'Failed asserting that the row was imported: resolveRecord() returned null (the row was skipped).');

        return $this;
    }

    /** The row ran through without a record (`resolveRecord()` returned null). */
    public function assertSkipped(): static
    {
        $this->assertCompleted();
        Assert::assertNull($this->getRecord(), 'Failed asserting that the row was skipped: a record was imported.');

        return $this;
    }

    /**
     * Validation failed: on any field, on the given fields (`['price', 'name']`), or with
     * the given rules (`['price' => ['numeric', 'min']]`, rule names without parameters).
     *
     * @param  array<int|string, string|array<int, string>>  $keys
     */
    public function assertHasErrors(array $keys = []): static
    {
        if ($keys === []) {
            Assert::assertNotEmpty($this->errors, 'Failed asserting that the row has validation errors.');

            return $this;
        }

        foreach ($keys as $field => $rules) {
            if (is_int($field)) {
                Assert::assertArrayHasKey((string) $rules, $this->errors, "Failed asserting that the row has a validation error for [{$rules}].");

                continue;
            }

            Assert::assertArrayHasKey($field, $this->errors, "Failed asserting that the row has a validation error for [{$field}].");

            foreach ((array) $rules as $rule) {
                Assert::assertContains(Str::snake($rule), $this->failedRules[$field] ?? [], "Failed asserting that [{$field}] failed the [{$rule}] rule.");
            }
        }

        return $this;
    }

    /**
     * No validation error at all, or none on the given fields / rules.
     *
     * @param  array<int|string, string|array<int, string>>  $keys
     */
    public function assertHasNoErrors(array $keys = []): static
    {
        if ($keys === []) {
            Assert::assertSame([], $this->errors, 'Failed asserting that the row has no validation errors: '.json_encode($this->errors));

            return $this;
        }

        foreach ($keys as $field => $rules) {
            if (is_int($field)) {
                Assert::assertArrayNotHasKey((string) $rules, $this->errors, "Failed asserting that the row has no validation error for [{$rules}].");

                continue;
            }

            foreach ((array) $rules as $rule) {
                Assert::assertNotContains(Str::snake($rule), $this->failedRules[$field] ?? [], "Failed asserting that [{$field}] passed the [{$rule}] rule.");
            }
        }

        return $this;
    }

    /** The importer failed the row on purpose (`RowImportFailedException`), optionally with this message. */
    public function assertHasRowFailure(?string $message = null): static
    {
        Assert::assertNotNull($this->rowFailure, 'Failed asserting that the row failed with a RowImportFailedException.');

        if ($message !== null) {
            Assert::assertSame($message, $this->rowFailure);
        }

        return $this;
    }

    public function assertHasNoRowFailure(): static
    {
        Assert::assertNull($this->rowFailure, "Failed asserting that the row did not fail: {$this->rowFailure}");

        return $this;
    }

    protected function assertCompleted(): void
    {
        Assert::assertSame([], $this->errors, 'The row failed validation: '.json_encode($this->errors));
        Assert::assertNull($this->rowFailure, "The row failed: {$this->rowFailure}");
        Assert::assertTrue($this->completed, 'The row did not complete.');
    }
}
