<?php

namespace App\Services\Media\Sources;

use App\Models\MediaSource;

interface MediaSourceAdapter
{
    /** @return iterable<SourceObject> */
    public function objects(MediaSource $source): iterable;

    /** @return array{range: bool, seekable: bool, read_only: true} */
    public function capabilities(MediaSource $source): array;

    public function open(MediaSource $source, string $locator, ?int $start, ?int $end): SourceResponse;

    public function localPath(MediaSource $source, string $locator): ?string;
}
