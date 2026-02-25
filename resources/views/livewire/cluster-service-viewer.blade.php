<div>
    <div class="flex items-center justify-between pb-4">
        <h3 class="font-semibold text-white">Services</h3>
        <x-forms.button wire:click="refreshServices">
            <svg class="w-4 h-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M23 4v6h-6M1 20v-6h6" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            Refresh
        </x-forms.button>
    </div>

    @if (count($services) === 0)
        <div class="p-12 text-center rounded-lg bg-coolgray-200">
            <svg class="w-10 h-10 mx-auto text-neutral-500 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <p class="text-neutral-400">No services found in this cluster.</p>
        </div>
    @else
        <div class="rounded-lg bg-coolgray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-coolgray-300">
                            <th class="px-4 py-2.5 text-left text-xs font-medium text-neutral-400 uppercase tracking-wider w-8"></th>
                            <th class="px-4 py-2.5 text-left text-xs font-medium text-neutral-400 uppercase tracking-wider">Service</th>
                            <th class="px-4 py-2.5 text-left text-xs font-medium text-neutral-400 uppercase tracking-wider">Image</th>
                            <th class="px-4 py-2.5 text-left text-xs font-medium text-neutral-400 uppercase tracking-wider">Mode</th>
                            <th class="px-4 py-2.5 text-left text-xs font-medium text-neutral-400 uppercase tracking-wider">Replicas</th>
                            <th class="px-4 py-2.5 text-left text-xs font-medium text-neutral-400 uppercase tracking-wider">Ports</th>
                            <th class="px-4 py-2.5 text-right text-xs font-medium text-neutral-400 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-coolgray-300">
                        @foreach ($services as $service)
                            @php
                                $isExpanded = $expandedServiceId === $service['id'];
                                $isScaling = $scaleServiceId === $service['id'];
                                $allRunning = $service['replicas_running'] >= $service['replicas_desired'] && $service['replicas_desired'] > 0;
                            @endphp
                            <tr
                                wire:click="toggleServiceExpand('{{ $service['id'] }}')"
                                @class([
                                    'cursor-pointer transition-colors',
                                    'bg-coolgray-300/30' => $isExpanded,
                                    'hover:bg-coolgray-300/20' => !$isExpanded,
                                ])>
                                <td class="px-4 py-3 text-neutral-500">
                                    <svg @class(['w-4 h-4 transition-transform', 'rotate-90' => $isExpanded]) viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M9 18l6-6-6-6" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </td>
                                <td class="px-4 py-3 font-medium text-white">{{ $service['name'] }}</td>
                                <td class="px-4 py-3 font-mono text-xs text-neutral-400 max-w-xs truncate">{{ $service['image'] }}</td>
                                <td class="px-4 py-3">
                                    <span class="px-2 py-0.5 text-xs rounded bg-coolgray-300 text-neutral-300">{{ $service['mode'] }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span @class([
                                        'font-medium',
                                        'text-green-400' => $allRunning,
                                        'text-yellow-400' => !$allRunning && $service['replicas_running'] > 0,
                                        'text-red-400' => $service['replicas_running'] === 0 && $service['replicas_desired'] > 0,
                                    ])>
                                        {{ $service['replicas_running'] }}/{{ $service['replicas_desired'] }}
                                    </span>
                                    @if (!$allRunning && $service['replicas_desired'] > 0)
                                        <div class="w-16 h-1 mt-1 rounded-full bg-coolgray-300 overflow-hidden">
                                            <div class="h-full rounded-full {{ $service['replicas_running'] > 0 ? 'bg-yellow-500' : 'bg-red-500' }}"
                                                 style="width: {{ $service['replicas_desired'] > 0 ? ($service['replicas_running'] / $service['replicas_desired']) * 100 : 0 }}%"></div>
                                        </div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 font-mono text-xs text-neutral-400">{{ $service['ports'] ?: '-' }}</td>
                                <td class="px-4 py-3 text-right" x-data>
                                    <div class="flex items-center justify-end gap-1" x-on:click.stop>
                                        <button
                                            wire:click.stop="startScaling('{{ $service['id'] }}', {{ $service['replicas_desired'] }})"
                                            class="px-2 py-1 text-xs rounded bg-coolgray-300 text-neutral-300 hover:bg-coolgray-300/80 hover:text-white transition-colors"
                                            title="Scale">
                                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M17 8l-5-5-5 5M12 3v12" stroke-linecap="round" stroke-linejoin="round"/>
                                            </svg>
                                        </button>
                                        <button
                                            wire:click.stop="rollbackService('{{ $service['id'] }}')"
                                            wire:confirm="Roll back this service to its previous version?"
                                            class="px-2 py-1 text-xs rounded bg-coolgray-300 text-neutral-300 hover:bg-yellow-500/20 hover:text-yellow-400 transition-colors"
                                            title="Rollback">
                                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M1 4v6h6M3.51 15a9 9 0 102.13-9.36L1 10" stroke-linecap="round" stroke-linejoin="round"/>
                                            </svg>
                                        </button>
                                        <button
                                            wire:click.stop="forceUpdateService('{{ $service['id'] }}')"
                                            wire:confirm="Force update will redistribute all tasks. Continue?"
                                            class="px-2 py-1 text-xs rounded bg-coolgray-300 text-neutral-300 hover:bg-blue-500/20 hover:text-blue-400 transition-colors"
                                            title="Force Update">
                                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M23 4v6h-6M1 20v-6h6" stroke-linecap="round" stroke-linejoin="round"/>
                                                <path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15" stroke-linecap="round" stroke-linejoin="round"/>
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            @if ($isScaling)
                                <tr class="bg-coolgray-300/20" x-data>
                                    <td colspan="7" class="px-4 py-3" x-on:click.stop>
                                        <div class="flex items-center gap-3">
                                            <span class="text-sm text-neutral-400">Scale <strong class="text-white">{{ $service['name'] }}</strong> to:</span>
                                            <input type="number" wire:model="scaleReplicas" min="0" max="100"
                                                class="w-20 px-2 py-1 text-sm rounded bg-coolgray-100 border border-coolgray-300 text-white focus:border-blue-500 focus:outline-none" />
                                            <span class="text-sm text-neutral-500">replicas</span>
                                            <x-forms.button isSmall wire:click="confirmScale('{{ $service['id'] }}')">Confirm</x-forms.button>
                                            <button wire:click="cancelScale" class="px-2 py-1 text-xs rounded text-neutral-400 hover:text-white transition-colors">Cancel</button>
                                        </div>
                                    </td>
                                </tr>
                            @endif

                            @if ($isExpanded)
                                <tr>
                                    <td colspan="7" class="p-0">
                                        <div class="bg-coolgray-100/50 border-l-2 border-blue-500/30 ml-8 mr-4 my-2 rounded">
                                            <div class="px-4 py-2 border-b border-coolgray-300/50">
                                                <span class="text-xs font-medium text-neutral-400 uppercase tracking-wider">Tasks for {{ $service['name'] }}</span>
                                            </div>
                                            @if (count($expandedTasks) === 0)
                                                <div class="px-4 py-4 text-sm text-neutral-500">No tasks found for this service.</div>
                                            @else
                                                <table class="w-full text-xs">
                                                    <thead>
                                                        <tr class="text-neutral-500">
                                                            <th class="px-4 py-2 text-left font-medium">Task ID</th>
                                                            <th class="px-4 py-2 text-left font-medium">Node</th>
                                                            <th class="px-4 py-2 text-left font-medium">Status</th>
                                                            <th class="px-4 py-2 text-left font-medium">Desired</th>
                                                            <th class="px-4 py-2 text-left font-medium">Image</th>
                                                            <th class="px-4 py-2 text-left font-medium">Error</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-coolgray-300/30">
                                                        @foreach ($expandedTasks as $task)
                                                            @php
                                                                $ts = strtolower($task['status'] ?? '');
                                                                $isRunning = str_contains($ts, 'running');
                                                                $isFailed = str_contains($ts, 'failed') || str_contains($ts, 'rejected');
                                                                $isPending = str_contains($ts, 'pending') || str_contains($ts, 'preparing') || str_contains($ts, 'starting') || str_contains($ts, 'assigned') || str_contains($ts, 'accepted');
                                                                $isComplete = str_contains($ts, 'complete') || str_contains($ts, 'shutdown');
                                                            @endphp
                                                            <tr class="hover:bg-coolgray-300/10">
                                                                <td class="px-4 py-2 font-mono text-neutral-300">{{ Str::limit($task['id'] ?? '', 12) }}</td>
                                                                <td class="px-4 py-2 text-neutral-300">{{ $task['node'] ?? '-' }}</td>
                                                                <td class="px-4 py-2">
                                                                    <span @class([
                                                                        'px-1.5 py-0.5 rounded',
                                                                        'bg-green-500/20 text-green-400' => $isRunning,
                                                                        'bg-red-500/20 text-red-400' => $isFailed,
                                                                        'bg-yellow-500/20 text-yellow-400' => $isPending,
                                                                        'bg-blue-500/20 text-blue-400' => $isComplete,
                                                                        'bg-neutral-500/20 text-neutral-400' => !$isRunning && !$isFailed && !$isPending && !$isComplete,
                                                                    ])>{{ $task['status'] ?? 'unknown' }}</span>
                                                                </td>
                                                                <td class="px-4 py-2 text-neutral-400">{{ $task['desired_state'] ?? '-' }}</td>
                                                                <td class="px-4 py-2 font-mono text-neutral-400 max-w-[200px] truncate">{{ $task['image'] ?? '-' }}</td>
                                                                <td class="px-4 py-2">
                                                                    @if (!empty($task['error']))
                                                                        <span class="text-red-400" title="{{ $task['error'] }}">{{ Str::limit($task['error'], 40) }}</span>
                                                                    @else
                                                                        <span class="text-neutral-600">-</span>
                                                                    @endif
                                                                </td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
