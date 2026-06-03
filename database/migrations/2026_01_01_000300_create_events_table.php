<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabella più grande del sistema (target: 10M+ righe).
 *
 * Scelte chiave:
 *  - `payload` JSON libero: ogni tipo di evento porta campi diversi
 *    (score, level, language, utm_source, custom_field_1, ...).
 *  - `player_id` è un bigint indicizzato MA senza foreign key: l'ingestione
 *    di eventi deve poter arrivare anche prima/indipendentemente dall'anagrafica
 *    player, ed evitare il costo del controllo FK su inserimenti massivi.
 *  - Indice composito (version_id, type, occurred_at): copre il pattern di query
 *    dominante degli export (filtra per version+tipo, ordina/filtra per data).
 */
class CreateEventsTable extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('version_id');
            $table->unsignedBigInteger('player_id')->nullable();
            $table->string('type', 64);
            $table->timestamp('occurred_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->foreign('version_id')->references('id')->on('versions')->cascadeOnDelete();
            $table->index(['version_id', 'type', 'occurred_at'], 'events_version_type_time_idx');
            $table->index(['version_id', 'player_id'], 'events_version_player_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
}
