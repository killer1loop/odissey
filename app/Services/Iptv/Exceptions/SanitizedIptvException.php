<?php

namespace App\Services\Iptv\Exceptions;

use RuntimeException;

class SanitizedIptvException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        int $status = 502,
    ) {
        parent::__construct("IPTV request failed [{$errorCode}].", $status);
    }

    public function httpStatus(): int
    {
        $status = $this->getCode();

        return $status >= 400 && $status <= 599 ? $status : 502;
    }
}
