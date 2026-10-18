<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Forms\Components\CheckboxList;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Resources\RelationManagers\RelationGroup;
use AlfaSix\Alfakit\Resources\RelationManagers\RelationManager;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Filters\SelectFilter;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tenancy\SharedAcrossTenants;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Audits every tenancy panel: whether each resource's model can be scoped, whether the
 * models behind relationship fields, filters and attach/associate actions are
 * tenant-scoped or deliberately shared, and whether resource, page and relation-manager
 * code reaches around the scoping with `DB::` or `withoutGlobalScopes()`.
 *
 * Exit code 1 on any FAIL, and on WARN too with `--strict`, so it can run in CI.
 */
#[AsCommand(name: 'alfakit:tenancy:check', description: 'Audit tenancy panels: resource scoping, relationship fields and raw queries')]
class TenancyCheckCommand extends Command
{
    public const OK = 'OK';

    public const WARN = 'WARN';

    public const FAIL = 'FAIL';

    protected $signature = 'alfakit:tenancy:check
        {--panel= : Only check this panel}
        {--strict : Exit with code 1 on warnings too}
        {--shared=* : Models shared across tenants on purpose (same as the #[SharedAcrossTenants] attribute)}';

    /** @var array<int, array{panel: string, check: string, subject: string, status: string, details: string}> */
    protected array $rows = [];

    /** @var array<int, string> */
    protected array $sharedModels = [];

    public function handle(): int
    {
        $this->rows = [];
        $this->sharedModels = array_map(
            static fn (string $model): string => ltrim(str_replace('/', '\\', $model), '\\'),
            array_filter((array) $this->option('shared'), 'is_string'),
        );

        $only = $this->option('panel');
        $panels = array_filter(
            alfakit()->getPanels(),
            static fn (Panel $panel): bool => $panel->hasTenancy() && (! is_string($only) || $only === '' || $panel->getId() === $only),
        );

        if ($panels === []) {
            $this->components->info(is_string($only) && $only !== '' ? "Panel [{$only}] does not use tenancy." : 'No panel uses tenancy; nothing to check.');

            return self::SUCCESS;
        }

        foreach ($panels as $panel) {
            $this->checkPanel($panel);
        }

        $this->table(
            ['Panel', 'Check', 'Subject', 'Status', 'Details'],
            array_map(static fn (array $row): array => array_values($row), $this->rows),
        );

        $counts = array_count_values(array_column($this->rows, 'status'));
        $failures = $counts[self::FAIL] ?? 0;
        $warnings = $counts[self::WARN] ?? 0;

        $this->newLine();
        $this->components->twoColumnDetail('Checks', (string) count($this->rows));
        $this->components->twoColumnDetail('OK', (string) ($counts[self::OK] ?? 0));
        $this->components->twoColumnDetail('Warnings', (string) $warnings);
        $this->components->twoColumnDetail('Failures', (string) $failures);

        if ($failures > 0 || ($warnings > 0 && (bool) $this->option('strict'))) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function checkPanel(Panel $panel): void
    {
        $tenantModel = (string) $panel->getTenantModel();
        $ownership = $panel->getTenantOwnershipRelationshipName();
        $files = [];

        foreach ($panel->getResources() as $resource) {
            $model = $resource::getModel();
            $subject = class_basename($resource).' → '.class_basename($model);
            $relationship = $this->resourceOwnershipRelationship($resource, $ownership);
            $owned = $this->hasRelationship($model, $relationship);

            if ($resource::isScopedToTenant()) {
                if ($owned) {
                    $this->row($panel, 'resource', $subject, self::OK, "scoped through {$relationship}()");
                } else {
                    $this->row($panel, 'resource', $subject, self::FAIL, class_basename($model)." has no {$relationship}() relationship, so its queries cannot be scoped. Add it, or set \$isScopedToTenant = false for a shared resource.");
                }
            } elseif ($this->isShared($model, $tenantModel, $ownership)) {
                $this->row($panel, 'resource', $subject, self::OK, 'not scoped; the model is shared across tenants');
            } else {
                $this->row($panel, 'resource', $subject, self::WARN, 'not scoped to the tenant (every tenant sees every record). Mark the model #[SharedAcrossTenants] if that is intended.');
            }

            $this->checkForm($panel, $resource, $model, $tenantModel, $ownership);
            $this->checkTable($panel, $resource, $model, $tenantModel, $ownership);
            $files[] = $this->fileOf($resource);

            foreach ($resource::getPages() as $registration) {
                $files[] = $this->fileOf($registration->page);
            }

            foreach ($this->relationManagers($resource) as $manager) {
                $files[] = $this->fileOf($manager);
                $this->checkRelationManager($panel, $resource, $manager, $model, $tenantModel, $ownership);
            }
        }

        foreach ($panel->getPages() as $page) {
            $files[] = $this->fileOf($page);
        }

        foreach (array_unique(array_filter($files)) as $file) {
            $this->scanFile($panel, $file);
        }
    }

    /**
     * @param  class-string<resource>  $resource
     * @param  class-string<Model>  $model
     */
    protected function checkForm(Panel $panel, string $resource, string $model, string $tenantModel, string $ownership): void
    {
        try {
            $schema = $resource::form(Schema::make()->model($model)->operation('create'));
            $components = $schema->getFlatComponents(withHidden: true);
        } catch (Throwable $exception) {
            $this->row($panel, 'form', class_basename($resource), self::WARN, 'could not build the form: '.$exception->getMessage());

            return;
        }

        foreach ($components as $component) {
            if (! ($component instanceof Select || $component instanceof CheckboxList) || ! $component->hasRelationship()) {
                continue;
            }

            $this->checkRelated($panel, class_basename($component), class_basename($resource).' → '.$component->getName(), $model, $component->getRelationshipName(), $tenantModel, $ownership);
        }
    }

    /**
     * @param  class-string<resource>  $resource
     * @param  class-string<Model>  $model
     */
    protected function checkTable(Panel $panel, string $resource, string $model, string $tenantModel, string $ownership): void
    {
        try {
            $table = $resource::table(Table::make());
            $filters = $table->getFilters();
        } catch (Throwable $exception) {
            $this->row($panel, 'table', class_basename($resource), self::WARN, 'could not build the table: '.$exception->getMessage());

            return;
        }

        foreach ($filters as $filter) {
            if ($filter instanceof SelectFilter && $filter->hasRelationship()) {
                $this->checkRelated($panel, 'SelectFilter', class_basename($resource).' → '.$filter->getName(), $model, $filter->getRelationshipName(), $tenantModel, $ownership);
            }
        }
    }

    /**
     * Attach/Associate actions in a relation manager offer records of the related model;
     * they are found statically because a manager needs an owner record to be built.
     *
     * @param  class-string<resource>  $resource
     * @param  class-string<RelationManager>  $manager
     * @param  class-string<Model>  $model
     */
    protected function checkRelationManager(Panel $panel, string $resource, string $manager, string $model, string $tenantModel, string $ownership): void
    {
        $file = $this->fileOf($manager);
        $source = $file !== null && is_file($file) ? (string) file_get_contents($file) : '';
        $relationship = $manager::getRelationshipName();

        foreach (['AttachAction', 'AssociateAction'] as $action) {
            if (preg_match('/\b'.$action.'::make\b/', $source) === 1) {
                $this->checkRelated($panel, $action, class_basename($manager).' → '.$relationship, $model, $relationship, $tenantModel, $ownership);
            }
        }
    }

    /**
     * @param  class-string<Model>  $model
     */
    protected function checkRelated(Panel $panel, string $check, string $subject, string $model, ?string $relationship, string $tenantModel, string $ownership): void
    {
        if ($relationship === null) {
            return;
        }

        $related = $this->relatedModel($model, $relationship);

        if ($related === null) {
            $this->row($panel, $check, $subject, self::FAIL, class_basename($model)." has no {$relationship}() relationship");

            return;
        }

        $name = class_basename($related);

        if (is_a($related, $tenantModel, true)) {
            $this->row($panel, $check, $subject, self::OK, "{$name} is the tenant model");
        } elseif ($this->hasRelationship($related, $ownership)) {
            $this->row($panel, $check, $subject, self::OK, "{$name} is tenant-scoped through {$ownership}()");
        } elseif ($this->isShared($related, $tenantModel, $ownership)) {
            $this->row($panel, $check, $subject, self::OK, "{$name} is shared across tenants on purpose");
        } else {
            $this->row($panel, $check, $subject, self::WARN, "{$name} is not tenant-scoped: every tenant can pick any record. Add {$ownership}() to it, or mark it #[SharedAcrossTenants] if that is intended.");
        }
    }

    /**
     * Flags `DB::` and `withoutGlobalScope(s)()` (other than dropping the soft-delete scope).
     */
    protected function scanFile(Panel $panel, string $file): void
    {
        if (! is_file($file)) {
            return;
        }

        $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);

        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $index => $line) {
            $trimmed = ltrim($line);
            $number = $index + 1;

            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, 'use ')) {
                continue;
            }

            if (preg_match('/(?<![\w\\\\])DB::/', $line) === 1) {
                $this->row($panel, 'query', "{$relative}:{$number}", self::WARN, 'raw DB:: query bypasses tenant scoping; prefer the model, or TenantScope::apply()');
            }

            if (preg_match_all('/withoutGlobalScopes?\s*\(([^()]*(?:\([^()]*\))?[^()]*)\)/', $line, $matches) > 0) {
                foreach ($matches[1] as $arguments) {
                    $scopes = array_filter(array_map('trim', explode(',', trim($arguments, " \t[]"))));

                    if ($scopes === [] || array_filter($scopes, static fn (string $scope): bool => ! str_contains($scope, 'SoftDeletingScope')) !== []) {
                        $this->row($panel, 'query', "{$relative}:{$number}", self::WARN, 'withoutGlobalScopes() removes model scopes; keep it to SoftDeletingScope::class on tenant-owned models');
                    }
                }
            }
        }
    }

    /**
     * @param  class-string<resource>  $resource
     */
    protected function resourceOwnershipRelationship(string $resource, string $default): string
    {
        $reflection = new ReflectionClass($resource);
        $own = $reflection->hasProperty('tenantOwnershipRelationshipName') ? $reflection->getStaticPropertyValue('tenantOwnershipRelationshipName') : null;

        return is_string($own) && $own !== '' ? $own : $default;
    }

    /**
     * @param  class-string<resource>  $resource
     * @return array<int, class-string<RelationManager>>
     */
    protected function relationManagers(string $resource): array
    {
        $managers = [];

        try {
            $relations = $resource::getRelations();
        } catch (Throwable) {
            return [];
        }

        foreach ($relations as $relation) {
            if ($relation instanceof RelationGroup) {
                array_push($managers, ...$relation->getManagers());
            } elseif (is_string($relation)) {
                $managers[] = $relation;
            }
        }

        return $managers;
    }

    /**
     * @param  class-string<Model>  $model
     * @return class-string<Model>|null
     */
    protected function relatedModel(string $model, string $relationship): ?string
    {
        if (! method_exists($model, $relationship)) {
            return null;
        }

        try {
            $relation = (new $model)->{$relationship}();
        } catch (Throwable) {
            return null;
        }

        return $relation instanceof Relation ? $relation->getRelated()::class : null;
    }

    /**
     * @param  class-string<Model>  $model
     */
    protected function hasRelationship(string $model, string $relationship): bool
    {
        if (! method_exists($model, $relationship)) {
            return false;
        }

        try {
            return (new $model)->{$relationship}() instanceof Relation;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  class-string<Model>  $model
     */
    protected function isShared(string $model, string $tenantModel, string $ownership): bool
    {
        return SharedAcrossTenants::appliesTo($model)
            || in_array($model, $this->sharedModels, true)
            || in_array(class_basename($model), $this->sharedModels, true);
    }

    /**
     * @param  class-string  $class
     */
    protected function fileOf(string $class): ?string
    {
        if (! class_exists($class)) {
            return null;
        }

        $file = (new ReflectionClass($class))->getFileName();

        return $file === false ? null : $file;
    }

    protected function row(Panel $panel, string $check, string $subject, string $status, string $details): void
    {
        $this->rows[] = [
            'panel' => $panel->getId(),
            'check' => $check,
            'subject' => $subject,
            'status' => $status,
            'details' => $details,
        ];
    }
}
