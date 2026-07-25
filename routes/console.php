<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('iptv:prune')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('media:transcodes:prune')
    ->everyTenMinutes()
    ->withoutOverlapping(15);
