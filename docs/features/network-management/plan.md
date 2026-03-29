# Network Management — Technical Implementation Plan

## Architecture

```
┌─────────────────────────────────────────────────────┐
│                  Service Provider                     │
│  registerNetworkManagement()                         │
│  - ApplicationDeploymentQueue::updated() observer    │
│  - Event listeners for Service/Database status       │
│  - Route registration (API + web)                    │
│  - Livewire component registration                   │
└──────────────┬──────────────────────────────────────┘
               │
     ┌─────────▼─────────┐
     │ NetworkReconcileJob│ (post-deployment)
     └─────────┬─────────┘
               │
     ┌─────────▼─────────┐
     │  NetworkService    │ (core engine)
     │  - createDockerNetwork()
     │  - connectContainer()
     │  - reconcileResource()
     │  - updateSwarmServiceNetworks()
     │  - migrateToProxyIsolation()
     └───────────────────┘
```

## Database Schema

### managed_networks
```sql
CREATE TABLE managed_networks (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    uuid VARCHAR(255) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    docker_network_name VARCHAR(255) NOT NULL,
    docker_id VARCHAR(255) NULL,
    server_id BIGINT NOT NULL REFERENCES servers(id) ON DELETE CASCADE,
    team_id BIGINT NULL REFERENCES teams(id) ON DELETE SET NULL,
    project_id BIGINT NULL REFERENCES projects(id) ON DELETE SET NULL,
    environment_id BIGINT NULL REFERENCES environments(id) ON DELETE SET NULL,
    scope ENUM('environment','shared','proxy','system') NOT NULL DEFAULT 'shared',
    driver VARCHAR(50) NOT NULL DEFAULT 'bridge',
    subnet VARCHAR(50) NULL,
    gateway VARCHAR(50) NULL,
    is_attachable BOOLEAN NOT NULL DEFAULT TRUE,
    is_internal BOOLEAN NOT NULL DEFAULT FALSE,
    is_proxy_network BOOLEAN NOT NULL DEFAULT FALSE,
    is_encrypted_overlay BOOLEAN NOT NULL DEFAULT FALSE,
    options JSON NULL,
    status ENUM('pending','active','error') NOT NULL DEFAULT 'pending',
    error_message TEXT NULL,
    last_synced_at TIMESTAMP NULL,
    UNIQUE KEY (docker_network_name, server_id)
);
```

### resource_networks
```sql
CREATE TABLE resource_networks (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    resource_type VARCHAR(255) NOT NULL,
    resource_id BIGINT NOT NULL,
    managed_network_id BIGINT NOT NULL REFERENCES managed_networks(id) ON DELETE CASCADE,
    is_auto_attached BOOLEAN NOT NULL DEFAULT FALSE,
    is_connected BOOLEAN NOT NULL DEFAULT FALSE,
    connected_at TIMESTAMP NULL,
    aliases JSON NULL,
    UNIQUE KEY (resource_type, resource_id, managed_network_id)
);
```

## Key Implementation Details

### Command Injection Prevention
All values interpolated into shell commands use `escapeshellarg()`:
```php
$parts[] = '--driver '.escapeshellarg($network->driver);
$parts[] = '--subnet '.escapeshellarg($network->subnet);
$parts[] = escapeshellarg($network->docker_network_name);
```

### Network Creation Verification
After `docker network create`, we verify via inspection before marking ACTIVE:
```php
$inspection = static::inspectNetwork($server, $network->docker_network_name);
if ($inspection && !empty($inspection['Id'])) {
    $network->update(['status' => 'active', 'docker_id' => $inspection['Id']]);
} else {
    $network->update(['status' => 'error', 'error_message' => '...']);
}
```

### Feature Flag Triple-Guard (overlays)
All overlay blocks check three conditions:
```php
if (config('corelix-platform.enabled', false)
    && config('corelix-platform.network_management.proxy_isolation', false)
    && config('corelix-platform.network_management.enabled', false)) {
```

### Swarm Manager Validation
Overlay networks require manager nodes:
```php
if ($network->driver === 'overlay' && !static::isSwarmManager($server)) {
    $network->update(['status' => 'error', 'error_message' => '...']);
    return false;
}
```

## Files Modified

### New files (Phase 1-3)
- `src/Services/NetworkService.php` — Core engine (~1450 lines)
- `src/Jobs/NetworkReconcileJob.php` — Post-deploy reconciliation
- `src/Jobs/ProxyMigrationJob.php` — Proxy migration
- `src/Models/ManagedNetwork.php` — Network model
- `src/Models/ResourceNetwork.php` — Pivot model
- `src/Policies/NetworkPolicy.php` — Authorization
- `src/Livewire/NetworkManager.php` — Server UI
- `src/Livewire/NetworkManagerPage.php` — Page wrapper
- `src/Livewire/ResourceNetworks.php` — Resource UI
- `src/Livewire/NetworkSettings.php` — Settings UI
- `src/Http/Controllers/Api/NetworkController.php` — API
- `resources/views/livewire/network-*.blade.php` — Views (4 files)
- `database/migrations/2024_01_01_000010_*.php` — Schema
- `database/migrations/2024_01_01_000011_*.php` — Swarm columns

### Overlay files (Phase 2 only)
- `src/Overrides/Helpers/proxy.php` — 4 functions modified
- `src/Overrides/Helpers/docker.php` — 3 functions modified
- `src/Overrides/Helpers/shared.php` — Service label injection (existing overlay updated)

### Updated files
- `src/CorelixPlatformServiceProvider.php` — Network registration
- `config/corelix-platform.php` — Network config keys
- `docker/Dockerfile` — Overlay COPY directives
- View overlays — Sidebar + navbar items
