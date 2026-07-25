<?php

namespace App\Services\Media\Captions;

final readonly class CaptionCandidate
{
    public function __construct(
        public string $provider,
        public string $externalId,
        public string $language,
        public string $label,
        public string $downloadUrl,
        public bool $hearingImpaired = false,
        public array $headers = [],
    ) {}
}
