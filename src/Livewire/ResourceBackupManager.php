<?php

namespace AmirhMoradi\CoolifyEnhanced\Livewire;

use AmirhMoradi\CoolifyEnhanced\Jobs\ResourceBackupJob;
use AmirhMoradi\CoolifyEnhanced\Models\ScheduledResourceBackup;
use AmirhMoradi\CoolifyEnhanced\Models\ScheduledResourceBackupExecution;
use App\Models\S3Storage;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

/**
 * Livewire component for managing resource backups (volumes, configuration, full).
 *
 * This component can be embedded on any resource page (Application, Service, Database)
 * to manage volume and configuration backups.
 */
class ResourceBackupManager extends Component
{
    public ?int $resourceId = null;

    public ?string $resourceType = null;

    public ?string $resourceName = null;

    // Backup form
    public string $backupType = 'volume';

    public string $frequency = '0 2 * * *';

    public ?string $timezone = null;

    public int $timeout = 3600;

    public bool $saveS3 = true;

    public bool $disableLocalBackup = false;

    public ?int $s3StorageId = null;

    // Retention
    public int $retentionAmountLocally = 0;

    public int $retentionDaysLocally = 0;

    public int $retentionAmountS3 = 0;

    public int $retentionDaysS3 = 0;

    // UI state
    public array $availableS3Storages = [];

    public array $backups = [];

    public array $executions = [];

    public ?int $selectedBackupId = null;

    public string $saveMessage = '';

    public string $saveStatus = '';

    public function mount(int $resourceId, string $resourceType, ?string $resourceName = null): void
    {
        $this->resourceId = $resourceId;
        $this->resourceType = $resourceType;
        $this->resourceName = $resourceName;

        $this->loadBackups();
        $this->loadS3Storages();
    }

    public function loadBackups(): void
    {
        $query = ScheduledResourceBackup::with('latest_log');

        // Show resource-specific backups AND coolify_instance backups for this team
        $query->where(function ($q) {
            $q->where(function ($sub) {
                $sub->where('resource_id', $this->resourceId)
                    ->where('resource_type', $this->resourceType);
            })->orWhere('backup_type', 'coolify_instance');
        });

        // Scope to current team
        try {
            $teamId = auth()->user()->currentTeam()->id;
            $query->where('team_id', $teamId);
        } catch (\Throwable $e) {
            // Fallback: don't scope
        }

        $this->backups = $query->get()
            ->map(fn ($b) => [
                'id' => $b->id,
                'uuid' => $b->uuid,
                'backup_type' => $b->backup_type,
                'frequency' => $b->frequency,
                'enabled' => $b->enabled,
                'save_s3' => $b->save_s3,
                'latest_status' => $b->latest_log?->status ?? 'never',
                'latest_at' => $b->latest_log?->created_at?->diffForHumans() ?? 'Never',
            ])
            ->toArray();
    }

    public function loadS3Storages(): void
    {
        try {
            $this->availableS3Storages = S3Storage::ownedByCurrentTeam(['id', 'name'])
                ->where('is_usable', true)
                ->get()
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])
                ->toArray();
        } catch (\Throwable $e) {
            $this->availableS3Storages = [];
        }
    }

    public function createBackup(): void
    {
        try {
            $teamId = auth()->user()->currentTeam()->id;

            $data = [
                'uuid' => (string) new Cuid2,
                'backup_type' => $this->backupType,
                'frequency' => $this->frequency,
                'timezone' => $this->timezone,
                'timeout' => $this->timeout,
                'save_s3' => $this->saveS3,
                'disable_local_backup' => $this->disableLocalBackup,
                's3_storage_id' => $this->saveS3 ? $this->s3StorageId : null,
                'retention_amount_locally' => $this->retentionAmountLocally,
                'retention_days_locally' => $this->retentionDaysLocally,
                'retention_amount_s3' => $this->retentionAmountS3,
                'retention_days_s3' => $this->retentionDaysS3,
                'team_id' => $teamId,
                'enabled' => true,
            ];

            // coolify_instance doesn't need a resource — it backs up /data/coolify
            if ($this->backupType === 'coolify_instance') {
                $data['resource_type'] = 'coolify_instance';
                $data['resource_id'] = 0;
            } else {
                $data['resource_type'] = $this->resourceType;
                $data['resource_id'] = $this->resourceId;
            }

            $backup = ScheduledResourceBackup::create($data);

            $this->loadBackups();
            $this->saveMessage = 'Backup schedule created.';
            $this->saveStatus = 'success';
            $this->dispatch('success', 'Resource backup schedule created successfully.');
        } catch (\Throwable $e) {
            $this->saveMessage = 'Failed: '.$e->getMessage();
            $this->saveStatus = 'error';
            $this->dispatch('error', 'Failed to create backup schedule.', $e->getMessage());
        }
    }

    public function runBackupNow(int $backupId): void
    {
        try {
            $backup = ScheduledResourceBackup::findOrFail($backupId);
            ResourceBackupJob::dispatch($backup);
            $this->dispatch('success', 'Backup job dispatched. Check executions for progress.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to dispatch backup.', $e->getMessage());
        }
    }

    public function toggleBackup(int $backupId): void
    {
        try {
            $backup = ScheduledResourceBackup::findOrFail($backupId);
            $backup->enabled = ! $backup->enabled;
            $backup->save();
            $this->loadBackups();
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to toggle backup.', $e->getMessage());
        }
    }

    public function deleteBackup(int $backupId): void
    {
        try {
            $backup = ScheduledResourceBackup::findOrFail($backupId);
            $backup->delete();
            $this->loadBackups();
            $this->selectedBackupId = null;
            $this->executions = [];
            $this->dispatch('success', 'Backup schedule deleted.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to delete backup.', $e->getMessage());
        }
    }

    public function selectBackup(int $backupId): void
    {
        $this->selectedBackupId = $backupId;
        $this->loadExecutions();
    }

    public function loadExecutions(): void
    {
        if (! $this->selectedBackupId) {
            $this->executions = [];

            return;
        }

        $this->executions = ScheduledResourceBackupExecution::where('scheduled_resource_backup_id', $this->selectedBackupId)
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get()
            ->map(fn ($e) => [
                'id' => $e->id,
                'uuid' => $e->uuid,
                'backup_type' => $e->backup_type,
                'backup_label' => $e->backup_label,
                'status' => $e->status,
                'size' => $e->size ? $this->formatBytes((int) $e->size) : '-',
                'is_encrypted' => $e->is_encrypted,
                's3_uploaded' => $e->s3_uploaded,
                'created_at' => $e->created_at->diffForHumans(),
                'message' => $e->message,
            ])
            ->toArray();
    }

    public function deleteExecution(int $executionId): void
    {
        try {
            $execution = ScheduledResourceBackupExecution::findOrFail($executionId);

            // Delete local file if exists
            if ($execution->filename && ! $execution->local_storage_deleted) {
                $backup = $execution->scheduledResourceBackup;
                $server = $backup?->server();
                if ($server) {
                    deleteBackupsLocally($execution->filename, $server);
                }
            }

            $execution->delete();
            $this->loadExecutions();
            $this->dispatch('success', 'Execution deleted.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to delete execution.', $e->getMessage());
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes, 1024));

        return round($bytes / pow(1024, $i), 2).' '.$units[$i];
    }

    public function render()
    {
        return view('coolify-enhanced::livewire.resource-backup-manager');
    }
}
