<?php

namespace App\Providers;

use App\Services\Export\ExportDefinitionValidator;
use App\Services\Export\ExportEngine;
use App\Services\IngestionService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register()
    {
        // I servizi principali sono singleton: niente stato condiviso mutabile,
        // quindi è sicuro riusarne l'istanza nel ciclo richiesta/job.
        $this->app->singleton(IngestionService::class);
        $this->app->singleton(ExportDefinitionValidator::class);
        $this->app->singleton(ExportEngine::class);
    }

    public function boot()
    {
        //
    }
}
