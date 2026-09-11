<?php

use App\Http\Controllers\FormController;
use Illuminate\Support\Facades\Route;

// Control plane (APP_ROLE=api): API-key auth, forms, drafts, publish, submissions, export.

Route::prefix('v1')->middleware('api-key')->group(function () {
    Route::post('forms', [FormController::class, 'store']);
    Route::get('forms', [FormController::class, 'index']);
    Route::get('forms/{form}', [FormController::class, 'show'])->whereUuid('form');
    Route::put('forms/{form}/draft', [FormController::class, 'updateDraft'])->whereUuid('form');
    Route::post('forms/{form}/publish', [FormController::class, 'publish'])->whereUuid('form');
    Route::get('forms/{form}/versions', [FormController::class, 'versions'])->whereUuid('form');
});
