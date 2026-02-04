<?php

namespace AmirhMoradi\CoolifyPermissions\Policies;

use AmirhMoradi\CoolifyPermissions\Services\PermissionService;
use App\Models\Service;
use App\Models\User;

class ServicePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Service $service): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $user->canPerform('view', $service);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Service $service): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $user->canPerform('update', $service);
    }

    public function delete(User $user, Service $service): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $user->canPerform('delete', $service);
    }

    public function deploy(User $user, Service $service): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $user->canPerform('deploy', $service);
    }
}
