<?php

use AmirhMoradi\CoolifyEnhanced\Http\Controllers\Api\PermissionsController;
use AmirhMoradi\CoolifyEnhanced\Http\Controllers\Api\ResourceBackupController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Coolify Enhanced API Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'api.sensitive'])->prefix('v1')->group(function () {
    // Project access management
    Route::get('/projects/{uuid}/access', [PermissionsController::class, 'listProjectAccess'])
        ->middleware('api.ability:read');

    Route::post('/projects/{uuid}/access', [PermissionsController::class, 'grantProjectAccess'])
        ->middleware('api.ability:write');

    Route::patch('/projects/{uuid}/access/{user_id}', [PermissionsController::class, 'updateProjectAccess'])
        ->middleware('api.ability:write');

    Route::delete('/projects/{uuid}/access/{user_id}', [PermissionsController::class, 'revokeProjectAccess'])
        ->middleware('api.ability:write');

    Route::get('/projects/{uuid}/access/{user_id}/check', [PermissionsController::class, 'checkPermission'])
        ->middleware('api.ability:read');

    // Resource backup management
    Route::get('/resource-backups', [ResourceBackupController::class, 'index'])
        ->middleware('api.ability:read');

    Route::post('/resource-backups', [ResourceBackupController::class, 'store'])
        ->middleware('api.ability:write');

    Route::get('/resource-backups/{uuid}', [ResourceBackupController::class, 'show'])
        ->middleware('api.ability:read');

    Route::post('/resource-backups/{uuid}/trigger', [ResourceBackupController::class, 'trigger'])
        ->middleware('api.ability:write');

    Route::delete('/resource-backups/{uuid}', [ResourceBackupController::class, 'destroy'])
        ->middleware('api.ability:write');
});
