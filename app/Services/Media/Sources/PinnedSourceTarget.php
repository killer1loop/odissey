<?php

namespace App\Services\Media\Sources;

use GuzzleHttp\Psr7\Uri;
use RuntimeException;
use Throwable;

final readonly class PinnedSourceTarget
{
    public function __construct(
        public string $url,
        public string $host,
        public int $port,
        public string $address,
    ) {}

    /**
     * Pin cURL to the address that passed the SSRF policy and disable inherited
     * proxy configuration. The URL hostname remains in use for Host and TLS SNI.
     *
     * @return array<int, array<int, string>|string>
     */
    public function curlOptions(): array
    {
        $options = [];

        if (filter_var($this->host, FILTER_VALIDATE_IP) === false) {
            if (! defined('CURLOPT_RESOLVE')) {
                throw new RuntimeException('source_pinning_unavailable');
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

    public function authority(): string
    {
        $host = str_contains($this->host, ':')
            ? '['.$this->host.']'
            : $this->host;
        $scheme = strtolower((string) parse_url($this->url, PHP_URL_SCHEME));
        $defaultPort = $scheme === 'https' ? 443 : 80;

        return $this->port === $defaultPort ? $host : $host.':'.$this->port;
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
                throw new RuntimeException('source_target_mismatch');
            }

            $address = str_contains($this->address, ':')
                ? '['.$this->address.']'
                : $this->address;

            return (string) $uri->withHost($address);
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new RuntimeException('source_target_mismatch');
        }
    }

    /**
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
