<?php

namespace App\Console\Commands;

use App\Models\Hold;
use App\Services\SlotService;
use Illuminate\Console\Command;

class ClearExpiredHolds extends Command
{
    protected $signature = 'holds:clear-expired';
    protected $description = 'Отмена неподтверждённых холдов, время жизни которых истекло, и синхронизация Redis';

    public function handle(SlotService $slotService)
    {
        $this->info("Очистка неподтверждённых холдов старше " . Hold::EXPIRES_IN_MINUTES . " минут");

        $affectedSlotIds = Hold::where('status', Hold::STATUS_HELD)
            ->where('expires_at', '<', now())
            ->pluck('slot_id')
            ->unique();

        if ($affectedSlotIds->isEmpty()) {
            $this->info("Просроченных холдов не найдено.");
            return;
        }

        $updatedCount = Hold::where('status', Hold::STATUS_HELD)
            ->where('expires_at', '<', now())
            ->update(['status' => Hold::STATUS_CANCELLED]);

        // Синхронизируем каждый затронутый слот в Redis
        foreach ($affectedSlotIds as $slotId) {
            $slotService->syncSlotToRedis($slotId);
        }

        $this->info("Очищено холдов: {$updatedCount}. Обновлено слотов в Redis: " . $affectedSlotIds->count());
    }
}
