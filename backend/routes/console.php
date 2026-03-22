<?php

use App\Services\SlotService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('redis:warmup', function (SlotService $service) {
    $service->warmUpCache();
    $this->info('Redis cache has been warmed up!');
})->purpose('Fill Redis ZSET and HASH from MySQL');

Schedule::command('holds:clear-expired')->everyMinute();