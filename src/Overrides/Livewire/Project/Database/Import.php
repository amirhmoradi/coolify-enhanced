<?php

// =============================================================================
// OVERLAY: Modified version of Coolify's Import Livewire component
// =============================================================================
// This file replaces app/Livewire/Project/Database/Import.php in the Coolify
// container. Changes from the original are marked with [ENCRYPTION OVERLAY].
//
// Modifications:
//   1. Added import for RcloneService
//   2. Modified restoreFromS3() to use rclone when encryption is enabled
//   3. Added isEncrypted property for user to indicate encrypted backups
// =============================================================================

namespace App\Livewire\Project\Database;

use AmirhMoradi\CoolifyEnhanced\Services\RcloneService; // [ENCRYPTION OVERLAY]
use App\Models\S3Storage;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Import extends Component
{
    use AuthorizesRequests;

    private function validateBucketName(string $bucket): bool
    {
        return preg_match('/^[a-zA-Z0-9.\-_]+$/', $bucket) === 1;
    }

    private function validateS3Path(string $path): bool
    {
        if (empty($path)) {
            return false;
        }

        $dangerousPatterns = [
            '..', '$(', '`', '|', ';', '&', '>', '<',
            "\n", "\r", "\0", "'", '"', '\\',
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (str_contains($path, $pattern)) {
                return false;
            }
        }

        return preg_match('/^[a-zA-Z0-9.\-_\/\s+@=]+$/', $path) === 1;
    }

    private function validateServerPath(string $path): bool
    {
        if (! str_starts_with($path, '/')) {
            return false;
        }

        $dangerousPatterns = [
            '..', '$(', '`', '|', ';', '&', '>', '<',
            "\n", "\r", "\0", "'", '"', '\\',
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (str_contains($path, $pattern)) {
                return false;
            }
        }

        return preg_match('/^[a-zA-Z0-9.\-_\/\s]+$/', $path) === 1;
    }

    public bool $unsupported = false;

    public ?int $resourceId = null;

    public ?string $resourceType = null;

    public ?int $serverId = null;

    public string $resourceUuid = '';

    public string $resourceStatus = '';

    public string $resourceDbType = '';

    public array $parameters = [];

    public array $containers = [];

    public bool $scpInProgress = false;

    public bool $importRunning = false;

    public ?string $filename = null;

    public ?string $filesize = null;

    public bool $isUploading = false;

    public int $progress = 0;

    public bool $error = false;

    public string $container;

    public array $importCommands = [];

    public bool $dumpAll = false;

    public string $restoreCommandText = '';

    public string $customLocation = '';

    public ?int $activityId = null;

    public string $postgresqlRestoreCommand = 'pg_restore -U $POSTGRES_USER -d ${POSTGRES_DB:-${POSTGRES_USER:-postgres}}';

    public string $mysqlRestoreCommand = 'mysql -u $MYSQL_USER -p$MYSQL_PASSWORD $MYSQL_DATABASE';

    public string $mariadbRestoreCommand = 'mariadb -u $MARIADB_USER -p$MARIADB_PASSWORD $MARIADB_DATABASE';

    public string $mongodbRestoreCommand = 'mongorestore --authenticationDatabase=admin --username $MONGO_INITDB_ROOT_USERNAME --password $MONGO_INITDB_ROOT_PASSWORD --uri mongodb://localhost:27017 --gzip --archive=';

    // S3 Restore properties
    public array $availableS3Storages = [];

    public ?int $s3StorageId = null;

    public string $s3Path = '';

    public ?int $s3FileSize = null;

    // [ENCRYPTION OVERLAY] Track whether the selected S3 storage has encryption
    public bool $s3EncryptionEnabled = false;

    #[Computed]
    public function resource()
    {
        if ($this->resourceId === null || $this->resourceType === null) {
            return null;
        }

        return $this->resourceType::find($this->resourceId);
    }

    #[Computed]
    public function server()
    {
        if ($this->serverId === null) {
            return null;
        }

        return Server::find($this->serverId);
    }

    public function getListeners()
    {
        $userId = Auth::id();

        return [
            "echo-private:user.{$userId},DatabaseStatusChanged" => '$refresh',
            'slideOverClosed' => 'resetActivityId',
        ];
    }

    public function resetActivityId()
    {
        $this->activityId = null;
    }

    public function mount()
    {
        $this->parameters = get_route_parameters();
        $this->getContainers();
        $this->loadAvailableS3Storages();
    }

    public function updatedDumpAll($value)
    {
        $morphClass = $this->resource->getMorphClass();

        if ($morphClass === \App\Models\ServiceDatabase::class) {
            $dbType = $this->resource->databaseType();
            if (str_contains($dbType, 'mysql')) {
                $morphClass = 'mysql';
            } elseif (str_contains($dbType, 'mariadb')) {
                $morphClass = 'mariadb';
            } elseif (str_contains($dbType, 'postgres')) {
                $morphClass = 'postgresql';
            }
        }

        switch ($morphClass) {
            case \App\Models\StandaloneMariadb::class:
            case 'mariadb':
                if ($value === true) {
                    $this->mariadbRestoreCommand = <<<'EOD'
for pid in $(mariadb -u root -p$MARIADB_ROOT_PASSWORD -N -e "SELECT id FROM information_schema.processlist WHERE user != 'root';"); do
  mariadb -u root -p$MARIADB_ROOT_PASSWORD -e "KILL $pid" 2>/dev/null || true
done && \
mariadb -u root -p$MARIADB_ROOT_PASSWORD -N -e "SELECT CONCAT('DROP DATABASE IF EXISTS \`',schema_name,'\`;') FROM information_schema.schemata WHERE schema_name NOT IN ('information_schema','mysql','performance_schema','sys');" | mariadb -u root -p$MARIADB_ROOT_PASSWORD && \
mariadb -u root -p$MARIADB_ROOT_PASSWORD -e "CREATE DATABASE IF NOT EXISTS \`${MARIADB_DATABASE:-default}\`;" && \
(gunzip -cf $tmpPath 2>/dev/null || cat $tmpPath) | sed -e '/^CREATE DATABASE/d' -e '/^USE \`mysql\`/d' | mariadb -u root -p$MARIADB_ROOT_PASSWORD ${MARIADB_DATABASE:-default}
EOD;
                    $this->restoreCommandText = $this->mariadbRestoreCommand.' && (gunzip -cf <temp_backup_file> 2>/dev/null || cat <temp_backup_file>) | mariadb -u root -p$MARIADB_ROOT_PASSWORD ${MARIADB_DATABASE:-default}';
                } else {
                    $this->mariadbRestoreCommand = 'mariadb -u $MARIADB_USER -p$MARIADB_PASSWORD $MARIADB_DATABASE';
                }
                break;
            case \App\Models\StandaloneMysql::class:
            case 'mysql':
                if ($value === true) {
                    $this->mysqlRestoreCommand = <<<'EOD'
for pid in $(mysql -u root -p$MYSQL_ROOT_PASSWORD -N -e "SELECT id FROM information_schema.processlist WHERE user != 'root';"); do
  mysql -u root -p$MYSQL_ROOT_PASSWORD -e "KILL $pid" 2>/dev/null || true
done && \
mysql -u root -p$MYSQL_ROOT_PASSWORD -N -e "SELECT CONCAT('DROP DATABASE IF EXISTS \`',schema_name,'\`;') FROM information_schema.schemata WHERE schema_name NOT IN ('information_schema','mysql','performance_schema','sys');" | mysql -u root -p$MYSQL_ROOT_PASSWORD && \
mysql -u root -p$MYSQL_ROOT_PASSWORD -e "CREATE DATABASE IF NOT EXISTS \`${MYSQL_DATABASE:-default}\`;" && \
(gunzip -cf $tmpPath 2>/dev/null || cat $tmpPath) | sed -e '/^CREATE DATABASE/d' -e '/^USE \`mysql\`/d' | mysql -u root -p$MYSQL_ROOT_PASSWORD ${MYSQL_DATABASE:-default}
EOD;
                    $this->restoreCommandText = $this->mysqlRestoreCommand.' && (gunzip -cf <temp_backup_file> 2>/dev/null || cat <temp_backup_file>) | mysql -u root -p$MYSQL_ROOT_PASSWORD ${MYSQL_DATABASE:-default}';
                } else {
                    $this->mysqlRestoreCommand = 'mysql -u $MYSQL_USER -p$MYSQL_PASSWORD $MYSQL_DATABASE';
                }
                break;
            case \App\Models\StandalonePostgresql::class:
            case 'postgresql':
                if ($value === true) {
                    $this->postgresqlRestoreCommand = <<<'EOD'
psql -U ${POSTGRES_USER} -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname IS NOT NULL AND pid <> pg_backend_pid()" && \
psql -U ${POSTGRES_USER} -t -c "SELECT datname FROM pg_database WHERE NOT datistemplate" | xargs -I {} dropdb -U ${POSTGRES_USER} --if-exists {} && \
createdb -U ${POSTGRES_USER} ${POSTGRES_DB:-${POSTGRES_USER:-postgres}}
EOD;
                    $this->restoreCommandText = $this->postgresqlRestoreCommand.' && (gunzip -cf <temp_backup_file> 2>/dev/null || cat <temp_backup_file>) | psql -U ${POSTGRES_USER} -d ${POSTGRES_DB:-${POSTGRES_USER:-postgres}}';
                } else {
                    $this->postgresqlRestoreCommand = 'pg_restore -U ${POSTGRES_USER} -d ${POSTGRES_DB:-${POSTGRES_USER:-postgres}}';
                }
                break;
        }

    }

    public function getContainers()
    {
        $this->containers = [];
        $teamId = data_get(auth()->user()->currentTeam(), 'id');

        $databaseUuid = data_get($this->parameters, 'database_uuid');
        $stackServiceUuid = data_get($this->parameters, 'stack_service_uuid');

        $resource = null;
        if ($databaseUuid) {
            $resource = getResourceByUuid($databaseUuid, $teamId);
            if (is_null($resource)) {
                abort(404);
            }
        } elseif ($stackServiceUuid) {
            $serviceUuid = data_get($this->parameters, 'service_uuid');
            $service = Service::whereUuid($serviceUuid)->first();
            if (! $service) {
                abort(404);
            }
            $resource = $service->databases()->whereUuid($stackServiceUuid)->first();
            if (is_null($resource)) {
                abort(404);
            }
        } else {
            abort(404);
        }

        $this->authorize('view', $resource);

        $this->resourceId = $resource->id;
        $this->resourceType = get_class($resource);
        $this->resourceStatus = $resource->status ?? '';

        if ($resource->getMorphClass() === \App\Models\ServiceDatabase::class) {
            $server = $resource->service?->server;
            if (! $server) {
                abort(404, 'Server not found for this service database.');
            }
            $this->serverId = $server->id;
            $this->container = $resource->name.'-'.$resource->service->uuid;
            $this->resourceUuid = $resource->uuid;

            $dbType = $resource->databaseType();
            if (str_contains($dbType, 'postgres')) {
                $this->resourceDbType = 'standalone-postgresql';
            } elseif (str_contains($dbType, 'mysql')) {
                $this->resourceDbType = 'standalone-mysql';
            } elseif (str_contains($dbType, 'mariadb')) {
                $this->resourceDbType = 'standalone-mariadb';
            } elseif (str_contains($dbType, 'mongo')) {
                $this->resourceDbType = 'standalone-mongodb';
            } else {
                $this->resourceDbType = $dbType;
            }
        } else {
            $server = $resource->destination?->server;
            if (! $server) {
                abort(404, 'Server not found for this database.');
            }
            $this->serverId = $server->id;
            $this->container = $resource->uuid;
            $this->resourceUuid = $resource->uuid;
            $this->resourceDbType = $resource->type();
        }

        if (str($resource->status)->startsWith('running')) {
            $this->containers[] = $this->container;
        }

        if (
            $resource->getMorphClass() === \App\Models\StandaloneRedis::class ||
            $resource->getMorphClass() === \App\Models\StandaloneKeydb::class ||
            $resource->getMorphClass() === \App\Models\StandaloneDragonfly::class ||
            $resource->getMorphClass() === \App\Models\StandaloneClickhouse::class
        ) {
            $this->unsupported = true;
        }

        if ($resource->getMorphClass() === \App\Models\ServiceDatabase::class) {
            $dbType = $resource->databaseType();
            if (str_contains($dbType, 'redis') || str_contains($dbType, 'keydb') ||
                str_contains($dbType, 'dragonfly') || str_contains($dbType, 'clickhouse')) {
                $this->unsupported = true;
            }
        }
    }

    public function checkFile()
    {
        if (filled($this->customLocation)) {
            if (! $this->validateServerPath($this->customLocation)) {
                $this->dispatch('error', 'Invalid file path. Path must be absolute and contain only safe characters (alphanumerics, dots, dashes, underscores, slashes).');

                return;
            }

            if (! $this->server) {
                $this->dispatch('error', 'Server not found. Please refresh the page.');

                return;
            }

            try {
                $escapedPath = escapeshellarg($this->customLocation);
                $result = instant_remote_process(["ls -l {$escapedPath}"], $this->server, throwError: false);
                if (blank($result)) {
                    $this->dispatch('error', 'The file does not exist or has been deleted.');

                    return;
                }
                $this->filename = $this->customLocation;
                $this->dispatch('success', 'The file exists.');
            } catch (\Throwable $e) {
                return handleError($e, $this);
            }
        }
    }

    public function runImport()
    {
        $this->authorize('update', $this->resource);

        if ($this->filename === '') {
            $this->dispatch('error', 'Please select a file to import.');

            return;
        }

        if (! $this->server) {
            $this->dispatch('error', 'Server not found. Please refresh the page.');

            return;
        }

        try {
            $this->importRunning = true;
            $this->importCommands = [];
            $backupFileName = "upload/{$this->resourceUuid}/restore";

            if (Storage::exists($backupFileName)) {
                $path = Storage::path($backupFileName);
                $tmpPath = '/tmp/'.basename($backupFileName).'_'.$this->resourceUuid;
                instant_scp($path, $tmpPath, $this->server);
                Storage::delete($backupFileName);
                $this->importCommands[] = "docker cp {$tmpPath} {$this->container}:{$tmpPath}";
            } elseif (filled($this->customLocation)) {
                if (! $this->validateServerPath($this->customLocation)) {
                    $this->dispatch('error', 'Invalid file path. Path must be absolute and contain only safe characters.');

                    return;
                }
                $tmpPath = '/tmp/restore_'.$this->resourceUuid;
                $escapedCustomLocation = escapeshellarg($this->customLocation);
                $this->importCommands[] = "docker cp {$escapedCustomLocation} {$this->container}:{$tmpPath}";
            } else {
                $this->dispatch('error', 'The file does not exist or has been deleted.');

                return;
            }

            $scriptPath = "/tmp/restore_{$this->resourceUuid}.sh";

            $restoreCommand = $this->buildRestoreCommand($tmpPath);

            $restoreCommandBase64 = base64_encode($restoreCommand);
            $this->importCommands[] = "echo \"{$restoreCommandBase64}\" | base64 -d > {$scriptPath}";
            $this->importCommands[] = "chmod +x {$scriptPath}";
            $this->importCommands[] = "docker cp {$scriptPath} {$this->container}:{$scriptPath}";

            $this->importCommands[] = "docker exec {$this->container} sh -c '{$scriptPath}'";
            $this->importCommands[] = "docker exec {$this->container} sh -c 'echo \"Import finished with exit code $?\"'";

            if (! empty($this->importCommands)) {
                $activity = remote_process($this->importCommands, $this->server, ignore_errors: true, callEventOnFinish: 'RestoreJobFinished', callEventData: [
                    'scriptPath' => $scriptPath,
                    'tmpPath' => $tmpPath,
                    'container' => $this->container,
                    'serverId' => $this->server->id,
                ]);

                $this->activityId = $activity->id;

                $this->dispatch('activityMonitor', $activity->id);
                $this->dispatch('databaserestore');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->filename = null;
            $this->importCommands = [];
        }
    }

    public function loadAvailableS3Storages()
    {
        try {
            $this->availableS3Storages = S3Storage::ownedByCurrentTeam(['id', 'name', 'description'])
                ->where('is_usable', true)
                ->get()
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'description' => $s->description,
                    // [ENCRYPTION OVERLAY] Include encryption status
                    'encryption_enabled' => RcloneService::isEncryptionEnabled($s),
                ])
                ->toArray();
        } catch (\Throwable $e) {
            $this->availableS3Storages = [];
        }
    }

    public function updatedS3Path($value)
    {
        $this->s3FileSize = null;

        if ($value !== null && $value !== '') {
            $this->s3Path = str($value)->trim()->start('/')->value();
        }
    }

    public function updatedS3StorageId()
    {
        $this->s3FileSize = null;

        // [ENCRYPTION OVERLAY] Update encryption status when storage selection changes
        $this->s3EncryptionEnabled = false;
        if ($this->s3StorageId) {
            foreach ($this->availableS3Storages as $storage) {
                if ($storage['id'] == $this->s3StorageId) {
                    $this->s3EncryptionEnabled = $storage['encryption_enabled'] ?? false;
                    break;
                }
            }
        }
    }

    public function checkS3File()
    {
        if (! $this->s3StorageId) {
            $this->dispatch('error', 'Please select an S3 storage.');

            return;
        }

        if (blank($this->s3Path)) {
            $this->dispatch('error', 'Please provide an S3 path.');

            return;
        }

        $cleanPath = ltrim($this->s3Path, '/');

        if (! $this->validateS3Path($cleanPath)) {
            $this->dispatch('error', 'Invalid S3 path. Path must contain only safe characters (alphanumerics, dots, dashes, underscores, slashes).');

            return;
        }

        try {
            $s3Storage = S3Storage::ownedByCurrentTeam()->findOrFail($this->s3StorageId);

            if (! $this->validateBucketName($s3Storage->bucket)) {
                $this->dispatch('error', 'Invalid S3 bucket name. Bucket name must contain only alphanumerics, dots, dashes, and underscores.');

                return;
            }

            $s3Storage->testConnection();

            // [ENCRYPTION OVERLAY] For encrypted storage with filename encryption,
            // we can't use the S3 driver to check the file since the filename on S3
            // is encrypted. We'll skip the pre-check and trust the user's path.
            if (RcloneService::isEncryptionEnabled($s3Storage)
                && data_get($s3Storage, 'filename_encryption', 'off') !== 'off') {
                $this->s3FileSize = -1; // Signal that we can't determine size
                $this->dispatch('success', 'S3 connection verified. File will be decrypted during restore (size cannot be determined for encrypted filenames).');

                return;
            }

            $disk = Storage::build([
                'driver' => 's3',
                'region' => $s3Storage->region,
                'key' => $s3Storage->key,
                'secret' => $s3Storage->secret,
                'bucket' => $s3Storage->bucket,
                'endpoint' => $s3Storage->endpoint,
                'use_path_style_endpoint' => true,
            ]);

            // [PATH PREFIX OVERLAY] Apply path prefix for file check
            $s3CheckPath = $cleanPath;
            if (filled($s3Storage->path)) {
                $pathPrefix = trim($s3Storage->path, '/');
                $s3CheckPath = $pathPrefix.'/'.$cleanPath;
            }

            if (! $disk->exists($s3CheckPath)) {
                $this->dispatch('error', 'File not found in S3. Please check the path.');

                return;
            }

            $this->s3FileSize = $disk->size($s3CheckPath);

            $message = 'File found in S3. Size: '.formatBytes($this->s3FileSize);
            if ($this->s3EncryptionEnabled) {
                $message .= ' (encrypted - will be decrypted during restore)';
            }
            $this->dispatch('success', $message);
        } catch (\Throwable $e) {
            $this->s3FileSize = null;

            return handleError($e, $this);
        }
    }

    // =========================================================================
    // [ENCRYPTION OVERLAY] Modified restoreFromS3() method
    // =========================================================================
    // When encryption is enabled on the selected S3 storage, uses rclone with
    // a crypt overlay to download and decrypt the backup. When encryption is
    // not enabled, falls back to the original mc-based download.
    // =========================================================================
    public function restoreFromS3()
    {
        $this->authorize('update', $this->resource);

        if (! $this->s3StorageId || blank($this->s3Path)) {
            $this->dispatch('error', 'Please select S3 storage and provide a path first.');

            return;
        }

        if (is_null($this->s3FileSize)) {
            $this->dispatch('error', 'Please check the file first by clicking "Check File".');

            return;
        }

        if (! $this->server) {
            $this->dispatch('error', 'Server not found. Please refresh the page.');

            return;
        }

        try {
            $this->importRunning = true;

            $s3Storage = S3Storage::ownedByCurrentTeam()->findOrFail($this->s3StorageId);

            // [ENCRYPTION OVERLAY] Branch based on encryption status
            if (RcloneService::isEncryptionEnabled($s3Storage)) {
                $this->restoreFromS3Encrypted($s3Storage);
            } else {
                $this->restoreFromS3Unencrypted($s3Storage);
            }
        } catch (\Throwable $e) {
            $this->importRunning = false;

            return handleError($e, $this);
        }
    }

    /**
     * [ENCRYPTION OVERLAY] Restore from encrypted S3 backup using rclone crypt.
     */
    private function restoreFromS3Encrypted(S3Storage $s3Storage): void
    {
        $bucket = $s3Storage->bucket;
        $cleanPath = ltrim($this->s3Path, '/');

        if (! $this->validateBucketName($bucket) || ! $this->validateS3Path($cleanPath)) {
            $this->dispatch('error', 'Invalid S3 bucket name or path.');

            return;
        }

        // Get database destination network
        if ($this->resource->getMorphClass() === \App\Models\ServiceDatabase::class) {
            $destinationNetwork = $this->resource->service->destination->network ?? 'coolify';
        } else {
            $destinationNetwork = $this->resource->destination->network ?? 'coolify';
        }

        $rcloneContainerName = "rclone-restore-{$this->resourceUuid}";
        $localDownloadPath = "/tmp/rclone-download-{$this->resourceUuid}-".basename($cleanPath);
        $containerTmpPath = "/tmp/restore_{$this->resourceUuid}-".basename($cleanPath);
        $scriptPath = "/tmp/restore_{$this->resourceUuid}.sh";

        $commands = [];

        // 1. Clean up any previous run
        $commands[] = "docker rm -f {$rcloneContainerName} 2>/dev/null || true";
        $commands[] = "rm -f {$localDownloadPath} 2>/dev/null || true";
        $commands[] = "docker exec {$this->container} rm -f {$containerTmpPath} {$scriptPath} 2>/dev/null || true";

        // [PATH PREFIX OVERLAY] Apply path prefix for rclone download
        $rclonePath = $cleanPath;
        if (filled($s3Storage->path)) {
            $pathPrefix = trim($s3Storage->path, '/');
            $rclonePath = $pathPrefix.'/'.$cleanPath;
        }

        // 2. Build rclone download commands (creates container, downloads via crypt)
        $downloadCommands = RcloneService::buildDownloadCommands(
            $s3Storage,
            $rclonePath,
            $localDownloadPath,
            $rcloneContainerName,
            $destinationNetwork
        );
        $commands = array_merge($commands, $downloadCommands);

        // 3. Copy from rclone container to server, then to database container
        $commands[] = "docker cp {$rcloneContainerName}:{$localDownloadPath} {$localDownloadPath}";
        $commands[] = "docker cp {$localDownloadPath} {$this->container}:{$containerTmpPath}";

        // 4. Cleanup rclone container and temp files
        $cleanupCommands = RcloneService::buildCleanupCommands($rcloneContainerName);
        $commands = array_merge($commands, $cleanupCommands);
        $commands[] = "rm -f {$localDownloadPath} 2>/dev/null || true";

        // 5. Build and execute restore command inside database container
        $restoreCommand = $this->buildRestoreCommand($containerTmpPath);
        $restoreCommandBase64 = base64_encode($restoreCommand);
        $commands[] = "echo \"{$restoreCommandBase64}\" | base64 -d > {$scriptPath}";
        $commands[] = "chmod +x {$scriptPath}";
        $commands[] = "docker cp {$scriptPath} {$this->container}:{$scriptPath}";

        // 6. Execute restore and cleanup
        $commands[] = "docker exec {$this->container} sh -c '{$scriptPath} && rm -f {$containerTmpPath} {$scriptPath}'";
        $commands[] = "docker exec {$this->container} sh -c 'echo \"Import finished with exit code $?\"'";

        $activity = remote_process($commands, $this->server, ignore_errors: true, callEventOnFinish: 'S3RestoreJobFinished', callEventData: [
            'containerName' => $rcloneContainerName,
            'serverTmpPath' => $localDownloadPath,
            'scriptPath' => $scriptPath,
            'containerTmpPath' => $containerTmpPath,
            'container' => $this->container,
            'serverId' => $this->server->id,
        ]);

        $this->activityId = $activity->id;
        $this->dispatch('activityMonitor', $activity->id);
        $this->dispatch('databaserestore');
        $this->dispatch('info', 'Restoring encrypted database backup from S3. Progress will be shown in the activity monitor...');
    }

    /**
     * Original unencrypted S3 restore using MinIO client (mc).
     */
    private function restoreFromS3Unencrypted(S3Storage $s3Storage): void
    {
        $key = $s3Storage->key;
        $secret = $s3Storage->secret;
        $bucket = $s3Storage->bucket;
        $endpoint = $s3Storage->endpoint;

        if (! $this->validateBucketName($bucket)) {
            $this->dispatch('error', 'Invalid S3 bucket name.');

            return;
        }

        $cleanPath = ltrim($this->s3Path, '/');

        if (! $this->validateS3Path($cleanPath)) {
            $this->dispatch('error', 'Invalid S3 path.');

            return;
        }

        $helperImage = config('constants.coolify.helper_image');
        $latestVersion = getHelperVersion();
        $fullImageName = "{$helperImage}:{$latestVersion}";

        if ($this->resource->getMorphClass() === \App\Models\ServiceDatabase::class) {
            $destinationNetwork = $this->resource->service->destination->network ?? 'coolify';
        } else {
            $destinationNetwork = $this->resource->destination->network ?? 'coolify';
        }

        $containerName = "s3-restore-{$this->resourceUuid}";
        $helperTmpPath = '/tmp/'.basename($cleanPath);
        $serverTmpPath = "/tmp/s3-restore-{$this->resourceUuid}-".basename($cleanPath);
        $containerTmpPath = "/tmp/restore_{$this->resourceUuid}-".basename($cleanPath);
        $scriptPath = "/tmp/restore_{$this->resourceUuid}.sh";

        $commands = [];

        $commands[] = "docker rm -f {$containerName} 2>/dev/null || true";
        $commands[] = "rm -f {$serverTmpPath} 2>/dev/null || true";
        $commands[] = "docker exec {$this->container} rm -f {$containerTmpPath} {$scriptPath} 2>/dev/null || true";

        $commands[] = "docker run -d --network {$destinationNetwork} --name {$containerName} {$fullImageName} sleep 3600";

        $escapedEndpoint = escapeshellarg($endpoint);
        $escapedKey = escapeshellarg($key);
        $escapedSecret = escapeshellarg($secret);
        $commands[] = "docker exec {$containerName} mc alias set s3temp {$escapedEndpoint} {$escapedKey} {$escapedSecret}";

        // [PATH PREFIX OVERLAY] Apply path prefix if configured
        $s3FullPath = $cleanPath;
        if (filled($s3Storage->path)) {
            $pathPrefix = trim($s3Storage->path, '/');
            $s3FullPath = $pathPrefix.'/'.$cleanPath;
        }

        $escapedS3Source = escapeshellarg("s3temp/{$bucket}/{$s3FullPath}");
        $commands[] = "docker exec {$containerName} mc stat {$escapedS3Source}";

        $escapedHelperTmpPath = escapeshellarg($helperTmpPath);
        $commands[] = "docker exec {$containerName} mc cp {$escapedS3Source} {$escapedHelperTmpPath}";

        $commands[] = "docker cp {$containerName}:{$helperTmpPath} {$serverTmpPath}";
        $commands[] = "docker cp {$serverTmpPath} {$this->container}:{$containerTmpPath}";

        $commands[] = "docker rm -f {$containerName} 2>/dev/null || true";
        $commands[] = "rm -f {$serverTmpPath} 2>/dev/null || true";

        $restoreCommand = $this->buildRestoreCommand($containerTmpPath);

        $restoreCommandBase64 = base64_encode($restoreCommand);
        $commands[] = "echo \"{$restoreCommandBase64}\" | base64 -d > {$scriptPath}";
        $commands[] = "chmod +x {$scriptPath}";
        $commands[] = "docker cp {$scriptPath} {$this->container}:{$scriptPath}";

        $commands[] = "docker exec {$this->container} sh -c '{$scriptPath} && rm -f {$containerTmpPath} {$scriptPath}'";
        $commands[] = "docker exec {$this->container} sh -c 'echo \"Import finished with exit code $?\"'";

        $activity = remote_process($commands, $this->server, ignore_errors: true, callEventOnFinish: 'S3RestoreJobFinished', callEventData: [
            'containerName' => $containerName,
            'serverTmpPath' => $serverTmpPath,
            'scriptPath' => $scriptPath,
            'containerTmpPath' => $containerTmpPath,
            'container' => $this->container,
            'serverId' => $this->server->id,
        ]);

        $this->activityId = $activity->id;
        $this->dispatch('activityMonitor', $activity->id);
        $this->dispatch('databaserestore');
        $this->dispatch('info', 'Restoring database from S3. Progress will be shown in the activity monitor...');
    }

    public function buildRestoreCommand(string $tmpPath): string
    {
        $morphClass = $this->resource->getMorphClass();

        if ($morphClass === \App\Models\ServiceDatabase::class) {
            $dbType = $this->resource->databaseType();
            if (str_contains($dbType, 'mysql')) {
                $morphClass = 'mysql';
            } elseif (str_contains($dbType, 'mariadb')) {
                $morphClass = 'mariadb';
            } elseif (str_contains($dbType, 'postgres')) {
                $morphClass = 'postgresql';
            } elseif (str_contains($dbType, 'mongo')) {
                $morphClass = 'mongodb';
            }
        }

        switch ($morphClass) {
            case \App\Models\StandaloneMariadb::class:
            case 'mariadb':
                $restoreCommand = $this->mariadbRestoreCommand;
                if ($this->dumpAll) {
                    $restoreCommand .= " && (gunzip -cf {$tmpPath} 2>/dev/null || cat {$tmpPath}) | mariadb -u root -p\$MARIADB_ROOT_PASSWORD \${MARIADB_DATABASE:-default}";
                } else {
                    $restoreCommand .= " < {$tmpPath}";
                }
                break;
            case \App\Models\StandaloneMysql::class:
            case 'mysql':
                $restoreCommand = $this->mysqlRestoreCommand;
                if ($this->dumpAll) {
                    $restoreCommand .= " && (gunzip -cf {$tmpPath} 2>/dev/null || cat {$tmpPath}) | mysql -u root -p\$MYSQL_ROOT_PASSWORD \${MYSQL_DATABASE:-default}";
                } else {
                    $restoreCommand .= " < {$tmpPath}";
                }
                break;
            case \App\Models\StandalonePostgresql::class:
            case 'postgresql':
                $restoreCommand = $this->postgresqlRestoreCommand;
                if ($this->dumpAll) {
                    $restoreCommand .= " && (gunzip -cf {$tmpPath} 2>/dev/null || cat {$tmpPath}) | psql -U \${POSTGRES_USER} -d \${POSTGRES_DB:-\${POSTGRES_USER:-postgres}}";
                } else {
                    $restoreCommand .= " {$tmpPath}";
                }
                break;
            case \App\Models\StandaloneMongodb::class:
            case 'mongodb':
                $restoreCommand = $this->mongodbRestoreCommand;
                if ($this->dumpAll === false) {
                    $restoreCommand .= "{$tmpPath}";
                }
                break;
            default:
                $restoreCommand = '';
        }

        return $restoreCommand;
    }
}
