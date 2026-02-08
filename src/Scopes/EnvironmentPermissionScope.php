<?php

namespace AmirhMoradi\CoolifyPermissions\Scopes;

use AmirhMoradi\CoolifyPermissions\Models\EnvironmentUser;
use AmirhMoradi\CoolifyPermissions\Services\PermissionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class EnvironmentPermissionScope implements Scope
{
    /**
     * Apply the scope to the given Eloquent query builder.
     *
     * Filters out environments where the authenticated user has an explicit
     * "none" override (view permission = false). Skipped for guests and
     * users with role bypass (owner/admin).
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

        // Load environment IDs where the user has an explicit record
        // with view=false (i.e. "none" access level).
        $blockedIds = EnvironmentUser::where('user_id', $user->id)
            ->get()
            ->filter(fn (EnvironmentUser $eu) => ! $eu->canView())
            ->pluck('environment_id')
            ->all();

        if (! empty($blockedIds)) {
            $builder->whereNotIn($model->getTable().'.id', $blockedIds);
        }
    }
}
