<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Forms\Components\FileUpload;
use AlfaSix\Alfakit\Support\ChunkedUpload;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'alfakit:prune-uploads', description: 'Delete abandoned temporary uploads')]
class PruneUploadsCommand extends Command
{
    protected $signature = 'alfakit:prune-uploads {--hours= : Delete files older than this many hours}';

    public function handle(): int
    {
        $disk = FileUpload::getTemporaryDisk();
        $directory = FileUpload::getTemporaryDirectory();
        $maxAge = now()->subHours((int) ($this->option('hours') ?? config('alfakit.uploads.max_age_hours', 24)));
        $deleted = 0;

        foreach ($disk->files($directory) as $file) {
            if ($disk->lastModified($file) < $maxAge->getTimestamp()) {
                $disk->delete($file);
                $deleted++;
            }
        }

        // Abandoned chunked uploads: a directory per upload, pruned by its newest chunk.
        $chunks = ChunkedUpload::root();
        $abandoned = 0;

        foreach ($disk->directories($chunks) as $owner) {
            foreach ($disk->directories($owner) as $upload) {
                $files = $disk->files($upload);
                $latest = max(array_map(static fn (string $file): int => $disk->lastModified($file), $files) ?: [0]);

                if ($latest < $maxAge->getTimestamp()) {
                    $disk->deleteDirectory($upload);
                    $abandoned++;
                }
            }

            if ($disk->allFiles($owner) === []) {
                $disk->deleteDirectory($owner);
            }
        }

        $this->components->info("Pruned {$deleted} temporary upload file(s) and {$abandoned} unfinished chunked upload(s).");

        return self::SUCCESS;
    }
}
