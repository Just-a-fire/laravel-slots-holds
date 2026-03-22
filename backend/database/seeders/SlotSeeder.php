<?php

namespace Database\Seeders;

use App\Models\Slot;
use App\Services\SlotService;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SlotSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $data = [
            ['capacity' => 10, 'remaining' => 6],
            ['capacity' => 5, 'remaining' => 5],
            ['capacity' => 1, 'remaining' => 1],
        ];
        foreach ($data as $datum) {
            $slot = Slot::create($datum);
            app(SlotService::class)->syncSlotToRedis($slot->id);
        }
    }
}
