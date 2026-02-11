<?php

namespace AmirhMoradi\CoolifyEnhanced\Livewire;

use Livewire\Component;

/**
 * Full-page Livewire component for the Resource Backups page.
 *
 * Renders the appropriate heading component (Application/Service/Database)
 * along with the ResourceBackupManager for managing volume, configuration,
 * full, and Coolify instance backups.
 */
class ResourceBackupPage extends Component
{
    public $resource;

    public string $resourceType = '';

    public string $resourceName = '';

    public array $parameters = [];

    public array $query = [];

    public function mount()
    {
        try {
            $this->parameters = get_route_parameters();
            $this->query = request()->query();

            $project = currentTeam()
                ->projects()
                ->select('id', 'uuid', 'team_id')
                ->where('uuid', request()->route('project_uuid'))
                ->firstOrFail();

            $environment = $project->environments()
                ->select('id', 'uuid', 'name', 'project_id')
                ->where('uuid', request()->route('environment_uuid'))
                ->firstOrFail();

            // Determine resource type from route parameters
            if (request()->route('application_uuid')) {
                $this->resource = $environment->applications()
                    ->where('uuid', request()->route('application_uuid'))
                    ->firstOrFail();
                $this->resourceType = \App\Models\Application::class;
                $this->resourceName = $this->resource->name;
            } elseif (request()->route('service_uuid')) {
                $this->resource = $environment->services()
                    ->whereUuid(request()->route('service_uuid'))
                    ->firstOrFail();
                $this->resourceType = \App\Models\Service::class;
                $this->resourceName = $this->resource->name;
            } elseif (request()->route('database_uuid')) {
                $this->resource = $environment->databases()
                    ->where('uuid', request()->route('database_uuid'))
                    ->first();
                if (! $this->resource) {
                    return redirect()->route('dashboard');
                }
                $this->resourceType = get_class($this->resource);
                $this->resourceName = $this->resource->name;
            } else {
                return redirect()->route('dashboard');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('coolify-enhanced::livewire.resource-backup-page');
    }
}
