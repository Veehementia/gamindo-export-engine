<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Lumen\Exceptions\Handler as ExceptionHandler;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Mappa le eccezioni su risposte JSON coerenti, con gli status HTTP corretti:
 *   422 validazione, 404 risorsa inesistente, 4xx/5xx il resto.
 */
class Handler extends ExceptionHandler
{
    protected $dontReport = [
        AuthorizationException::class,
        ValidationException::class,
        ModelNotFoundException::class,
    ];

    public function report(Throwable $e)
    {
        parent::report($e);
    }

    public function render($request, Throwable $e)
    {
        if ($e instanceof ValidationException) {
            return new JsonResponse([
                'message' => 'I dati inviati non sono validi.',
                'errors' => $e->errors(),
            ], 422);
        }

        if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
            return new JsonResponse(['message' => 'Risorsa non trovata.'], 404);
        }

        $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

        $payload = ['message' => $e->getMessage() ?: 'Errore interno del server.'];
        if (config('app.debug')) {
            $payload['exception'] = get_class($e);
            $payload['trace'] = collect($e->getTrace())->take(5)->all();
        }

        return new JsonResponse($payload, $status);
    }
}
