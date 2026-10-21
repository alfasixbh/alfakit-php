<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Versions\RecordVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Deletes old record versions (`HasRecordVersions`): older than `--days`, and/or all but
 * the newest `--keep` per record. Schedule it, e.g. `Schedule::command('alfakit:prune-versions --days=365')->daily()`.
 */
#[AsCommand(name: 'alfakit:prune-versions', description: 'Delete old record versions')]
class PruneVersionsCommand extends Command
{
    protected $signature = 'alfakit:prune-versions
        {--days= : Delete versions older than this many days}
        {--keep= : Keep only this many newest versions per record}
        {--model= : Only prune versions of this model class (or morph alias)}';

    public function handle(): int
    {
        $days = $this->option('days');
        $keep = $this->option('keep');
        $model = $this->option('model');

        if ($days === null && $keep === null) {
            $this->components->error('Pass --days and/or --keep.');

            return self::INVALID;
        }

        if (($days !== null && (! ctype_digit((string) $days) || (int) $days < 1)) || ($keep !== null && (! ctype_digit((string) $keep) || (int) $keep < 1))) {
            $this->components->error('--days and --keep must be positive whole numbers.');

            return self::INVALID;
        }

        $table = (new RecordVersion)->getTable();

        if (! Schema::connection((new RecordVersion)->getConnectionName())->hasTable($table)) {
            $this->components->info("Nothing to prune: the {$table} table doesn't exist yet (php artisan vendor:publish --tag=alfakit-record-versions-migrations, then php artisan migrate).");

            return self::SUCCESS;
        }

        $type = is_string($model) && $model !== '' ? (is_subclass_of($model, \Illuminate\Database\Eloquent\Model::class) ? (new $model)->getMorphClass() : $model) : null;
        $deleted = 0;

        if ($days !== null) {
            $deleted += RecordVersion::query()
                ->when($type !== null, static fn ($query) => $query->where('versionable_type', $type))
                ->where('created_at', '<', now()->subDays((int) $days))
                ->delete();
        }

        if ($keep !== null) {
            $records = RecordVersion::query()
                ->select(['versionable_type', 'versionable_id'])
                ->when($type !== null, static fn ($query) => $query->where('versionable_type', $type))
                ->groupBy(['versionable_type', 'versionable_id'])
                ->havingRaw('count(*) > ?', [(int) $keep])
                ->toBase()
                ->get();

            foreach ($records as $record) {
                $scope = static fn () => RecordVersion::query()
                    ->where('versionable_type', $record->versionable_type)
                    ->where('versionable_id', $record->versionable_id);

                $newest = $scope()->orderByDesc('id')->limit((int) $keep)->pluck('id')->all();

                $deleted += $scope()->whereNotIn('id', $newest)->delete();
            }
        }

        $this->components->info("Pruned {$deleted} record version(s).");

        return self::SUCCESS;
    }
}
