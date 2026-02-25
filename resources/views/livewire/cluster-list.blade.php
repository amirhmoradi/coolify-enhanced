<div>
    <div class="flex items-center justify-between pb-6">
        <div>
            <h2 class="text-xl font-bold">Clusters</h2>
            <div class="text-sm text-neutral-400 mt-1">Manage Docker Swarm clusters across your infrastructure.</div>
        </div>
        <div class="flex gap-2">
            <x-forms.button wire:click="autoDetect">
                <svg class="w-4 h-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.66 0 3-4.03 3-9s-1.34-9-3-9m0 18c-1.66 0-3-4.03-3-9s1.34-9 3-9" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Auto-detect from Servers
            </x-forms.button>
        </div>
    </div>

    @if (count($clusters) === 0)
        <div class="p-12 text-center rounded border border-dashed border-coolgray-300">
            <svg class="w-12 h-12 mx-auto text-neutral-500 mb-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M5 12H3l9-9 9 9h-2M5 12v7a2 2 0 002 2h10a2 2 0 002-2v-7" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M9 21v-6a2 2 0 012-2h2a2 2 0 012 2v6" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <p class="text-neutral-400 text-lg mb-2">No clusters found.</p>
            <p class="text-neutral-500 text-sm">
                Mark a server as "Swarm Manager" in its settings and click
                <strong class="text-neutral-400">Auto-detect from Servers</strong> to get started.
            </p>
        </div>
    @else
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
            @foreach ($clusters as $cluster)
                <div class="relative group rounded-lg bg-coolgray-200 hover:bg-coolgray-300/70 transition-colors">
                    <a href="/cluster/{{ $cluster['uuid'] }}" class="block p-5">
                        <div class="flex items-center gap-3 mb-3">
                            <span @class([
                                'w-2.5 h-2.5 rounded-full shrink-0',
                                'bg-green-500' => ($cluster['status'] ?? 'unknown') === 'healthy',
                                'bg-yellow-500' => ($cluster['status'] ?? 'unknown') === 'degraded',
                                'bg-red-500' => ($cluster['status'] ?? 'unknown') === 'unreachable',
                                'bg-neutral-500' => !in_array($cluster['status'] ?? 'unknown', ['healthy', 'degraded', 'unreachable']),
                            ])></span>
                            <h3 class="font-semibold text-white truncate">{{ $cluster['name'] }}</h3>
                            <span class="px-2 py-0.5 text-xs rounded bg-coolgray-300 text-neutral-400 shrink-0">
                                {{ ucfirst($cluster['type'] ?? 'swarm') }}
                            </span>
                        </div>

                        <div class="flex items-center gap-4 text-sm text-neutral-400 mb-2">
                            <span class="flex items-center gap-1">
                                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="2" y="3" width="20" height="14" rx="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M8 21h8M12 17v4" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                {{ data_get($cluster, 'metadata.node_count', 0) }} nodes
                            </span>
                            <span class="flex items-center gap-1">
                                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                {{ data_get($cluster, 'metadata.service_count', 0) }} services
                            </span>
                        </div>

                        <div class="flex items-center justify-between">
                            <span class="text-xs text-neutral-500">
                                @if ($syncTime = data_get($cluster, 'metadata.last_sync_at'))
                                    Last sync: {{ \Carbon\Carbon::parse($syncTime)->diffForHumans() }}
                                @else
                                    Never synced
                                @endif
                            </span>
                            <span @class([
                                'px-2 py-0.5 text-xs rounded',
                                'bg-green-500/20 text-green-400' => ($cluster['status'] ?? 'unknown') === 'healthy',
                                'bg-yellow-500/20 text-yellow-400' => ($cluster['status'] ?? 'unknown') === 'degraded',
                                'bg-red-500/20 text-red-400' => ($cluster['status'] ?? 'unknown') === 'unreachable',
                                'bg-neutral-500/20 text-neutral-400' => !in_array($cluster['status'] ?? 'unknown', ['healthy', 'degraded', 'unreachable']),
                            ])>
                                {{ ucfirst($cluster['status'] ?? 'unknown') }}
                            </span>
                        </div>
                    </a>

                    <div class="absolute top-3 right-3 opacity-0 group-hover:opacity-100 transition-opacity">
                        <x-forms.button
                            isError
                            isSmall
                            wire:click="deleteCluster('{{ $cluster['uuid'] }}')"
                            wire:confirm="Are you sure you want to delete the cluster '{{ $cluster['name'] }}'? This will unlink all associated servers.">
                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m3 0v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6h14z" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </x-forms.button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
