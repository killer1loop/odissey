<?php

namespace App\Services\Iptv;

use App\Services\Iptv\Exceptions\SanitizedIptvException;
use Illuminate\Container\Container;

class UpstreamUrlGuard
{
    public function __construct(
        private readonly HostAddressResolver $resolver,
    ) {}

    public function normalizeBaseUrl(string $url, bool $allowInsecureHttp): string
    {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (
            ! is_array($parts)
            || ! in_array($scheme, ['http', 'https'], true)
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new SanitizedIptvException('invalid_provider_url', 422);
        }

        if ($scheme === 'http' && ! $allowInsecureHttp) {
            throw new SanitizedIptvException('insecure_http_requires_consent', 422);
        }

        $this->assertPublicTarget($url, $allowInsecureHttp);

        return $url;
    }

    public function assertPublicTarget(string $url, bool $allowInsecureHttp): void
    {
        $this->pin($url, $allowInsecureHttp);
    }

    public function pin(string $url, bool $allowInsecureHttp): PinnedUpstreamTarget
    {
        $container = Container::getInstance();
        $configuredMaxBytes = $container?->bound('config')
            ? (int) config('iptv.resource_url_max_bytes')
            : 8192;

        if (
            strlen($url)
            > min(16384, max(2048, $configuredMaxBytes))
        ) {
            throw new SanitizedIptvException('upstream_url_too_large', 502);
        }

        if (preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            throw new SanitizedIptvException('invalid_upstream_resource', 502);
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));

        if (
            ! is_array($parts)
            || ! in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new SanitizedIptvException('invalid_upstream_resource', 502);
        }

        if ($scheme === 'http' && ! $allowInsecureHttp) {
            throw new SanitizedIptvException('insecure_upstream_resource', 502);
        }

        if (
            $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
        ) {
            throw new SanitizedIptvException('blocked_upstream_target', 502);
        }

        $addresses = $this->resolver->resolve($host);

        if ($addresses === []) {
            throw new SanitizedIptvException('unresolved_upstream_target', 502);
        }

        foreach ($addresses as $address) {
            if ($this->isNonPublicIp($address)) {
                throw new SanitizedIptvException('blocked_upstream_target', 502);
            }
        }

        $port = isset($parts['port'])
            ? (int) $parts['port']
            : ($scheme === 'https' ? 443 : 80);

        return new PinnedUpstreamTarget(
            url: $url,
            host: $host,
            port: $port,
            address: $addresses[0],
        );
    }

    private function isNonPublicIp(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return true;
        }

        return filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }
}
