<?php

namespace AmirhMoradi\CoolifyEnhanced\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledResourceBackupExecution extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_encrypted' => 'boolean',
            's3_uploaded' => 'boolean',
            'local_storage_deleted' => 'boolean',
            's3_storage_deleted' => 'boolean',
        ];
    }

    public function scheduledResourceBackup(): BelongsTo
    {
        return $this->belongsTo(ScheduledResourceBackup::class);
    }
}
