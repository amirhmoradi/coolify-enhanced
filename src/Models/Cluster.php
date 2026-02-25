<?php

namespace AmirhMoradi\CoolifyEnhanced\Models;

use AmirhMoradi\CoolifyEnhanced\Contracts\ClusterDriverInterface;
use AmirhMoradi\CoolifyEnhanced\Drivers\SwarmClusterDriver;
use App\Models\BaseModel;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Visus\Cuid2\Cuid2;

class Cluster extends BaseModel
{
    protected $guarded = ['id'];

    protected $casts = [
        'settings' => 'encrypted:array',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (Cluster $cluster) {
            if (empty($cluster->uuid)) {
                $cluster->uuid = (string) new Cuid2(7);
            }
        });
    }

    // ── Relationships ─────────────────────────────────────

    public function managerServer(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'manager_server_id');
    }

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ClusterEvent::class);
    }

    public function secrets(): HasMany
    {
        return $this->hasMany(SwarmSecret::class);
    }

    public function configs(): HasMany
    {
        return $this->hasMany(SwarmConfig::class);
    }

    // ── Driver ────────────────────────────────────────────

    public function driver(): ClusterDriverInterface
    {
        return match ($this->type) {
            'swarm' => app(SwarmClusterDriver::class)->setCluster($this),
            default => throw new \InvalidArgumentException("Unknown cluster type: {$this->type}"),
        };
    }

    // ── Status Helpers ────────────────────────────────────

    public function isHealthy(): bool
    {
        return $this->status === 'healthy';
    }

    public function isDegraded(): bool
    {
        return $this->status === 'degraded';
    }

    public function isUnreachable(): bool
    {
        return $this->status === 'unreachable';
    }

    public function isSwarm(): bool
    {
        return $this->type === 'swarm';
    }

    public function isKubernetes(): bool
    {
        return $this->type === 'kubernetes';
    }

    // ── Scopes ────────────────────────────────────────────

    public function scopeOwnedByTeam($query, int $teamId)
    {
        return $query->where('team_id', $teamId);
    }

    public function scopeSwarm($query)
    {
        return $query->where('type', 'swarm');
    }

    public function scopeHealthy($query)
    {
        return $query->where('status', 'healthy');
    }
}
