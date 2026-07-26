<?php

namespace App\Services\Media\Sources;

use App\Services\Iptv\HostAddressResolver;
use App\Services\Network\GloballyRoutableIp;
use RuntimeException;
use Throwable;

class HttpSourceGuard
{
    public function __construct(
        private readonly HostAddressResolver $resolver,
    ) {}

    public function validate(string $url, bool $allowPrivate): string
    {
        return rtrim($this->pin($url, $allowPrivate)->url, '/');
    }

    public function pin(string $url, bool $allowPrivate): PinnedSourceTarget
    {
        $url = trim($url);

        if ($url === '' || strlen($url) > 8192 || preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            throw new RuntimeException('invalid_source_url');
        }

        try {
            $parts = parse_url($url);
        } catch (Throwable) {
            throw new RuntimeException('invalid_source_url');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $rawHost = (string) ($parts['host'] ?? '');
        $host = strtolower(rtrim(trim($rawHost, '[]'), '.'));

        if (
            ! is_array($parts)
            || ! in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || str_ends_with(trim($rawHost, '[]'), '.')
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            throw new RuntimeException('invalid_source_url');
        }

        if ($scheme === 'http' && ! $allowPrivate) {
            throw new RuntimeException('insecure_source_url');
        }

        $port = isset($parts['port'])
            ? (int) $parts['port']
            : ($scheme === 'https' ? 443 : 80);

        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('invalid_source_url');
        }

        $addresses = $this->resolver->resolve($host);

        if ($addresses === []) {
            throw new RuntimeException('source_dns_failed');
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                throw new RuntimeException('source_dns_failed');
            }

            if (
                ! $allowPrivate
                && ! GloballyRoutableIp::allows($address)
            ) {
                throw new RuntimeException('private_source_address');
            }
        }

        return new PinnedSourceTarget(
            url: $url,
            host: $host,
            port: $port,
            address: $addresses[0],
        );
    }
}
