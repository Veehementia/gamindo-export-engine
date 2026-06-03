<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una "version" è l'unità di aggregazione di tutto il sistema: rappresenta una
 * release di gioco / campagna. Ogni player, evento, transazione, ecc. appartiene
 * a una version, ed è il perno di tutte le query di export.
 */
class CreateVersionsTable extends Migration
{
    public function up(): void
    {
        Schema::create('versions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('game', 191)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('game');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('versions');
    }
}
