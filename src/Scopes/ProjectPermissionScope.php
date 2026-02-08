<?php

namespace AmirhMoradi\CoolifyPermissions\Scopes;

use AmirhMoradi\CoolifyPermissions\Models\ProjectUser;
use AmirhMoradi\CoolifyPermissions\Services\PermissionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class ProjectPermissionScope implements Scope
{
    /**
     * Apply the scope to the given Eloquent query builder.
     *
     * Filters projects to only those where the authenticated user has been
     * explicitly granted view access. Skipped for guests and users with
     * role bypass (owner/admin).
     */
    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();

        if (! $user) {
            return;
        }

        if (! PermissionService::isEnabled()) {
            return;
        }

        if (PermissionService::hasRoleBypass($user)) {
            return;
        }

        // Only show projects where the user has an explicit record with view=true.
        // No record = no access (project hidden).
        $allowedIds = ProjectUser::where('user_id', $user->id)
            ->get()
            ->filter(fn (ProjectUser $pu) => $pu->canView())
            ->pluck('project_id')
            ->all();

        $builder->whereIn($model->getTable().'.id', $allowedIds);
    }
}
