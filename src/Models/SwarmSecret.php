<?php

namespace AmirhMoradi\CoolifyEnhanced\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SwarmSecret extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'labels' => 'array',
        'docker_created_at' => 'datetime',
        'docker_updated_at' => 'datetime',
    ];

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(Cluster::class);
    }
}
