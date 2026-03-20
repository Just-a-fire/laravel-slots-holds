<?php

namespace App\Services;

use App\Models\Hold;
use App\Models\Slot;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SlotService {
    
    private const CACHE_KEY = 'slots_availability';

    public function getAvailableSlots() {

        $cacheKey = self::CACHE_KEY;

        $data = Cache::get($cacheKey);

        if ($data !== null) {
            return $data;
        }

        return Cache::lock($cacheKey . '_lock', 10)->block(5, function () use ($cacheKey) {
            return Cache::remember($cacheKey, 15, function () {
                // return Slot::select('id as slot_id', 'capacity', 'remaining')->get();

                return Slot::query()
                    // виртуальная колонка. Используется Eager Loading для избежания проблемы N + 1 
                    ->withCount(['holds as active_holds_count' => function ($query) {
                        $query->where('status', Hold::STATUS_HELD)
                            ->where('expires_at', '>', now());
                    }])
                    ->get()
                    ->map(function ($slot) {
                        return [
                            'slot_id'   => $slot->id,
                            'capacity'  => $slot->capacity,
                            // виртуальный остаток: remaining в БД - активные холды
                            'remaining' => max(0, $slot->remaining - $slot->active_holds_count),
                        ];
                    });
            });
        });
    }

    public function createHold(int $slotId, string $idempotencyKey) {
        return DB::transaction(function () use ($slotId, $idempotencyKey) {
            // Проверка идемпотентности ВНУТРИ транзакции с локом
            $hold = Hold::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($hold) {
                return $hold;
            }

            // блокировка 
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
                'expires_at' => now()->addMinutes(5), // Холды живут 5 минут 
            ]);

            // инвалидация кеша, т.к. для защиты от овербукинга это метод меняет виртуальный remaining
            $this->invalidateCache();
            return $hold;
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