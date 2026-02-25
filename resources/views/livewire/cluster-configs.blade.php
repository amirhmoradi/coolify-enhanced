<div>
    <div class="flex items-center justify-between pb-4">
        <div>
            <h3 class="font-semibold text-white">Swarm Configs</h3>
            <p class="text-sm text-neutral-400 mt-0.5">Non-sensitive configuration data that can be mounted into service containers.</p>
        </div>
        <div class="flex items-center gap-2">
            <x-forms.button wire:click="refreshConfigs">
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
                    Create Config
                </x-forms.button>
            @endif
        </div>
    </div>

    {{-- Create Form --}}
    @if ($showCreateForm)
        <div class="p-4 mb-4 rounded-lg bg-coolgray-200 border border-coolgray-300">
            <h4 class="font-medium text-white text-sm pb-3">Create New Config</h4>
            <div class="space-y-3">
                <x-forms.input id="newName" label="Config Name" required
                    placeholder="e.g., nginx-config"
                    helper="Alphanumeric, dots, dashes, and underscores only." />
                <div>
                    <label class="block text-sm font-medium text-neutral-300 pb-1">Config Data</label>
                    <textarea wire:model="newData" rows="8"
                        class="w-full px-3 py-2 text-sm rounded bg-black/50 border border-coolgray-300 text-green-400 font-mono focus:border-blue-500 focus:outline-none"
                        placeholder="Paste your configuration content here..."></textarea>
                </div>
                <div class="flex gap-2 pt-1">
                    <x-forms.button wire:click="createConfig">Create Config</x-forms.button>
                    <button wire:click="$set('showCreateForm', false)" class="px-3 py-1.5 text-sm text-neutral-400 hover:text-white transition-colors">Cancel</button>
                </div>
            </div>
        </div>
    @endif

    {{-- View Config Panel --}}
    @if ($viewingConfigId)
        <div class="p-4 mb-4 rounded-lg bg-coolgray-200 border border-blue-500/30">
            <div class="flex items-center justify-between pb-3">
                <h4 class="font-medium text-white text-sm">
                    Config: <span class="font-mono text-blue-400">{{ $viewingConfigName }}</span>
                </h4>
                <button wire:click="closeViewer" class="text-neutral-400 hover:text-white transition-colors">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M18 6L6 18M6 6l12 12" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
            </div>
            <pre class="p-3 rounded bg-black/50 border border-coolgray-300 text-sm font-mono text-green-400 overflow-x-auto max-h-[400px] overflow-y-auto whitespace-pre-wrap">{{ $viewingConfigData ?? 'Unable to retrieve config data.' }}</pre>
        </div>
    @endif

    {{-- Configs Table --}}
    @if (count($configs) === 0)
        <div class="p-12 text-center rounded-lg bg-coolgray-200">
            <svg class="w-10 h-10 mx-auto text-neutral-500 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <p class="text-neutral-400">No configs in this cluster.</p>
            <p class="text-sm text-neutral-500 mt-1">Configs store non-sensitive data like configuration files that services can mount.</p>
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
                        @foreach ($configs as $config)
                            <tr class="hover:bg-coolgray-300/20 transition-colors">
                                <td class="px-4 py-3 font-medium text-white">
                                    <div class="flex items-center gap-2">
                                        <svg class="w-4 h-4 text-neutral-500 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                        {{ $config['name'] ?? 'Unknown' }}
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-neutral-400 text-xs">
                                    @if ($created = ($config['created_at'] ?? null))
                                        {{ \Carbon\Carbon::parse($created)->format('M d, Y H:i') }}
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    @php $labels = $config['labels'] ?? []; @endphp
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
                                    <div class="flex items-center justify-end gap-1">
                                        <button
                                            wire:click="viewConfig('{{ $config['id'] ?? '' }}')"
                                            class="px-2 py-1 text-xs rounded text-blue-400 hover:bg-blue-500/20 transition-colors">
                                            View
                                        </button>
                                        <button
                                            wire:click="removeConfig('{{ $config['id'] ?? '' }}')"
                                            wire:confirm="Remove config '{{ $config['name'] ?? '' }}'? Services using this config will fail on next restart."
                                            class="px-2 py-1 text-xs rounded text-red-400 hover:bg-red-500/20 transition-colors">
                                            Remove
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
