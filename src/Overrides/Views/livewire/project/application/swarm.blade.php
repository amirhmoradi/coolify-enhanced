{{-- =============================================================================
     OVERLAY: Modified version of Coolify's project/application/swarm.blade.php
     Changes: Replace raw YAML textarea with structured SwarmConfigForm when
     cluster management is enabled.
     [SWARM CONFIG OVERLAY]
     ============================================================================= --}}
<div>
    @if (config('coolify-enhanced.cluster_management', false))
        @livewire('enhanced::swarm-config-form', ['application' => $application])
    @else
        <h2>Docker Swarm</h2>
        <div>
            <x-forms.textarea label="Custom Docker Compose (Swarm Deploy)" id="application.docker_compose_custom_start_command"
                placeholder="Enter deploy section in YAML format..." />
            <x-forms.button type="submit">Save</x-forms.button>
        </div>
    @endif
</div>
