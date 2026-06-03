<?php

/** @var \Laravel\Lumen\Routing\Router $router */

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
| Il gruppo è già dentro prefix/namespace/middleware definiti in bootstrap/app.php.
*/

$router->group(['prefix' => 'api/v1'], function () use ($router) {

    // Healthcheck (usato da Docker / load balancer).
    $router->get('health', function () {
        return response()->json(['status' => 'ok', 'time' => date('c')]);
    });

    // --- Versions ---------------------------------------------------------
    $router->get('versions', 'VersionController@index');
    $router->post('versions', 'VersionController@store');
    $router->get('versions/{versionId}', 'VersionController@show');

    // --- Ingestione massiva ----------------------------------------------
    $router->post('versions/{versionId}/players', 'IngestionController@players');
    $router->post('versions/{versionId}/events', 'IngestionController@events');
    $router->post('versions/{versionId}/transactions', 'IngestionController@transactions');
    $router->post('versions/{versionId}/answers', 'IngestionController@answers');
    $router->post('versions/{versionId}/rewards', 'IngestionController@rewards');

    // --- Export -----------------------------------------------------------
    $router->post('versions/{versionId}/exports', 'ExportController@store');
    $router->post('versions/{versionId}/exports/preview', 'ExportController@preview');
    $router->get('exports/{uuid}', 'ExportController@show');
    $router->get('exports/{uuid}/download', 'ExportController@download');
    $router->post('exports/{uuid}/cancel', 'ExportController@cancel');
    $router->post('exports/{uuid}/retry', 'ExportController@retry');

    // --- Template di export (bonus) --------------------------------------
    $router->get('versions/{versionId}/export-templates', 'TemplateController@index');
    $router->post('versions/{versionId}/export-templates', 'TemplateController@store');
    $router->get('export-templates/{templateId}', 'TemplateController@show');
    $router->delete('export-templates/{templateId}', 'TemplateController@destroy');
});
