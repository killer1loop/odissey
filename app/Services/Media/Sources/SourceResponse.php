<?php

namespace App\Services\Media\Sources;

final readonly class SourceResponse
{
    public function __construct(
        public mixed $body,
        public int $status,
        public int $size,
        public string $contentType,
        public ?string $contentRange = null,
    ) {}
}
