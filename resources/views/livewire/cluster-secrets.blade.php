<div>
    <div class="flex items-center justify-between pb-4">
        <div>
            <h3 class="font-semibold text-white">Swarm Secrets</h3>
            <p class="text-sm text-neutral-400 mt-0.5">Docker secrets are encrypted at rest and only available to services granted explicit access.</p>
        </div>
        <div class="flex items-center gap-2">
            <x-forms.button wire:click="refreshSecrets">
                <svg class="w-4 h-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M23 4v6h-6M1 20v-6h6" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Refresh
            </x-forms.button>
            @if (!$showCreateForm)
                <x-forms.button wire:click="$set('showCreateForm', true)">
                    <svg class="w-4 h-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 5v14M5 12h14" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    Create Secret
                </x-forms.button>
            @endif
        </div>
    </div>

    {{-- Create Form --}}
    @if ($showCreateForm)
        <div class="p-4 mb-4 rounded-lg bg-coolgray-200 border border-coolgray-300">
            <h4 class="font-medium text-white text-sm pb-3">Create New Secret</h4>
            <div class="space-y-3">
                <x-forms.input id="newName" label="Secret Name" required
                    placeholder="e.g., db-password"
                    helper="Alphanumeric, dots, dashes, and underscores only." />
                <div>
                    <label class="block text-sm font-medium text-neutral-300 pb-1">Secret Value</label>
                    <textarea wire:model="newValue" rows="3"
                        class="w-full px-3 py-2 text-sm rounded bg-coolgray-100 border border-coolgray-300 text-white font-mono focus:border-blue-500 focus:outline-none"
                        placeholder="Enter the secret value..." style="-webkit-text-security: disc;"></textarea>
                </div>
                <div class="flex items-center gap-2 p-2 rounded bg-yellow-500/10 border border-yellow-500/20">
                    <svg class="w-4 h-4 text-yellow-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span class="text-xs text-yellow-400">Secret values cannot be viewed after creation. Store them securely.</span>
                </div>
                <div class="flex gap-2 pt-1">
                    <x-forms.button wire:click="createSecret">Create Secret</x-forms.button>
                    <button wire:click="$set('showCreateForm', false)" class="px-3 py-1.5 text-sm text-neutral-400 hover:text-white transition-colors">Cancel</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Secrets Table --}}
    @if (count($secrets) === 0)
        <div class="p-12 text-center rounded-lg bg-coolgray-200">
            <svg class="w-10 h-10 mx-auto text-neutral-500 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <p class="text-neutral-400">No secrets in this cluster.</p>
            <p class="text-sm text-neutral-500 mt-1">Secrets provide encrypted storage for sensitive data like passwords and API keys.</p>
        </div>
    @else
        <div class="rounded-lg bg-coolgray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-coolgray-300">
                            <th class="px-4 py-2.5 text-left text-xs font-medium text-neutral-400 uppercase tracking-wider">Name</th>
                            <th class="px-4 py-2.5 text-left text-xs font-medium text-neutral-400 uppercase tracking-wider">Created</th>
                            <th class="px-4 py-2.5 text-left text-xs font-medium text-neutral-400 uppercase tracking-wider">Labels</th>
                            <th class="px-4 py-2.5 text-right text-xs font-medium text-neutral-400 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-coolgray-300">
                        @foreach ($secrets as $secret)
                            <tr class="hover:bg-coolgray-300/20 transition-colors">
                                <td class="px-4 py-3 font-medium text-white">
                                    <div class="flex items-center gap-2">
                                        <svg class="w-4 h-4 text-neutral-500 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                        {{ $secret['name'] ?? 'Unknown' }}
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-neutral-400 text-xs">
                                    @if ($created = ($secret['created_at'] ?? null))
                                        {{ \Carbon\Carbon::parse($created)->format('M d, Y H:i') }}
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    @php $labels = $secret['labels'] ?? []; @endphp
                                    @if (is_array($labels) && count($labels) > 0)
                                        <div class="flex flex-wrap gap-1">
                                            @foreach (array_slice($labels, 0, 3, true) as $k => $v)
                                                <span class="px-1.5 py-0.5 text-xs rounded bg-coolgray-300 text-neutral-400 font-mono">
                                                    {{ $k }}={{ Str::limit($v, 15) }}
                                                </span>
                                            @endforeach
                                            @if (count($labels) > 3)
                                                <span class="text-xs text-neutral-500">+{{ count($labels) - 3 }} more</span>
                                            @endif
                                        </div>
                                    @else
                                        <span class="text-neutral-500 text-xs">-</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button
                                        wire:click="removeSecret('{{ $secret['id'] ?? '' }}')"
                                        wire:confirm="Remove secret '{{ $secret['name'] ?? '' }}'? Services using this secret will fail on next restart."
                                        class="px-2 py-1 text-xs rounded text-red-400 hover:bg-red-500/20 transition-colors">
                                        Remove
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
