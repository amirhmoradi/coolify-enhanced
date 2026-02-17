<div>
    <div class="flex flex-col gap-2">
        <div class="flex items-center justify-between">
            <div>
                <h2>Custom Template Sources</h2>
                <div class="subtitle">
                    Add GitHub repositories containing docker-compose templates.
                    Templates will appear in the one-click service list when creating new resources.
                </div>
            </div>
        </div>

        <div class="flex gap-2 mt-2">
            @if(!$showForm)
                <x-forms.button wire:click="showAddForm">+ Add Source</x-forms.button>
            @endif
            @if($sources->where('enabled', true)->count() > 0)
                <x-forms.button wire:click="syncAll" isHighlighted>Sync All</x-forms.button>
            @endif
        </div>
    </div>

    {{-- Add/Edit Form --}}
    @if($showForm)
        <div class="flex flex-col gap-2 p-4 mt-4 rounded border dark:border-coolgray-300 dark:bg-coolgray-100">
            <h3>{{ $editingSourceId ? 'Edit Source' : 'Add New Source' }}</h3>

            <x-forms.input
                id="formName"
                label="Name"
                required
                placeholder="e.g., My Company Templates"
                helper="A display name to identify this template source."
            />

            <x-forms.input
                id="formRepositoryUrl"
                label="Repository URL"
                required
                placeholder="https://github.com/owner/repo"
                helper="GitHub repository URL. Supports github.com and GitHub Enterprise."
            />

            <div class="grid grid-cols-1 gap-2 lg:grid-cols-2">
                <x-forms.input
                    id="formBranch"
                    label="Branch"
                    required
                    placeholder="main"
                    helper="Git branch to fetch templates from."
                />

                <x-forms.input
                    id="formFolderPath"
                    label="Folder Path"
                    required
                    placeholder="templates/compose"
                    helper="Path within the repository where YAML template files are located."
                />
            </div>

            <x-forms.input
                type="password"
                id="formAuthToken"
                label="Auth Token (optional)"
                placeholder="{{ $editingSourceId ? 'Leave empty to keep existing token' : 'GitHub Personal Access Token' }}"
                helper="Required for private repositories. Use a fine-grained PAT with 'Contents: Read' permission."
            />

            <div class="flex gap-2 mt-2">
                <x-forms.button wire:click="saveSource">
                    {{ $editingSourceId ? 'Save Changes' : 'Save & Sync' }}
                </x-forms.button>
                <x-forms.button wire:click="cancelForm" isError>Cancel</x-forms.button>
            </div>
        </div>
    @endif

    {{-- Sources List --}}
    @if($sources->count() > 0)
        <div class="flex flex-col gap-4 mt-4">
            @foreach($sources as $source)
                <div class="p-4 rounded border dark:border-coolgray-300 dark:bg-coolgray-100 {{ !$source->enabled ? 'opacity-60' : '' }}">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <h4 class="truncate">{{ $source->name }}</h4>
                                @if($source->last_sync_status === 'success')
                                    <span class="text-xs px-1.5 py-0.5 rounded bg-green-500/20 text-green-400">synced</span>
                                @elseif($source->last_sync_status === 'failed')
                                    <span class="text-xs px-1.5 py-0.5 rounded bg-red-500/20 text-red-400">failed</span>
                                @elseif($source->last_sync_status === 'syncing')
                                    <span class="text-xs px-1.5 py-0.5 rounded bg-yellow-500/20 text-yellow-400">syncing...</span>
                                @endif
                                @if(!$source->enabled)
                                    <span class="text-xs px-1.5 py-0.5 rounded bg-neutral-500/20 text-neutral-400">disabled</span>
                                @endif
                            </div>
                            <div class="text-sm text-neutral-400 dark:text-neutral-500 truncate mt-1">
                                {{ $source->repository_url }}
                            </div>
                            <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-neutral-500 mt-1">
                                <span>Branch: {{ $source->branch }}</span>
                                <span>Path: {{ $source->folder_path }}</span>
                                <span>{{ $source->template_count }} templates</span>
                                @if($source->last_synced_at)
                                    <span>Last synced: {{ $source->last_synced_at->diffForHumans() }}</span>
                                @else
                                    <span>Never synced</span>
                                @endif
                                @if($source->auth_token)
                                    <span>Authenticated</span>
                                @endif
                            </div>
                            @if($source->last_sync_status === 'failed' && $source->last_sync_error)
                                <div class="text-xs text-red-400 mt-2 p-2 rounded bg-red-500/10">
                                    {{ Str::limit($source->last_sync_error, 200) }}
                                </div>
                            @endif
                        </div>

                        <div class="flex gap-2 shrink-0">
                            <x-forms.button wire:click="syncSource({{ $source->id }})">Sync</x-forms.button>
                            <x-forms.button wire:click="editSource({{ $source->id }})">Edit</x-forms.button>
                            <x-forms.button
                                wire:click="toggleEnabled({{ $source->id }})"
                            >{{ $source->enabled ? 'Disable' : 'Enable' }}</x-forms.button>
                            <x-forms.button
                                wire:click="deleteSource({{ $source->id }})"
                                wire:confirm="Are you sure you want to delete '{{ $source->name }}'? Deployed services from these templates will not be affected."
                                isError
                            >Delete</x-forms.button>
                        </div>
                    </div>

                    {{-- Expandable templates list --}}
                    @if($source->template_count > 0)
                        <div class="mt-2">
                            <button
                                wire:click="toggleExpanded('{{ $source->uuid }}')"
                                class="text-xs text-neutral-400 hover:text-neutral-200 transition-colors"
                            >
                                @if($expandedSourceUuid === $source->uuid)
                                    Hide Templates
                                @else
                                    Show Templates ({{ $source->template_count }})
                                @endif
                            </button>

                            @if($expandedSourceUuid === $source->uuid)
                                <div class="mt-2 p-3 rounded bg-black/20 dark:bg-black/30">
                                    <div class="grid grid-cols-1 gap-1 text-xs md:grid-cols-2 lg:grid-cols-3">
                                        @forelse($this->expandedTemplates as $key => $template)
                                            <div class="flex items-center gap-2 p-1 rounded hover:bg-white/5">
                                                @php
                                                    $logo = data_get($template, 'logo', '');
                                                    $isUrl = str_starts_with($logo, 'http');
                                                @endphp
                                                @if($isUrl)
                                                    <img src="{{ $logo }}" class="w-5 h-5 object-contain rounded" onerror="this.style.display='none'" />
                                                @else
                                                    <div class="w-5 h-5 rounded bg-neutral-700 flex items-center justify-center text-xs">
                                                        {{ strtoupper(substr($key, 0, 1)) }}
                                                    </div>
                                                @endif
                                                <div class="truncate">
                                                    <span class="text-neutral-200">{{ str($key)->headline() }}</span>
                                                    @if($slogan = data_get($template, 'slogan'))
                                                        <span class="text-neutral-500 ml-1">— {{ Str::limit($slogan, 60) }}</span>
                                                    @endif
                                                </div>
                                            </div>
                                        @empty
                                            <div class="text-neutral-500 col-span-full">No templates cached. Try syncing.</div>
                                        @endforelse
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @elseif(!$showForm)
        <div class="mt-4 p-8 text-center rounded border border-dashed dark:border-coolgray-300">
            <div class="text-neutral-400 mb-2">No custom template sources configured.</div>
            <div class="text-sm text-neutral-500">
                Add a GitHub repository containing docker-compose YAML files to extend the one-click service list.
            </div>
        </div>
    @endif
</div>
