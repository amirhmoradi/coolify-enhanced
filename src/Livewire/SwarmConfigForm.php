<?php

namespace AmirhMoradi\CoolifyEnhanced\Livewire;

use App\Models\Application;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Symfony\Component\Yaml\Yaml;

class SwarmConfigForm extends Component
{
    use AuthorizesRequests;

    public Application $application;

    // Deployment mode
    public string $mode = 'replicated';

    public int $replicas = 1;

    public bool $workerOnly = false;

    // Update policy
    public int $updateParallelism = 1;

    public string $updateDelay = '10s';

    public string $updateFailureAction = 'pause';

    public string $updateMonitor = '5s';

    public string $updateOrder = 'stop-first';

    public string $updateMaxFailureRatio = '0';

    // Rollback policy
    public int $rollbackParallelism = 1;

    public string $rollbackDelay = '10s';

    public string $rollbackFailureAction = 'pause';

    public string $rollbackMonitor = '5s';

    public string $rollbackOrder = 'stop-first';

    public string $rollbackMaxFailureRatio = '0';

    // Constraints & preferences
    public array $constraints = [];

    public array $preferences = [];

    // Resource limits
    public string $cpuLimit = '';

    public string $memoryLimitMb = '';

    public string $cpuReservation = '';

    public string $memoryReservationMb = '';

    // Health check
    public string $healthCmd = '';

    public string $healthInterval = '30s';

    public string $healthTimeout = '30s';

    public int $healthRetries = 3;

    public string $healthStartPeriod = '0s';

    // Restart policy
    public string $restartCondition = 'on-failure';

    public string $restartDelay = '5s';

    public int $restartMaxAttempts = 3;

    public string $restartWindow = '120s';

    // UI state
    public bool $showAdvanced = false;

    public function mount(Application $application): void
    {
        $this->application = $application;
        $this->loadFromApplication();
    }

    public function submit(): void
    {
        try {
            $this->authorize('update', $this->application);

            $deployYaml = $this->generateDeployYaml();

            $this->application->update([
                'swarm_replicas' => $this->replicas,
                'swarm_placement_constraints' => base64_encode($deployYaml),
            ]);

            $this->dispatch('success', 'Swarm deployment configuration saved.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to save configuration: '.$e->getMessage());
        }
    }

    public function generateDeployYaml(): string
    {
        $deploy = [];

        $deploy['mode'] = $this->mode;

        if ($this->mode === 'replicated') {
            $deploy['replicas'] = $this->replicas;
        }

        // Placement
        $placement = [];
        $filteredConstraints = array_filter($this->constraints, fn ($c) => ! empty($c));
        if (! empty($filteredConstraints)) {
            $placement['constraints'] = array_values($filteredConstraints);
        }

        $filteredPreferences = array_filter($this->preferences, fn ($p) => ! empty($p));
        if (! empty($filteredPreferences)) {
            $placement['preferences'] = array_map(
                fn ($p) => ['spread' => $p],
                array_values($filteredPreferences)
            );
        }

        if ($this->workerOnly && ! in_array('node.role == worker', $filteredConstraints)) {
            $placement['constraints'][] = 'node.role == worker';
        }

        if (! empty($placement)) {
            $deploy['placement'] = $placement;
        }

        // Update config
        $deploy['update_config'] = [
            'parallelism' => $this->updateParallelism,
            'delay' => $this->updateDelay,
            'failure_action' => $this->updateFailureAction,
            'monitor' => $this->updateMonitor,
            'order' => $this->updateOrder,
            'max_failure_ratio' => (float) $this->updateMaxFailureRatio,
        ];

        // Rollback config
        $deploy['rollback_config'] = [
            'parallelism' => $this->rollbackParallelism,
            'delay' => $this->rollbackDelay,
            'failure_action' => $this->rollbackFailureAction,
            'monitor' => $this->rollbackMonitor,
            'order' => $this->rollbackOrder,
            'max_failure_ratio' => (float) $this->rollbackMaxFailureRatio,
        ];

        // Resources
        $resources = [];
        $limits = [];
        $reservations = [];

        if ($this->cpuLimit !== '') {
            $limits['cpus'] = $this->cpuLimit;
        }
        if ($this->memoryLimitMb !== '') {
            $limits['memory'] = $this->memoryLimitMb.'M';
        }
        if ($this->cpuReservation !== '') {
            $reservations['cpus'] = $this->cpuReservation;
        }
        if ($this->memoryReservationMb !== '') {
            $reservations['memory'] = $this->memoryReservationMb.'M';
        }

        if (! empty($limits)) {
            $resources['limits'] = $limits;
        }
        if (! empty($reservations)) {
            $resources['reservations'] = $reservations;
        }
        if (! empty($resources)) {
            $deploy['resources'] = $resources;
        }

        // Restart policy
        $deploy['restart_policy'] = [
            'condition' => $this->restartCondition,
            'delay' => $this->restartDelay,
            'max_attempts' => $this->restartMaxAttempts,
            'window' => $this->restartWindow,
        ];

        $result = ['deploy' => $deploy];

        // Health check (separate from deploy section in compose)
        if (! empty($this->healthCmd)) {
            $result['healthcheck'] = [
                'test' => ['CMD-SHELL', $this->healthCmd],
                'interval' => $this->healthInterval,
                'timeout' => $this->healthTimeout,
                'retries' => $this->healthRetries,
                'start_period' => $this->healthStartPeriod,
            ];
        }

        return Yaml::dump($result, 10, 2);
    }

    public function addConstraint(): void
    {
        $this->constraints[] = '';
    }

    public function removeConstraint(int $index): void
    {
        unset($this->constraints[$index]);
        $this->constraints = array_values($this->constraints);
    }

    public function addPreference(): void
    {
        $this->preferences[] = '';
    }

    public function removePreference(int $index): void
    {
        unset($this->preferences[$index]);
        $this->preferences = array_values($this->preferences);
    }

    public function render()
    {
        $previewYaml = '';
        try {
            $previewYaml = $this->generateDeployYaml();
        } catch (\Throwable) {
            // Preview is best-effort
        }

        return view('coolify-enhanced::livewire.swarm-config-form', [
            'previewYaml' => $previewYaml,
        ]);
    }

    private function loadFromApplication(): void
    {
        $this->replicas = $this->application->swarm_replicas ?? 1;

        $raw = $this->application->swarm_placement_constraints;
        if (empty($raw)) {
            return;
        }

        try {
            $decoded = base64_decode($raw, true);
            if ($decoded === false) {
                return;
            }

            $parsed = Yaml::parse($decoded);
            if (! is_array($parsed)) {
                return;
            }

            $deploy = $parsed['deploy'] ?? [];

            $this->mode = $deploy['mode'] ?? 'replicated';
            $this->replicas = $deploy['replicas'] ?? $this->replicas;

            // Placement
            $placement = $deploy['placement'] ?? [];
            $this->constraints = $placement['constraints'] ?? [];
            $this->workerOnly = in_array('node.role == worker', $this->constraints);

            $prefs = $placement['preferences'] ?? [];
            $this->preferences = array_map(
                fn ($p) => is_array($p) ? ($p['spread'] ?? '') : $p,
                $prefs
            );

            // Update config
            $uc = $deploy['update_config'] ?? [];
            $this->updateParallelism = $uc['parallelism'] ?? 1;
            $this->updateDelay = $uc['delay'] ?? '10s';
            $this->updateFailureAction = $uc['failure_action'] ?? 'pause';
            $this->updateMonitor = $uc['monitor'] ?? '5s';
            $this->updateOrder = $uc['order'] ?? 'stop-first';
            $this->updateMaxFailureRatio = (string) ($uc['max_failure_ratio'] ?? '0');

            // Rollback config
            $rc = $deploy['rollback_config'] ?? [];
            $this->rollbackParallelism = $rc['parallelism'] ?? 1;
            $this->rollbackDelay = $rc['delay'] ?? '10s';
            $this->rollbackFailureAction = $rc['failure_action'] ?? 'pause';
            $this->rollbackMonitor = $rc['monitor'] ?? '5s';
            $this->rollbackOrder = $rc['order'] ?? 'stop-first';
            $this->rollbackMaxFailureRatio = (string) ($rc['max_failure_ratio'] ?? '0');

            // Resources
            $resources = $deploy['resources'] ?? [];
            $limits = $resources['limits'] ?? [];
            $reservations = $resources['reservations'] ?? [];

            $this->cpuLimit = $limits['cpus'] ?? '';
            $this->memoryLimitMb = str_replace('M', '', $limits['memory'] ?? '');
            $this->cpuReservation = $reservations['cpus'] ?? '';
            $this->memoryReservationMb = str_replace('M', '', $reservations['memory'] ?? '');

            // Restart policy
            $rp = $deploy['restart_policy'] ?? [];
            $this->restartCondition = $rp['condition'] ?? 'on-failure';
            $this->restartDelay = $rp['delay'] ?? '5s';
            $this->restartMaxAttempts = $rp['max_attempts'] ?? 3;
            $this->restartWindow = $rp['window'] ?? '120s';

            // Health check
            $hc = $parsed['healthcheck'] ?? [];
            if (! empty($hc)) {
                $test = $hc['test'] ?? [];
                $this->healthCmd = is_array($test) ? ($test[1] ?? '') : $test;
                $this->healthInterval = $hc['interval'] ?? '30s';
                $this->healthTimeout = $hc['timeout'] ?? '30s';
                $this->healthRetries = $hc['retries'] ?? 3;
                $this->healthStartPeriod = $hc['start_period'] ?? '0s';
            }
        } catch (\Throwable) {
            // Failed to parse existing config — use defaults
        }
    }
}
