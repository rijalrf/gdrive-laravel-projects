<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\GoogleDriveController;

// Service Status/Health Check
Route::get('/', [GoogleDriveController::class, 'index'])->name('status');

// Service API Endpoints
Route::prefix('api')->group(function () {
    Route::post('/upload', [GoogleDriveController::class, 'upload'])->name('api.upload');
    Route::get('/preview', [GoogleDriveController::class, 'preview'])->name('api.preview');
    Route::post('/backup', [GoogleDriveController::class, 'backup'])->name('api.backup');
});

