<div>
    <x-slot:title>
        {{ data_get_str($resource, 'name')->limit(10) }} > Resource Backups | Coolify
    </x-slot>
    <h1>Resource Backups</h1>
    <livewire:project.shared.configuration-checker :resource="$resource" />

    {{-- Render the appropriate heading component based on resource type --}}
    @if($resource instanceof \App\Models\Application)
        <livewire:project.application.heading :application="$resource" />
    @elseif($resource instanceof \App\Models\Service)
        <livewire:project.service.heading :service="$resource" :parameters="$parameters" :query="$query" />
    @else
        <livewire:project.database.heading :database="$resource" />
    @endif

    <div class="pt-4">
        @livewire('enhanced::resource-backup-manager', [
            'resourceId' => $resource->id,
            'resourceType' => $resourceType,
            'resourceName' => $resourceName,
        ])
    </div>
</div>
