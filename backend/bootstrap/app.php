<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordNotFoundException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {

        // Кастомизация ответа при неверном URL (404)
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                // и не существующем ресурсе
                if ($e->getPrevious() instanceof ModelNotFoundException) {
                    return response()->json([
                        'error' => 'Resource not found',
                        'message' => 'The requested record (ID) does not exist in our database.'
                    ], 404);
                }

                return response()->json([
                    'error' => 'Endpoint not found',
                    'message' => 'Check your URL and HTTP method.'
                ], 404);
            }
        });
    })->create();
