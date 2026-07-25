<?php

namespace App\Services\Iptv\Exceptions;

class UpstreamResponseException extends SanitizedIptvException
{
    public function __construct(
        string $errorCode,
        public readonly ?int $upstreamStatus = null,
        int $status = 502,
    ) {
        parent::__construct($errorCode, $status);
    }
}
