<div wire:poll.10s="refreshEvents">
    <div class="flex items-center justify-between pb-4">
        <h3 class="font-semibold text-white">Events</h3>
        <x-forms.button wire:click="refreshEvents">
            <svg class="w-4 h-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M23 4v6h-6M1 20v-6h6" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            Refresh
        </x-forms.button>
    </div>

    {{-- Filter Bar --}}
    <div class="flex flex-wrap items-center gap-3 pb-4">
        <div class="flex items-center gap-2">
            <label class="text-xs text-neutral-400">Type:</label>
            <select wire:model.live="filterType"
                class="px-2 py-1 text-sm rounded bg-coolgray-200 border border-coolgray-300 text-white focus:border-blue-500 focus:outline-none">
                <option value="">All Types</option>
                <option value="node">Node</option>
                <option value="service">Service</option>
                <option value="task">Task</option>
                <option value="container">Container</option>
                <option value="network">Network</option>
                <option value="secret">Secret</option>
                <option value="config">Config</option>
            </select>
        </div>
        <div class="flex items-center gap-2">
            <label class="text-xs text-neutral-400">Action:</label>
            <x-forms.input
                wire:model.live.debounce.300ms="filterAction"
                placeholder="e.g., create, update, remove"
                class="!py-1 !text-sm !w-48"
            />
        </div>
        @if ($filterType || $filterAction)
            <button wire:click="clearFilters" class="text-xs text-neutral-400 hover:text-white transition-colors">
                Clear Filters
            </button>
        @endif
    </div>

    @if (count($events) === 0)
        <div class="p-12 text-center rounded-lg bg-coolgray-200">
            <svg class="w-10 h-10 mx-auto text-neutral-500 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <p class="text-neutral-400">
                @if ($filterType || $filterAction)
                    No events match the current filters.
                @else
                    No events recorded yet. Events will appear as cluster activity occurs.
                @endif
            </p>
        </div>
    @else
        <div class="rounded-lg bg-coolgray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-coolgray-300">
                            <th class="px-4 py-2.5 text-left text-xs font-medium text-neutral-400 uppercase tracking-wider">Time</th>
                            <th class="px-4 py-2.5 text-left text-xs font-medium text-neutral-400 uppercase tracking-wider">Type</th>
                            <th class="px-4 py-2.5 text-left text-xs font-medium text-neutral-400 uppercase tracking-wider">Action</th>
                            <th class="px-4 py-2.5 text-left text-xs font-medium text-neutral-400 uppercase tracking-wider">Actor</th>
                            <th class="px-4 py-2.5 text-left text-xs font-medium text-neutral-400 uppercase tracking-wider">Details</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-coolgray-300">
                        @foreach ($events as $event)
                            @php
                                $type = strtolower($event['event_type'] ?? $event['type'] ?? '');
                                $typeBadgeClass = match($type) {
                                    'node' => 'bg-blue-500/20 text-blue-400',
                                    'service' => 'bg-purple-500/20 text-purple-400',
                                    'task' => 'bg-cyan-500/20 text-cyan-400',
                                    'container' => 'bg-green-500/20 text-green-400',
                                    'network' => 'bg-orange-500/20 text-orange-400',
                                    'secret' => 'bg-red-500/20 text-red-400',
                                    'config' => 'bg-yellow-500/20 text-yellow-400',
                                    default => 'bg-neutral-500/20 text-neutral-400',
                                };

                                $eventTime = $event['event_time'] ?? $event['created_at'] ?? null;
                                $attributes = $event['attributes'] ?? [];
                                $attrPreview = is_array($attributes)
                                    ? collect($attributes)->take(3)->map(fn($v, $k) => "$k=$v")->implode(', ')
                                    : '';
                            @endphp
                            <tr class="hover:bg-coolgray-300/20 transition-colors">
                                <td class="px-4 py-2.5 text-neutral-300 whitespace-nowrap text-xs">
                                    @if ($eventTime)
                                        <span title="{{ $eventTime }}">
                                            {{ \Carbon\Carbon::parse($eventTime)->format('M d H:i:s') }}
                                        </span>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="px-4 py-2.5">
                                    <span class="px-2 py-0.5 text-xs rounded {{ $typeBadgeClass }}">
                                        {{ ucfirst($type ?: 'unknown') }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-white font-medium">
                                    {{ $event['action'] ?? '-' }}
                                </td>
                                <td class="px-4 py-2.5 text-neutral-300">
                                    <div class="max-w-[200px] truncate" title="{{ $event['actor_name'] ?? $event['actor_id'] ?? '' }}">
                                        {{ $event['actor_name'] ?? Str::limit($event['actor_id'] ?? '-', 16) }}
                                    </div>
                                </td>
                                <td class="px-4 py-2.5 text-neutral-400 text-xs">
                                    @if ($attrPreview)
                                        <span class="font-mono max-w-[300px] truncate block" title="{{ $attrPreview }}">
                                            {{ Str::limit($attrPreview, 60) }}
                                        </span>
                                    @else
                                        -
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Pagination --}}
        <div class="flex items-center justify-center gap-3 pt-4">
            @if ($page > 1)
                <x-forms.button isSmall wire:click="previousPage">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M15 18l-6-6 6-6" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    Previous
                </x-forms.button>
            @endif
            <span class="text-sm text-neutral-500">Page {{ $page }}</span>
            @if ($hasMorePages)
                <x-forms.button isSmall wire:click="nextPage">
                    Next
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M9 18l6-6-6-6" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </x-forms.button>
            @endif
        </div>
    @endif
</div>
