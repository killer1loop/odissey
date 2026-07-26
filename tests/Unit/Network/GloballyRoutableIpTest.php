<?php

namespace Tests\Unit\Network;

use App\Services\Network\GloballyRoutableIp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GloballyRoutableIpTest extends TestCase
{
    #[DataProvider('specialAddresses')]
    public function test_special_use_addresses_are_rejected(string $address): void
    {
        $this->assertFalse(GloballyRoutableIp::allows($address));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function specialAddresses(): array
    {
        return [
            'unspecified v4' => ['0.0.0.0'],
            'private v4' => ['10.0.0.1'],
            'carrier nat' => ['100.64.0.1'],
            'loopback' => ['127.0.0.1'],
            'link local' => ['169.254.169.254'],
            'benchmark' => ['198.18.0.1'],
            'documentation' => ['203.0.113.1'],
            'multicast' => ['224.0.0.1'],
            'loopback v6' => ['::1'],
            'unique local v6' => ['fd00::1'],
            'site local v6' => ['fec0::1'],
            'multicast v6' => ['ff02::1'],
            'mapped private v4' => ['::ffff:169.254.169.254'],
            'nat64 private v4' => ['64:ff9b::a9fe:a9fe'],
            'six-to-four loopback' => ['2002:7f00:1::'],
            'documentation v6' => ['2001:db8::1'],
            'documentation v6 second block' => ['3fff::1'],
        ];
    }

    public function test_public_unicast_addresses_are_allowed(): void
    {
        $this->assertTrue(GloballyRoutableIp::allows('8.8.8.8'));
        $this->assertTrue(
            GloballyRoutableIp::allows('2606:4700:4700::1111'),
        );
    }
}
