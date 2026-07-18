<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('trading_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('pair', 20);
            $table->string('timeframe', 10);
            $table->string('direction', 10);
            $table->unsignedTinyInteger('confidence')->default(0);
            $table->decimal('current_price', 24, 10)->nullable();
            $table->json('analysis');
            $table->timestamps();
            $table->index(['pair', 'timeframe', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trading_analyses');
    }
};
