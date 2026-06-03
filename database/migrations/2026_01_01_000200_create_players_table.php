<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anagrafica giocatori (fino a ~1M per version). `external_id` è l'identificativo
 * lato client/gioco ed è unico nell'ambito della version (così l'ingestione può
 * fare upsert idempotenti). `total_score` è denormalizzato per export veloci.
 */
class CreatePlayersTable extends Migration
{
    public function up(): void
    {
        Schema::create('players', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('version_id');
            $table->string('external_id', 191);
            $table->string('email', 191)->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->unsignedBigInteger('total_score')->default(0);
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->foreign('version_id')->references('id')->on('versions')->cascadeOnDelete();
            // Idempotenza dell'ingestione: un external_id per version.
            $table->unique(['version_id', 'external_id']);
            // Filtri/sort tipici sugli export.
            $table->index(['version_id', 'registered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('players');
    }
}
