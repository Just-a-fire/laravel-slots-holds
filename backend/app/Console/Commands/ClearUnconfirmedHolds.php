<?php

namespace App\Console\Commands;

use App\Models\Hold;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ClearUnconfirmedHolds extends Command
{
    protected $signature = 'holds:clear-unconfirmed';
    protected $description = 'Отмена всех неподтверждённых холдов, в т.ч. время жизни которых НЕ истекло, для удобства тестирования параллельных запросов (Stress Test)';

    public function handle()
    {
        $this->info("Очистка всех неподтверждённых холдов, в т.ч. живых");
        // все неподтверждённые холды в статусе HELD
        $unconfirmedCount = Hold::where('status', Hold::STATUS_HELD)
            ->update(['status' => Hold::STATUS_CANCELLED]);

        if ($unconfirmedCount > 0) { // если такие нашлись
            // инвалидируем кеш, так как доступность (remaining) слотов изменилась
            Cache::forget('slots_availability');
            $this->info("Очищено холдов: {$unconfirmedCount}");
        }
    }
}
