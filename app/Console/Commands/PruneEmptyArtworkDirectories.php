<?php

namespace App\Console\Commands;

use FilesystemIterator;
use Illuminate\Console\Command;
use Throwable;

class PruneEmptyArtworkDirectories extends Command
{
    protected $signature = 'media:artwork:prune-empty';

    protected $description = 'Remove empty per-item artwork directories';

    public function handle(): int
    {
        $root = rtrim(
            (string) config('odissey.artwork_path'),
            DIRECTORY_SEPARATOR,
        );
        if (
            $root === ''
            || $root === DIRECTORY_SEPARATOR
            || ! str_starts_with($root, DIRECTORY_SEPARATOR)
            || is_link($root)
        ) {
            $this->error('Artwork storage path is invalid.');

            return self::FAILURE;
        }
        if (! is_dir($root)) {
            $this->info('Pruned 0 empty artwork directories.');

            return self::SUCCESS;
        }

        $pruned = 0;
        try {
            foreach (
                new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS) as $entry
            ) {
                if (
                    $entry->isDir()
                    && ! $entry->isLink()
                    && @rmdir($entry->getPathname())
                ) {
                    $pruned++;
                }
            }
        } catch (Throwable) {
            $this->error('Artwork directory cleanup failed.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Pruned %d empty artwork director%s.',
            $pruned,
            $pruned === 1 ? 'y' : 'ies',
        ));

        return self::SUCCESS;
    }
}
