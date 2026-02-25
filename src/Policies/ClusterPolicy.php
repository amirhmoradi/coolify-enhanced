<?php

namespace AmirhMoradi\CoolifyEnhanced\Policies;

use AmirhMoradi\CoolifyEnhanced\Models\Cluster;
use AmirhMoradi\CoolifyEnhanced\Services\PermissionService;
use App\Models\User;

class ClusterPolicy
{
    /**
     * Any authenticated team member can view the cluster list.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Team members can view clusters belonging to their team.
     */
    public function view(User $user, Cluster $cluster): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return $this->belongsToUserTeam($user, $cluster);
    }

    /**
     * Only team owners and admins can create clusters.
     */
    public function create(User $user): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        return PermissionService::hasRoleBypass($user);
    }

    /**
     * Only team owners and admins can update clusters.
     */
    public function update(User $user, Cluster $cluster): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        if (! $this->belongsToUserTeam($user, $cluster)) {
            return false;
        }

        return PermissionService::hasRoleBypass($user);
    }

    /**
     * Only team owners and admins can delete clusters.
     */
    public function delete(User $user, Cluster $cluster): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        if (! $this->belongsToUserTeam($user, $cluster)) {
            return false;
        }

        return PermissionService::hasRoleBypass($user);
    }

    /**
     * Only team owners and admins can manage nodes (drain, promote, demote, remove).
     */
    public function manageNodes(User $user, Cluster $cluster): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        if (! $this->belongsToUserTeam($user, $cluster)) {
            return false;
        }

        return PermissionService::hasRoleBypass($user);
    }

    /**
     * Only team owners and admins can manage services (scale, rollback, force-update).
     */
    public function manageServices(User $user, Cluster $cluster): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        if (! $this->belongsToUserTeam($user, $cluster)) {
            return false;
        }

        return PermissionService::hasRoleBypass($user);
    }

    /**
     * Only team owners and admins can manage secrets.
     */
    public function manageSecrets(User $user, Cluster $cluster): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        if (! $this->belongsToUserTeam($user, $cluster)) {
            return false;
        }

        return PermissionService::hasRoleBypass($user);
    }

    /**
     * Only team owners and admins can manage configs.
     */
    public function manageConfigs(User $user, Cluster $cluster): bool
    {
        if (! PermissionService::isEnabled()) {
            return true;
        }

        if (! $this->belongsToUserTeam($user, $cluster)) {
            return false;
        }

        return PermissionService::hasRoleBypass($user);
    }

    private function belongsToUserTeam(User $user, Cluster $cluster): bool
    {
        $currentTeam = $user->currentTeam();

        return $currentTeam && $currentTeam->id === $cluster->team_id;
    }
}
