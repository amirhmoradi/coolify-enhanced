<?php

namespace AmirhMoradi\CoolifyPermissions\Livewire\Admin;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Users extends Component
{
    use AuthorizesRequests;

    public $users = [];

    public $teams = [];

    #[Validate('required|string|min:3|max:255')]
    public $name = '';

    #[Validate('required|email|unique:users,email')]
    public $email = '';

    #[Validate('required|string|min:8')]
    public $password = '';

    public $selectedUserId = '';

    public $assignTeamId = '';

    public $assignRole = 'member';

    public function mount()
    {
        $this->checkAccess();
        $this->loadData();
    }

    protected function checkAccess()
    {
        $user = auth()->user();

        // Check if user is global admin or instance admin
        if (! $user->is_global_admin && ! $user->isInstanceAdmin()) {
            abort(403, 'You do not have permission to access this page.');
        }
    }

    public function loadData()
    {
        $this->users = User::with('teams')->get()->map(fn ($user) => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_global_admin' => $user->is_global_admin ?? false,
            'status' => $user->status ?? 'active',
            'teams' => $user->teams->map(fn ($team) => [
                'id' => $team->id,
                'name' => $team->name,
                'role' => $team->pivot->role,
            ])->toArray(),
        ])->toArray();

        $this->teams = Team::select('id', 'name')->get()->toArray();
    }

    public function createUser()
    {
        try {
            $this->validate([
                'name' => 'required|string|min:3|max:255',
                'email' => 'required|email|unique:users,email',
                'password' => 'required|string|min:8',
            ]);

            User::create([
                'name' => $this->name,
                'email' => $this->email,
                'password' => bcrypt($this->password),
            ]);

            $this->reset(['name', 'email', 'password']);
            $this->loadData();

            $this->dispatch('success', 'User created successfully.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function assignUserToTeam()
    {
        try {
            $this->validate([
                'selectedUserId' => 'required|exists:users,id',
                'assignTeamId' => 'required|exists:teams,id',
                'assignRole' => 'required|in:owner,admin,member,viewer',
            ]);

            $user = User::findOrFail($this->selectedUserId);
            $team = Team::findOrFail($this->assignTeamId);

            // Check if already a member
            if ($user->teams()->where('teams.id', $team->id)->exists()) {
                throw new \Exception('User is already a member of this team.');
            }

            $role = $this->assignRole;
            $user->teams()->attach($team->id, ['role' => $role]);

            $this->reset(['selectedUserId', 'assignTeamId', 'assignRole']);
            $this->loadData();

            $this->dispatch('success', "User assigned to {$team->name} as {$role}");
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function toggleGlobalAdmin(int $userId)
    {
        try {
            $user = User::findOrFail($userId);

            // Prevent removing your own global admin
            if ($user->id === auth()->id() && $user->is_global_admin) {
                throw new \Exception('You cannot remove your own global admin status.');
            }

            $user->is_global_admin = ! $user->is_global_admin;
            $user->save();

            $this->loadData();

            $status = $user->is_global_admin ? 'granted' : 'revoked';
            $this->dispatch('success', "Global admin {$status} for {$user->name}");
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function suspendUser(int $userId)
    {
        try {
            $user = User::findOrFail($userId);

            if ($user->id === auth()->id()) {
                throw new \Exception('You cannot suspend yourself.');
            }

            $user->status = 'suspended';
            $user->save();

            $this->loadData();

            $this->dispatch('success', "User {$user->name} has been suspended.");
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function unsuspendUser(int $userId)
    {
        try {
            $user = User::findOrFail($userId);
            $user->status = 'active';
            $user->save();

            $this->loadData();

            $this->dispatch('success', "User {$user->name} has been unsuspended.");
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('coolify-permissions::livewire.admin.users');
    }
}
