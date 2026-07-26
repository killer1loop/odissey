<?php

namespace App\Services\Iptv;

use App\Services\Iptv\Exceptions\SanitizedIptvException;
use GuzzleHttp\Psr7\Uri;
use Throwable;

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
        $options = [];

        if (filter_var($this->host, FILTER_VALIDATE_IP) === false) {
            if (! defined('CURLOPT_RESOLVE')) {
                throw new SanitizedIptvException('upstream_pinning_unavailable');
            }

            $address = str_contains($this->address, ':')
                ? '['.$this->address.']'
                : $this->address;
            $options[CURLOPT_RESOLVE] = [
                "{$this->host}:{$this->port}:{$address}",
            ];
        }

        if (defined('CURLOPT_PROXY')) {
            $options[CURLOPT_PROXY] = '';
        }

        if (defined('CURLOPT_NOPROXY')) {
            $options[CURLOPT_NOPROXY] = '*';
        }

        return $options;
    }

    public function connectUrl(?string $logicalUrl = null): string
    {
        try {
            $uri = new Uri($logicalUrl ?? $this->url);
            $scheme = strtolower($uri->getScheme());
            $host = strtolower(trim($uri->getHost(), '[]'));
            $port = $uri->getPort() ?? ($scheme === 'https' ? 443 : 80);

            if (
                ! in_array($scheme, ['http', 'https'], true)
                || $host !== $this->host
                || $port !== $this->port
                || $uri->getUserInfo() !== ''
                || $uri->getFragment() !== ''
            ) {
                throw new SanitizedIptvException('invalid_upstream_resource');
            }

            $address = str_contains($this->address, ':')
                ? '['.$this->address.']'
                : $this->address;

            return (string) $uri->withHost($address);
        } catch (SanitizedIptvException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new SanitizedIptvException('invalid_upstream_resource');
        }
    }

    public function authority(): string
    {
        $host = str_contains($this->host, ':')
            ? '['.$this->host.']'
            : $this->host;
        $scheme = strtolower((string) parse_url($this->url, PHP_URL_SCHEME));
        $defaultPort = $scheme === 'https' ? 443 : 80;

        return $this->port === $defaultPort
            ? $host
            : $host.':'.$this->port;
    }

    /**
     * Guzzle's true streaming handler ignores raw cURL options. Connect to the
     * validated IP directly while preserving the logical host for HTTP and TLS.
     *
     * @return array<string, mixed>
     */
    public function streamOptions(): array
    {
        return [
            'protocols' => ['http', 'https'],
            'proxy' => [
                'http' => null,
                'https' => null,
                'no' => [],
            ],
            'stream_context' => [
                'ssl' => [
                    'peer_name' => $this->host,
                    'SNI_enabled' => true,
                    'SNI_server_name' => $this->host,
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ],
        ];
    }
}
