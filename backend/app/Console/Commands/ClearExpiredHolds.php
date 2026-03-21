<?php

namespace App\Console\Commands;

use App\Models\Hold;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ClearExpiredHolds extends Command
{
    protected $signature = 'holds:clear-expired';
    protected $description = 'Отмена неподтверждённых холдов, время жизни которых истекло';

    public function handle()
    {
        $this->info("Очистка неподтверждённых холдов старше " . Hold::EXPIRES_IN_MINUTES . " минут");
        // просроченные холды в статусе HELD
        $expiredCount = Hold::where('status', Hold::STATUS_HELD)
            ->where('expires_at', '<', now())
            ->update(['status' => Hold::STATUS_CANCELLED]);

        if ($expiredCount > 0) { // если такие нашлись
            // инвалидируем кеш, так как доступность (remaining) слотов изменилась
            Cache::forget('slots_availability');
            $this->info("Очищено холдов: {$expiredCount}");
        }
    }
}
