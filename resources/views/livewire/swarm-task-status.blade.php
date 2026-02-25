<div>
    @if (count($tasks) > 0)
        @php
            $taskCollection = collect($tasks);
            $runningCount = $taskCollection->filter(fn($t) => str_contains(strtolower($t['status'] ?? ''), 'running'))->count();
            $totalCount = $taskCollection->count();
            $allRunning = $runningCount === $totalCount && $totalCount > 0;
        @endphp
        <div class="rounded-lg bg-coolgray-200 p-3" wire:poll.15s="loadTasks">
            <div class="flex items-center gap-2 mb-2">
                <svg class="w-4 h-4 text-neutral-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <span class="text-sm font-medium text-neutral-300">Swarm:</span>
                <span @class([
                    'text-sm font-medium',
                    'text-green-400' => $allRunning,
                    'text-yellow-400' => !$allRunning && $runningCount > 0,
                    'text-red-400' => $runningCount === 0,
                ])>
                    {{ $runningCount }}/{{ $totalCount }} tasks running
                </span>
            </div>

            <div class="space-y-1">
                @foreach ($tasks as $task)
                    @php
                        $ts = strtolower($task['status'] ?? '');
                        $isRunning = str_contains($ts, 'running');
                        $isFailed = str_contains($ts, 'failed') || str_contains($ts, 'rejected');
                        $isPending = str_contains($ts, 'pending') || str_contains($ts, 'preparing') || str_contains($ts, 'starting');
                    @endphp
                    <div class="flex items-center gap-2 text-xs">
                        <span @class([
                            'w-1.5 h-1.5 rounded-full shrink-0',
                            'bg-green-500' => $isRunning,
                            'bg-red-500' => $isFailed,
                            'bg-yellow-500' => $isPending,
                            'bg-neutral-500' => !$isRunning && !$isFailed && !$isPending,
                        ])></span>
                        <span class="text-neutral-400 font-mono truncate" title="{{ $task['id'] ?? '' }}">
                            {{ Str::limit($task['id'] ?? '', 10) }}
                        </span>
                        <span class="text-neutral-500">{{ $task['node'] ?? '-' }}</span>
                        <span @class([
                            'px-1 py-0.5 rounded text-xs ml-auto shrink-0',
                            'bg-green-500/20 text-green-400' => $isRunning,
                            'bg-red-500/20 text-red-400' => $isFailed,
                            'bg-yellow-500/20 text-yellow-400' => $isPending,
                            'bg-neutral-500/20 text-neutral-400' => !$isRunning && !$isFailed && !$isPending,
                        ])>
                            {{ $task['status'] ?? 'unknown' }}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
