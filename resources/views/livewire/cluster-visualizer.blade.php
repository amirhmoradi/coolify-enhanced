<div>
    <div class="flex items-center justify-between pb-4">
        <div class="flex items-center gap-2">
            <button
                wire:click="setMode('grid')"
                @class([
                    'px-3 py-1.5 text-sm rounded transition-colors',
                    'bg-white/10 text-white' => $mode === 'grid',
                    'text-neutral-400 hover:text-white' => $mode !== 'grid',
                ])>
                <svg class="w-4 h-4 inline-block mr-1 -mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="3" width="7" height="7" stroke-linecap="round" stroke-linejoin="round"/>
                    <rect x="14" y="3" width="7" height="7" stroke-linecap="round" stroke-linejoin="round"/>
                    <rect x="3" y="14" width="7" height="7" stroke-linecap="round" stroke-linejoin="round"/>
                    <rect x="14" y="14" width="7" height="7" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Grid View
            </button>
            <button
                wire:click="setMode('topology')"
                @class([
                    'px-3 py-1.5 text-sm rounded transition-colors',
                    'bg-white/10 text-white' => $mode === 'topology',
                    'text-neutral-400 hover:text-white' => $mode !== 'topology',
                ])>
                <svg class="w-4 h-4 inline-block mr-1 -mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="5" r="3" stroke-linecap="round" stroke-linejoin="round"/>
                    <circle cx="5" cy="19" r="3" stroke-linecap="round" stroke-linejoin="round"/>
                    <circle cx="19" cy="19" r="3" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M12 8v3M8.5 16.5L10.5 11M15.5 16.5L13.5 11" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Topology View
            </button>
        </div>
        <x-forms.button wire:click="refreshData">
            <svg class="w-4 h-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M23 4v6h-6M1 20v-6h6" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            Refresh
        </x-forms.button>
    </div>

    @if (count($nodes) === 0)
        <div class="p-12 text-center rounded-lg bg-coolgray-200">
            <p class="text-neutral-400">No node data available. Try refreshing.</p>
        </div>
    @elseif ($mode === 'grid')
        {{-- Grid View (Portainer-style: columns per node) --}}
        <div class="grid gap-4" style="grid-template-columns: repeat({{ min(count($nodes), 4) }}, minmax(0, 1fr));">
            @foreach ($nodes as $node)
                @php
                    $nodeId = $node['id'] ?? '';
                    $nodeTasks = collect($tasks)->where('node_id', $nodeId)->values();
                @endphp
                <div class="rounded-lg bg-coolgray-200 overflow-hidden flex flex-col">
                    {{-- Node Header --}}
                    <div class="px-3 py-2.5 border-b border-coolgray-300 bg-coolgray-300/50">
                        <div class="flex items-center gap-2">
                            <span @class([
                                'w-2 h-2 rounded-full shrink-0',
                                'bg-green-500' => ($node['status'] ?? '') === 'ready',
                                'bg-red-500' => ($node['status'] ?? '') === 'down',
                                'bg-neutral-500' => !in_array($node['status'] ?? '', ['ready', 'down']),
                            ])></span>
                            <span class="font-medium text-sm text-white truncate">{{ $node['hostname'] ?? 'Unknown' }}</span>
                        </div>
                        <div class="flex items-center gap-2 mt-1">
                            <span @class([
                                'px-1.5 py-0.5 text-xs rounded',
                                'bg-blue-500/20 text-blue-400' => ($node['role'] ?? '') === 'manager',
                                'bg-neutral-500/20 text-neutral-400' => ($node['role'] ?? '') !== 'manager',
                            ])>{{ ucfirst($node['role'] ?? 'worker') }}</span>
                            @if ($node['is_leader'] ?? false)
                                <span class="px-1.5 py-0.5 text-xs rounded bg-yellow-500/20 text-yellow-400">Leader</span>
                            @endif
                            <span class="text-xs text-neutral-500">{{ $nodeTasks->count() }} tasks</span>
                        </div>
                    </div>

                    {{-- Task Blocks --}}
                    <div class="p-2 flex-1 flex flex-col gap-1.5 min-h-[100px]">
                        @forelse ($nodeTasks as $task)
                            @php
                                $ts = strtolower($task['status'] ?? '');
                                $isRun = str_contains($ts, 'running');
                                $isFail = str_contains($ts, 'failed') || str_contains($ts, 'rejected');
                                $isPend = str_contains($ts, 'pending') || str_contains($ts, 'preparing') || str_contains($ts, 'starting');
                                $isUpd = str_contains($ts, 'updating');
                            @endphp
                            <div @class([
                                'px-2.5 py-2 rounded text-xs border-l-2',
                                'border-green-500 bg-green-500/10' => $isRun,
                                'border-red-500 bg-red-500/10' => $isFail,
                                'border-yellow-500 bg-yellow-500/10' => $isPend,
                                'border-blue-500 bg-blue-500/10' => $isUpd,
                                'border-neutral-500 bg-neutral-500/10' => !$isRun && !$isFail && !$isPend && !$isUpd,
                            ])>
                                <div class="flex items-center justify-between gap-1">
                                    <span class="text-white truncate font-medium" title="{{ $task['service_name'] ?? '' }}">
                                        {{ Str::limit($task['service_name'] ?? $task['name'] ?? 'Unknown', 20) }}
                                    </span>
                                    <span @class([
                                        'shrink-0 text-xs',
                                        'text-green-400' => $isRun,
                                        'text-red-400' => $isFail,
                                        'text-yellow-400' => $isPend,
                                        'text-blue-400' => $isUpd,
                                        'text-neutral-400' => !$isRun && !$isFail && !$isPend && !$isUpd,
                                    ])>
                                        {{ $task['status'] ?? '' }}
                                    </span>
                                </div>
                            </div>
                        @empty
                            <div class="flex items-center justify-center h-full text-neutral-500 text-xs">
                                No tasks
                            </div>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>

    @elseif ($mode === 'topology')
        {{-- Topology View --}}
        @php
            $managers = collect($nodes)->where('role', 'manager')->values();
            $workers = collect($nodes)->where('role', 'worker')->values();
        @endphp
        <div class="flex flex-col items-center gap-2">
            {{-- Manager Tier --}}
            @if ($managers->isNotEmpty())
                <div class="text-xs text-neutral-500 uppercase tracking-wider mb-1">Managers</div>
                <div class="flex flex-wrap justify-center gap-4">
                    @foreach ($managers as $mNode)
                        @php
                            $mTasks = collect($tasks)->where('node_id', $mNode['id'])->count();
                            $memGb = round(($mNode['memory_bytes'] ?? 0) / 1073741824, 1);
                            $cpuCores = $mNode['cpu_cores'] ?? 1;
                            $scale = max(1, min(1.5, $cpuCores / 4));
                        @endphp
                        <div
                            @class([
                                'rounded-lg border-2 p-4 transition-colors relative',
                                'border-blue-500/50 bg-blue-500/5' => ($mNode['status'] ?? '') === 'ready',
                                'border-red-500/50 bg-red-500/5' => ($mNode['status'] ?? '') === 'down',
                                'border-neutral-500/50 bg-neutral-500/5' => !in_array($mNode['status'] ?? '', ['ready', 'down']),
                            ])
                            style="min-width: {{ $scale * 160 }}px;">
                            @if ($mNode['is_leader'] ?? false)
                                <div class="absolute -top-2.5 left-1/2 -translate-x-1/2 px-2 py-0.5 text-xs rounded bg-yellow-500/20 text-yellow-400 whitespace-nowrap">
                                    Leader
                                </div>
                            @endif
                            <div class="flex items-center gap-2 mb-2">
                                <span @class([
                                    'w-2.5 h-2.5 rounded-full',
                                    'bg-green-500' => ($mNode['status'] ?? '') === 'ready',
                                    'bg-red-500' => ($mNode['status'] ?? '') !== 'ready',
                                ])></span>
                                <span class="font-semibold text-white text-sm">{{ $mNode['hostname'] ?? 'Unknown' }}</span>
                            </div>
                            <div class="text-xs text-neutral-400 space-y-0.5">
                                <div>{{ $cpuCores }} CPU &middot; {{ $memGb }} GB</div>
                                <div>{{ $mTasks }} tasks</div>
                            </div>
                            <div class="mt-1.5">
                                <span @class([
                                    'px-1.5 py-0.5 text-xs rounded',
                                    'bg-green-500/20 text-green-400' => ($mNode['availability'] ?? '') === 'active',
                                    'bg-yellow-500/20 text-yellow-400' => ($mNode['availability'] ?? '') === 'drain',
                                    'bg-orange-500/20 text-orange-400' => ($mNode['availability'] ?? '') === 'pause',
                                ])>{{ ucfirst($mNode['availability'] ?? 'unknown') }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Connection lines (CSS-based) --}}
            @if ($managers->isNotEmpty() && $workers->isNotEmpty())
                <div class="flex items-center justify-center py-1">
                    <div class="flex flex-col items-center">
                        <div class="w-px h-8 bg-neutral-600"></div>
                        @if ($workers->count() > 1)
                            <div class="h-px bg-neutral-600" style="width: {{ min($workers->count() * 100, 500) }}px;"></div>
                            <div class="flex justify-between" style="width: {{ min($workers->count() * 100, 500) }}px;">
                                @foreach ($workers as $w)
                                    <div class="w-px h-6 bg-neutral-600"></div>
                                @endforeach
                            </div>
                        @else
                            <div class="w-px h-6 bg-neutral-600"></div>
                        @endif
                    </div>
                </div>
            @endif

            {{-- Worker Tier --}}
            @if ($workers->isNotEmpty())
                <div class="text-xs text-neutral-500 uppercase tracking-wider mb-1">Workers</div>
                <div class="flex flex-wrap justify-center gap-4">
                    @foreach ($workers as $wNode)
                        @php
                            $wTasks = collect($tasks)->where('node_id', $wNode['id'])->count();
                            $wMemGb = round(($wNode['memory_bytes'] ?? 0) / 1073741824, 1);
                            $wCpuCores = $wNode['cpu_cores'] ?? 1;
                            $wScale = max(1, min(1.5, $wCpuCores / 4));
                        @endphp
                        <div
                            @class([
                                'rounded-lg border-2 p-4 transition-colors',
                                'border-green-500/30 bg-green-500/5' => ($wNode['status'] ?? '') === 'ready',
                                'border-red-500/30 bg-red-500/5' => ($wNode['status'] ?? '') === 'down',
                                'border-neutral-500/30 bg-neutral-500/5' => !in_array($wNode['status'] ?? '', ['ready', 'down']),
                            ])
                            style="min-width: {{ $wScale * 160 }}px;">
                            <div class="flex items-center gap-2 mb-2">
                                <span @class([
                                    'w-2.5 h-2.5 rounded-full',
                                    'bg-green-500' => ($wNode['status'] ?? '') === 'ready',
                                    'bg-red-500' => ($wNode['status'] ?? '') !== 'ready',
                                ])></span>
                                <span class="font-semibold text-white text-sm">{{ $wNode['hostname'] ?? 'Unknown' }}</span>
                            </div>
                            <div class="text-xs text-neutral-400 space-y-0.5">
                                <div>{{ $wCpuCores }} CPU &middot; {{ $wMemGb }} GB</div>
                                <div>{{ $wTasks }} tasks</div>
                            </div>
                            <div class="mt-1.5">
                                <span @class([
                                    'px-1.5 py-0.5 text-xs rounded',
                                    'bg-green-500/20 text-green-400' => ($wNode['availability'] ?? '') === 'active',
                                    'bg-yellow-500/20 text-yellow-400' => ($wNode['availability'] ?? '') === 'drain',
                                    'bg-orange-500/20 text-orange-400' => ($wNode['availability'] ?? '') === 'pause',
                                ])>{{ ucfirst($wNode['availability'] ?? 'unknown') }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($managers->isEmpty() && $workers->isEmpty())
                <div class="p-12 text-center text-neutral-400">No nodes found.</div>
            @endif
        </div>
    @endif
</div>
