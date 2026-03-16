<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

use App\Models\Hold;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('slot_id')->constrained();
            $table->uuid('idempotency_key');
            $table->enum('status', [
                Hold::STATUS_HELD, 
                Hold::STATUS_CONFIRMED, 
                Hold::STATUS_CANCELLED
            ])->default(Hold::STATUS_HELD);
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('holds');
    }
};
