<?php

use App\Http\Controllers\Auth\TwitchAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// OAuth da Twitch. Ficam nas rotas web porque precisam da sessão (state e login).
Route::get('/auth/twitch/redirect', [TwitchAuthController::class, 'redirect'])->name('twitch.redirect');
Route::get('/auth/twitch/callback', [TwitchAuthController::class, 'callback'])->name('twitch.callback');
