<?php

namespace AmirhMoradi\CoolifyEnhanced\Notifications;

use AmirhMoradi\CoolifyEnhanced\Models\Cluster;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ClusterDegraded extends Notification
{
    use Queueable;

    public function __construct(
        public Cluster $cluster,
        public int $downNodes = 0,
        public int $totalNodes = 0,
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'cluster_degraded',
            'cluster_id' => $this->cluster->id,
            'cluster_uuid' => $this->cluster->uuid,
            'cluster_name' => $this->cluster->name,
            'message' => "Cluster '{$this->cluster->name}' is degraded: {$this->downNodes}/{$this->totalNodes} nodes are down.",
            'severity' => 'warning',
        ];
    }
}
