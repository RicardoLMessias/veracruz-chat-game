<?php

use App\Http\Controllers\Auth\TwitchAuthController;
use App\Http\Controllers\ChannelStatusController;
use App\Http\Controllers\ChatOverviewController;
use App\Http\Controllers\CommunityController;
use App\Http\Controllers\EconomyAdminController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\SetupController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'app' => config('app.name'),
]));

// Configuração inicial (antes de existir login). Salvar fica limitado a 10 tentativas por minuto.
Route::get('/setup', [SetupController::class, 'status']);
Route::post('/setup/twitch', [SetupController::class, 'saveTwitch'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', MeController::class);
    Route::get('/channel/status', ChannelStatusController::class);
    Route::get('/chat/overview', ChatOverviewController::class);
    Route::get('/community', [CommunityController::class, 'index']);
    Route::get('/community/{twitchUserId}', [CommunityController::class, 'show'])->where('twitchUserId', '[0-9]+');
    Route::post('/auth/logout', [TwitchAuthController::class, 'logout']);

    // Gerenciar economia
    Route::get('/economy/summary', [EconomyAdminController::class, 'summary']);
    Route::get('/economy/settings', [EconomyAdminController::class, 'settings']);
    Route::put('/economy/settings', [EconomyAdminController::class, 'updateSettings']);
    Route::post('/economy/adjust', [EconomyAdminController::class, 'adjust']);
    Route::post('/economy/reset', [EconomyAdminController::class, 'reset'])->middleware('throttle:10,1');
});
