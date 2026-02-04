<div>
    <x-slot:title>Admin Users | Coolify</x-slot>

    <h1>User Management</h1>
    <div class="pt-2 pb-6">
        Manage all users in the Coolify instance.
        @if (!config('coolify-permissions.enabled'))
            <div class="mt-2 text-warning">
                Granular permissions are currently disabled. Enable by setting
                <code>COOLIFY_GRANULAR_PERMISSIONS=true</code> in your environment.
            </div>
        @endif
    </div>

    {{-- Create User Section --}}
    <div class="pb-8">
        <h3 class="pb-2">Create New User</h3>
        <div class="flex gap-4 items-end">
            <div class="w-48">
                <x-forms.input wire:model="name" label="Name" id="name" placeholder="John Doe" />
            </div>
            <div class="w-64">
                <x-forms.input wire:model="email" label="Email" id="email" type="email" placeholder="john@example.com" />
            </div>
            <div class="w-48">
                <x-forms.input wire:model="password" label="Password" id="password" type="password" />
            </div>
            <x-forms.button wire:click="createUser">Create User</x-forms.button>
        </div>
    </div>

    {{-- Assign User to Team Section --}}
    @if (count($teams) > 0 && count($users) > 0)
        <div class="pb-8">
            <h3 class="pb-2">Assign User to Team</h3>
            <div class="flex gap-4 items-end">
                <div class="w-64">
                    <x-forms.select wire:model="selectedUserId" label="User" id="selectedUserId">
                        <option value="">Select a user...</option>
                        @foreach ($users as $user)
                            <option value="{{ $user['id'] }}">{{ $user['name'] }} ({{ $user['email'] }})</option>
                        @endforeach
                    </x-forms.select>
                </div>
                <div class="w-48">
                    <x-forms.select wire:model="assignTeamId" label="Team" id="assignTeamId">
                        <option value="">Select a team...</option>
                        @foreach ($teams as $team)
                            <option value="{{ $team['id'] }}">{{ $team['name'] }}</option>
                        @endforeach
                    </x-forms.select>
                </div>
                <div class="w-32">
                    <x-forms.select wire:model="assignRole" label="Role" id="assignRole">
                        <option value="owner">Owner</option>
                        <option value="admin">Admin</option>
                        <option value="member">Member</option>
                        <option value="viewer">Viewer</option>
                    </x-forms.select>
                </div>
                <x-forms.button wire:click="assignUserToTeam">Assign</x-forms.button>
            </div>
        </div>
    @endif

    {{-- Users List --}}
    <div class="pt-4">
        <h3 class="pb-2">All Users</h3>
        @if (count($users) > 0)
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead>
                        <tr class="text-left border-b dark:border-coolgray-400">
                            <th class="px-5 py-3 text-sm font-semibold">Name</th>
                            <th class="px-5 py-3 text-sm font-semibold">Email</th>
                            <th class="px-5 py-3 text-sm font-semibold">Global Admin</th>
                            <th class="px-5 py-3 text-sm font-semibold">Status</th>
                            <th class="px-5 py-3 text-sm font-semibold">Teams</th>
                            <th class="px-5 py-3 text-sm font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($users as $user)
                            <tr class="border-b dark:border-coolgray-400 dark:hover:bg-coolgray-100">
                                <td class="px-5 py-4 text-sm">{{ $user['name'] }}</td>
                                <td class="px-5 py-4 text-sm">{{ $user['email'] }}</td>
                                <td class="px-5 py-4 text-sm">
                                    @if ($user['is_global_admin'])
                                        <span class="px-2 py-1 text-xs rounded bg-green-500/20 text-green-400">Yes</span>
                                    @else
                                        <span class="px-2 py-1 text-xs rounded bg-neutral-500/20 text-neutral-400">No</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4 text-sm">
                                    @if ($user['status'] === 'active')
                                        <span class="px-2 py-1 text-xs rounded bg-green-500/20 text-green-400">Active</span>
                                    @else
                                        <span class="px-2 py-1 text-xs rounded bg-red-500/20 text-red-400">Suspended</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4 text-sm">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($user['teams'] as $team)
                                            <span class="px-2 py-1 text-xs rounded bg-blue-500/20 text-blue-400">
                                                {{ $team['name'] }} ({{ $team['role'] }})
                                            </span>
                                        @endforeach
                                        @if (empty($user['teams']))
                                            <span class="text-neutral-400">No teams</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-5 py-4 text-sm">
                                    <div class="flex gap-2">
                                        @if ($user['id'] !== auth()->id())
                                            <x-forms.button wire:click="toggleGlobalAdmin({{ $user['id'] }})">
                                                {{ $user['is_global_admin'] ? 'Revoke Admin' : 'Make Admin' }}
                                            </x-forms.button>
                                            @if ($user['status'] === 'active')
                                                <x-forms.button isError wire:click="suspendUser({{ $user['id'] }})" wire:confirm="Are you sure you want to suspend this user?">
                                                    Suspend
                                                </x-forms.button>
                                            @else
                                                <x-forms.button wire:click="unsuspendUser({{ $user['id'] }})">
                                                    Unsuspend
                                                </x-forms.button>
                                            @endif
                                        @else
                                            <span class="text-neutral-400">(This is you)</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="text-neutral-400">No users found.</div>
        @endif
    </div>
</div>
