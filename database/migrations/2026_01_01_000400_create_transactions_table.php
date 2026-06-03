<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transazioni economiche (acquisti, ricariche, ...). `amount` in centesimi
 * (interi) per evitare problemi di arrotondamento dei float.
 */
class CreateTransactionsTable extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('version_id');
            $table->unsignedBigInteger('player_id')->nullable();
            $table->bigInteger('amount')->default(0); // centesimi
            $table->string('currency', 3)->default('EUR');
            $table->string('status', 32)->default('completed');
            $table->timestamp('occurred_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->foreign('version_id')->references('id')->on('versions')->cascadeOnDelete();
            $table->index(['version_id', 'occurred_at']);
            $table->index(['version_id', 'player_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
}
