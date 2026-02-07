<?php

namespace AmirhMoradi\CoolifyPermissions\Livewire;

use AmirhMoradi\CoolifyPermissions\Models\EnvironmentUser;
use AmirhMoradi\CoolifyPermissions\Models\ProjectUser;
use AmirhMoradi\CoolifyPermissions\Services\PermissionService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class AccessMatrix extends Component
{
    public array $users = [];
    public array $projects = [];
    public array $permissions = [];
    public string $search = '';
    public string $bulkLevel = 'full_access';

    protected $listeners = ['refreshAccessMatrix' => 'loadMatrix'];

    public function mount(): void
    {
        $this->loadMatrix();
    }

    public function loadMatrix(): void
    {
        $team = auth()->user()->currentTeam();
        if (! $team) {
            return;
        }

        $bypassRoles = config('coolify-permissions.bypass_roles', ['owner', 'admin']);

        // Load team members via relationship (includes pivot with role)
        $this->users = $team->members->map(function ($user) use ($bypassRoles) {
            $role = $user->pivot->role ?? 'member';

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $role,
                'bypass' => in_array($role, $bypassRoles),
            ];
        })->sortBy('name')->values()->toArray();

        // Load projects with environments
        $this->projects = $team->projects()->with('environments')->get()->map(function ($project) {
            return [
                'id' => $project->id,
                'uuid' => $project->uuid,
                'name' => $project->name,
                'environments' => $project->environments->map(function ($env) {
                    return [
                        'id' => $env->id,
                        'name' => $env->name,
                    ];
                })->sortBy('name')->values()->toArray(),
            ];
        })->sortBy('name')->values()->toArray();

        // Load all project permissions in bulk
        $projectIds = collect($this->projects)->pluck('id')->toArray();
        $projectPerms = collect();
        if (! empty($projectIds)) {
            $projectPerms = DB::table('project_user')
                ->whereIn('project_id', $projectIds)
                ->get()
                ->groupBy('user_id');
        }

        // Load all environment permissions in bulk
        $envIds = collect($this->projects)->flatMap(function ($p) {
            return collect($p['environments'])->pluck('id');
        })->toArray();
        $envPerms = collect();
        if (! empty($envIds)) {
            $envPerms = DB::table('environment_user')
                ->whereIn('environment_id', $envIds)
                ->get()
                ->groupBy('user_id');
        }

        // Build permissions matrix
        $this->permissions = [];
        foreach ($this->users as $user) {
            $userId = $user['id'];
            $this->permissions[$userId] = [];

            foreach ($this->projects as $project) {
                $projectId = $project['id'];
                $perm = $projectPerms->get($userId)?->firstWhere('project_id', $projectId);
                $this->permissions[$userId]["p_{$projectId}"] = $perm
                    ? $this->resolveLevel(json_decode($perm->permissions, true))
                    : 'none';

                foreach ($project['environments'] as $env) {
                    $envId = $env['id'];
                    $ePerm = $envPerms->get($userId)?->firstWhere('environment_id', $envId);
                    // null means inherited from project
                    $this->permissions[$userId]["e_{$envId}"] = $ePerm
                        ? $this->resolveLevel(json_decode($ePerm->permissions, true))
                        : 'inherited';
                }
            }
        }
    }

    /**
     * Resolve a permission level string from a permissions array.
     */
    protected function resolveLevel(array $perms): string
    {
        $view = $perms['view'] ?? false;
        $deploy = $perms['deploy'] ?? false;
        $manage = $perms['manage'] ?? false;
        $delete = $perms['delete'] ?? false;

        if ($view && $deploy && $manage && $delete) {
            return 'full_access';
        }
        if ($view && $deploy) {
            return 'deploy';
        }
        if ($view) {
            return 'view_only';
        }

        return 'none';
    }

    /**
     * Update a project-level permission for a user.
     */
    public function updateProjectPermission(int $userId, int $projectId, string $level): void
    {
        $this->authorizeAdmin();

        $user = User::findOrFail($userId);
        $project = \App\Models\Project::findOrFail($projectId);

        if ($level === 'none') {
            PermissionService::revokeProjectAccess($user, $project);
        } else {
            PermissionService::grantProjectAccess($user, $project, $level);
        }

        $this->permissions[$userId]["p_{$projectId}"] = $level;
        $this->dispatch('permissionUpdated');
    }

    /**
     * Update an environment-level permission override for a user.
     *
     * "inherited" removes the override so the project level cascades down.
     * Any other level sets an explicit environment override.
     */
    public function updateEnvironmentPermission(int $userId, int $envId, string $level): void
    {
        $this->authorizeAdmin();

        $user = User::findOrFail($userId);
        $environment = \App\Models\Environment::findOrFail($envId);

        if ($level === 'inherited') {
            // Remove the override — permission will cascade from project
            PermissionService::revokeEnvironmentAccess($user, $environment);
        } else {
            PermissionService::grantEnvironmentAccess($user, $environment, $level);
        }

        $this->permissions[$userId]["e_{$envId}"] = $level;
        $this->dispatch('permissionUpdated');
    }

    /**
     * Set all project+environment permissions for a single user.
     */
    public function setAllForUser(int $userId, string $level): void
    {
        $this->authorizeAdmin();

        $user = User::findOrFail($userId);

        foreach ($this->projects as $project) {
            $proj = \App\Models\Project::find($project['id']);
            if (! $proj) {
                continue;
            }

            if ($level === 'none') {
                PermissionService::revokeProjectAccess($user, $proj);
            } else {
                PermissionService::grantProjectAccess($user, $proj, $level);
            }

            // Reset all environment overrides to inherited
            foreach ($project['environments'] as $env) {
                $environment = \App\Models\Environment::find($env['id']);
                if ($environment) {
                    PermissionService::revokeEnvironmentAccess($user, $environment);
                }
            }
        }

        $this->loadMatrix();
    }

    /**
     * Set a permission level for all users on a specific project.
     */
    public function setAllForProject(int $projectId, string $level): void
    {
        $this->authorizeAdmin();

        $project = \App\Models\Project::findOrFail($projectId);

        foreach ($this->users as $user) {
            if ($user['bypass']) {
                continue;
            }

            $userModel = User::find($user['id']);
            if (! $userModel) {
                continue;
            }

            if ($level === 'none') {
                PermissionService::revokeProjectAccess($userModel, $project);
            } else {
                PermissionService::grantProjectAccess($userModel, $project, $level);
            }
        }

        $this->loadMatrix();
    }

    /**
     * Set a permission level for all users on a specific environment.
     */
    public function setAllForEnvironment(int $envId, string $level): void
    {
        $this->authorizeAdmin();

        $environment = \App\Models\Environment::findOrFail($envId);

        foreach ($this->users as $user) {
            if ($user['bypass']) {
                continue;
            }

            $userModel = User::find($user['id']);
            if (! $userModel) {
                continue;
            }

            if ($level === 'inherited') {
                PermissionService::revokeEnvironmentAccess($userModel, $environment);
            } else {
                PermissionService::grantEnvironmentAccess($userModel, $environment, $level);
            }
        }

        $this->loadMatrix();
    }

    /**
     * Get the effective permission for an environment cell (resolves inheritance).
     */
    public function getEffectiveLevel(int $userId, int $envId, int $projectId): string
    {
        $envLevel = $this->permissions[$userId]["e_{$envId}"] ?? 'inherited';

        if ($envLevel !== 'inherited') {
            return $envLevel;
        }

        return $this->permissions[$userId]["p_{$projectId}"] ?? 'none';
    }

    /**
     * Get filtered users based on search.
     */
    public function getFilteredUsersProperty(): array
    {
        if (empty($this->search)) {
            return $this->users;
        }

        $search = strtolower($this->search);

        return array_values(array_filter($this->users, function ($user) use ($search) {
            return str_contains(strtolower($user['name']), $search)
                || str_contains(strtolower($user['email']), $search)
                || str_contains(strtolower($user['role']), $search);
        }));
    }

    /**
     * Verify the current user can manage permissions.
     */
    protected function authorizeAdmin(): void
    {
        $currentUser = auth()->user();
        if (! PermissionService::hasRoleBypass($currentUser)) {
            abort(403, 'Only team owners and admins can manage permissions.');
        }
    }

    public function render()
    {
        return view('coolify-permissions::livewire.access-matrix', [
            'filteredUsers' => $this->filteredUsers,
        ]);
    }
}
