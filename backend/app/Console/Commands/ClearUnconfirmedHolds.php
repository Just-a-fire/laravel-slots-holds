<?php

namespace App\Console\Commands;

use App\Models\Hold;
use App\Services\SlotService;
use Illuminate\Console\Command;

class ClearUnconfirmedHolds extends Command
{
    protected $signature = 'holds:clear-unconfirmed';
    protected $description = 'Отмена всех неподтверждённых холдов, в т.ч. время жизни которых НЕ истекло, и синхронизация Redis для удобства тестирования параллельных запросов (Stress Test)';

    public function handle(SlotService $slotService)
    {
        $this->info("Очистка всех неподтверждённых холдов, в т.ч. живых");
        // ID все неподтверждённые холдов в статусе HELD
        $affectedSlotIds = Hold::where('status', Hold::STATUS_HELD)
            ->pluck('slot_id')
            ->unique();

        if ($affectedSlotIds->isEmpty()) {
            $this->info("Неподтверждённых холдов не найдено.");
            return;
        }

        $updatedCount = Hold::where('status', Hold::STATUS_HELD)
            ->update(['status' => Hold::STATUS_CANCELLED]);

        // 3. Синхронизируем каждый затронутый слот в Redis
        foreach ($affectedSlotIds as $slotId) {
            $slotService->syncSlotToRedis($slotId);
        }

        $this->info("Очищено холдов: {$updatedCount}. Обновлено слотов в Redis: " . $affectedSlotIds->count());
    }
}
