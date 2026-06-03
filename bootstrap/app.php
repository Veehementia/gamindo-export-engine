<?php

require_once __DIR__ . '/../vendor/autoload.php';

(new Laravel\Lumen\Bootstrap\LoadEnvironmentVariables(
    dirname(__DIR__)
))->bootstrap();

date_default_timezone_set(env('APP_TIMEZONE', 'UTC'));

/*
|--------------------------------------------------------------------------
| Creazione dell'applicazione
|--------------------------------------------------------------------------
*/

$app = new Laravel\Lumen\Application(
    dirname(__DIR__)
);

// Abilita la facciata Facade (Cache::, Queue::, DB::, Storage::, ...).
$app->withFacades();

// Abilita Eloquent ORM (i nostri Model).
$app->withEloquent();

/*
|--------------------------------------------------------------------------
| Binding del container
|--------------------------------------------------------------------------
*/

$app->singleton(
    Illuminate\Contracts\Debug\ExceptionHandler::class,
    App\Exceptions\Handler::class
);

$app->singleton(
    Illuminate\Contracts\Console\Kernel::class,
    App\Console\Kernel::class
);

/*
|--------------------------------------------------------------------------
| File di configurazione
|--------------------------------------------------------------------------
| Lumen non carica i file di config automaticamente: li dichiariamo qui.
*/

$app->configure('app');
$app->configure('database');
$app->configure('cache');
$app->configure('queue');
$app->configure('filesystems');
$app->configure('export');

/*
|--------------------------------------------------------------------------
| Middleware
|--------------------------------------------------------------------------
*/

$app->routeMiddleware([
    'json' => App\Http\Middleware\ForceJsonResponse::class,
]);

/*
|--------------------------------------------------------------------------
| Service Provider
|--------------------------------------------------------------------------
*/

$app->register(App\Providers\AppServiceProvider::class);

// Filesystem: necessario alla facciata Storage (salvataggio/download degli export).
$app->register(Illuminate\Filesystem\FilesystemServiceProvider::class);

// Redis: registrato solo se il pacchetto è presente. Serve quando cache/coda
// usano il driver redis; con driver database/array/sync non è richiesto.
if (class_exists(Illuminate\Redis\RedisServiceProvider::class)) {
    $app->register(Illuminate\Redis\RedisServiceProvider::class);
}

/*
|--------------------------------------------------------------------------
| Rotte
|--------------------------------------------------------------------------
*/

$app->router->group([
    'namespace' => 'App\Http\Controllers',
    'middleware' => 'json',
], function ($router) {
    require __DIR__ . '/../routes/api.php';
});

return $app;
