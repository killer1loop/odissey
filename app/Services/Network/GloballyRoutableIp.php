<?php

namespace App\Services\Network;

final class GloballyRoutableIp
{
    /**
     * FILTER_FLAG_NO_PRIV_RANGE/NO_RES_RANGE does not cover every special-use
     * network. This policy accepts public IPv4 unicast and IPv6 global unicast
     * while explicitly excluding special allocations inside those spaces.
     */
    public static function allows(string $address): bool
    {
        $packed = @inet_pton($address);

        if (! is_string($packed)) {
            return false;
        }

        if (strlen($packed) === 4) {
            foreach ([
                ['0.0.0.0', 8],
                ['10.0.0.0', 8],
                ['100.64.0.0', 10],
                ['127.0.0.0', 8],
                ['169.254.0.0', 16],
                ['172.16.0.0', 12],
                ['192.0.0.0', 24],
                ['192.0.2.0', 24],
                ['192.88.99.0', 24],
                ['192.168.0.0', 16],
                ['198.18.0.0', 15],
                ['198.51.100.0', 24],
                ['203.0.113.0', 24],
                ['224.0.0.0', 4],
                ['240.0.0.0', 4],
            ] as [$network, $prefix]) {
                if (self::matches($packed, $network, $prefix)) {
                    return false;
                }
            }

            return true;
        }

        if (
            strlen($packed) !== 16
            || ! self::matches($packed, '2000::', 3)
        ) {
            return false;
        }

        foreach ([
            ['2001::', 23],
            ['2001:db8::', 32],
            ['2002::', 16],
            ['3fff::', 20],
        ] as [$network, $prefix]) {
            if (self::matches($packed, $network, $prefix)) {
                return false;
            }
        }

        return true;
    }

    private static function matches(
        string $packedAddress,
        string $network,
        int $prefix,
    ): bool {
        $packedNetwork = inet_pton($network);

        if (
            ! is_string($packedNetwork)
            || strlen($packedNetwork) !== strlen($packedAddress)
        ) {
            return false;
        }

        $wholeBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if (
            $wholeBytes > 0
            && substr($packedAddress, 0, $wholeBytes)
                !== substr($packedNetwork, 0, $wholeBytes)
        ) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($packedAddress[$wholeBytes]) & $mask)
            === (ord($packedNetwork[$wholeBytes]) & $mask);
    }
}
