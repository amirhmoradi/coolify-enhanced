<div>
    <div class="flex items-center justify-between pb-4">
        <h3 class="font-semibold text-white">Node Management</h3>
        <div class="flex items-center gap-2">
            <x-forms.button wire:click="refreshNodes">
                <svg class="w-4 h-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M23 4v6h-6M1 20v-6h6" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Refresh
            </x-forms.button>
            <x-forms.button wire:click="$set('showAddNode', true)">
                <svg class="w-4 h-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 5v14M5 12h14" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Add Node
            </x-forms.button>
        </div>
    </div>

    {{-- Add Node Wizard --}}
    @if ($showAddNode)
        <div class="mb-4">
            @livewire('enhanced::cluster-add-node', ['clusterId' => $clusterId], key('add-node-' . $clusterId))
        </div>
    @endif

    {{-- Node Table --}}
    @if (count($nodes) === 0)
        <div class="p-12 text-center rounded-lg bg-coolgray-200">
            <p class="text-neutral-400">No nodes found in this cluster.</p>
        </div>
    @else
        <div class="rounded-lg bg-coolgray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-coolgray-300">
                            <th class="px-4 py-2.5 text-left text-xs font-medium text-neutral-400 uppercase tracking-wider">Status</th>
                            <th class="px-4 py-2.5 text-left text-xs font-medium text-neutral-400 uppercase tracking-wider">Hostname</th>
                            <th class="px-4 py-2.5 text-left text-xs font-medium text-neutral-400 uppercase tracking-wider">Role</th>
                            <th class="px-4 py-2.5 text-left text-xs font-medium text-neutral-400 uppercase tracking-wider">IP</th>
                            <th class="px-4 py-2.5 text-left text-xs font-medium text-neutral-400 uppercase tracking-wider">Docker</th>
                            <th class="px-4 py-2.5 text-left text-xs font-medium text-neutral-400 uppercase tracking-wider">CPU</th>
                            <th class="px-4 py-2.5 text-left text-xs font-medium text-neutral-400 uppercase tracking-wider">Memory</th>
                            <th class="px-4 py-2.5 text-left text-xs font-medium text-neutral-400 uppercase tracking-wider">Availability</th>
                            <th class="px-4 py-2.5 text-right text-xs font-medium text-neutral-400 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-coolgray-300">
                        @foreach ($nodes as $node)
                            @php
                                $isSelected = $selectedNodeId === ($node['id'] ?? '');
                                $isLeader = $node['is_leader'] ?? false;
                                $nodeRole = $node['role'] ?? 'worker';
                                $nodeAvailability = $node['availability'] ?? 'active';
                            @endphp
                            <tr @class([
                                'transition-colors',
                                'bg-coolgray-300/20' => $isSelected,
                                'hover:bg-coolgray-300/20' => !$isSelected,
                            ])>
                                <td class="px-4 py-3">
                                    <span class="flex items-center gap-2">
                                        <span @class([
                                            'w-2 h-2 rounded-full',
                                            'bg-green-500' => ($node['status'] ?? '') === 'ready',
                                            'bg-red-500' => ($node['status'] ?? '') === 'down',
                                            'bg-neutral-500' => !in_array($node['status'] ?? '', ['ready', 'down']),
                                        ])></span>
                                        <span class="text-neutral-300">{{ ucfirst($node['status'] ?? 'unknown') }}</span>
                                    </span>
                                </td>
                                <td class="px-4 py-3 font-medium text-white">
                                    {{ $node['hostname'] ?? 'Unknown' }}
                                    @if ($isLeader)
                                        <span class="ml-1.5 px-1.5 py-0.5 text-xs rounded bg-yellow-500/20 text-yellow-400">Leader</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <span @class([
                                        'px-2 py-0.5 text-xs rounded',
                                        'bg-blue-500/20 text-blue-400' => $nodeRole === 'manager',
                                        'bg-neutral-500/20 text-neutral-400' => $nodeRole !== 'manager',
                                    ])>{{ ucfirst($nodeRole) }}</span>
                                </td>
                                <td class="px-4 py-3 font-mono text-xs text-neutral-300">{{ $node['ip'] ?? '-' }}</td>
                                <td class="px-4 py-3 text-neutral-300">{{ $node['engine_version'] ?? '-' }}</td>
                                <td class="px-4 py-3 text-neutral-300">{{ $node['cpu_cores'] ?? 0 }} cores</td>
                                <td class="px-4 py-3 text-neutral-300">{{ round(($node['memory_bytes'] ?? 0) / 1073741824, 1) }} GB</td>
                                <td class="px-4 py-3">
                                    <span @class([
                                        'px-2 py-0.5 text-xs rounded',
                                        'bg-green-500/20 text-green-400' => $nodeAvailability === 'active',
                                        'bg-yellow-500/20 text-yellow-400' => $nodeAvailability === 'drain',
                                        'bg-orange-500/20 text-orange-400' => $nodeAvailability === 'pause',
                                    ])>{{ ucfirst($nodeAvailability) }}</span>
                                </td>
                                <td class="px-4 py-3 text-right" x-data="{ open: false }">
                                    <div class="relative inline-block text-left">
                                        <button @click="open = !open" @click.outside="open = false"
                                            class="px-2 py-1 text-xs rounded bg-coolgray-300 text-neutral-300 hover:text-white transition-colors">
                                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/>
                                            </svg>
                                        </button>
                                        <div x-show="open" x-transition
                                            class="absolute right-0 z-10 mt-1 w-48 rounded-md bg-coolgray-100 border border-coolgray-300 shadow-lg">
                                            <div class="py-1">
                                                @if ($nodeAvailability !== 'drain')
                                                    <button wire:click="drainNode('{{ $node['id'] }}')"
                                                        wire:confirm="Draining this node will reschedule all running tasks to other nodes. Continue?"
                                                        @click="open = false"
                                                        class="block w-full px-4 py-2 text-left text-sm text-yellow-400 hover:bg-coolgray-200 transition-colors">
                                                        Drain Node
                                                    </button>
                                                @endif
                                                @if ($nodeAvailability !== 'active')
                                                    <button wire:click="activateNode('{{ $node['id'] }}')"
                                                        @click="open = false"
                                                        class="block w-full px-4 py-2 text-left text-sm text-green-400 hover:bg-coolgray-200 transition-colors">
                                                        Activate Node
                                                    </button>
                                                @endif
                                                @if ($nodeAvailability === 'active')
                                                    <button wire:click="pauseNode('{{ $node['id'] }}')"
                                                        @click="open = false"
                                                        class="block w-full px-4 py-2 text-left text-sm text-orange-400 hover:bg-coolgray-200 transition-colors">
                                                        Pause Node
                                                    </button>
                                                @endif
                                                <div class="border-t border-coolgray-300 my-1"></div>
                                                @if ($nodeRole === 'worker')
                                                    <button wire:click="promoteNode('{{ $node['id'] }}')"
                                                        wire:confirm="Promote this worker to manager?"
                                                        @click="open = false"
                                                        class="block w-full px-4 py-2 text-left text-sm text-blue-400 hover:bg-coolgray-200 transition-colors">
                                                        Promote to Manager
                                                    </button>
                                                @elseif ($nodeRole === 'manager' && !$isLeader)
                                                    <button wire:click="demoteNode('{{ $node['id'] }}')"
                                                        wire:confirm="Demote this manager to worker? This cannot be done on the last manager."
                                                        @click="open = false"
                                                        class="block w-full px-4 py-2 text-left text-sm text-orange-400 hover:bg-coolgray-200 transition-colors">
                                                        Demote to Worker
                                                    </button>
                                                @endif
                                                <button wire:click="selectNode('{{ $node['id'] }}')"
                                                    @click="open = false"
                                                    class="block w-full px-4 py-2 text-left text-sm text-neutral-300 hover:bg-coolgray-200 transition-colors">
                                                    Manage Labels
                                                </button>
                                                <div class="border-t border-coolgray-300 my-1"></div>
                                                @if ($nodeAvailability === 'drain')
                                                    <button wire:click="removeNode('{{ $node['id'] }}')"
                                                        wire:confirm="Permanently remove this node from the cluster?"
                                                        @click="open = false"
                                                        class="block w-full px-4 py-2 text-left text-sm text-red-400 hover:bg-coolgray-200 transition-colors">
                                                        Remove Node
                                                    </button>
                                                @else
                                                    <span class="block px-4 py-2 text-sm text-neutral-500 cursor-not-allowed" title="Drain the node before removal">
                                                        Remove Node
                                                    </span>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Label Management Panel --}}
    @if ($selectedNodeId)
        @php
            $selectedNode = collect($nodes)->firstWhere('id', $selectedNodeId);
        @endphp
        <div class="mt-4 p-4 rounded-lg bg-coolgray-200 border border-coolgray-300">
            <div class="flex items-center justify-between pb-3">
                <h4 class="font-semibold text-white text-sm">
                    Labels for {{ $selectedNode['hostname'] ?? 'Unknown' }}
                </h4>
                <button wire:click="$set('selectedNodeId', null)" class="text-neutral-400 hover:text-white transition-colors">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M18 6L6 18M6 6l12 12" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
            </div>

            {{-- Current Labels --}}
            @if (count($selectedNodeLabels) > 0)
                <div class="overflow-x-auto mb-4">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-coolgray-300/50">
                                <th class="px-3 py-2 text-left text-xs font-medium text-neutral-400">Key</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-neutral-400">Value</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-neutral-400 w-20">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-coolgray-300/50">
                            @foreach ($selectedNodeLabels as $key => $value)
                                <tr class="hover:bg-coolgray-300/10">
                                    <td class="px-3 py-2 font-mono text-xs text-neutral-300">{{ $key }}</td>
                                    <td class="px-3 py-2 font-mono text-xs text-neutral-400">{{ $value }}</td>
                                    <td class="px-3 py-2 text-right">
                                        <button wire:click="removeLabel('{{ $key }}')"
                                            wire:confirm="Remove label '{{ $key }}'?"
                                            class="px-1.5 py-0.5 text-xs rounded text-red-400 hover:bg-red-500/20 transition-colors">
                                            Remove
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-sm text-neutral-500 mb-4">No labels on this node.</p>
            @endif

            {{-- Add Label Form --}}
            <div class="flex items-end gap-2">
                <div class="flex-1">
                    <x-forms.input id="newLabelKey" label="Label Key" placeholder="e.g., region" />
                </div>
                <div class="flex-1">
                    <x-forms.input id="newLabelValue" label="Label Value" placeholder="e.g., us-east-1" />
                </div>
                <x-forms.button wire:click="addLabel">Add Label</x-forms.button>
            </div>
        </div>
    @endif
</div>
