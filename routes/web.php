<?php

use AmirhMoradi\CoolifyPermissions\Livewire\Admin\Users as AdminUsers;
use AmirhMoradi\CoolifyPermissions\Livewire\Project\Access as ProjectAccess;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Coolify Granular Permissions Web Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['web', 'auth', 'verified'])->group(function () {
    // Admin users management (requires global admin or instance admin)
    Route::get('/admin/users', AdminUsers::class)
        ->name('coolify-permissions.admin.users');

    // Project access management
    Route::get('/project/{project_uuid}/access', ProjectAccess::class)
        ->name('coolify-permissions.project.access')
        ->middleware('can.update.resource');
});
