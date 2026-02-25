<?php

use AmirhMoradi\CoolifyEnhanced\Http\Controllers\Api\ClusterController;
use AmirhMoradi\CoolifyEnhanced\Http\Controllers\Api\CustomTemplateSourceController;
use AmirhMoradi\CoolifyEnhanced\Http\Controllers\Api\NetworkController;
use AmirhMoradi\CoolifyEnhanced\Http\Controllers\Api\PermissionsController;
use AmirhMoradi\CoolifyEnhanced\Http\Controllers\Api\ResourceBackupController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Coolify Enhanced API Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', \App\Http\Middleware\ApiAllowed::class, 'api.sensitive'])->prefix('v1')->group(function () {
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

    // Custom template source management
    Route::get('/template-sources', [CustomTemplateSourceController::class, 'index'])
        ->middleware('api.ability:read');

    Route::post('/template-sources', [CustomTemplateSourceController::class, 'store'])
        ->middleware('api.ability:write');

    Route::get('/template-sources/{uuid}', [CustomTemplateSourceController::class, 'show'])
        ->middleware('api.ability:read');

    Route::patch('/template-sources/{uuid}', [CustomTemplateSourceController::class, 'update'])
        ->middleware('api.ability:write');

    Route::delete('/template-sources/{uuid}', [CustomTemplateSourceController::class, 'destroy'])
        ->middleware('api.ability:write');

    Route::post('/template-sources/{uuid}/sync', [CustomTemplateSourceController::class, 'sync'])
        ->middleware('api.ability:write');

    Route::post('/template-sources/sync-all', [CustomTemplateSourceController::class, 'syncAll'])
        ->middleware('api.ability:write');

    // Network management
    Route::get('/servers/{uuid}/networks', [NetworkController::class, 'index'])
        ->middleware('api.ability:read');

    Route::post('/servers/{uuid}/networks', [NetworkController::class, 'store'])
        ->middleware('api.ability:write');

    Route::get('/servers/{uuid}/networks/{network_uuid}', [NetworkController::class, 'show'])
        ->middleware('api.ability:read');

    Route::delete('/servers/{uuid}/networks/{network_uuid}', [NetworkController::class, 'destroy'])
        ->middleware('api.ability:write');

    Route::post('/servers/{uuid}/networks/sync', [NetworkController::class, 'sync'])
        ->middleware('api.ability:write');

    Route::post('/servers/{uuid}/networks/migrate-proxy', [NetworkController::class, 'migrateProxy'])
        ->middleware('api.ability:write');

    Route::post('/servers/{uuid}/networks/cleanup-proxy', [NetworkController::class, 'cleanupProxy'])
        ->middleware('api.ability:write');

    Route::get('/resources/{type}/{uuid}/networks', [NetworkController::class, 'resourceNetworks'])
        ->middleware('api.ability:read');

    Route::post('/resources/{type}/{uuid}/networks', [NetworkController::class, 'attachResource'])
        ->middleware('api.ability:write');

    Route::delete('/resources/{type}/{uuid}/networks/{network_uuid}', [NetworkController::class, 'detachResource'])
        ->middleware('api.ability:write');

    // Cluster management
    Route::get('/clusters', [ClusterController::class, 'index'])
        ->middleware('api.ability:read');
    Route::post('/clusters', [ClusterController::class, 'create'])
        ->middleware('api.ability:write');
    Route::get('/clusters/{uuid}', [ClusterController::class, 'show'])
        ->middleware('api.ability:read');
    Route::patch('/clusters/{uuid}', [ClusterController::class, 'update'])
        ->middleware('api.ability:write');
    Route::delete('/clusters/{uuid}', [ClusterController::class, 'destroy'])
        ->middleware('api.ability:write');
    Route::post('/clusters/{uuid}/sync', [ClusterController::class, 'sync'])
        ->middleware('api.ability:write');
    Route::get('/clusters/{uuid}/nodes', [ClusterController::class, 'nodes'])
        ->middleware('api.ability:read');
    Route::post('/clusters/{uuid}/nodes/{nodeId}/action', [ClusterController::class, 'nodeAction'])
        ->middleware('api.ability:write');
    Route::delete('/clusters/{uuid}/nodes/{nodeId}', [ClusterController::class, 'removeNode'])
        ->middleware('api.ability:write');
    Route::get('/clusters/{uuid}/services', [ClusterController::class, 'services'])
        ->middleware('api.ability:read');
    Route::get('/clusters/{uuid}/services/{serviceId}/tasks', [ClusterController::class, 'serviceTasks'])
        ->middleware('api.ability:read');
    Route::post('/clusters/{uuid}/services/{serviceId}/scale', [ClusterController::class, 'scaleService'])
        ->middleware('api.ability:write');
    Route::post('/clusters/{uuid}/services/{serviceId}/rollback', [ClusterController::class, 'rollbackService'])
        ->middleware('api.ability:write');
    Route::post('/clusters/{uuid}/services/{serviceId}/force-update', [ClusterController::class, 'forceUpdateService'])
        ->middleware('api.ability:write');
    Route::get('/clusters/{uuid}/events', [ClusterController::class, 'events'])
        ->middleware('api.ability:read');
    Route::get('/clusters/{uuid}/visualizer', [ClusterController::class, 'visualizer'])
        ->middleware('api.ability:read');
    Route::get('/clusters/{uuid}/secrets', [ClusterController::class, 'secrets'])
        ->middleware('api.ability:read');
    Route::post('/clusters/{uuid}/secrets', [ClusterController::class, 'createSecret'])
        ->middleware('api.ability:write');
    Route::delete('/clusters/{uuid}/secrets/{secretId}', [ClusterController::class, 'removeSecret'])
        ->middleware('api.ability:write');
    Route::get('/clusters/{uuid}/configs', [ClusterController::class, 'configs'])
        ->middleware('api.ability:read');
    Route::post('/clusters/{uuid}/configs', [ClusterController::class, 'createConfig'])
        ->middleware('api.ability:write');
    Route::delete('/clusters/{uuid}/configs/{configId}', [ClusterController::class, 'removeConfig'])
        ->middleware('api.ability:write');
});
