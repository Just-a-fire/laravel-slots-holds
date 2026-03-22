<?php

namespace Tests\Feature;

use App\Console\Commands\ClearExpiredHolds;
use App\Models\Hold;
use App\Models\Slot;
use App\Services\SlotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;


class SlotBookingTest extends TestCase
{
    use RefreshDatabase; // Очистка БД перед каждым тестом

    protected function setUp(): void
    {
        parent::setUp();
        $databaseName = config('database.connections.mysql.database');
        if (strripos($databaseName, 'test') === false) {
            throw new \Exception("ОПАСНОСТЬ: Тест пытается использовать рабочую ($databaseName) базу данных!");
        }
    }

    protected function tearDown(): void
    {
        // Очищаем Redis после каждого теста
        Redis::flushdb();
        parent::tearDown();
    }

    #[Test]
    public function it_calculates_virtual_remaining_correctly()
    {
        // 1. Создаем слот с 1 местом
        $slot = Slot::create(['capacity' => 1, 'remaining' => 1]);

        // 2. Создаем один активный холд (виртуально занимает место)
        Hold::create([
            'slot_id' => $slot->id,
            'idempotency_key' => Str::uuid(),
            'status' => Hold::STATUS_HELD,
            'expires_at' => now()->addMinutes(Hold::EXPIRES_IN_MINUTES)
        ]);

        // Синхронизируем Redis после ручного создания холда
        app(SlotService::class)->syncSlotToRedis($slot->id);

        // 3. Запрос доступности должен вернуть remaining: 0
        $response = $this->getJson('/api/slots/availability');

        $response->assertStatus(200)
            ->assertJsonFragment(['slot_id' => $slot->id, 'remaining' => 0]);
    }

    #[Test]
    public function it_enforces_idempotency_on_hold_creation()
    {
        $slot = Slot::create(['capacity' => 10, 'remaining' => 10]);
        $uuid = (string) Str::uuid();

        // Первый запрос
        $response1 = $this->withHeaders(['Idempotency-Key' => $uuid])
            ->postJson("/api/slots/{$slot->id}/hold");
        
        $response1->assertStatus(201);
        $holdId = $response1->json('id');

        // Второй запрос с тем же ключом
        $response2 = $this->withHeaders(['Idempotency-Key' => $uuid])
            ->postJson("/api/slots/{$slot->id}/hold");

        $response2->assertStatus(201);
        $this->assertEquals($holdId, $response2->json('id'));
        $this->assertDatabaseCount('holds', 1); // В базе осталась 1 запись
    }

    #[Test]
    public function it_prevents_overbooking_based_on_active_holds()
    {
        $slot = Slot::create(['capacity' => 1, 'remaining' => 1]);

        // Создаем первый холд
        $this->withHeaders(['Idempotency-Key' => Str::uuid()])
            ->postJson("/api/slots/{$slot->id}/hold")
            ->assertStatus(201);

        // Пытаемся создать второй холд на то же место
        $this->withHeaders(['Idempotency-Key' => Str::uuid()])
            ->postJson("/api/slots/{$slot->id}/hold")
            ->assertStatus(409); // Conflict: мест нет из-за активного холда
    }

    #[Test]
    public function it_frees_up_virtual_remaining_after_hold_expiration()
    {
        // 1. Создаем слот с 1 местом
        $slot = Slot::create(['capacity' => 1, 'remaining' => 1]);

        app(SlotService::class)->syncSlotToRedis($slot->id);

        // 2. Создаем холд (занимает виртуальное место)
        $this->withHeaders(['Idempotency-Key' => 'uuid-1'])
            ->postJson("/api/slots/{$slot->id}/hold")
            ->assertStatus(201);

        // Проверяем, что мест 0
        $this->getJson('/api/slots/availability')
            ->assertJsonFragment(['remaining' => 0]);

        // 3. "Перематываем" на время жизни холдов + 1 минута для запаса
        $this->travel(Hold::EXPIRES_IN_MINUTES + 1)->minutes();

        // 4. Запускаем команду очистки
        $this->artisan(ClearExpiredHolds::class); // 'holds:clear-expired'

        // 5. Проверяем, что место снова доступно (remaining: 1)
        $this->getJson('/api/slots/availability')
            ->assertJsonFragment(['remaining' => 1]);
            
        // 6. Проверяем, что статус холда в базе сменился на cancelled
        $this->assertDatabaseHas('holds', [
            'idempotency_key' => 'uuid-1',
            'status' => 'cancelled'
        ]);
    }

    #[Test]
    public function it_synchronizes_redis_atomically_after_hold_creation()
    {
        $remaining = 10;
        $slot = Slot::create(['capacity' => 10, 'remaining' => $remaining]);
        
        // 1. Создаем холд
        $this->withHeaders(['Idempotency-Key' => 'test-sync'])
            ->postJson("/api/slots/{$slot->id}/hold");

        // 2. Проверяем, что в Redis (БД 2) данные обновились мгновенно
        $remainingInRedis = Redis::zscore(SlotService::ZSET_KEY, $slot->id);
        
        // Должно быть 9 (10 в базе - 1 активный холд)
        $this->assertEquals($remaining - 1, (int)$remainingInRedis);
    }

    #[Test]
    public function it_rebuilds_cache_from_db_if_redis_is_empty()
    {
        $remaining = 5;
        $slot = Slot::create(['capacity' => 5, 'remaining' => $remaining]);
        
        // 1. Полностью очищаем Redis БД №2 
        Redis::flushdb();
        $this->assertEquals(0, Redis::exists(SlotService::ZSET_KEY));

        // 2. Запрос к API инициирует warmUpCache()
        $response = $this->getJson('/api/slots/availability');

        // 3. Проверяем, что кеш самовосстановился
        $response->assertStatus(200);
        $this->assertEquals(1, Redis::exists(SlotService::ZSET_KEY)); 
        $this->assertEquals($remaining, (int)Redis::zscore(SlotService::ZSET_KEY, $slot->id));
    }

}
