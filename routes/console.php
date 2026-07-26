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

Schedule::command('iptv:epg:refresh')
    ->hourly()
    ->withoutOverlapping(10);

Schedule::command('media:transcodes:prune')
    ->everyTenMinutes()
    ->withoutOverlapping(15);

Schedule::command('media:history:prune')
    ->daily()
    ->withoutOverlapping();

Schedule::command('iptv:epg:prune')
    ->daily()
    ->withoutOverlapping();

Schedule::command('media:sources:scan')
    ->weekly()
    ->withoutOverlapping();
