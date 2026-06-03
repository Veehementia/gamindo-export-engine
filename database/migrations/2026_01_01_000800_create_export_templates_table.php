<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (Bonus) Template di export riutilizzabili: si salva una `definition` e la si
 * richiama per nome quando si crea un export, senza re-inviare tutto il JSON.
 */
class CreateExportTemplatesTable extends Migration
{
    public function up(): void
    {
        Schema::create('export_templates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('version_id');
            $table->string('name', 191);
            $table->string('description')->nullable();
            $table->json('definition');
            $table->timestamps();

            $table->foreign('version_id')->references('id')->on('versions')->cascadeOnDelete();
            $table->unique(['version_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_templates');
    }
}
