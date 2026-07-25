<?php

namespace App\Services\Iptv;

use Illuminate\Http\Client\Factory;

class ConfidentialHttpFactory extends Factory
{
    public function __construct()
    {
        // IPTV requests contain provider credentials in protocol-mandated URLs.
        // A factory without an event dispatcher prevents request/response
        // telemetry listeners from receiving those confidential URLs.
        parent::__construct();
    }
}
