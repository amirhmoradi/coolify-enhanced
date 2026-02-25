<div x-data="{ copied: false }">
    <div class="p-4 rounded-lg bg-coolgray-200 border border-coolgray-300">
        <div class="flex items-center justify-between pb-3">
            <h4 class="font-semibold text-white">Add Node to Cluster</h4>
            <button wire:click="$dispatch('closeAddNode')" class="text-neutral-400 hover:text-white transition-colors">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M18 6L6 18M6 6l12 12" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </button>
        </div>

        {{-- Step Indicators --}}
        <div class="flex items-center gap-2 pb-4">
            @foreach ([1 => 'Choose Role', 2 => 'Join Command', 3 => 'Done'] as $stepNum => $stepLabel)
                <div class="flex items-center gap-2">
                    <span @class([
                        'w-6 h-6 rounded-full flex items-center justify-center text-xs font-medium',
                        'bg-white text-black' => $step === $stepNum,
                        'bg-green-500/20 text-green-400' => $step > $stepNum,
                        'bg-coolgray-300 text-neutral-500' => $step < $stepNum,
                    ])>
                        @if ($step > $stepNum)
                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                <path d="M20 6L9 17l-5-5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        @else
                            {{ $stepNum }}
                        @endif
                    </span>
                    <span @class([
                        'text-xs',
                        'text-white font-medium' => $step === $stepNum,
                        'text-green-400' => $step > $stepNum,
                        'text-neutral-500' => $step < $stepNum,
                    ])>{{ $stepLabel }}</span>
                </div>
                @if ($stepNum < 3)
                    <div @class([
                        'flex-1 h-px',
                        'bg-green-500/40' => $step > $stepNum,
                        'bg-coolgray-300' => $step <= $stepNum,
                    ])></div>
                @endif
            @endforeach
        </div>

        {{-- Step 1: Choose Role --}}
        @if ($step === 1)
            <div class="space-y-3">
                <p class="text-sm text-neutral-400">Choose the role for the new node:</p>
                <div class="flex gap-3">
                    <label @class([
                        'flex-1 p-4 rounded-lg border-2 cursor-pointer transition-colors',
                        'border-blue-500 bg-blue-500/10' => $role === 'worker',
                        'border-coolgray-300 hover:border-neutral-500' => $role !== 'worker',
                    ])>
                        <input type="radio" wire:model="role" value="worker" class="sr-only" />
                        <div class="flex items-center gap-2 mb-1">
                            <span class="px-2 py-0.5 text-xs rounded bg-neutral-500/20 text-neutral-400">Worker</span>
                        </div>
                        <p class="text-sm text-neutral-300">Runs tasks and services. Cannot manage the cluster.</p>
                    </label>
                    <label @class([
                        'flex-1 p-4 rounded-lg border-2 cursor-pointer transition-colors',
                        'border-blue-500 bg-blue-500/10' => $role === 'manager',
                        'border-coolgray-300 hover:border-neutral-500' => $role !== 'manager',
                    ])>
                        <input type="radio" wire:model="role" value="manager" class="sr-only" />
                        <div class="flex items-center gap-2 mb-1">
                            <span class="px-2 py-0.5 text-xs rounded bg-blue-500/20 text-blue-400">Manager</span>
                        </div>
                        <p class="text-sm text-neutral-300">Participates in Raft consensus. Manages cluster state and scheduling.</p>
                    </label>
                </div>
                <div class="pt-2">
                    <x-forms.button wire:click="generateJoinCommand">
                        Generate Join Command
                        <svg class="w-4 h-4 ml-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M5 12h14M12 5l7 7-7 7" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </x-forms.button>
                </div>
            </div>
        @endif

        {{-- Step 2: Join Command --}}
        @if ($step === 2)
            <div class="space-y-4">
                <p class="text-sm text-neutral-400">
                    Run the following command on the server you want to add as a <strong class="text-white">{{ $role }}</strong>:
                </p>

                <div class="relative">
                    <pre class="p-4 rounded bg-black/50 border border-coolgray-300 text-sm font-mono text-green-400 overflow-x-auto whitespace-pre-wrap break-all">{{ $joinCommand }}</pre>
                    <button
                        @click="navigator.clipboard.writeText(@js($joinCommand)); copied = true; setTimeout(() => copied = false, 2000)"
                        class="absolute top-2 right-2 px-2 py-1 text-xs rounded bg-coolgray-300 text-neutral-300 hover:text-white transition-colors">
                        <span x-show="!copied">Copy</span>
                        <span x-show="copied" x-cloak class="text-green-400">Copied!</span>
                    </button>
                </div>

                <div class="flex items-center gap-3 p-3 rounded bg-coolgray-100 border border-coolgray-300">
                    <div wire:loading wire:target="checkNewNode" class="flex items-center gap-2">
                        <svg class="w-4 h-4 animate-spin text-blue-400" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/>
                            <path d="M4 12a8 8 0 018-8" stroke="currentColor" stroke-width="3" stroke-linecap="round" class="opacity-75"/>
                        </svg>
                        <span class="text-sm text-blue-400">Checking for new nodes...</span>
                    </div>
                    <div wire:loading.remove wire:target="checkNewNode" class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-neutral-500 animate-pulse" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M12 6v6l4 2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <span class="text-sm text-neutral-400">Waiting for node to join...</span>
                    </div>
                </div>

                <div class="flex gap-2">
                    <x-forms.button wire:click="checkNewNode">
                        Check Now
                    </x-forms.button>
                    <button wire:click="$set('step', 1)" class="px-3 py-1.5 text-sm text-neutral-400 hover:text-white transition-colors">
                        Back
                    </button>
                </div>
            </div>
        @endif

        {{-- Step 3: Success --}}
        @if ($step === 3)
            <div class="text-center py-4">
                <div class="w-12 h-12 mx-auto mb-3 rounded-full bg-green-500/20 flex items-center justify-center">
                    <svg class="w-6 h-6 text-green-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M20 6L9 17l-5-5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <h4 class="text-lg font-semibold text-white mb-1">Node Joined!</h4>
                <p class="text-sm text-neutral-400 mb-4">
                    The new {{ $role }} node has successfully joined the cluster.
                </p>
                <x-forms.button wire:click="$dispatch('closeAddNode')">Done</x-forms.button>
            </div>
        @endif
    </div>
</div>
