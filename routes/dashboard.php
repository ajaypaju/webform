<?php

use App\Http\Controllers\Dashboard\BuilderController;
use App\Http\Controllers\Dashboard\LoginController;
use App\Http\Middleware\PublicHeaders;
use Illuminate\Support\Facades\Route;

// Dashboard (APP_ROLE=api): session-authenticated, server-rendered, CSRF on every mutation. Loaded under the `web`
// middleware group; the stateless /v1 API in routes/api.php never sees a session.

Route::middleware(PublicHeaders::class.":'none'")->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:login');
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::prefix('dashboard')->middleware('dashboard')->group(function () {
        Route::get('/', [BuilderController::class, 'index'])->name('dashboard.forms');
        Route::post('forms', [BuilderController::class, 'store'])->name('dashboard.forms.store');
        Route::get('forms/{form}', [BuilderController::class, 'show'])->whereUuid('form')->name('dashboard.forms.show');
        Route::put('forms/{form}/draft', [BuilderController::class, 'saveDraft'])->whereUuid('form');
        Route::post('forms/{form}/publish', [BuilderController::class, 'publish'])->whereUuid('form');
    });
});
