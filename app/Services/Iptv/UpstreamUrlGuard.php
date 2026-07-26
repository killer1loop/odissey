<?php

namespace App\Services\Iptv;

use App\Services\Iptv\Exceptions\SanitizedIptvException;
use App\Services\Network\GloballyRoutableIp;
use Illuminate\Container\Container;
use Throwable;

class UpstreamUrlGuard
{
    public function __construct(
        private readonly HostAddressResolver $resolver,
    ) {}

    public function normalizeBaseUrl(string $url, bool $allowInsecureHttp): string
    {
        $url = rtrim(trim($url), '/');

        try {
            $parts = parse_url($url);
        } catch (Throwable) {
            throw new SanitizedIptvException('invalid_provider_url', 422);
        }

        if (! is_array($parts)) {
            throw new SanitizedIptvException('invalid_provider_url', 422);
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (
            ! in_array($scheme, ['http', 'https'], true)
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

    public function normalizedOrigin(
        string $url,
        bool $allowInsecureHttp,
    ): string {
        $parts = $this->resourceParts($url, $allowInsecureHttp);
        $host = str_contains($parts['host'], ':')
            ? '['.$parts['host'].']'
            : $parts['host'];

        return $parts['scheme'].'://'.$host.':'.$parts['port'];
    }

    public function pin(string $url, bool $allowInsecureHttp): PinnedUpstreamTarget
    {
        $parts = $this->resourceParts($url, $allowInsecureHttp);
        $addresses = $this->resolver->resolve($parts['host']);

        if ($addresses === []) {
            throw new SanitizedIptvException('unresolved_upstream_target', 502);
        }

        foreach ($addresses as $address) {
            if ($this->isNonPublicIp($address)) {
                throw new SanitizedIptvException('blocked_upstream_target', 502);
            }
        }

        return new PinnedUpstreamTarget(
            url: $url,
            host: $parts['host'],
            port: $parts['port'],
            address: $addresses[0],
        );
    }

    /**
     * @return array{scheme: string, host: string, port: int}
     */
    private function resourceParts(
        string $url,
        bool $allowInsecureHttp,
    ): array {
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

        try {
            $parts = parse_url($url);
        } catch (Throwable) {
            throw new SanitizedIptvException('invalid_upstream_resource', 502);
        }

        if (! is_array($parts)) {
            throw new SanitizedIptvException('invalid_upstream_resource', 502);
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $rawHost = (string) ($parts['host'] ?? '');
        $unwrappedHost = trim($rawHost, '[]');
        $host = strtolower(rtrim($unwrappedHost, '.'));

        if (
            ! in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || str_ends_with($unwrappedHost, '.')
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
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

        $port = isset($parts['port'])
            ? (int) $parts['port']
            : ($scheme === 'https' ? 443 : 80);

        if ($port < 1 || $port > 65535) {
            throw new SanitizedIptvException('invalid_upstream_resource', 502);
        }

        return [
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
        ];
    }

    private function isNonPublicIp(string $host): bool
    {
        return ! GloballyRoutableIp::allows($host);
    }
}
