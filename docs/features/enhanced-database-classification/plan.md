# Plan: Multi-Port Database Proxy Support

## Problem Statement

Coolify's "Make Publicly Available" feature creates an nginx TCP proxy that forwards exactly **one** public port to **one** internal port. For databases like Memgraph (bolt: 7687, HTTPS log UI: 7444), Neo4j (bolt: 7687, HTTP browser: 7474), or ArangoDB (HTTP: 8529, internal cluster: 8530), only the primary port gets proxied. Users cannot expose secondary ports through this mechanism.

### Current Architecture (single port)

```
User toggle → is_public=true, public_port=X
→ StartDatabaseProxy creates nginx container:
    stream { server { listen X; proxy_pass container:internalPort; } }
→ Docker maps X:X on the host
```

- `ServiceDatabase` has `public_port` (int, nullable) and `is_public` (bool)
- `StartDatabaseProxy` generates nginx config with a single `server` block
- The Livewire component (`Service/Index.php`) has one `publicPort` property and one `isPublic` toggle

## Solution Design

### Approach: `coolify.proxyPorts` Docker label + `proxy_ports` DB column

Instead of modifying Coolify's core `public_port`/`is_public` mechanism (which works well for single-port databases), we add a **parallel multi-port proxy** system that integrates cleanly with the existing one:

1. **A new `coolify.proxyPorts` Docker label** in compose templates defines which ports a database can expose
2. **A new `proxy_ports` JSON column** on `service_databases` stores user-configured public→internal port mappings
3. **The `StartDatabaseProxy` overlay** reads `proxy_ports` and generates multiple nginx `server` blocks
4. **The UI overlay** shows a port mapping table when multiple ports are available

### Why this approach?

- **No schema change to existing columns** — `public_port` and `is_public` continue to work for the primary port (backward compatible)
- **Template-driven** — template authors declare available ports via a label; users don't need to guess
- **Compose-native** — uses Docker labels, consistent with `coolify.database` convention
- **Per-service granularity** — each ServiceDatabase can have different proxy port configurations
- **No parsers.php overlay needed** — labels pass through as-is in compose YAML
- **Works with custom templates** — any template author can add `coolify.proxyPorts` to their services

### Label Format

```yaml
services:
  memgraph:
    image: memgraph/memgraph:latest
    labels:
      coolify.database: "true"
      coolify.proxyPorts: "7687:bolt,7444:https-logs"
```

Format: `internalPort:label,internalPort:label,...`
- `internalPort` — the container port to expose
- `label` — human-readable name for the UI (e.g., "bolt", "https-logs", "browser")
- First port in the list is the "primary" port (used as default for `public_port` if not already set)

If `coolify.proxyPorts` is absent, the system falls back to the existing single-port behavior using `DATABASE_PORT_MAP`.

### Database Schema Change

```sql
ALTER TABLE service_databases ADD COLUMN proxy_ports JSON NULLABLE;
```

JSON Format stored in the column:
```json
{
  "7687": {"public_port": 17687, "label": "bolt", "enabled": true},
  "7444": {"public_port": 17444, "label": "https-logs", "enabled": true}
}
```

- Keys are internal ports (string, from the label)
- `public_port` — user-specified external port
- `label` — from the `coolify.proxyPorts` label
- `enabled` — whether this port is proxied

## Changes Required

### 1. Migration: Add `proxy_ports` column to `service_databases`

**File**: `database/migrations/XXXX_add_proxy_ports_to_service_databases.php`

```php
Schema::table('service_databases', function (Blueprint $table) {
    $table->json('proxy_ports')->nullable()->after('public_port');
});
```

### 2. StartDatabaseProxy overlay: Multi-server-block nginx config

**File**: `src/Overrides/Actions/Database/StartDatabaseProxy.php`

When `$database->proxy_ports` is set and has enabled entries:
- Generate nginx config with **multiple `server` blocks** — one per enabled port
- Docker compose `ports` array includes all enabled public ports
- Fall back to existing single-port behavior when `proxy_ports` is null/empty

```php
// Enhanced nginx config with multiple ports
if ($database instanceof ServiceDatabase && !empty($database->proxy_ports)) {
    $proxyPorts = is_array($database->proxy_ports)
        ? $database->proxy_ports
        : json_decode($database->proxy_ports, true);
    $serverBlocks = '';
    $dockerPorts = [];
    foreach ($proxyPorts as $intPort => $config) {
        if (!($config['enabled'] ?? false)) continue;
        $pubPort = $config['public_port'];
        $serverBlocks .= "   server {\n";
        $serverBlocks .= "        listen {$pubPort};\n";
        $serverBlocks .= "        proxy_pass {$containerName}:{$intPort};\n";
        $serverBlocks .= "   }\n";
        $dockerPorts[] = "{$pubPort}:{$pubPort}";
    }
    // Use multi-port config if any ports are enabled
    if (!empty($dockerPorts)) {
        // Generate nginx conf and docker-compose with multiple ports
        // ...
    }
} else {
    // Existing single-port behavior (unchanged)
}
```

### 3. ServiceDatabase model overlay: `proxy_ports` support

**File**: `src/Overrides/Models/ServiceDatabase.php`

- Add `proxy_ports` to `$casts` as `array`
- Add `getServiceDatabaseUrls()` method returning all public URLs with labels
- Add `parseProxyPortsLabel()` static helper to parse `coolify.proxyPorts` label format

```php
public function getServiceDatabaseUrls(): array
{
    if (empty($this->proxy_ports)) {
        return $this->is_public && $this->public_port
            ? [['url' => $this->getServiceDatabaseUrl(), 'label' => 'primary', 'internal_port' => null]]
            : [];
    }

    $urls = [];
    foreach ($this->proxy_ports as $internal => $config) {
        if ($config['enabled'] ?? false) {
            $realIp = $this->service->server->ip;
            if ($this->service->server->isLocalhost() || isDev()) {
                $realIp = base_ip();
            }
            $urls[] = [
                'url' => "{$realIp}:{$config['public_port']}",
                'label' => $config['label'] ?? "port-{$internal}",
                'internal_port' => (int) $internal,
            ];
        }
    }
    return $urls;
}

public static function parseProxyPortsLabel(string $label): array
{
    // Parse "7687:bolt,7444:https-logs" → array
    $result = [];
    foreach (explode(',', $label) as $entry) {
        $entry = trim($entry);
        if (empty($entry)) continue;
        $parts = explode(':', $entry, 2);
        $port = (int) trim($parts[0]);
        $name = isset($parts[1]) ? trim($parts[1]) : "port-{$port}";
        if ($port > 0) {
            $result[(string) $port] = [
                'public_port' => null, // User sets this
                'label' => $name,
                'enabled' => false,
            ];
        }
    }
    return $result;
}
```

### 4. Service/Index.php Livewire overlay: Multi-port proxy UI

**File**: `src/Overrides/Livewire/Project/Service/Index.php`

This is a full overlay of `app/Livewire/Project/Service/Index.php` with minimal additions:

New properties:
```php
public array $proxyPorts = [];
public array $availableProxyPorts = [];
public bool $hasMultiPortProxy = false;
```

Enhanced `initializeDatabaseProperties()`:
```php
private function initializeDatabaseProperties(): void
{
    // ... existing code ...

    // [MULTI-PORT PROXY OVERLAY] Parse coolify.proxyPorts from compose
    $this->availableProxyPorts = $this->parseAvailableProxyPorts();
    $this->hasMultiPortProxy = count($this->availableProxyPorts) > 0;

    if ($this->hasMultiPortProxy) {
        $this->proxyPorts = $this->serviceDatabase->proxy_ports ?? [];
        // Initialize missing ports from available list
        foreach ($this->availableProxyPorts as $port => $info) {
            if (!isset($this->proxyPorts[$port])) {
                $this->proxyPorts[$port] = [
                    'public_port' => null,
                    'label' => $info['label'],
                    'enabled' => false,
                ];
            }
        }
    }
}

private function parseAvailableProxyPorts(): array
{
    // Read coolify.proxyPorts label from compose YAML
    $service = $this->serviceDatabase->service;
    if (!$service || !$service->docker_compose_raw) return [];

    $compose = Yaml::parse($service->docker_compose_raw);
    $serviceConfig = data_get($compose, "services.{$this->serviceDatabase->name}");
    if (!$serviceConfig) return [];

    $labels = data_get($serviceConfig, 'labels', []);
    // Check map format: coolify.proxyPorts: "7687:bolt,7444:log"
    // Check array format: - coolify.proxyPorts=7687:bolt,7444:log
    $proxyPortsValue = null;
    if (is_array($labels)) {
        foreach ($labels as $key => $value) {
            if (is_string($key) && strtolower($key) === 'coolify.proxyports') {
                $proxyPortsValue = $value;
                break;
            }
            if (is_string($value)) {
                $parts = explode('=', $value, 2);
                if (count($parts) === 2 && strtolower(trim($parts[0])) === 'coolify.proxyports') {
                    $proxyPortsValue = trim($parts[1]);
                    break;
                }
            }
        }
    }

    if (!$proxyPortsValue) return [];
    return ServiceDatabase::parseProxyPortsLabel($proxyPortsValue);
}
```

Enhanced `instantSave()`:
```php
public function instantSave()
{
    try {
        $this->authorize('update', $this->serviceDatabase);

        if ($this->hasMultiPortProxy) {
            // Multi-port mode
            return $this->instantSaveMultiPort();
        }

        // Original single-port behavior (unchanged)
        // ...
    } catch (\Throwable $e) {
        return handleError($e, $this);
    }
}

private function instantSaveMultiPort()
{
    // Validate: at least one port must have a public_port set if enabling
    if ($this->isPublic) {
        $hasAnyEnabled = false;
        foreach ($this->proxyPorts as $port => &$config) {
            if ($config['enabled'] ?? false) {
                if (empty($config['public_port'])) {
                    $this->dispatch('error', "Public port required for {$config['label']} ({$port}).");
                    $this->isPublic = false;
                    return;
                }
                $hasAnyEnabled = true;
            }
        }
        if (!$hasAnyEnabled) {
            $this->dispatch('error', 'Enable at least one port mapping.');
            $this->isPublic = false;
            return;
        }
    }

    $this->serviceDatabase->proxy_ports = $this->proxyPorts;
    $this->serviceDatabase->is_public = $this->isPublic;
    $this->serviceDatabase->save();

    if ($this->isPublic) {
        if (!str($this->serviceDatabase->status)->startsWith('running')) {
            $this->dispatch('error', 'Database must be started to be publicly accessible.');
            $this->isPublic = false;
            $this->serviceDatabase->is_public = false;
            $this->serviceDatabase->save();
            return;
        }
        StartDatabaseProxy::run($this->serviceDatabase);
        $this->db_url_public = $this->serviceDatabase->getServiceDatabaseUrl();
        $this->dispatch('success', 'Database is now publicly accessible.');
    } else {
        StopDatabaseProxy::run($this->serviceDatabase);
        $this->db_url_public = null;
        $this->dispatch('success', 'Database is no longer publicly accessible.');
    }
}

public function updateProxyPorts()
{
    try {
        $this->authorize('update', $this->serviceDatabase);
        $this->serviceDatabase->proxy_ports = $this->proxyPorts;
        $this->serviceDatabase->save();

        // If proxy is running, restart it with new port config
        if ($this->serviceDatabase->is_public) {
            StartDatabaseProxy::run($this->serviceDatabase);
            $this->dispatch('success', 'Proxy port configuration updated.');
        }
    } catch (\Throwable $e) {
        return handleError($e, $this);
    }
}
```

### 5. Blade view overlay: Multi-port proxy section

**File**: `src/Overrides/Views/livewire/project/service/index.blade.php`

Full copy of `resources/views/livewire/project/service/index.blade.php` with enhanced proxy section:

```blade
{{-- Corelix Enhanced: Multi-port proxy section --}}
@if ($hasMultiPortProxy)
    <div class="flex flex-col gap-2">
        <div class="flex items-center gap-2 py-2">
            <h3>Proxy</h3>
            <x-loading wire:loading wire:target="instantSave,updateProxyPorts" />
            @if ($serviceDatabase->is_public)
                <x-slide-over fullScreen>
                    <x-slot:title>Proxy Logs</x-slot:title>
                    <x-slot:content>
                        <livewire:project.shared.get-logs :server="$server" :resource="$service"
                            :servicesubtype="$serviceDatabase" container="{{ $serviceDatabase->uuid }}-proxy"
                            :collapsible="false" lazy />
                    </x-slot:content>
                    <x-forms.button @click="slideOverOpen=true">Logs</x-forms.button>
                </x-slide-over>
            @endif
        </div>

        <div class="flex flex-col gap-2 w-64">
            <x-forms.checkbox canGate="update" :canResource="$serviceDatabase" instantSave id="isPublic"
                label="Make it publicly available" />
        </div>

        <div class="flex flex-col gap-2 mt-2">
            <h4 class="text-sm font-medium">Port Mappings</h4>
            <p class="text-xs text-neutral-400">Configure which ports to expose publicly.</p>
            @foreach ($proxyPorts as $internalPort => $config)
                <div class="flex items-center gap-4">
                    <x-forms.checkbox
                        wire:model.live="proxyPorts.{{ $internalPort }}.enabled"
                        wire:change="updateProxyPorts"
                        label="{{ $config['label'] ?? 'port' }} ({{ $internalPort }})"
                        :disabled="$serviceDatabase->is_public" />
                    <x-forms.input
                        wire:model.defer="proxyPorts.{{ $internalPort }}.public_port"
                        placeholder="{{ $internalPort }}"
                        label="Public Port"
                        type="number"
                        :disabled="$serviceDatabase->is_public" />
                </div>
            @endforeach
        </div>

        @if ($serviceDatabase->is_public)
            @php $urls = $serviceDatabase->getServiceDatabaseUrls(); @endphp
            @foreach ($urls as $urlInfo)
                <x-forms.input
                    label="{{ $urlInfo['label'] }} ({{ $urlInfo['internal_port'] }})"
                    helper="Your credentials are available in your environment variables."
                    type="password"
                    readonly
                    value="{{ $urlInfo['url'] }}" />
            @endforeach
        @endif
    </div>
@else
    {{-- Original single-port proxy section (unchanged) --}}
    <div class="flex flex-col gap-2">
        ...existing proxy section...
    </div>
@endif
```

### 6. StopDatabaseProxy: No changes needed

The existing `StopDatabaseProxy` does `docker rm -f {uuid}-proxy` which removes the entire proxy container. This works regardless of how many ports it was serving.

### 7. Dockerfile updates

```dockerfile
# Multi-port proxy overlays
COPY --chown=www-data:www-data src/Overrides/Livewire/Project/Service/Index.php \
    /var/www/html/app/Livewire/Project/Service/Index.php
COPY --chown=www-data:www-data src/Overrides/Views/livewire/project/service/index.blade.php \
    /var/www/html/resources/views/livewire/project/service/index.blade.php
```

## How It Works End-to-End

### Template Author Experience

```yaml
# documentation: https://memgraph.com/docs
# slogan: Real-time graph database
# type: database

services:
  memgraph:
    image: memgraph/memgraph:latest
    labels:
      coolify.database: "true"
      coolify.proxyPorts: "7687:bolt,7444:log-viewer"
    volumes:
      - memgraph-data:/var/lib/memgraph
```

### User Experience

1. User deploys Memgraph from template
2. Classified as ServiceDatabase (via `coolify.database`)
3. On the service page, "Proxy" section shows:
   - Toggle: "Make it publicly available"
   - Port mappings table:
     - [ ] bolt (7687) → Public Port: `[_____]`
     - [ ] log-viewer (7444) → Public Port: `[_____]`
4. User enters public ports (e.g., 17687, 17444), enables both checkboxes
5. User enables the main toggle → nginx proxy starts with two `server` blocks
6. Both ports are accessible externally
7. UI shows public URLs:
   - bolt (7687): `1.2.3.4:17687`
   - log-viewer (7444): `1.2.3.4:17444`

### Fallback for Non-Labeled Databases

Databases without `coolify.proxyPorts`:
- Continue using the existing single-port behavior
- `DATABASE_PORT_MAP` determines the internal port
- UI shows the original single port input
- Zero behavioral change

## Files Modified/Created

| File | Action | Description |
|------|--------|-------------|
| `database/migrations/XXXX_add_proxy_ports_to_service_databases.php` | **Create** | Add `proxy_ports` JSON column |
| `src/Overrides/Actions/Database/StartDatabaseProxy.php` | **Modify** | Multi-server-block nginx config |
| `src/Overrides/Models/ServiceDatabase.php` | **Modify** | Add `getServiceDatabaseUrls()`, cast `proxy_ports`, add `parseProxyPortsLabel()` |
| `src/Overrides/Livewire/Project/Service/Index.php` | **Create** | Override with multi-port proxy logic |
| `src/Overrides/Views/livewire/project/service/index.blade.php` | **Create** | View overlay with multi-port proxy UI |
| `docker/Dockerfile` | **Modify** | Add COPY lines for new overlays |
| `CLAUDE.md` | **Modify** | Document multi-port proxy architecture |
| `AGENTS.md` | **Modify** | Document overlay files and pitfalls |
| `README.md` | **Modify** | Document multi-port proxy for users |
| `docs/custom-templates.md` | **Modify** | Document `coolify.proxyPorts` label |

## Risks and Mitigations

| Risk | Mitigation |
|------|------------|
| Port conflicts on host | Validate port uniqueness across all databases on the server before starting proxy |
| View overlay drift with Coolify upstream | Mark enhanced sections with `{{-- Corelix Platform --}}` comments; the overlay is a full file copy |
| `proxy_ports` JSON schema migration | Column is nullable; existing databases unaffected |
| Template without `coolify.proxyPorts` label | Falls back to existing single-port behavior seamlessly |
| Service/Index.php is large (~560 lines) | Only add properties and methods; existing methods unchanged |
| `proxy_ports` and `public_port` coexistence | When multi-port is active, `public_port` stores the primary port; `is_public` gates everything |

## Testing Checklist

- [ ] Deploy Memgraph template with `coolify.proxyPorts: "7687:bolt,7444:log-viewer"`
- [ ] Verify classification as ServiceDatabase
- [ ] UI shows port mapping table instead of single port input
- [ ] Enter public ports, enable both checkboxes
- [ ] Enable "Make Publicly Available" → proxy starts with two server blocks
- [ ] Connect to bolt port externally
- [ ] Access log viewer on secondary port
- [ ] Disable one port checkbox, save → proxy restarts with only one server block
- [ ] Disable all ports / toggle off → proxy container removed
- [ ] Deploy a standard database (PostgreSQL) → single-port behavior unchanged
- [ ] Re-deploy/re-parse service → `proxy_ports` config preserved
- [ ] Port conflict validation works (two databases can't use same public port on same server)
