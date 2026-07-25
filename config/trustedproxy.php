<?php

$trustedProxies = explode(',', (string) env(
    'ODISSEY_TRUSTED_PROXIES',
    '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,fc00::/7',
));

return [
    /*
    |--------------------------------------------------------------------------
    | Trusted reverse proxies
    |--------------------------------------------------------------------------
    |
    | Trust only private networks that can contain the deployment's reverse
    | proxy. Direct public clients must never be allowed to supply their own
    | forwarded address for authentication or setup rate limiting.
    |
    */
    'proxies' => array_values(array_filter(array_map(
        static fn (string $proxy): string => trim($proxy),
        $trustedProxies,
    ))),
];
