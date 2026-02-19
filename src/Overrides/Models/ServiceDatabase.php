<?php

// =============================================================================
// OVERLAY: Modified version of Coolify's ServiceDatabase model
// =============================================================================
// This file replaces app/Models/ServiceDatabase.php in the Coolify container.
// Changes from the original are marked with [DATABASE CLASSIFICATION OVERLAY].
//
// Modification:
//   1. Expanded databaseType() to map wire-compatible databases to their parent
//      backup type (e.g., YugabyteDB → postgresql, TiDB → mysql, FerretDB → mongodb)
//   2. This automatically fixes isBackupSolutionAvailable(), DatabaseBackupJob
//      dump commands, import UI visibility, and StartDatabaseProxy port mapping
//      for all wire-compatible database types
// =============================================================================

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceDatabase extends BaseModel
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected static function booted()
    {
        static::deleting(function ($service) {
            $service->persistentStorages()->delete();
            $service->fileStorages()->delete();
            $service->scheduledBackups()->delete();
        });
        static::saving(function ($service) {
            if ($service->isDirty('status')) {
                $service->forceFill(['last_online_at' => now()]);
            }
        });
    }

    public static function ownedByCurrentTeamAPI(int $teamId)
    {
        return ServiceDatabase::whereRelation('service.environment.project.team', 'id', $teamId)->orderBy('name');
    }

    /**
     * Get query builder for service databases owned by current team.
     * If you need all service databases without further query chaining, use ownedByCurrentTeamCached() instead.
     */
    public static function ownedByCurrentTeam()
    {
        return ServiceDatabase::whereRelation('service.environment.project.team', 'id', currentTeam()->id)->orderBy('name');
    }

    /**
     * Get all service databases owned by current team (cached for request duration).
     */
    public static function ownedByCurrentTeamCached()
    {
        return once(function () {
            return ServiceDatabase::ownedByCurrentTeam()->get();
        });
    }

    public function restart()
    {
        $container_id = $this->name.'-'.$this->service->uuid;
        remote_process(["docker restart {$container_id}"], $this->service->server);
    }

    public function isRunning()
    {
        return str($this->status)->contains('running');
    }

    public function isExited()
    {
        return str($this->status)->contains('exited');
    }

    public function isLogDrainEnabled()
    {
        return data_get($this, 'is_log_drain_enabled', false);
    }

    public function isStripprefixEnabled()
    {
        return data_get($this, 'is_stripprefix_enabled', true);
    }

    public function isGzipEnabled()
    {
        return data_get($this, 'is_gzip_enabled', true);
    }

    public function type()
    {
        return 'service';
    }

    public function serviceType()
    {
        return null;
    }

    // [DATABASE CLASSIFICATION OVERLAY] — Expanded with wire-compatible database mappings
    // Maps database images to their wire-compatible parent type so that backup tools
    // (pg_dump, mysqldump, mongodump), backup UI visibility, import, and port mapping
    // all work automatically for compatible databases.
    //
    // Wire-compatible means the database speaks the same protocol AND standard dump
    // tools produce correct backups. Databases where standard tools fail (CockroachDB
    // with pg_dump, Vitess with mysqldump) are NOT mapped — users should use
    // Resource Backups (volume-level) or set custom_type manually.
    public function databaseType()
    {
        if (filled($this->custom_type)) {
            return 'standalone-'.$this->custom_type;
        }
        $image = str($this->image)->before(':');

        // --- Original Coolify mappings ---
        if ($image->contains('supabase/postgres')) {
            $finalImage = 'supabase/postgres';
        } elseif ($image->contains('timescale')) {
            $finalImage = 'postgresql';
        } elseif ($image->contains('pgvector')) {
            $finalImage = 'postgresql';
        } elseif ($image->contains('postgres') || $image->contains('postgis')) {
            $finalImage = 'postgresql';
        }
        // --- Wire-compatible PostgreSQL databases ---
        // These speak PostgreSQL wire protocol AND pg_dump produces valid backups
        elseif ($image->contains('yugabyte')) {
            $finalImage = 'postgresql'; // YugabyteDB YSQL — runs PG query layer
        } elseif ($image->contains('age') && ! $image->contains('garage') && ! $image->contains('image')) {
            $finalImage = 'postgresql'; // Apache AGE — IS a PostgreSQL extension
        }
        // --- Wire-compatible MySQL databases ---
        // These speak MySQL wire protocol AND mysqldump produces valid backups
        elseif ($image->contains('percona')) {
            $finalImage = 'mysql'; // Percona Server — drop-in MySQL replacement
        } elseif ($image->contains('tidb')) {
            $finalImage = 'mysql'; // TiDB — MySQL 5.7 wire-compatible
        }
        // --- Wire-compatible MongoDB databases ---
        // These speak MongoDB wire protocol AND mongodump produces valid backups
        elseif ($image->contains('ferretdb')) {
            $finalImage = 'mongodb'; // FerretDB — MongoDB wire-compatible proxy
        }
        // [END DATABASE CLASSIFICATION OVERLAY]
        else {
            $finalImage = $image;
        }

        return "standalone-$finalImage";
    }

    public function getServiceDatabaseUrl()
    {
        $port = $this->public_port;
        $realIp = $this->service->server->ip;
        if ($this->service->server->isLocalhost() || isDev()) {
            $realIp = base_ip();
        }

        return "{$realIp}:{$port}";
    }

    public function team()
    {
        return data_get($this, 'environment.project.team');
    }

    public function workdir()
    {
        return service_configuration_dir()."/{$this->service->uuid}";
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function persistentStorages()
    {
        return $this->morphMany(LocalPersistentVolume::class, 'resource');
    }

    public function fileStorages()
    {
        return $this->morphMany(LocalFileVolume::class, 'resource');
    }

    public function getFilesFromServer(bool $isInit = false)
    {
        getFilesystemVolumesFromServer($this, $isInit);
    }

    public function scheduledBackups()
    {
        return $this->morphMany(ScheduledDatabaseBackup::class, 'database');
    }

    public function isBackupSolutionAvailable()
    {
        return str($this->databaseType())->contains('mysql') ||
            str($this->databaseType())->contains('postgres') ||
            str($this->databaseType())->contains('postgis') ||
            str($this->databaseType())->contains('mariadb') ||
            str($this->databaseType())->contains('mongo') ||
            filled($this->custom_type);
    }
}
