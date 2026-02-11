<?php

use AmirhMoradi\CoolifyEnhanced\Livewire\ResourceBackupPage;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Coolify Enhanced Web Routes
|--------------------------------------------------------------------------
|
| These routes add "Resource Backups" pages to Coolify's existing
| resource detail pages (Application, Service, Database).
|
| The permissions UI is injected via the InjectPermissionsUI middleware
| into Coolify's /team/admin page. API routes are in routes/api.php.
|
*/

Route::middleware(['auth'])->group(function () {
    // Application resource backups
    Route::get(
        'project/{project_uuid}/environment/{environment_uuid}/application/{application_uuid}/resource-backups',
        ResourceBackupPage::class
    )->name('project.application.resource-backups');

    // Database resource backups
    Route::get(
        'project/{project_uuid}/environment/{environment_uuid}/database/{database_uuid}/resource-backups',
        ResourceBackupPage::class
    )->name('project.database.resource-backups');

    // Service resource backups
    Route::get(
        'project/{project_uuid}/environment/{environment_uuid}/service/{service_uuid}/resource-backups',
        ResourceBackupPage::class
    )->name('project.service.resource-backups');
});
