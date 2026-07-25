<?php

namespace App\Services\Iptv;

use App\Services\Iptv\Exceptions\SanitizedIptvException;

class PinnedUpstreamTarget
{
    public function __construct(
        public readonly string $url,
        public readonly string $host,
        public readonly int $port,
        public readonly string $address,
    ) {}

    /**
     * Pin cURL to the already validated address. cURL keeps the URL hostname
     * for the Host header, TLS SNI, and certificate verification.
     *
     * @return array<int, array<int, string>|string>
     */
    public function curlOptions(): array
    {
        if (! defined('CURLOPT_RESOLVE')) {
            throw new SanitizedIptvException('upstream_pinning_unavailable');
        }

        $address = str_contains($this->address, ':')
            ? '['.$this->address.']'
            : $this->address;

        $options = [
            CURLOPT_RESOLVE => [
                "{$this->host}:{$this->port}:{$address}",
            ],
        ];

        if (defined('CURLOPT_PROXY')) {
            $options[CURLOPT_PROXY] = '';
        }

        return $options;
    }
}
