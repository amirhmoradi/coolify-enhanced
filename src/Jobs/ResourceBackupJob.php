<?php

namespace AmirhMoradi\CoolifyEnhanced\Jobs;

use AmirhMoradi\CoolifyEnhanced\Models\ScheduledResourceBackup;
use AmirhMoradi\CoolifyEnhanced\Models\ScheduledResourceBackupExecution;
use AmirhMoradi\CoolifyEnhanced\Services\RcloneService;
use App\Models\S3Storage;
use App\Models\Server;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Visus\Cuid2\Cuid2;

class ResourceBackupJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $maxExceptions = 1;

    public ?Team $team = null;

    public ?Server $server = null;

    public ?S3Storage $s3 = null;

    public string $backup_dir;

    public bool $s3_uploaded = false;

    public ?string $backup_output = null;

    public ?string $error_output = null;

    public $timeout = 3600;

    public function __construct(public ScheduledResourceBackup $backup)
    {
        $this->onQueue('high');
        $this->timeout = $backup->timeout ?? 3600;
    }

    public function handle(): void
    {
        // Safety: if the feature was disabled while this job was queued, exit silently
        if (! config('coolify-enhanced.enabled', false)) {
            Log::info('ResourceBackup: Feature disabled, skipping backup '.$this->backup->uuid);

            return;
        }

        try {
            $this->team = Team::find($this->backup->team_id);
            if (! $this->team) {
                $this->backup->delete();

                return;
            }

            $this->s3 = $this->backup->s3;
            $backupType = $this->backup->backup_type;

            // Coolify instance backup doesn't need a resource or server resolved via resource chain
            if ($backupType === ScheduledResourceBackup::TYPE_COOLIFY_INSTANCE) {
                $this->server = $this->resolveCoolifyServer();
                if (! $this->server) {
                    throw new \Exception('Could not resolve the Coolify server for instance backup');
                }
                $this->backup_dir = backup_dir().'/coolify-instance/'.str($this->team->name)->slug().'-'.$this->team->id;
                $this->backupCoolifyInstance();
                $this->removeOldBackups();

                return;
            }

            $this->server = $this->backup->server();
            if (! $this->server) {
                throw new \Exception('Server not found for resource backup');
            }

            $resource = $this->backup->resource;
            if (! $resource) {
                throw new \Exception('Resource not found for backup');
            }

            $resourceName = str(data_get($resource, 'name', 'unknown'))->slug()->value();
            $resourceUuid = data_get($resource, 'uuid', 'unknown');
            $this->backup_dir = backup_dir().'/resources/'.str($this->team->name)->slug().'-'.$this->team->id.'/'.$resourceName.'-'.$resourceUuid;

            if ($backupType === ScheduledResourceBackup::TYPE_VOLUME) {
                $this->backupVolumes();
            } elseif ($backupType === ScheduledResourceBackup::TYPE_CONFIGURATION) {
                $this->backupConfiguration();
            } elseif ($backupType === ScheduledResourceBackup::TYPE_FULL) {
                $this->backupVolumes();
                $this->backupConfiguration();
            }

            // Clean up old backups
            $this->removeOldBackups();
        } catch (\Throwable $e) {
            Log::channel('scheduled-errors')->error('ResourceBackup failed', [
                'job' => 'ResourceBackupJob',
                'backup_id' => $this->backup->uuid ?? 'unknown',
                'resource' => $this->backup->resource?->name ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Backup Docker volumes for the resource's containers.
     */
    private function backupVolumes(): void
    {
        $containers = $this->backup->getContainerNames();
        if (empty($containers)) {
            Log::info('ResourceBackup: No containers found for volume backup');

            return;
        }

        foreach ($containers as $containerName) {
            try {
                $this->backupContainerVolumes($containerName);
            } catch (\Throwable $e) {
                $this->addToErrorOutput("Volume backup failed for container {$containerName}: ".$e->getMessage());
            }
        }
    }

    /**
     * Backup all volumes for a specific container.
     */
    private function backupContainerVolumes(string $containerName): void
    {
        // Get volume mounts from container inspection
        $volumeJson = instant_remote_process(
            ["docker inspect {$containerName} --format '{{json .Mounts}}' 2>/dev/null || echo '[]'"],
            $this->server,
            false,
            false,
            null,
            disableMultiplexing: true
        );

        $mounts = json_decode(trim($volumeJson), true);
        if (! is_array($mounts) || empty($mounts)) {
            Log::info("ResourceBackup: No volumes found for container {$containerName}");

            return;
        }

        // Filter to only named volumes and bind mounts (skip tmpfs, etc.)
        $backupableMounts = collect($mounts)->filter(function ($mount) {
            return in_array($mount['Type'] ?? '', ['volume', 'bind']);
        });

        if ($backupableMounts->isEmpty()) {
            Log::info("ResourceBackup: No backupable volumes for container {$containerName}");

            return;
        }

        foreach ($backupableMounts as $mount) {
            $this->backupSingleVolume($containerName, $mount);
        }
    }

    /**
     * Backup a single volume/bind mount.
     */
    private function backupSingleVolume(string $containerName, array $mount): void
    {
        $volumeName = $mount['Name'] ?? basename($mount['Source'] ?? 'unknown');
        $source = $mount['Source'] ?? null;
        $destination = $mount['Destination'] ?? null;
        $type = $mount['Type'] ?? 'unknown';

        if (! $source) {
            return;
        }

        // Sanitize volume name for filename
        $safeVolumeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $volumeName);
        $timestamp = Carbon::now()->timestamp;
        $backupFile = "/volume-{$safeVolumeName}-{$timestamp}.tar.gz";
        $backupLocation = $this->backup_dir.$backupFile;

        $logUuid = $this->generateUniqueUuid();

        $execution = ScheduledResourceBackupExecution::create([
            'uuid' => $logUuid,
            'backup_type' => 'volume',
            'backup_label' => "{$containerName}:{$volumeName}",
            'filename' => $backupLocation,
            'scheduled_resource_backup_id' => $this->backup->id,
            'local_storage_deleted' => false,
        ]);

        try {
            $commands = [];
            $commands[] = "mkdir -p {$this->backup_dir}";

            if ($type === 'volume') {
                // Named Docker volume - use a helper container to read it
                $commands[] = "docker run --rm"
                    ." -v {$volumeName}:/source:ro"
                    ." -v {$this->backup_dir}:/backup"
                    ." alpine tar czf /backup{$backupFile} -C /source .";
            } else {
                // Bind mount - tar the source directory directly
                $escapedSource = escapeshellarg($source);
                $commands[] = "tar czf {$backupLocation} -C {$escapedSource} .";
            }

            $output = instant_remote_process($commands, $this->server, true, false, $this->timeout, disableMultiplexing: true);
            $size = $this->calculateSize($backupLocation);

            if ($size <= 0) {
                throw new \Exception('Backup file is empty or was not created');
            }

            // Upload to S3 if enabled
            $isEncrypted = false;
            $s3UploadError = null;
            $localStorageDeleted = false;

            if ($this->backup->save_s3) {
                try {
                    $this->uploadToS3($backupLocation, $this->backup_dir.'/', $logUuid);
                    $isEncrypted = RcloneService::isEncryptionEnabled($this->s3);

                    if ($this->backup->disable_local_backup) {
                        $this->deleteLocalFile($backupLocation);
                        $localStorageDeleted = true;
                    }
                } catch (\Throwable $e) {
                    $s3UploadError = $e->getMessage();
                }
            }

            $message = $output ? trim($output) : null;
            if ($s3UploadError) {
                $message = ($message ? $message."\n\n" : '')."Warning: S3 upload failed: {$s3UploadError}";
            }

            $updateData = [
                'status' => 'success',
                'message' => $message,
                'size' => $size,
                's3_uploaded' => $this->backup->save_s3 ? $this->s3_uploaded : null,
                'local_storage_deleted' => $localStorageDeleted,
            ];

            if ($this->s3_uploaded) {
                $updateData['is_encrypted'] = $isEncrypted;
            }

            $execution->update($updateData);
        } catch (\Throwable $e) {
            $execution->update([
                'status' => 'failed',
                'message' => $this->error_output ?? $e->getMessage(),
                'size' => 0,
                'filename' => null,
                's3_uploaded' => null,
                'finished_at' => Carbon::now(),
            ]);
        } finally {
            $execution->update(['finished_at' => Carbon::now()]);
            $this->s3_uploaded = false;
        }
    }

    /**
     * Backup resource configuration as a JSON export.
     */
    private function backupConfiguration(): void
    {
        $resource = $this->backup->resource;
        if (! $resource) {
            return;
        }

        $timestamp = Carbon::now()->timestamp;
        $backupFile = "/config-{$timestamp}.json";
        $backupLocation = $this->backup_dir.$backupFile;

        $logUuid = $this->generateUniqueUuid();

        $execution = ScheduledResourceBackupExecution::create([
            'uuid' => $logUuid,
            'backup_type' => 'configuration',
            'backup_label' => 'configuration',
            'filename' => $backupLocation,
            'scheduled_resource_backup_id' => $this->backup->id,
            'local_storage_deleted' => false,
        ]);

        try {
            $configData = $this->exportResourceConfig($resource);
            $jsonContent = json_encode($configData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            // Write JSON to server via base64 to avoid escaping issues
            $encoded = base64_encode($jsonContent);
            $commands = [
                "mkdir -p {$this->backup_dir}",
                "echo '{$encoded}' | base64 -d > {$backupLocation}",
            ];

            instant_remote_process($commands, $this->server, true, false, null, disableMultiplexing: true);
            $size = $this->calculateSize($backupLocation);

            if ($size <= 0) {
                throw new \Exception('Configuration backup file is empty');
            }

            // Upload to S3 if enabled
            $isEncrypted = false;
            $s3UploadError = null;
            $localStorageDeleted = false;

            if ($this->backup->save_s3) {
                try {
                    $this->uploadToS3($backupLocation, $this->backup_dir.'/', $logUuid);
                    $isEncrypted = RcloneService::isEncryptionEnabled($this->s3);

                    if ($this->backup->disable_local_backup) {
                        $this->deleteLocalFile($backupLocation);
                        $localStorageDeleted = true;
                    }
                } catch (\Throwable $e) {
                    $s3UploadError = $e->getMessage();
                }
            }

            $message = null;
            if ($s3UploadError) {
                $message = "Warning: S3 upload failed: {$s3UploadError}";
            }

            $updateData = [
                'status' => 'success',
                'message' => $message,
                'size' => $size,
                's3_uploaded' => $this->backup->save_s3 ? $this->s3_uploaded : null,
                'local_storage_deleted' => $localStorageDeleted,
            ];

            if ($this->s3_uploaded) {
                $updateData['is_encrypted'] = $isEncrypted;
            }

            $execution->update($updateData);
        } catch (\Throwable $e) {
            $execution->update([
                'status' => 'failed',
                'message' => $e->getMessage(),
                'size' => 0,
                'filename' => null,
                's3_uploaded' => null,
                'finished_at' => Carbon::now(),
            ]);
        } finally {
            $execution->update(['finished_at' => Carbon::now()]);
            $this->s3_uploaded = false;
        }
    }

    /**
     * Export resource configuration as an associative array.
     */
    private function exportResourceConfig($resource): array
    {
        $config = [
            'backup_meta' => [
                'type' => 'coolify_enhanced_resource_backup',
                'version' => '1.0',
                'created_at' => Carbon::now()->toIso8601String(),
                'resource_type' => get_class($resource),
                'resource_uuid' => $resource->uuid ?? null,
                'resource_name' => $resource->name ?? null,
            ],
            'resource' => $resource->toArray(),
        ];

        // Export environment variables
        if (method_exists($resource, 'environment_variables')) {
            $config['environment_variables'] = $resource->environment_variables()
                ->get()
                ->map(fn ($var) => [
                    'key' => $var->key,
                    'value' => $var->value,
                    'is_preview' => $var->is_preview ?? false,
                    'is_build_time' => $var->is_build_time ?? false,
                    'is_shared' => $var->is_shared ?? false,
                ])
                ->toArray();
        }

        // Export persistent storages / volume configurations
        if (method_exists($resource, 'persistentStorages')) {
            $config['persistent_storages'] = $resource->persistentStorages()
                ->get()
                ->toArray();
        }

        // Export docker-compose content for applications
        if (property_exists($resource, 'docker_compose_raw') || isset($resource->docker_compose_raw)) {
            $config['docker_compose_raw'] = $resource->docker_compose_raw ?? null;
            $config['docker_compose'] = $resource->docker_compose ?? null;
        }

        // Service-specific: export full compose
        if ($resource instanceof \App\Models\Service) {
            $config['docker_compose_raw'] = $resource->docker_compose_raw ?? null;
            $config['docker_compose'] = $resource->docker_compose ?? null;

            // Export service applications and databases config
            $config['service_applications'] = $resource->applications?->toArray() ?? [];
            $config['service_databases'] = $resource->databases?->toArray() ?? [];
        }

        // Export labels
        if (property_exists($resource, 'custom_labels') || isset($resource->custom_labels)) {
            $config['custom_labels'] = $resource->custom_labels ?? null;
        }

        // Export the environment the resource belongs to
        if (method_exists($resource, 'environment') && $resource->environment) {
            $config['environment'] = [
                'name' => $resource->environment->name ?? null,
                'uuid' => $resource->environment->uuid ?? null,
            ];
        }

        return $config;
    }

    /**
     * Backup the entire Coolify installation (/data/coolify/) as a tar.gz.
     *
     * Excludes the backups directory to avoid duplication, and excludes
     * large ephemeral directories (metrics, tmp).
     */
    private function backupCoolifyInstance(): void
    {
        $baseDir = base_configuration_dir(); // /data/coolify
        $timestamp = Carbon::now()->timestamp;
        $backupFile = "/coolify-instance-{$timestamp}.tar.gz";
        $backupLocation = $this->backup_dir.$backupFile;

        $logUuid = $this->generateUniqueUuid();

        $execution = ScheduledResourceBackupExecution::create([
            'uuid' => $logUuid,
            'backup_type' => 'coolify_instance',
            'backup_label' => 'coolify-instance',
            'filename' => $backupLocation,
            'scheduled_resource_backup_id' => $this->backup->id,
            'local_storage_deleted' => false,
        ]);

        try {
            // Exclude backups (avoids backup-of-backups duplication),
            // metrics (ephemeral monitoring data), and common temp dirs
            $excludes = [
                '--exclude=./backups',
                '--exclude=./metrics',
                '--exclude=./tmp',
                '--exclude=./.cache',
            ];
            $excludeStr = implode(' ', $excludes);

            $commands = [
                "mkdir -p {$this->backup_dir}",
                "tar czf {$backupLocation} {$excludeStr} -C {$baseDir} .",
            ];

            $output = instant_remote_process($commands, $this->server, true, false, $this->timeout, disableMultiplexing: true);
            $size = $this->calculateSize($backupLocation);

            if ($size <= 0) {
                throw new \Exception('Coolify instance backup file is empty or was not created');
            }

            // Upload to S3 if enabled
            $isEncrypted = false;
            $s3UploadError = null;
            $localStorageDeleted = false;

            if ($this->backup->save_s3) {
                try {
                    $this->uploadToS3($backupLocation, $this->backup_dir.'/', $logUuid);
                    $isEncrypted = RcloneService::isEncryptionEnabled($this->s3);

                    if ($this->backup->disable_local_backup) {
                        $this->deleteLocalFile($backupLocation);
                        $localStorageDeleted = true;
                    }
                } catch (\Throwable $e) {
                    $s3UploadError = $e->getMessage();
                }
            }

            $message = $output ? trim($output) : null;
            if ($s3UploadError) {
                $message = ($message ? $message."\n\n" : '')."Warning: S3 upload failed: {$s3UploadError}";
            }

            $updateData = [
                'status' => 'success',
                'message' => $message,
                'size' => $size,
                's3_uploaded' => $this->backup->save_s3 ? $this->s3_uploaded : null,
                'local_storage_deleted' => $localStorageDeleted,
            ];

            if ($this->s3_uploaded) {
                $updateData['is_encrypted'] = $isEncrypted;
            }

            $execution->update($updateData);
        } catch (\Throwable $e) {
            $execution->update([
                'status' => 'failed',
                'message' => $e->getMessage(),
                'size' => 0,
                'filename' => null,
                's3_uploaded' => null,
                'finished_at' => Carbon::now(),
            ]);
        } finally {
            $execution->update(['finished_at' => Carbon::now()]);
            $this->s3_uploaded = false;
        }
    }

    /**
     * Resolve the Coolify server (the server running this Coolify instance).
     * For instance backups, we always run on server_id=0 (localhost).
     */
    private function resolveCoolifyServer(): ?Server
    {
        return Server::find(0);
    }

    /**
     * Upload a backup file to S3, using encryption if enabled.
     */
    private function uploadToS3(string $backupLocation, string $backupDir, string $logUuid): void
    {
        if (is_null($this->s3)) {
            return;
        }

        $this->s3->testConnection(shouldSave: true);

        // Determine Docker network from resource
        $network = $this->resolveNetwork();

        if (RcloneService::isEncryptionEnabled($this->s3)) {
            $this->uploadToS3Encrypted($backupLocation, $backupDir, $logUuid, $network);
        } else {
            $this->uploadToS3Unencrypted($backupLocation, $backupDir, $logUuid, $network);
        }

        $this->s3_uploaded = true;
    }

    /**
     * Upload via rclone with crypt overlay (encrypted).
     */
    private function uploadToS3Encrypted(string $backupLocation, string $backupDir, string $logUuid, string $network): void
    {
        $containerName = "rclone-resource-backup-{$logUuid}";

        try {
            // Apply S3 path prefix if configured
            $remotePath = $backupDir;
            if (filled($this->s3->path)) {
                $pathPrefix = trim($this->s3->path, '/');
                $remotePath = '/'.$pathPrefix.$backupDir;
            }

            $commands = RcloneService::buildUploadCommands(
                $this->s3,
                $backupLocation,
                $remotePath,
                $containerName,
                $network
            );

            instant_remote_process($commands, $this->server, true, false, null, disableMultiplexing: true);
        } finally {
            $cleanupCommands = RcloneService::buildCleanupCommands($containerName);
            instant_remote_process($cleanupCommands, $this->server, false, false, null, disableMultiplexing: true);
        }
    }

    /**
     * Upload via MinIO client (unencrypted).
     */
    private function uploadToS3Unencrypted(string $backupLocation, string $backupDir, string $logUuid, string $network): void
    {
        $key = $this->s3->key;
        $secret = $this->s3->secret;
        $bucket = $this->s3->bucket;
        $endpoint = $this->s3->endpoint;

        $helperImage = config('constants.coolify.helper_image');
        $latestVersion = getHelperVersion();
        $fullImageName = "{$helperImage}:{$latestVersion}";

        $containerName = "backup-of-{$logUuid}";

        $containerExists = instant_remote_process(["docker ps -a -q -f name={$containerName}"], $this->server, false, false, null, disableMultiplexing: true);
        if (filled($containerExists)) {
            instant_remote_process(["docker rm -f {$containerName}"], $this->server, false, false, null, disableMultiplexing: true);
        }

        $commands = [];
        $commands[] = "docker run -d --network {$network} --name {$containerName} --rm -v {$backupLocation}:{$backupLocation}:ro {$fullImageName}";

        $escapedEndpoint = escapeshellarg($endpoint);
        $escapedKey = escapeshellarg($key);
        $escapedSecret = escapeshellarg($secret);

        $commands[] = "docker exec {$containerName} mc alias set temporary {$escapedEndpoint} {$escapedKey} {$escapedSecret}";

        // Build S3 path with optional prefix
        $s3Path = $bucket;
        if (filled($this->s3->path)) {
            $pathPrefix = trim($this->s3->path, '/');
            $s3Path .= '/'.$pathPrefix;
        }
        $s3Path .= $backupDir;

        $escapedBackupLocation = escapeshellarg($backupLocation);
        $escapedS3Path = escapeshellarg("temporary/{$s3Path}");

        $commands[] = "docker exec {$containerName} mc cp {$escapedBackupLocation} {$escapedS3Path}";

        try {
            instant_remote_process($commands, $this->server, true, false, null, disableMultiplexing: true);
        } finally {
            instant_remote_process(["docker rm -f {$containerName}"], $this->server, false, false, null, disableMultiplexing: true);
        }
    }

    /**
     * Resolve the Docker network for the resource.
     */
    private function resolveNetwork(): string
    {
        $resource = $this->backup->resource;

        if ($resource instanceof \App\Models\Application && $resource->destination) {
            return $resource->destination->network;
        }

        if ($resource instanceof \App\Models\Service && $resource->destination) {
            return $resource->destination->network;
        }

        // Fallback: try destination relationship
        if (method_exists($resource, 'destination') && $resource->destination) {
            return $resource->destination->network;
        }

        // Last resort: use coolify network
        return 'coolify';
    }

    private function calculateSize(string $path): int
    {
        $size = instant_remote_process(
            ["du -b {$path} | cut -f1"],
            $this->server,
            false,
            false,
            null,
            disableMultiplexing: true
        );

        return (int) trim($size);
    }

    private function deleteLocalFile(string $path): void
    {
        instant_remote_process(
            ["rm -f ".escapeshellarg($path)],
            $this->server,
            throwError: false
        );
    }

    private function generateUniqueUuid(): string
    {
        $attempts = 0;
        do {
            $uuid = (string) new Cuid2;
            $exists = ScheduledResourceBackupExecution::where('uuid', $uuid)->exists();
            $attempts++;
            if ($attempts >= 3 && $exists) {
                throw new \Exception('Unable to generate unique UUID after 3 attempts');
            }
        } while ($exists);

        return $uuid;
    }

    private function addToErrorOutput(string $output): void
    {
        if ($this->error_output) {
            $this->error_output .= "\n".$output;
        } else {
            $this->error_output = $output;
        }
    }

    /**
     * Remove old backup executions based on retention settings.
     */
    private function removeOldBackups(): void
    {
        try {
            // Local retention
            if (! $this->backup->disable_local_backup) {
                $this->deleteOldBackupsLocally();
            }

            // S3 retention
            if ($this->backup->save_s3 && $this->s3) {
                $this->deleteOldBackupsFromS3();
            }

            // Delete fully removed executions
            $this->backup->executions()
                ->where('local_storage_deleted', true)
                ->where('s3_storage_deleted', true)
                ->delete();

            $this->backup->executions()
                ->where('local_storage_deleted', true)
                ->whereNull('s3_uploaded')
                ->delete();
        } catch (\Throwable $e) {
            Log::warning('ResourceBackup: Failed to remove old backups', ['error' => $e->getMessage()]);
        }
    }

    private function deleteOldBackupsLocally(): void
    {
        $successful = $this->backup->executions()
            ->where('status', 'success')
            ->where('local_storage_deleted', false)
            ->orderBy('created_at', 'desc')
            ->get();

        if ($successful->isEmpty()) {
            return;
        }

        $toDelete = $this->getBackupsToDelete(
            $successful,
            $this->backup->retention_amount_locally,
            $this->backup->retention_days_locally,
            $this->backup->retention_max_storage_locally
        );

        if ($toDelete->isEmpty()) {
            return;
        }

        $files = $toDelete->filter(fn ($e) => ! empty($e->filename))->pluck('filename')->all();
        if (! empty($files)) {
            deleteBackupsLocally($files, $this->server);
        }

        $this->backup->executions()
            ->whereIn('id', $toDelete->pluck('id'))
            ->update(['local_storage_deleted' => true]);
    }

    private function deleteOldBackupsFromS3(): void
    {
        $successful = $this->backup->executions()
            ->where('status', 'success')
            ->where('s3_storage_deleted', false)
            ->orderBy('created_at', 'desc')
            ->get();

        if ($successful->isEmpty()) {
            return;
        }

        $toDelete = $this->getBackupsToDelete(
            $successful,
            $this->backup->retention_amount_s3,
            $this->backup->retention_days_s3,
            $this->backup->retention_max_storage_s3
        );

        if ($toDelete->isEmpty()) {
            return;
        }

        $files = $toDelete->filter(fn ($e) => ! empty($e->filename))->pluck('filename')->all();
        if (! empty($files)) {
            deleteBackupsS3($files, $this->s3);
        }

        $this->backup->executions()
            ->whereIn('id', $toDelete->pluck('id'))
            ->update(['s3_storage_deleted' => true]);
    }

    /**
     * Determine which backup executions should be deleted based on retention rules.
     */
    private function getBackupsToDelete($successful, int $amount, int $days, float $maxStorageGB)
    {
        if ($amount === 0 && $days === 0 && $maxStorageGB == 0) {
            return collect();
        }

        $toDelete = collect();

        if ($amount > 0) {
            $toDelete = $toDelete->merge($successful->skip($amount));
        }

        if ($days > 0 && $successful->isNotEmpty()) {
            $oldest = $successful->first()->created_at->clone()->utc()->subDays($days);
            $toDelete = $toDelete->merge(
                $successful->filter(fn ($e) => $e->created_at->utc() < $oldest)
            );
        }

        if ($maxStorageGB > 0) {
            $maxBytes = $maxStorageGB * pow(1024, 3);
            $totalSize = 0;

            foreach ($successful->skip(1) as $exec) {
                $totalSize += (int) $exec->size;
                if ($totalSize > $maxBytes) {
                    $toDelete = $toDelete->merge(
                        $successful->filter(fn ($b) => $b->created_at->utc() <= $exec->created_at->utc())->skip(1)
                    );
                    break;
                }
            }
        }

        return $toDelete->unique('id');
    }

    public function failed(?\Throwable $exception): void
    {
        Log::channel('scheduled-errors')->error('ResourceBackup permanently failed', [
            'job' => 'ResourceBackupJob',
            'backup_id' => $this->backup->uuid ?? 'unknown',
            'error' => $exception?->getMessage(),
        ]);
    }
}
