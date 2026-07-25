<?php

namespace App\Services\Media\Sources;

final readonly class SourceObject
{
    public function __construct(
        public string $locator,
        public string $path,
        public int $size,
        public ?string $etag = null,
        public ?int $modifiedAt = null,
    ) {}
}
