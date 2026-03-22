<?php

namespace App\Services;

use App\Models\Hold;
use App\Models\Slot;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class SlotService {

    public const ZSET_KEY = 'slots:availability';
    public const HASH_KEY = 'slots:data';

    public function getAvailableSlots(): array
    {
        // Проверяем оба ключа при ситуациях пропажи одного из случайного удаления 
        // или из-за политики вытеснения maxmemory-policy, если Redis переполнен
        $exists = Redis::exists(self::ZSET_KEY) && Redis::exists(self::HASH_KEY);

        if ($exists) { // Если индекс есть, сразу отдаём кеш
            return $this->fetchFromRedis();
        }
        // Если индекса нет — включаем Atomic Lock, чтобы только ОДИН процесс наполнил Redis
        return Cache::lock(self::ZSET_KEY . '_lock', 10)->block(5, function () {
            // Double-check: вдруг кто-то уже наполнил, пока мы ждали лок
            if (!Redis::exists(self::ZSET_KEY) || !Redis::exists(self::HASH_KEY)) {
                $this->warmUpCache();
            }
            return $this->fetchFromRedis();
        });        
    }

    public function createHold(int $slotId, string $idempotencyKey): ?Hold
    {
        // Используем Redis-замок на 10 секунд специально для этого ключа.
        // Это гарантирует, что только ОДИН процесс с таким UUID войдет в БД.
        return Cache::lock('hold_create_' . $idempotencyKey, 10)->block(5, function () use ($slotId, $idempotencyKey) {
            // Внутри замка нам уже не нужен lockForUpdate для идемпотентности, 
            // так как конкурентов с таким же ключом сюда просто не пустит Redis.
            $hold = Hold::where('idempotency_key', $idempotencyKey)->first();
            if ($hold) return $hold; // здесь можно возвращать 200, а не 201

            return DB::transaction(function () use ($slotId, $idempotencyKey) {
                try { // для обработки дубликата, если Редис на мгновение "мигнет", отвалится по таймауту или кто-то вручную удалит замок во время выполнения
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

                    $this->syncSlotToRedis($slotId); // Атомарная синхронизация
                    return $hold;

                } catch (\Illuminate\Database\QueryException $e) {
                    if ($e->getCode() === '23000') { // Duplicate entry
                        Log::warning("Race condition detected & handled for Idempotency-Key: {$idempotencyKey}", [
                            'slot_id' => $slotId,
                            'error_message' => $e->getMessage()
                        ]);
                        
                        return Hold::where('idempotency_key', $idempotencyKey)->first();
                    }
                    throw $e;
                }
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

            // $this->invalidateCache();
            $this->syncSlotToRedis($hold->slot_id);

            return true;
        });
    }

    public function cancelHold(int $holdId): void
    {
        DB::transaction(function () use ($holdId) {
            $hold = Hold::where('id', $holdId)->lockForUpdate()->firstOrFail();

            if (\in_array($hold->status, [Hold::STATUS_HELD, Hold::STATUS_CONFIRMED])) {
                if ($hold->status === Hold::STATUS_CONFIRMED) {
                    Slot::where('id', $hold->slot_id)->increment('remaining');
                }
                $hold->update(['status' => Hold::STATUS_CANCELLED]);
                $this->syncSlotToRedis($hold->slot_id);
            }
        });
    }

    /**
     * Атомарная синхронизация MySQL -> Redis (ZSET + HASH)
     */
    public function syncSlotToRedis(int $slotId): void {
        try {
            $slot = Slot::query()->withCount(['holds as active_holds_count' => fn($query) =>
                $query->where('status', Hold::STATUS_HELD)->where('expires_at', '>', now())
            ])->find($slotId);

            if (!$slot) {
                // Если слота нет в БД — удаляем его из кеша
                Redis::transaction(function ($tx) use ($slotId) {
                    $tx->zrem(self::ZSET_KEY, $slotId);
                    $tx->hdel(self::HASH_KEY, $slotId);
                });
                return;
            }

            $virtualRemaining = max(0, $slot->remaining - $slot->active_holds_count);

            // Используем Redis Transaction (MULTI/EXEC)
            Redis::transaction(function ($tx) use ($slot, $virtualRemaining) {
                // Обновляем индекс доступности
                $tx->zadd(self::ZSET_KEY, $virtualRemaining, $slot->id);            
                // Обновляем статические данные
                $tx->hset(self::HASH_KEY, $slot->id, json_encode([
                    // 'slot_id'  => $slot->id,
                    'capacity' => $slot->capacity,
                ]));
            });
        } catch (\Throwable $e) {
            Log::error("Redis sync error for slot {$slotId}: " . $e->getMessage());
        }
    }

    public function warmUpCache(): void {
        // Очищаем старый кеш перед полным прогревом (атомарно, важно для удаления удаленных в БД записей)
        Redis::del([self::ZSET_KEY, self::HASH_KEY]);

        // использование chunkById для стабильной выборки
        Slot::query()->withCount(['holds as active_holds_count' => fn($query) =>
            $query->where('status', Hold::STATUS_HELD)
                ->where('expires_at', '>', now())
        ])->chunkById(500, function ($slots) {
            Redis::pipeline(function ($pipe) use ($slots) {
                foreach ($slots as $slot) {
                    $virtualRemaining = max(0, $slot->remaining - $slot->active_holds_count);
                    $pipe->zadd(self::ZSET_KEY, $virtualRemaining, $slot->id);
                    $pipe->hset(self::HASH_KEY, $slot->id, json_encode(['capacity' => $slot->capacity]));
                }
            });
        });
    }

    /**
     * Вынос логики чтения из Redis в отдельный метод
     */
    private function fetchFromRedis(): array {
        // $ids = Redis::zrevrangebyscore(self::ZSET_KEY, '+inf', 0); // указать 1, если нужен вывод слотов только со свободными местами 
        $ids = Redis::zrange(self::ZSET_KEY, 0, -1);
        if (empty($ids)) return [];

        // сортировка ID, чтобы выдача всегда была одинаковой
        sort($ids); 

        $rawDetails = Redis::hmget(self::HASH_KEY, $ids);
        
        $result = [];
        foreach ($ids as $index => $id) {
            $details = json_decode($rawDetails[$index], true);
            $result[] = [
                'slot_id'   => (int)$id,
                'capacity'  => $details['capacity'] ?? 0,
                'remaining' => (int)Redis::zscore(self::ZSET_KEY, $id)
            ];
        }
        return $result;
    }

}