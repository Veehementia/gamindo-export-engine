<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Premi assegnati ai player (badge, coupon, punti, ...).
 */
class CreateRewardsTable extends Migration
{
    public function up(): void
    {
        Schema::create('rewards', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('version_id');
            $table->unsignedBigInteger('player_id')->nullable();
            $table->string('type', 64);
            $table->string('value', 191)->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->foreign('version_id')->references('id')->on('versions')->cascadeOnDelete();
            $table->index(['version_id', 'type']);
            $table->index(['version_id', 'player_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rewards');
    }
}
