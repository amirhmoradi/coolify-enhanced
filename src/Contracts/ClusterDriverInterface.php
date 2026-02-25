<?php

namespace AmirhMoradi\CoolifyEnhanced\Contracts;

use AmirhMoradi\CoolifyEnhanced\Models\Cluster;
use Illuminate\Support\Collection;

interface ClusterDriverInterface
{
    /**
     * Initialize the driver with a cluster instance.
     */
    public function setCluster(Cluster $cluster): self;

    // ── Cluster Info ──────────────────────────────────────

    /**
     * Get cluster-wide information (version, status, node count, etc.)
     *
     * @return array{id: string, created: string, version: string, nodes: int, managers: int, workers: int}
     */
    public function getClusterInfo(): array;

    /**
     * Check cluster health. Returns 'healthy', 'degraded', or 'unreachable'.
     */
    public function getClusterHealth(): string;

    // ── Nodes ─────────────────────────────────────────────

    /**
     * List all nodes in the cluster.
     *
     * @return Collection<int, array{
     *   id: string, hostname: string, role: string, status: string,
     *   availability: string, ip: string, engine_version: string,
     *   cpu_cores: int, memory_bytes: int, labels: array,
     *   is_leader: bool, manager_reachability: ?string
     * }>
     */
    public function getNodes(): Collection;

    /**
     * Get detailed info for a single node.
     *
     * @return array{
     *   id: string, hostname: string, role: string, status: string,
     *   availability: string, ip: string, engine_version: string,
     *   cpu_cores: int, memory_bytes: int, labels: array,
     *   is_leader: bool, manager_reachability: ?string,
     *   platform_os: string, platform_arch: string
     * }
     */
    public function getNode(string $nodeId): array;

    /**
     * Get resource usage for a node (CPU%, mem%, disk%).
     *
     * @return array{cpu_percent: float, memory_percent: float, memory_used: int, memory_total: int, disk_percent: float}
     */
    public function getNodeResources(string $nodeId): array;

    // ── Services ──────────────────────────────────────────

    /**
     * List all services in the cluster.
     *
     * @return Collection<int, array{
     *   id: string, name: string, image: string, mode: string,
     *   replicas_running: int, replicas_desired: int,
     *   ports: string, updated_at: ?string
     * }>
     */
    public function getServices(): Collection;

    /**
     * Get detailed info for a single service.
     *
     * @return array{
     *   id: string, name: string, image: string, mode: string,
     *   replicas_running: int, replicas_desired: int,
     *   ports: array, labels: array, created_at: string, updated_at: string
     * }
     */
    public function getService(string $serviceId): array;

    /**
     * Get tasks for a specific service.
     *
     * @return Collection<int, array{
     *   id: string, name: string, node: string,
     *   status: string, desired_state: string, error: ?string,
     *   image: string, ports: string
     * }>
     */
    public function getServiceTasks(string $serviceId): Collection;

    // ── Tasks ─────────────────────────────────────────────

    /**
     * Get all tasks across the cluster.
     *
     * @return Collection<int, array{
     *   id: string, name: string, service_id: string, node_id: string,
     *   status: string, desired_state: string, error: ?string, image: string
     * }>
     */
    public function getAllTasks(): Collection;

    /**
     * Get tasks running on a specific node.
     *
     * @return Collection<int, array{
     *   id: string, name: string, service_id: string,
     *   status: string, desired_state: string, error: ?string, image: string
     * }>
     */
    public function getNodeTasks(string $nodeId): Collection;

    // ── Events ────────────────────────────────────────────

    /**
     * Get recent cluster events.
     *
     * @param  int  $since  Unix timestamp
     * @return Collection<int, array{
     *   type: string, action: string, actor_id: ?string,
     *   actor_name: ?string, attributes: array, scope: ?string, time: int
     * }>
     */
    public function getEvents(int $since, ?string $filterType = null): Collection;

    // ── Join Tokens ───────────────────────────────────────

    /**
     * Get the current join tokens.
     *
     * @return array{worker: string, manager: string}
     */
    public function getJoinTokens(): array;

    // ── Node Management (Phase 2) ─────────────────────────

    /**
     * Update node availability (active, pause, drain).
     */
    public function updateNodeAvailability(string $nodeId, string $availability): bool;

    /**
     * Promote a worker to manager.
     */
    public function promoteNode(string $nodeId): bool;

    /**
     * Demote a manager to worker.
     */
    public function demoteNode(string $nodeId): bool;

    /**
     * Remove a node from the cluster.
     */
    public function removeNode(string $nodeId, bool $force = false): bool;

    /**
     * Add or remove node labels.
     *
     * @param  array<string, string>  $add  Labels to add (key => value)
     * @param  array<int, string>  $remove  Label keys to remove
     */
    public function updateNodeLabels(string $nodeId, array $add = [], array $remove = []): bool;

    // ── Service Management (Phase 2) ──────────────────────

    /**
     * Scale a service to the desired number of replicas.
     */
    public function scaleService(string $serviceId, int $replicas): bool;

    /**
     * Rollback a service to its previous version.
     */
    public function rollbackService(string $serviceId): bool;

    /**
     * Force update a service (redistribute tasks).
     */
    public function forceUpdateService(string $serviceId): bool;

    // ── Secrets (Phase 3) ─────────────────────────────────

    /**
     * List all secrets.
     *
     * @return Collection<int, array{id: string, name: string, created_at: string, updated_at: string, labels: array}>
     */
    public function getSecrets(): Collection;

    /**
     * Create a secret.
     *
     * @param  array<string, string>  $labels
     * @return string Docker secret ID
     */
    public function createSecret(string $name, string $data, array $labels = []): string;

    /**
     * Remove a secret.
     */
    public function removeSecret(string $secretId): bool;

    // ── Configs (Phase 3) ─────────────────────────────────

    /**
     * List all configs.
     *
     * @return Collection<int, array{id: string, name: string, data: string, created_at: string, labels: array}>
     */
    public function getConfigs(): Collection;

    /**
     * Create a config.
     *
     * @param  array<string, string>  $labels
     * @return string Docker config ID
     */
    public function createConfig(string $name, string $data, array $labels = []): string;

    /**
     * Remove a config.
     */
    public function removeConfig(string $configId): bool;

    // ── Stacks (Phase 3) ──────────────────────────────────

    /**
     * List all stacks.
     *
     * @return Collection<int, array{name: string, services: int, orchestrator: string}>
     */
    public function getStacks(): Collection;
}
