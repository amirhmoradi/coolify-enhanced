<?php

use AmirhMoradi\CoolifyEnhanced\Livewire\ResourceBackupPage;
use AmirhMoradi\CoolifyEnhanced\Livewire\RestoreBackup;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Coolify Enhanced Web Routes
|--------------------------------------------------------------------------
|
| These routes add "Resource Backups" sub-pages to Coolify's existing
| resource configuration pages and server pages.
|
| Application/Database/Service routes point to the same Livewire component
| Coolify uses for their configuration pages — the overlay view files
| handle rendering the backup manager when $currentRoute matches.
|
| The server route uses our own ResourceBackupPage component.
|
*/

Route::middleware(['web', 'auth', 'verified'])->group(function () {
    // Application resource backups (rendered inside Configuration component via overlay)
    Route::get(
        'project/{project_uuid}/environment/{environment_uuid}/application/{application_uuid}/resource-backups',
        \App\Livewire\Project\Application\Configuration::class
    )->name('project.application.resource-backups');

    // Database resource backups (rendered inside Configuration component via overlay)
    Route::get(
        'project/{project_uuid}/environment/{environment_uuid}/database/{database_uuid}/resource-backups',
        \App\Livewire\Project\Database\Configuration::class
    )->name('project.database.resource-backups');

    // Service resource backups (rendered inside Configuration component via overlay)
    Route::get(
        'project/{project_uuid}/environment/{environment_uuid}/service/{service_uuid}/resource-backups',
        \App\Livewire\Project\Service\Configuration::class
    )->name('project.service.resource-backups');

    // Server resource backups (uses our own full-page component)
    Route::get(
        'server/{server_uuid}/resource-backups',
        ResourceBackupPage::class
    )->name('server.resource-backups');

    // Settings: Restore/Import Backups page
    Route::get('settings/restore-backup', RestoreBackup::class)
        ->name('settings.restore-backup');
});
