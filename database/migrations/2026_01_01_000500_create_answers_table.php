<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Risposte a quiz/sondaggi. `is_correct` denormalizzato per aggregazioni rapide.
 */
class CreateAnswersTable extends Migration
{
    public function up(): void
    {
        Schema::create('answers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('version_id');
            $table->unsignedBigInteger('player_id')->nullable();
            $table->string('question_id', 64);
            $table->text('answer')->nullable();
            $table->boolean('is_correct')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->foreign('version_id')->references('id')->on('versions')->cascadeOnDelete();
            $table->index(['version_id', 'question_id']);
            $table->index(['version_id', 'player_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('answers');
    }
}
