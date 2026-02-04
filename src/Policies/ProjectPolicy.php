<?php

namespace AmirhMoradi\CoolifyPermissions\Policies;

use AmirhMoradi\CoolifyPermissions\Services\PermissionService;
use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    /**
     * Determine whether the user can view any projects.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the project.
     */
    public function view(User $user, Project $project): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $user->canPerform('view', $project);
    }

    /**
     * Determine whether the user can create projects.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the project.
     */
    public function update(User $user, Project $project): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $user->canPerform('update', $project);
    }

    /**
     * Determine whether the user can delete the project.
     */
    public function delete(User $user, Project $project): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $user->canPerform('delete', $project);
    }

    /**
     * Determine whether the user can manage access to the project.
     */
    public function manageAccess(User $user, Project $project): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $user->canPerform('update', $project);
    }
}
