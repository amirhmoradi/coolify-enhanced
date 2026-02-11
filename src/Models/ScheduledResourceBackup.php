<?php

namespace AmirhMoradi\CoolifyEnhanced\Models;

use App\Models\S3Storage;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ScheduledResourceBackup extends Model
{
    protected $guarded = [];

    protected $casts = [
        'enabled' => 'boolean',
        'save_s3' => 'boolean',
        'disable_local_backup' => 'boolean',
        'retention_amount_locally' => 'integer',
        'retention_days_locally' => 'integer',
        'retention_max_storage_locally' => 'decimal:7',
        'retention_amount_s3' => 'integer',
        'retention_days_s3' => 'integer',
        'retention_max_storage_s3' => 'decimal:7',
        'timeout' => 'integer',
    ];

    /**
     * Supported backup types.
     */
    public const TYPE_VOLUME = 'volume';

    public const TYPE_CONFIGURATION = 'configuration';

    public const TYPE_FULL = 'full';

    public const TYPE_COOLIFY_INSTANCE = 'coolify_instance';

    public const TYPES = [
        self::TYPE_VOLUME,
        self::TYPE_CONFIGURATION,
        self::TYPE_FULL,
        self::TYPE_COOLIFY_INSTANCE,
    ];

    /**
     * The resource being backed up (Application, Service, or Database).
     */
    public function resource(): MorphTo
    {
        return $this->morphTo('resource');
    }

    public function s3(): BelongsTo
    {
        return $this->belongsTo(S3Storage::class, 's3_storage_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function executions(): HasMany
    {
        return $this->hasMany(ScheduledResourceBackupExecution::class);
    }

    public function latest_log(): HasOne
    {
        return $this->hasOne(ScheduledResourceBackupExecution::class)->latest();
    }

    /**
     * Resolve the server from the resource relationship chain.
     */
    public function server()
    {
        // Coolify instance backups always run on the local server (id=0)
        if ($this->backup_type === self::TYPE_COOLIFY_INSTANCE) {
            return \App\Models\Server::find(0);
        }

        $resource = $this->resource;
        if (! $resource) {
            return null;
        }

        // Application
        if (method_exists($resource, 'destination') && $resource->destination) {
            return $resource->destination->server ?? null;
        }

        // Service
        if (method_exists($resource, 'server') && $resource->server) {
            return $resource->server;
        }

        return null;
    }

    /**
     * Get the container name(s) for this resource.
     */
    public function getContainerNames(): array
    {
        $resource = $this->resource;
        if (! $resource) {
            return [];
        }

        // Application - uses uuid as container name
        if ($resource instanceof \App\Models\Application) {
            return [$resource->uuid];
        }

        // Service - list all service containers
        if ($resource instanceof \App\Models\Service) {
            $containers = [];
            foreach ($resource->applications as $app) {
                $containers[] = "{$app->name}-{$resource->uuid}";
            }
            foreach ($resource->databases as $db) {
                $containers[] = "{$db->name}-{$resource->uuid}";
            }

            return $containers;
        }

        // Standalone databases
        if (property_exists($resource, 'uuid')) {
            return [$resource->uuid];
        }

        return [];
    }
}
