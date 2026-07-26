<?php

$canonicalHost = strtolower((string) parse_url(
    (string) env('APP_URL', 'http://localhost'),
    PHP_URL_HOST,
));
$additionalHosts = array_map(
    static function (string $configuredHost): string {
        $configuredHost = trim($configuredHost);

        if ($configuredHost === '') {
            return '';
        }

        return strtolower((string) (
            parse_url(
                str_contains($configuredHost, '://')
                    ? $configuredHost
                    : 'http://'.$configuredHost,
                PHP_URL_HOST,
            ) ?: ''
        ));
    },
    explode(',', (string) env('ODISSEY_TRUSTED_HOSTS', '')),
);
$trustedHosts = array_values(array_unique(array_filter([
    $canonicalHost,
    'localhost',
    '127.0.0.1',
    ...$additionalHosts,
])));

return [
    /*
    |--------------------------------------------------------------------------
    | First-launch setup token
    |--------------------------------------------------------------------------
    |
    | Production fails closed: this value must be configured and the matching
    | token supplied before the first administrator can be created. It is
    | ignored after setup has completed.
    |
    */
    'setup_token' => env('ODISSEY_SETUP_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Trusted HTTP hosts
    |--------------------------------------------------------------------------
    |
    | APP_URL is always canonical. Additional entries are exact hostnames for
    | private health checks or alternate operator-controlled ingress routes.
    | Wildcards and arbitrary regular expressions are intentionally unsupported.
    |
    */
    'trusted_hosts' => array_map(
        static fn (string $allowedHost): string => '^'.preg_quote($allowedHost, '/').'$',
        $trustedHosts,
    ),
];
