<?php

use App\Http\Middleware\AttachRequestId;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
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
        $exceptions->respond(function (Response $response): Response {
            if (! request()->is('api/*') || ! $response instanceof JsonResponse || $response->getStatusCode() < 400) {
                return $response;
            }

            $data = $response->getData(true);
            if (! is_array($data)) {
                $data = [];
            }
            unset($data['exception'], $data['file'], $data['line'], $data['trace']);
            $pdfCode = is_array($data['error'] ?? null) ? ($data['error']['code'] ?? null) : null;
            if ($response->getStatusCode() >= 500 && (! is_string($pdfCode) || preg_match('/^PDF_[A-Z0-9_]+$/', $pdfCode) !== 1)) {
                $data = ['message' => '本次操作出现异常，请先确认操作结果；持续异常请联系管理员。'];
            } elseif ($response->getStatusCode() === 404 && str_contains($data['message'] ?? '', 'No query results for model')) {
                $data = ['message' => '未找到所需记录，请刷新后重试。'];
            }
            $response->setData($data);

            return $response;
        });
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
