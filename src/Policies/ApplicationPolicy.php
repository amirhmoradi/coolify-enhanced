<?php

namespace AmirhMoradi\CoolifyPermissions\Policies;

use AmirhMoradi\CoolifyPermissions\Services\PermissionService;
use App\Models\Application;
use App\Models\User;

class ApplicationPolicy
{
    /**
     * Determine whether the user can view any applications.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the application.
     */
    public function view(User $user, Application $application): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $user->canPerform('view', $application);
    }

    /**
     * Determine whether the user can create applications.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the application.
     */
    public function update(User $user, Application $application): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $user->canPerform('update', $application);
    }

    /**
     * Determine whether the user can delete the application.
     */
    public function delete(User $user, Application $application): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $user->canPerform('delete', $application);
    }

    /**
     * Determine whether the user can deploy the application.
     */
    public function deploy(User $user, Application $application): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $user->canPerform('deploy', $application);
    }
}
