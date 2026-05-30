<?php

use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

$frontendIndex = public_path('app/index.html');

Route::get('/', function () use ($frontendIndex) {
    if (File::exists($frontendIndex)) {
        return response(File::get($frontendIndex), Response::HTTP_OK)
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    return view('welcome');
});

Route::fallback(function () use ($frontendIndex) {
    if (! File::exists($frontendIndex) || request()->is('app/*')) {
        abort(Response::HTTP_NOT_FOUND);
    }

    return response(File::get($frontendIndex), Response::HTTP_OK)
        ->header('Content-Type', 'text/html; charset=UTF-8');
});
