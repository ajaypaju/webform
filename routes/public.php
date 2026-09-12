<?php

use App\Http\Controllers\Public\FormPageController;
use App\Http\Controllers\Public\SubmissionController;
use App\Http\Controllers\Public\VersionController;
use Illuminate\Support\Facades\Route;

// Public data plane (APP_ROLE=ingest): form page, definition JSON, submission intake. No auth: everything here is
// published, and forms are identified by unguessable UUIDs.

Route::prefix('v1')->whereUuid(['form', 'version'])->group(function () {
    Route::get('forms/{form}/versions/{version}', [VersionController::class, 'show']);
    Route::get('forms/{form}', [VersionController::class, 'current']);
    Route::post('forms/{form}/submissions', [SubmissionController::class, 'store']);
});

Route::get('f/{form}', FormPageController::class)->whereUuid('form');
