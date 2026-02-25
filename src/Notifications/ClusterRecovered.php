<?php

namespace AmirhMoradi\CoolifyEnhanced\Notifications;

use AmirhMoradi\CoolifyEnhanced\Models\Cluster;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ClusterRecovered extends Notification
{
    use Queueable;

    public function __construct(
        public Cluster $cluster,
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'cluster_recovered',
            'cluster_id' => $this->cluster->id,
            'cluster_uuid' => $this->cluster->uuid,
            'cluster_name' => $this->cluster->name,
            'message' => "Cluster '{$this->cluster->name}' has recovered and is now healthy.",
            'severity' => 'info',
        ];
    }
}
