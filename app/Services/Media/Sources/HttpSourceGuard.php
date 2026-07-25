<?php

namespace App\Services\Media\Sources;

use RuntimeException;

class HttpSourceGuard
{
    public function validate(string $url, bool $allowPrivate): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('invalid_source_url');
        }
        if (($parts['scheme'] ?? '') === 'http' && ! $allowPrivate) {
            throw new RuntimeException('insecure_source_url');
        }
        $addresses = filter_var($parts['host'], FILTER_VALIDATE_IP)
            ? [$parts['host']]
            : array_values(array_unique(array_merge(gethostbynamel($parts['host']) ?: [], array_column(dns_get_record($parts['host'], DNS_AAAA), 'ipv6'))));
        if ($addresses === []) {
            throw new RuntimeException('source_dns_failed');
        }
        foreach ($addresses as $address) {
            if (! $allowPrivate && ! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('private_source_address');
            }
        }

        return rtrim($url, '/');
    }
}
