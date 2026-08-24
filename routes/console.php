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

Schedule::command('iptv:logos:refresh')
    ->dailyAt('03:17')
    ->withoutOverlapping(30)
    ->runInBackground();

Schedule::command('media:transcodes:prune')
    ->everyTenMinutes()
    ->withoutOverlapping(15);

Schedule::command('media:history:prune')
    ->daily()
    ->withoutOverlapping();

Schedule::command('native-client:prune')
    ->hourly()
    ->withoutOverlapping(10);

Schedule::command('queue:prune-failed --hours=168')
    ->dailyAt('02:37')
    ->withoutOverlapping(10);

Schedule::command('iptv:epg:prune')
    ->daily()
    ->withoutOverlapping();

Schedule::command('media:sources:scan')
    ->weekly()
    ->withoutOverlapping()
    ->runInBackground();
