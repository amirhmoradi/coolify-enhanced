<div wire:poll.{{ $pollInterval }}s="refreshData">
    {{-- Header --}}
    <div class="flex items-center gap-4 pb-6">
        <a href="/clusters" class="text-neutral-400 hover:text-white transition-colors">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M19 12H5M12 19l-7-7 7-7" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </a>
        <div class="flex items-center gap-3">
            <h2 class="text-xl font-bold text-white">{{ $cluster->name }}</h2>
            <span @class([
                'px-2 py-0.5 rounded text-xs font-medium',
                'bg-green-500/20 text-green-400' => $health === 'healthy',
                'bg-yellow-500/20 text-yellow-400' => $health === 'degraded',
                'bg-red-500/20 text-red-400' => $health === 'unreachable',
                'bg-neutral-500/20 text-neutral-400' => !in_array($health, ['healthy', 'degraded', 'unreachable']),
            ])>
                {{ ucfirst($health) }}
            </span>
            <span class="px-2 py-0.5 rounded text-xs bg-coolgray-300 text-neutral-400">
                {{ ucfirst($cluster->type) }}
            </span>
        </div>
        <div class="ml-auto">
            <x-forms.button wire:click="refreshData">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M23 4v6h-6M1 20v-6h6" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </x-forms.button>
        </div>
    </div>

    {{-- Tab Navigation --}}
    <div class="flex gap-1 border-b border-coolgray-300 mb-6 overflow-x-auto">
        @php
            $tabs = [
                'overview' => 'Overview',
                'nodes' => 'Nodes',
                'services' => 'Services',
                'visualizer' => 'Visualizer',
                'events' => 'Events',
                'secrets' => 'Secrets',
                'configs' => 'Configs',
            ];
        @endphp
        @foreach ($tabs as $tab => $label)
            <button
                wire:click="$set('activeTab', '{{ $tab }}')"
                @class([
                    'px-4 py-2.5 text-sm font-medium border-b-2 transition-colors whitespace-nowrap -mb-px',
                    'border-white text-white' => $activeTab === $tab,
                    'border-transparent text-neutral-400 hover:text-neutral-200' => $activeTab !== $tab,
                ])>
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- Overview Tab --}}
    @if ($activeTab === 'overview')
        @php
            $nodeCollection = collect($nodes);
            $serviceCollection = collect($services);
            $totalRunning = $serviceCollection->sum('replicas_running');
            $totalDesired = $serviceCollection->sum('replicas_desired');
            $degradedCount = $serviceCollection->filter(fn($s) => $s['replicas_running'] < $s['replicas_desired'])->count();
        @endphp

        {{-- Summary Cards --}}
        <div class="grid grid-cols-1 gap-4 mb-6 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-lg bg-coolgray-200 p-4">
                <p class="text-xs text-neutral-400 uppercase tracking-wider mb-1">Cluster Status</p>
                <p class="text-lg font-semibold text-white flex items-center gap-2">
                    <span @class([
                        'w-2.5 h-2.5 rounded-full',
                        'bg-green-500' => $health === 'healthy',
                        'bg-yellow-500' => $health === 'degraded',
                        'bg-red-500' => $health === 'unreachable',
                        'bg-neutral-500' => !in_array($health, ['healthy', 'degraded', 'unreachable']),
                    ])></span>
                    {{ ucfirst($health) }}
                </p>
                <p class="text-xs text-neutral-500 mt-1">{{ ucfirst($cluster->type) }} cluster</p>
            </div>

            <div class="rounded-lg bg-coolgray-200 p-4">
                <p class="text-xs text-neutral-400 uppercase tracking-wider mb-1">Nodes</p>
                <p class="text-lg font-semibold text-white">{{ $nodeCollection->count() }}</p>
                <p class="text-xs text-neutral-500 mt-1">
                    {{ $nodeCollection->where('role', 'manager')->count() }} managers &middot;
                    {{ $nodeCollection->where('role', 'worker')->count() }} workers
                </p>
            </div>

            <div class="rounded-lg bg-coolgray-200 p-4">
                <p class="text-xs text-neutral-400 uppercase tracking-wider mb-1">Services</p>
                <p class="text-lg font-semibold text-white">{{ $serviceCollection->count() }}</p>
                <p class="text-xs mt-1 {{ $degradedCount > 0 ? 'text-yellow-400' : 'text-neutral-500' }}">
                    {{ $degradedCount > 0 ? $degradedCount . ' degraded' : 'All healthy' }}
                </p>
            </div>

            <div class="rounded-lg bg-coolgray-200 p-4">
                <p class="text-xs text-neutral-400 uppercase tracking-wider mb-1">Tasks</p>
                <p class="text-lg font-semibold text-white">
                    {{ $totalRunning }} <span class="text-sm font-normal text-neutral-400">/ {{ $totalDesired }}</span>
                </p>
                <p class="text-xs mt-1 {{ $totalRunning < $totalDesired ? 'text-yellow-400' : 'text-neutral-500' }}">
                    {{ $totalRunning < $totalDesired ? ($totalDesired - $totalRunning) . ' pending' : 'All running' }}
                </p>
            </div>
        </div>

        {{-- Node Listing Table --}}
        <div class="rounded-lg bg-coolgray-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-coolgray-300">
                <h3 class="font-semibold text-sm text-white">Nodes</h3>
            </div>
            @if ($nodeCollection->isEmpty())
                <div class="p-8 text-center text-neutral-400">No nodes found. Try refreshing.</div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-coolgray-300">
                                <th class="px-4 py-2.5 text-left text-xs font-medium text-neutral-400 uppercase tracking-wider">Status</th>
                                <th class="px-4 py-2.5 text-left text-xs font-medium text-neutral-400 uppercase tracking-wider">Hostname</th>
                                <th class="px-4 py-2.5 text-left text-xs font-medium text-neutral-400 uppercase tracking-wider">Role</th>
                                <th class="px-4 py-2.5 text-left text-xs font-medium text-neutral-400 uppercase tracking-wider">IP Address</th>
                                <th class="px-4 py-2.5 text-left text-xs font-medium text-neutral-400 uppercase tracking-wider">Docker</th>
                                <th class="px-4 py-2.5 text-left text-xs font-medium text-neutral-400 uppercase tracking-wider">CPU</th>
                                <th class="px-4 py-2.5 text-left text-xs font-medium text-neutral-400 uppercase tracking-wider">Memory</th>
                                <th class="px-4 py-2.5 text-left text-xs font-medium text-neutral-400 uppercase tracking-wider">Availability</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-coolgray-300">
                            @foreach ($nodes as $node)
                                <tr class="hover:bg-coolgray-300/30 transition-colors">
                                    <td class="px-4 py-3">
                                        <span class="flex items-center gap-2">
                                            <span @class([
                                                'w-2 h-2 rounded-full',
                                                'bg-green-500' => ($node['status'] ?? '') === 'ready',
                                                'bg-red-500' => ($node['status'] ?? '') === 'down',
                                                'bg-yellow-500' => ($node['status'] ?? '') === 'disconnected',
                                                'bg-neutral-500' => !in_array($node['status'] ?? '', ['ready', 'down', 'disconnected']),
                                            ])></span>
                                            <span class="text-neutral-300">{{ ucfirst($node['status'] ?? 'unknown') }}</span>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 font-medium text-white">
                                        {{ $node['hostname'] ?? 'Unknown' }}
                                        @if ($node['is_leader'] ?? false)
                                            <span class="ml-1.5 px-1.5 py-0.5 text-xs rounded bg-yellow-500/20 text-yellow-400">Leader</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        <span @class([
                                            'px-2 py-0.5 text-xs rounded',
                                            'bg-blue-500/20 text-blue-400' => ($node['role'] ?? '') === 'manager',
                                            'bg-neutral-500/20 text-neutral-400' => ($node['role'] ?? '') !== 'manager',
                                        ])>{{ ucfirst($node['role'] ?? 'worker') }}</span>
                                    </td>
                                    <td class="px-4 py-3 font-mono text-xs text-neutral-300">{{ $node['ip'] ?? '-' }}</td>
                                    <td class="px-4 py-3 text-neutral-300">{{ $node['engine_version'] ?? '-' }}</td>
                                    <td class="px-4 py-3 text-neutral-300">{{ $node['cpu_cores'] ?? 0 }} cores</td>
                                    <td class="px-4 py-3 text-neutral-300">{{ round(($node['memory_bytes'] ?? 0) / 1073741824, 1) }} GB</td>
                                    <td class="px-4 py-3">
                                        <span @class([
                                            'px-2 py-0.5 text-xs rounded',
                                            'bg-green-500/20 text-green-400' => ($node['availability'] ?? '') === 'active',
                                            'bg-yellow-500/20 text-yellow-400' => ($node['availability'] ?? '') === 'drain',
                                            'bg-orange-500/20 text-orange-400' => ($node['availability'] ?? '') === 'pause',
                                            'bg-neutral-500/20 text-neutral-400' => !in_array($node['availability'] ?? '', ['active', 'drain', 'pause']),
                                        ])>{{ ucfirst($node['availability'] ?? 'unknown') }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif

    @if ($activeTab === 'nodes')
        @livewire('enhanced::cluster-node-manager', ['clusterId' => $cluster->id], key('node-manager-' . $cluster->id))
    @endif

    @if ($activeTab === 'services')
        @livewire('enhanced::cluster-service-viewer', ['clusterId' => $cluster->id], key('service-viewer-' . $cluster->id))
    @endif

    @if ($activeTab === 'visualizer')
        @livewire('enhanced::cluster-visualizer', ['clusterId' => $cluster->id, 'mode' => $visualizerMode], key('visualizer-' . $cluster->id))
    @endif

    @if ($activeTab === 'events')
        @livewire('enhanced::cluster-events', ['clusterId' => $cluster->id], key('events-' . $cluster->id))
    @endif

    @if ($activeTab === 'secrets')
        @livewire('enhanced::cluster-secrets', ['clusterId' => $cluster->id], key('secrets-' . $cluster->id))
    @endif

    @if ($activeTab === 'configs')
        @livewire('enhanced::cluster-configs', ['clusterId' => $cluster->id], key('configs-' . $cluster->id))
    @endif
</div>
