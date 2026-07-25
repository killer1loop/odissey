<?php

namespace App\Services\Iptv;

class HostAddressResolver
{
    /**
     * Resolve all currently advertised A and AAAA addresses for SSRF checks.
     *
     * @return array<int, string>
     */
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if (is_array($records)) {
            foreach ($records as $record) {
                foreach (['ip', 'ipv6'] as $key) {
                    if (isset($record[$key]) && is_string($record[$key])) {
                        $addresses[] = $record[$key];
                    }
                }
            }
        }

        if ($addresses === []) {
            $ipv4Addresses = @gethostbynamel($host);

            if (is_array($ipv4Addresses)) {
                $addresses = [...$addresses, ...$ipv4Addresses];
            }
        }

        return array_values(array_unique($addresses));
    }
}
