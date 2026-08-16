<?php

use App\Http\Middleware\AttachRequestId;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->api(append: [
            AttachRequestId::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            $code = $exception->getMessage();

            if ($request->is('api/pdf/*') && preg_match('/^PDF_[A-Z0-9_]+$/', $code) === 1) {
                return response()->json([
                    'error' => [
                        'code' => $code,
                        'message' => $code,
                    ],
                ], $exception->getStatusCode());
            }

            return null;
        });
    })->create();
