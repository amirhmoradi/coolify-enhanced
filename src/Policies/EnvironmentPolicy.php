<?php

namespace AmirhMoradi\CoolifyPermissions\Policies;

use AmirhMoradi\CoolifyPermissions\Services\PermissionService;
use App\Models\Environment;
use App\Models\User;

class EnvironmentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Environment $environment): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $user->canPerform('view', $environment);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Environment $environment): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $user->canPerform('update', $environment);
    }

    public function delete(User $user, Environment $environment): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $user->canPerform('delete', $environment);
    }
}
