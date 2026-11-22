<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\GiftController;
use App\Http\Controllers\RecipientController;
use App\Http\Controllers\SharingController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->group(function () {
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
    });

    Route::post('/invitations/preview', [SharingController::class, 'preview'])->middleware('throttle:20,1');

    Route::middleware('auth')->group(function () {
        Route::apiResource('recipients', RecipientController::class);
        Route::get('/recipients/{recipient}/gifts', [GiftController::class, 'index']);
        Route::post('/recipients/{recipient}/gifts', [GiftController::class, 'store']);
        Route::apiResource('gifts', GiftController::class)->only(['show', 'update', 'destroy']);
        Route::get('/gifts/{gift}/photo', [GiftController::class, 'photo']);
        Route::put('/gifts/{gift}/photo', [GiftController::class, 'replacePhoto']);
        Route::delete('/gifts/{gift}/photo', [GiftController::class, 'deletePhoto']);
        Route::get('/recipients/{recipient}/members', [SharingController::class, 'members']);
        Route::delete('/recipients/{recipient}/members/{user}', [SharingController::class, 'remove']);
        Route::delete('/recipients/{recipient}/membership', [SharingController::class, 'leave']);
        Route::get('/recipients/{recipient}/invitation', [SharingController::class, 'current']);
        Route::post('/recipients/{recipient}/invitation', [SharingController::class, 'issue'])->middleware('throttle:20,1');
        Route::delete('/recipients/{recipient}/invitation', [SharingController::class, 'revoke']);
        Route::post('/invitations/accept', [SharingController::class, 'accept'])->middleware('throttle:20,1');
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::get('/', function () {
    $index = public_path('app/index.html');
    abort_unless(is_file($index), 503, 'フロントエンドをビルドしてください。');

    return response()->file($index, ['Content-Type' => 'text/html; charset=UTF-8']);
});
