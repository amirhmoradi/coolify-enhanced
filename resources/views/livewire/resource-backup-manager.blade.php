<div>
    <div class="flex flex-col gap-4">
        <div>
            <h2>Resource Backups</h2>
            <div class="subtitle">
                Back up Docker volumes, resource configuration, the full Coolify installation, or everything for {{ $resourceName ?? 'this resource' }}.
            </div>
        </div>

        {{-- Existing Backup Schedules --}}
        @if(count($backups) > 0)
            <div class="flex flex-col gap-2">
                <h3>Scheduled Backups</h3>
                <div class="flex flex-col gap-1">
                    @foreach($backups as $backup)
                        <div class="flex items-center justify-between p-3 rounded border dark:border-coolgray-300 dark:bg-coolgray-100 cursor-pointer
                            {{ $selectedBackupId === $backup['id'] ? 'border-warning' : '' }}"
                            wire:click="selectBackup({{ $backup['id'] }})">
                            <div class="flex items-center gap-3">
                                @php
                                    $typeColors = match($backup['backup_type']) {
                                        'full' => 'bg-purple-500/20 text-purple-400',
                                        'volume' => 'bg-blue-500/20 text-blue-400',
                                        'coolify_instance' => 'bg-orange-500/20 text-orange-400',
                                        default => 'bg-green-500/20 text-green-400',
                                    };
                                    $typeLabel = match($backup['backup_type']) {
                                        'coolify_instance' => 'Instance',
                                        default => ucfirst($backup['backup_type']),
                                    };
                                @endphp
                                <span class="px-2 py-0.5 text-xs rounded {{ $typeColors }}">
                                    {{ $typeLabel }}
                                </span>
                                <span class="text-sm">{{ $backup['frequency'] }}</span>
                                <span class="text-xs opacity-60">Last: {{ $backup['latest_at'] }}</span>
                                @if($backup['latest_status'] === 'success')
                                    <span class="text-xs text-success">OK</span>
                                @elseif($backup['latest_status'] === 'failed')
                                    <span class="text-xs text-error">Failed</span>
                                @elseif($backup['latest_status'] === 'running')
                                    <span class="text-xs text-warning">Running</span>
                                @endif
                            </div>
                            <div class="flex items-center gap-2">
                                <x-forms.button isSmall wire:click.stop="runBackupNow({{ $backup['id'] }})">Run Now</x-forms.button>
                                <x-forms.button isSmall wire:click.stop="toggleBackup({{ $backup['id'] }})">
                                    {{ $backup['enabled'] ? 'Disable' : 'Enable' }}
                                </x-forms.button>
                                <x-forms.button isSmall isError
                                    wire:click.stop="deleteBackup({{ $backup['id'] }})"
                                    wire:confirm="Are you sure you want to delete this backup schedule?">
                                    Delete
                                </x-forms.button>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Executions for selected backup --}}
        @if($selectedBackupId && count($executions) > 0)
            <div class="flex flex-col gap-2">
                <h3>Backup Executions</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b dark:border-coolgray-300">
                                <th class="text-left p-2">Type</th>
                                <th class="text-left p-2">Label</th>
                                <th class="text-left p-2">Status</th>
                                <th class="text-left p-2">Size</th>
                                <th class="text-left p-2">Encrypted</th>
                                <th class="text-left p-2">S3</th>
                                <th class="text-left p-2">When</th>
                                <th class="text-left p-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($executions as $exec)
                                <tr class="border-b dark:border-coolgray-300/50">
                                    <td class="p-2">
                                        {{ $exec['backup_type'] === 'coolify_instance' ? 'Instance' : ucfirst($exec['backup_type']) }}
                                    </td>
                                    <td class="p-2 text-xs opacity-80">{{ $exec['backup_label'] ?? '-' }}</td>
                                    <td class="p-2">
                                        <span class="{{ $exec['status'] === 'success' ? 'text-success' : ($exec['status'] === 'failed' ? 'text-error' : 'text-warning') }}">
                                            {{ ucfirst($exec['status']) }}
                                        </span>
                                    </td>
                                    <td class="p-2">{{ $exec['size'] }}</td>
                                    <td class="p-2">{{ $exec['is_encrypted'] ? 'Yes' : 'No' }}</td>
                                    <td class="p-2">{{ $exec['s3_uploaded'] === true ? 'Yes' : ($exec['s3_uploaded'] === false ? 'No' : '-') }}</td>
                                    <td class="p-2 text-xs">{{ $exec['created_at'] }}</td>
                                    <td class="p-2">
                                        <x-forms.button isSmall isError
                                            wire:click="deleteExecution({{ $exec['id'] }})"
                                            wire:confirm="Delete this backup execution?">
                                            Delete
                                        </x-forms.button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- New Backup Schedule Form --}}
        <div class="flex flex-col gap-2 p-4 rounded border dark:border-coolgray-300 dark:bg-coolgray-100">
            <h3>New Backup Schedule</h3>

            <div class="grid grid-cols-2 gap-2">
                <x-forms.select id="backupType" label="Backup Type">
                    <option value="volume">Volume — Docker volume snapshots (tar.gz)</option>
                    <option value="configuration">Configuration — Settings, env vars, compose files (JSON)</option>
                    <option value="full">Full — Volumes + Configuration</option>
                    <option value="coolify_instance">Coolify Instance — Full /data/coolify installation backup</option>
                </x-forms.select>

                <x-forms.input
                    id="frequency"
                    label="Schedule (Cron)"
                    placeholder="0 2 * * *"
                    helper="Cron expression. Default: daily at 2 AM."
                />
            </div>

            @if($backupType === 'coolify_instance')
                <div class="flex items-start gap-2 p-3 rounded-md text-sm opacity-80">
                    <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a.75.75 0 000 1.5h.253a.25.25 0 01.244.304l-.459 2.066A1.75 1.75 0 0010.747 15H11a.75.75 0 000-1.5h-.253a.25.25 0 01-.244-.304l.459-2.066A1.75 1.75 0 009.253 9H9z" clip-rule="evenodd"/>
                    </svg>
                    <div>
                        Backs up <code>/data/coolify</code> (source, SSH keys, application/service/database configs).
                        <strong>Excludes</strong> <code>/data/coolify/backups</code> and <code>/data/coolify/metrics</code>
                        to avoid duplicating existing backups. Database data should be backed up separately using Coolify's built-in database backup feature.
                    </div>
                </div>
            @endif

            <div class="grid grid-cols-2 gap-2">
                <x-forms.input
                    id="timezone"
                    label="Timezone"
                    placeholder="Server timezone if empty"
                />
                <x-forms.input
                    type="number"
                    id="timeout"
                    label="Timeout (seconds)"
                    placeholder="3600"
                />
            </div>

            <div class="flex gap-4">
                <x-forms.checkbox id="saveS3" label="Upload to S3" />
                @if($saveS3)
                    <x-forms.checkbox id="disableLocalBackup" label="Delete local after S3 upload" />
                @endif
            </div>

            @if($saveS3)
                <x-forms.select id="s3StorageId" label="S3 Storage">
                    <option value="">Select S3 Storage...</option>
                    @foreach($availableS3Storages as $storage)
                        <option value="{{ $storage['id'] }}">{{ $storage['name'] }}</option>
                    @endforeach
                </x-forms.select>
            @endif

            <div class="grid grid-cols-2 gap-4 pt-2">
                <div class="flex flex-col gap-2">
                    <span class="text-sm font-medium">Local Retention</span>
                    <x-forms.input type="number" id="retentionAmountLocally" label="Keep last N backups" placeholder="0 = unlimited" />
                    <x-forms.input type="number" id="retentionDaysLocally" label="Keep for N days" placeholder="0 = unlimited" />
                </div>
                @if($saveS3)
                    <div class="flex flex-col gap-2">
                        <span class="text-sm font-medium">S3 Retention</span>
                        <x-forms.input type="number" id="retentionAmountS3" label="Keep last N backups" placeholder="0 = unlimited" />
                        <x-forms.input type="number" id="retentionDaysS3" label="Keep for N days" placeholder="0 = unlimited" />
                    </div>
                @endif
            </div>

            <div class="flex items-center gap-3 pt-2">
                <x-forms.button wire:click="createBackup">Create Backup Schedule</x-forms.button>

                @if($saveMessage)
                    <span class="text-sm {{ $saveStatus === 'success' ? 'text-success' : 'text-error' }}">
                        {{ $saveMessage }}
                    </span>
                @endif
            </div>
        </div>
    </div>
</div>
