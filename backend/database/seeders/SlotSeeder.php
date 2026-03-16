<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

use App\Models\Slot;

class SlotSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Slot::create(['capacity' => 10, 'remaining' => 6]);
        Slot::create(['capacity' => 5, 'remaining' => 5]);
        Slot::create(['capacity' => 1, 'remaining' => 1]);
    }
}
