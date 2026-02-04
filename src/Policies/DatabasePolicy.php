<?php

namespace AmirhMoradi\CoolifyPermissions\Policies;

use AmirhMoradi\CoolifyPermissions\Services\PermissionService;
use App\Models\User;

/**
 * Generic policy for all database types.
 * Works with: StandalonePostgresql, StandaloneMysql, StandaloneMariadb,
 * StandaloneMongodb, StandaloneRedis, StandaloneKeydb, StandaloneDragonfly, StandaloneClickhouse
 */
class DatabasePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, $database): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $user->canPerform('view', $database);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, $database): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $user->canPerform('update', $database);
    }

    public function delete(User $user, $database): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $user->canPerform('delete', $database);
    }

    public function deploy(User $user, $database): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $user->canPerform('deploy', $database);
    }
}
