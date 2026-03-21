<?php

namespace App\Services;

use App\Models\Hold;
use App\Models\Slot;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SlotService {
    
    private const CACHE_KEY = 'slots_availability';

    private const CACHE_TTL = 15;

    public function getAvailableSlots() {

        $cacheKey = self::CACHE_KEY;
        $cacheTtl = self::CACHE_TTL;

        $data = Cache::get($cacheKey);

        if ($data !== null) {
            return $data;
        }

        return Cache::lock($cacheKey . '_lock', 10)->block(5, fn() => 
            Cache::remember($cacheKey, $cacheTtl, fn() => 
                Slot::query()
                    // виртуальная колонка. Используется Eager Loading для избежания проблемы N + 1 
                    ->withCount(['holds as active_holds_count' => fn($query) =>
                        $query->where('status', Hold::STATUS_HELD)
                            ->where('expires_at', '>', now())
                    ])
                    ->get()
                    ->map(fn($slot) => [
                        'slot_id'   => $slot->id,
                        'capacity'  => $slot->capacity,
                        // виртуальный остаток: remaining в БД - активные холды
                        'remaining' => max(0, $slot->remaining - $slot->active_holds_count),
                    ])
            )
        );
    }

    public function createHold(int $slotId, string $idempotencyKey) {
        // Используем Redis-замок на 10 секунд специально для этого ключа.
        // Это гарантирует, что только ОДИН процесс с таким UUID войдет в БД.
        return Cache::lock('hold_create_' . $idempotencyKey, 10)->block(5, function () use ($slotId, $idempotencyKey) {
            // Внутри замка нам уже не нужен lockForUpdate для идемпотентности, 
            // так как конкурентов с таким же ключом сюда просто не пустит Redis.
            $hold = Hold::where('idempotency_key', $idempotencyKey)->first();
            if ($hold) return $hold; // здесь можно возвращать 200, а не 201

            return DB::transaction(function () use ($slotId, $idempotencyKey) {
                // блокировка слота (он существует, поэтому Gap Lock не возникнет)
                $lockedSlot  = Slot::where('id', $slotId)->lockForUpdate()->firstOrFail();

                // подсчёт активных холдов
                $activeHoldsCount = Hold::where('slot_id', $slotId)
                    ->where('status', Hold::STATUS_HELD)
                    ->where('expires_at', '>', now())
                    ->count();

                // Проверка  места с учетом холдов
                if ($lockedSlot->remaining - $activeHoldsCount <= 0) {
                    return null; // 409 Conflict
                }

                $hold = Hold::create([
                    'slot_id' => $lockedSlot->id,
                    'idempotency_key' => $idempotencyKey,
                    'status' => Hold::STATUS_HELD,
                    'expires_at' => now()->addMinutes(Hold::EXPIRES_IN_MINUTES), // Холды живут 5 минут 
                ]);

                // инвалидация кеша, т.к. для защиты от овербукинга это метод меняет виртуальный remaining
                $this->invalidateCache();
                return $hold;
            });
        });
    }

    public function confirmHold(int $holdId): bool
    {
        return DB::transaction(function () use ($holdId) {
            $hold = Hold::where('id', $holdId)->lockForUpdate()->firstOrFail();

            if ($hold->status !== Hold::STATUS_HELD) {
                return false;
            }

            $slot = Slot::where('id', $hold->slot_id)->lockForUpdate()->firstOrFail();
            if ($slot->remaining <= 0) {
                return false;
            }

            $slot->decrement('remaining');
            $hold->update(['status' => Hold::STATUS_CONFIRMED]);

            $this->invalidateCache();
            return true;
        });
    }

    public function cancelHold(int $holdId)
    {
        DB::transaction(function () use ($holdId) {
            $hold = Hold::where('id', $holdId)->lockForUpdate()->firstOrFail();

            if ($hold->status === Hold::STATUS_CONFIRMED) {
                Slot::where('id', $hold->slot_id)->increment('remaining');
            }

            $hold->update(['status'=> Hold::STATUS_CANCELLED]);
            $this->invalidateCache();
        });
    }

    private function invalidateCache() {
        Cache::forget(self::CACHE_KEY);
    }

}