<?php

use CorelixIo\Platform\Livewire\AppearanceSettings;
use CorelixIo\Platform\Livewire\CustomTemplateSources;
use CorelixIo\Platform\Livewire\NetworkManager;
use CorelixIo\Platform\Livewire\NetworkSettings;
use CorelixIo\Platform\Livewire\ResourceBackupPage;
use CorelixIo\Platform\Livewire\RestoreBackup;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Corelix Platform Web Routes
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

    // Settings: Custom Template Sources page
    Route::get('settings/custom-templates', CustomTemplateSources::class)
        ->name('settings.custom-templates');

    // Server: Networks management page
    Route::get(
        'server/{server_uuid}/networks',
        \CorelixIo\Platform\Livewire\NetworkManagerPage::class
    )->name('server.networks');

    // Resource: Networks sub-page (rendered inside Configuration via overlay)
    Route::get(
        'project/{project_uuid}/environment/{environment_uuid}/application/{application_uuid}/networks',
        \App\Livewire\Project\Application\Configuration::class
    )->name('project.application.networks');

    Route::get(
        'project/{project_uuid}/environment/{environment_uuid}/database/{database_uuid}/networks',
        \App\Livewire\Project\Database\Configuration::class
    )->name('project.database.networks');

    Route::get(
        'project/{project_uuid}/environment/{environment_uuid}/service/{service_uuid}/networks',
        \App\Livewire\Project\Service\Configuration::class
    )->name('project.service.networks');

    // Settings: Network management page
    Route::get('settings/networks', NetworkSettings::class)
        ->name('settings.networks');

    // Settings: Appearance (enhanced UI theme)
    Route::get('settings/appearance', AppearanceSettings::class)
        ->name('settings.appearance');



});
