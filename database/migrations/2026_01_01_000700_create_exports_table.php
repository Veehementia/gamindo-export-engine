<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stato di una richiesta di export. È la "macchina a stati" del flusso asincrono:
 *
 *   pending --> processing --> completed
 *                          \-> failed
 *                          \-> cancelled
 *
 *  - `uuid`        : id pubblico esposto nelle API (non esponiamo l'auto-increment).
 *  - `definition`  : il payload di export validato (fogli/colonne/filtri/...).
 *  - `progress`    : 0..100, aggiornato dal job per il progress percentage.
 *  - `rows_*`      : righe totali stimate e scritte, per calcolare la percentuale.
 *  - `cancel_requested` : flag soft-cancel, il job lo controlla periodicamente.
 */
class CreateExportsTable extends Migration
{
    public function up(): void
    {
        Schema::create('exports', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('version_id');
            $table->string('format', 16)->default('xlsx');
            $table->string('status', 16)->default('pending')->index();
            $table->json('definition');

            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedBigInteger('rows_estimated')->default(0);
            $table->unsignedBigInteger('rows_written')->default(0);

            $table->boolean('cancel_requested')->default(false);
            $table->unsignedInteger('attempts')->default(0);

            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->text('error')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('version_id')->references('id')->on('versions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exports');
    }
}
