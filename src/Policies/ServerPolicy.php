<?php

namespace AmirhMoradi\CoolifyPermissions\Policies;

use AmirhMoradi\CoolifyPermissions\Services\PermissionService;
use App\Models\Server;
use App\Models\User;

class ServerPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Server $server): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        // Servers are team-level resources, check role bypass
        return PermissionService::hasRoleBypass($user);
    }

    public function create(User $user): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::hasRoleBypass($user);
    }

    public function update(User $user, Server $server): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::hasRoleBypass($user);
    }

    public function delete(User $user, Server $server): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::hasRoleBypass($user);
    }
}
