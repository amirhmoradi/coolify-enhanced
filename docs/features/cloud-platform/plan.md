# Corelix.io Cloud Platform — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a managed PaaS (corelix.io) on top of corelix-platform with automated Hetzner server provisioning, Stripe billing, user onboarding, and platform admin tools.

**Architecture:** Extend corelix-platform as the platform core. Coolify teams = tenants. New services for provisioning (Hetzner API), billing (Stripe/Cashier), metering, and admin. Provider abstraction via `CloudProviderInterface` for future multi-cloud. Feature-flagged as pro tier.

**Tech Stack:** Laravel 11 + Livewire 3 (existing), Laravel Cashier (Stripe), hetzner-cloud-php/client or hetzner-laravel, existing corelix-platform overlay architecture

---

## Phase 0: Foundation & Whitelabel (Week 1)

### Task 0.1: Whitelabel as Corelix

**Files:**
- Modify: `docker/Dockerfile` (add Corelix PAAS_* defaults)
- Modify: `.github/workflows/docker-publish.yml` (add Corelix build variant)
- Create: `docker/brands/corelix/` (logo, favicon, OG image assets)

**Step 1:** Create `docker/brands/corelix/` directory with brand assets:
- `logo.svg` — Corelix logo
- `favicon.svg` — Corelix favicon
- `og-image.png` — OpenGraph image (1200x630)

**Step 2:** Add Corelix build variant to `docker-publish.yml`:
```yaml
- name: Build Corelix image
  uses: docker/build-push-action@v5
  with:
    build-args: |
      PAAS_WHITELABEL=true
      PAAS_BRAND_NAME=Corelix
      PAAS_BRAND_DESCRIPTION=Deploy your apps to the cloud in seconds
      PAAS_DOCS_URL=https://docs.corelix.io
      PAAS_WEBSITE_URL=https://corelix.io
      PAAS_SUPPORT_URL=https://corelix.io/support
      PAAS_TWITTER_HANDLE=@corelixio
      PAAS_HIDE_SPONSORSHIP=true
      PAAS_HIDE_VERSION_LINK=true
      PAAS_HIDE_ANALYTICS=true
    tags: ghcr.io/corelix/corelix:latest
```

**Step 3:** Verify branded image builds and all "Coolify" references are replaced.

**Step 4:** Commit: `feat(cloud): whitelabel as Corelix with brand assets`

### Task 0.2: Register Cloud Features in Feature Registry

**Files:**
- Modify: `config/features.php` (add 6 cloud feature entries)
- Modify: `.free-edition-ignore` (add cloud-only files)
- Modify: `.env.free` (add cloud flags set to false)

**Step 1:** Add cloud features to `config/features.php` registry:
```php
[
    'key' => 'SERVER_PROVISIONING',
    'name' => 'Server Provisioning',
    'description' => 'Automated server provisioning via Hetzner, DigitalOcean, and other cloud providers',
    'tier' => 'pro',
    'default' => true,
    'category' => 'cloud',
    'env_var' => 'FEATURE_SERVER_PROVISIONING',
    'parent' => null,
],
[
    'key' => 'BILLING',
    'name' => 'Billing & Subscriptions',
    'description' => 'Stripe-powered subscription plans, invoices, and payment management',
    'tier' => 'pro',
    'default' => true,
    'category' => 'cloud',
    'env_var' => 'FEATURE_BILLING',
    'parent' => null,
],
[
    'key' => 'CLOUD_ONBOARDING',
    'name' => 'Cloud Onboarding',
    'description' => 'Self-service sign-up flow with guided first deployment wizard',
    'tier' => 'pro',
    'default' => true,
    'category' => 'cloud',
    'env_var' => 'FEATURE_CLOUD_ONBOARDING',
    'parent' => null,
],
[
    'key' => 'ADMIN_DASHBOARD',
    'name' => 'Admin Super-Dashboard',
    'description' => 'Platform operator view with tenant, revenue, and fleet management',
    'tier' => 'pro',
    'default' => true,
    'category' => 'cloud',
    'env_var' => 'FEATURE_ADMIN_DASHBOARD',
    'parent' => null,
],
[
    'key' => 'USAGE_METERING',
    'name' => 'Usage Metering',
    'description' => 'Per-tenant compute, storage, and bandwidth usage tracking',
    'tier' => 'pro',
    'default' => true,
    'category' => 'cloud',
    'env_var' => 'FEATURE_USAGE_METERING',
    'parent' => 'BILLING',
],
[
    'key' => 'RESOURCE_QUOTAS',
    'name' => 'Resource Quotas',
    'description' => 'Plan-based resource limits enforcement (max servers, apps, databases)',
    'tier' => 'pro',
    'default' => true,
    'category' => 'cloud',
    'env_var' => 'FEATURE_RESOURCE_QUOTAS',
    'parent' => 'BILLING',
],
```

**Step 2:** Add cloud files to `.free-edition-ignore`.

**Step 3:** Add `FEATURE_SERVER_PROVISIONING=false`, etc. to `.env.free`.

**Step 4:** Run `./scripts/build-free.sh` to verify free edition still compiles.

**Step 5:** Commit: `feat(cloud): register cloud platform feature flags`

---

## Phase 1: Server Provisioning (Week 2-3)

### Task 1.1: Cloud Provider Abstraction

**Files:**
- Create: `src/Contracts/CloudProviderInterface.php`
- Create: `src/DataTransferObjects/ServerSpec.php`
- Create: `src/DataTransferObjects/ProvisioningResult.php`
- Create: `src/DataTransferObjects/ServerStatus.php`

**Step 1:** Define the provider interface:
```php
interface CloudProviderInterface
{
    public function name(): string;
    public function createServer(ServerSpec $spec): ProvisioningResult;
    public function deleteServer(string $providerId): void;
    public function resizeServer(string $providerId, string $newType): void;
    public function getServerStatus(string $providerId): ServerStatus;
    public function listRegions(): array;
    public function listServerTypes(): array;
    public function createFirewall(string $name, array $rules): string;
    public function createSshKey(string $name, string $publicKey): string;
    public function deleteSshKey(string $keyId): void;
}
```

**Step 2:** Create DTOs:
```php
class ServerSpec {
    public string $name;
    public string $type;      // e.g., 'cx22'
    public string $region;    // e.g., 'fsn1'
    public string $image;     // e.g., 'ubuntu-24.04'
    public string $sshKeyId;
    public ?string $firewallId;
    public array $labels = [];
}

class ProvisioningResult {
    public string $providerId;
    public string $ipv4;
    public ?string $ipv6;
    public string $status;
    public ?string $rootPassword;
}

class ServerStatus {
    public string $status;  // 'initializing', 'running', 'stopping', 'off', 'deleting'
    public ?string $ipv4;
    public array $metrics;  // CPU, RAM, disk usage
}
```

**Step 3:** Commit: `feat(cloud): cloud provider abstraction interface and DTOs`

### Task 1.2: Hetzner Provider Implementation

**Files:**
- Create: `src/Providers/HetznerProvider.php`
- Modify: `composer.json` (add hetzner-cloud-php/client dependency)

**Step 1:** Add dependency:
```bash
composer require hetzner-cloud-php/client
```

**Step 2:** Implement `HetznerProvider` using the SDK:
- `createServer()` — calls Hetzner servers API, returns `ProvisioningResult`
- `deleteServer()` — deletes server by Hetzner ID
- `resizeServer()` — server resize action
- `getServerStatus()` — poll server status
- `listRegions()` — returns Hetzner locations (fsn1, nbg1, hel1, ash, hil, sin)
- `listServerTypes()` — returns available sizes (cx22, cx32, cx42, cx52)
- `createFirewall()` — creates firewall with SSH (22), HTTP (80), HTTPS (443), Coolify ports
- `createSshKey()` — uploads SSH public key

**Step 3:** Add Hetzner config to `config/coolify-enhanced.php`:
```php
'cloud' => [
    'default_provider' => env('CLOUD_PROVIDER', 'hetzner'),
    'hetzner' => [
        'api_token' => env('HETZNER_API_TOKEN'),
        'default_image' => env('HETZNER_DEFAULT_IMAGE', 'ubuntu-24.04'),
        'default_region' => env('HETZNER_DEFAULT_REGION', 'fsn1'),
        'default_type' => env('HETZNER_DEFAULT_TYPE', 'cx22'),
    ],
],
```

**Step 4:** Commit: `feat(cloud): Hetzner provider implementation`

### Task 1.3: Provisioned Server Model & Migration

**Files:**
- Create: `database/migrations/*_create_provisioned_servers_table.php`
- Create: `src/Models/ProvisionedServer.php`

**Step 1:** Create migration:
```php
Schema::create('provisioned_servers', function (Blueprint $table) {
    $table->id();
    $table->uuid('uuid')->unique();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();
    $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
    $table->string('provider');           // 'hetzner', 'digitalocean'
    $table->string('provider_id');        // Hetzner server ID
    $table->string('provider_type');      // 'cx22', 'cx32', etc.
    $table->string('region');             // 'fsn1', 'nbg1', etc.
    $table->string('ipv4')->nullable();
    $table->string('ipv6')->nullable();
    $table->string('ssh_key_id')->nullable();
    $table->string('firewall_id')->nullable();
    $table->string('status');             // 'provisioning', 'installing', 'ready', 'error', 'deleting'
    $table->json('metadata')->nullable(); // Provider-specific metadata
    $table->text('error_message')->nullable();
    $table->decimal('monthly_cost', 8, 2)->default(0);
    $table->timestamp('provisioned_at')->nullable();
    $table->timestamp('ready_at')->nullable();
    $table->timestamps();

    $table->unique(['provider', 'provider_id']);
    $table->index('team_id');
    $table->index('status');
});
```

**Step 2:** Create `ProvisionedServer` model with team scope, status helpers, and provider relationship.

**Step 3:** Commit: `feat(cloud): provisioned server model and migration`

### Task 1.4: Provisioner Service & Jobs

**Files:**
- Create: `src/Services/ProvisionerService.php`
- Create: `src/Jobs/ProvisionServerJob.php`
- Create: `src/Jobs/DestroyServerJob.php`
- Create: `src/Jobs/InstallCoolifyOnServerJob.php`

**Step 1:** `ProvisionerService` orchestrates the full flow:
```php
class ProvisionerService
{
    public function provision(Team $team, ServerSpec $spec): ProvisionedServer;
    public function destroy(ProvisionedServer $server): void;
    public function resize(ProvisionedServer $server, string $newType): void;
    public function getStatus(ProvisionedServer $server): ServerStatus;
    public function resolveProvider(string $name): CloudProviderInterface;
}
```

**Step 2:** `ProvisionServerJob` — async job:
1. Create SSH key pair (if not exists for team)
2. Create firewall (if not exists)
3. Call provider `createServer()`
4. Poll until server is running
5. Dispatch `InstallCoolifyOnServerJob`

**Step 3:** `InstallCoolifyOnServerJob`:
1. SSH into new server
2. Run Coolify install script
3. Wait for Coolify to start
4. Register server in Coolify's `servers` table (belonging to team)
5. Link `provisioned_servers.server_id` → `servers.id`
6. Mark as `ready`

**Step 4:** `DestroyServerJob`:
1. Remove server from Coolify DB
2. Call provider `deleteServer()`
3. Clean up SSH key and firewall (if no other servers use them)
4. Delete `ProvisionedServer` record

**Step 5:** Commit: `feat(cloud): provisioner service and async jobs`

### Task 1.5: Provisioning API & UI

**Files:**
- Create: `src/Http/Controllers/Api/ProvisioningController.php`
- Create: `src/Livewire/ServerProvisioner.php`
- Create: `resources/views/livewire/server-provisioner.blade.php`
- Modify: `routes/api.php`
- Modify: `routes/web.php`

**Step 1:** REST API endpoints:
```
POST   /api/v1/cloud/servers          — Provision new server
GET    /api/v1/cloud/servers          — List provisioned servers
GET    /api/v1/cloud/servers/{uuid}   — Get server status
DELETE /api/v1/cloud/servers/{uuid}   — Destroy server
POST   /api/v1/cloud/servers/{uuid}/resize — Resize server
GET    /api/v1/cloud/regions          — List available regions
GET    /api/v1/cloud/server-types     — List available sizes
```

**Step 2:** Livewire UI component:
- Server size selector (radio cards)
- Region selector (map or dropdown)
- "Provision Server" button → shows progress states
- Server list with status badges and actions (resize, destroy)

**Step 3:** Gate routes with `feature:SERVER_PROVISIONING` middleware.

**Step 4:** Commit: `feat(cloud): provisioning API and UI`

---

## Phase 2: Billing (Week 4-5)

### Task 2.1: Stripe & Laravel Cashier Setup

**Files:**
- Modify: `composer.json` (add laravel/cashier)
- Create: `database/migrations/*_create_subscription_plans_table.php`
- Create: `src/Models/SubscriptionPlan.php`

**Step 1:** Install Cashier:
```bash
composer require laravel/cashier
```

**Step 2:** Create `SubscriptionPlan` model with plan limits:
```php
Schema::create('subscription_plans', function (Blueprint $table) {
    $table->id();
    $table->string('slug')->unique();         // 'hobby', 'pro', 'team', 'enterprise'
    $table->string('name');
    $table->string('stripe_price_id');
    $table->decimal('price', 8, 2);
    $table->string('billing_period');          // 'monthly', 'yearly'
    $table->integer('max_servers')->default(1);
    $table->integer('max_apps')->default(3);
    $table->integer('max_databases')->default(1);
    $table->integer('max_team_members')->default(1);
    $table->integer('max_storage_gb')->default(10);
    $table->boolean('byos_allowed')->default(false);
    $table->boolean('is_active')->default(true);
    $table->json('features')->nullable();       // Plan-specific feature flags
    $table->timestamps();
});
```

**Step 3:** Seed default plans (Hobby, Pro, Team).

**Step 4:** Commit: `feat(cloud): Stripe Cashier setup and subscription plans`

### Task 2.2: Billing Service

**Files:**
- Create: `src/Services/BillingService.php`
- Create: `src/Services/TenantQuotaService.php`
- Create: `src/Http/Controllers/StripeWebhookController.php`

**Step 1:** `BillingService`:
```php
class BillingService
{
    public function createSubscription(Team $team, SubscriptionPlan $plan): Subscription;
    public function changePlan(Team $team, SubscriptionPlan $newPlan): void;
    public function cancelSubscription(Team $team): void;
    public function resumeSubscription(Team $team): void;
    public function addServerUsage(Team $team, ProvisionedServer $server): void;
    public function getCurrentInvoice(Team $team): ?Invoice;
    public function getInvoiceHistory(Team $team): Collection;
    public function isInGoodStanding(Team $team): bool;
}
```

**Step 2:** `TenantQuotaService`:
```php
class TenantQuotaService
{
    public function canProvisionServer(Team $team): bool;
    public function canCreateApp(Team $team): bool;
    public function canCreateDatabase(Team $team): bool;
    public function canAddTeamMember(Team $team): bool;
    public function getUsage(Team $team): array;  // { servers: 2/5, apps: 8/∞, ... }
    public function getPlanLimits(Team $team): SubscriptionPlan;
    public function enforceQuotas(Team $team): void;  // throws QuotaExceededException
}
```

**Step 3:** Stripe webhook controller for `invoice.paid`, `invoice.payment_failed`, `customer.subscription.updated`, `customer.subscription.deleted`.

**Step 4:** Commit: `feat(cloud): billing and quota services`

### Task 2.3: Billing UI

**Files:**
- Create: `src/Livewire/BillingDashboard.php`
- Create: `src/Livewire/PlanSelector.php`
- Create: `resources/views/livewire/billing-dashboard.blade.php`
- Create: `resources/views/livewire/plan-selector.blade.php`

**Step 1:** `PlanSelector` — shows plan comparison cards with "Current Plan" badge, upgrade/downgrade buttons, redirects to Stripe checkout.

**Step 2:** `BillingDashboard` — shows current plan, next invoice amount, payment method, invoice history, usage overview. Integrates Stripe's customer portal for payment method changes.

**Step 3:** Add billing routes gated by `feature:BILLING`.

**Step 4:** Commit: `feat(cloud): billing dashboard and plan selector UI`

---

## Phase 3: Onboarding & Admin (Week 6-8)

### Task 3.1: Sign-Up & Onboarding Flow

**Files:**
- Create: `src/Livewire/OnboardingWizard.php`
- Create: `src/Services/OnboardingService.php`
- Create: `resources/views/livewire/onboarding-wizard.blade.php`

**Step 1:** `OnboardingService` manages a state machine:
```
States: plan_selection → payment → server_provisioning → git_connect → first_deploy → complete
```

**Step 2:** `OnboardingWizard` multi-step component:
1. Welcome + plan selection (PlanSelector component)
2. Payment (Stripe checkout redirect → return)
3. Server provisioning (auto-triggered, show progress bar)
4. Connect Git repository (GitHub OAuth flow)
5. Deploy first app (select repo, auto-detect build, deploy)
6. Success page with dashboard link

**Step 3:** Track onboarding progress in DB (`onboarding_state` column on teams table).

**Step 4:** Commit: `feat(cloud): onboarding wizard with multi-step flow`

### Task 3.2: Admin Super-Dashboard

**Files:**
- Create: `src/Http/Middleware/PlatformAdminMiddleware.php`
- Create: `src/Livewire/AdminDashboard.php`
- Create: `src/Livewire/TenantList.php`
- Create: `resources/views/livewire/admin-dashboard.blade.php`
- Create: `resources/views/livewire/tenant-list.blade.php`

**Step 1:** `PlatformAdminMiddleware` — checks `is_platform_admin` flag on user (new column, or check against env-configured admin email list).

**Step 2:** `AdminDashboard`:
- MRR card (sum of active subscriptions)
- Total tenants card
- Total servers card (provisioned across all tenants)
- Server fleet health (green/yellow/red breakdown)
- Recent sign-ups list
- Failed payment alerts

**Step 3:** `TenantList`:
- Searchable/sortable table of all teams
- Columns: name, plan, MRR, servers, apps, last active, status
- Actions: view details, impersonate, suspend/unsuspend

**Step 4:** Admin routes at `/admin/*` gated by `PlatformAdminMiddleware`.

**Step 5:** Commit: `feat(cloud): admin super-dashboard with tenant management`

### Task 3.3: Quota Enforcement Middleware

**Files:**
- Create: `src/Http/Middleware/QuotaEnforcementMiddleware.php`
- Modify: `src/CorelixPlatformServiceProvider.php`

**Step 1:** Middleware checks quotas before resource creation:
- Before server provisioning: check `max_servers`
- Before app creation: check `max_apps`
- Before database creation: check `max_databases`
- Before team member invite: check `max_team_members`

**Step 2:** Returns 402 with upgrade prompt when quota exceeded.

**Step 3:** Register as route middleware, apply to relevant routes.

**Step 4:** Commit: `feat(cloud): quota enforcement middleware`

---

## Phase 4: Polish & Launch (Week 9-12)

### Task 4.1: Marketing Site

**Files:**
- Create: separate repository or `marketing/` directory
- Static site or Next.js app at `corelix.io`

**Step 1:** Landing page with:
- Hero: "Deploy your apps to the cloud in seconds"
- Feature grid (what Corelix offers)
- Pricing table (interactive plan comparison)
- Testimonials / social proof section
- CTA: "Get Started Free" → sign-up flow

**Step 2:** Documentation site at `docs.corelix.io` (Docusaurus, Nextra, or similar).

**Step 3:** Commit: `feat(cloud): marketing site scaffold`

### Task 4.2: Settings Integration

**Files:**
- Modify: `src/Overrides/Views/components/settings/navbar.blade.php`
- Modify: relevant sidebar overlays

**Step 1:** Add "Billing" tab to Settings navbar (gated by `@feature('BILLING')`).

**Step 2:** Add "Server Fleet" to server sidebar showing provisioned vs BYOS servers.

**Step 3:** Commit: `feat(cloud): billing and fleet settings integration`

### Task 4.3: MCP Server Cloud Tools

**Files:**
- Create: `mcp-server/src/tools/cloud.ts`
- Modify: `mcp-server/src/lib/mcp-server.ts`

**Step 1:** Add cloud management MCP tools:
```
cloud-provision-server    — Provision a new managed server
cloud-list-servers        — List provisioned servers
cloud-destroy-server      — Destroy a provisioned server
cloud-get-billing         — Get current billing summary
cloud-list-plans          — List available subscription plans
cloud-get-usage           — Get resource usage for current team
```

**Step 2:** Gate tools behind `COOLIFY_CLOUD=true` env var.

**Step 3:** Commit: `feat(cloud): MCP server cloud management tools`

### Task 4.4: Documentation Updates

**Files:**
- Modify: `README.md`
- Modify: `CLAUDE.md`
- Modify: `AGENTS.md`

**Step 1:** Update README with:
- Corelix.io section (what it is, how to sign up)
- Cloud features section (provisioning, billing, plans)
- Enterprise licensing section

**Step 2:** Update CLAUDE.md with:
- Cloud platform architecture knowledge
- New services (ProvisionerService, BillingService, TenantQuotaService)
- New models and files
- Common pitfalls

**Step 3:** Update AGENTS.md with:
- Cloud platform development guidelines
- Provider abstraction patterns
- Billing integration patterns

**Step 4:** Commit: `docs: update project documentation for cloud platform`

---

## Phase 5: Post-MVP (Month 4-6)

### Task 5.1: Usage Metering

- `MeteringService` collects per-tenant metrics via cron:
  - vCPU-hours (from Hetzner metrics API)
  - Storage GB (from Docker volumes)
  - Bandwidth GB (from Hetzner traffic API)
- `UsageRecord` model stores daily snapshots
- Feed into Stripe metered billing (usage records API)

### Task 5.2: BYOS Mode

- "Connect Your Server" flow (Coolify already supports adding servers)
- Billing: platform fee only (no server cost)
- BYOS-specific plans with lower platform fee
- Health monitoring for user-managed servers

### Task 5.3: Additional Cloud Providers

- `DigitalOceanProvider` implements `CloudProviderInterface`
- `VultrProvider` implements `CloudProviderInterface`
- Provider selector in provisioning UI
- Per-provider pricing tables

### Task 5.4: Enterprise Private Instances

- `EnterpriseTenantService` provisions dedicated Coolify instances
- Docker Compose stack per enterprise tenant
- Separate database per tenant (PostgreSQL)
- Dedicated subdomain: `{tenant}.app.corelix.io`
- SLA monitoring and alerting

---

## Dependency Map

```
Phase 0 (Foundation)
├── Task 0.1: Whitelabel ← no deps
└── Task 0.2: Feature flags ← no deps

Phase 1 (Provisioning)
├── Task 1.1: Provider abstraction ← no deps
├── Task 1.2: Hetzner provider ← 1.1
├── Task 1.3: Server model ← no deps
├── Task 1.4: Provisioner service ← 1.1, 1.2, 1.3
└── Task 1.5: API & UI ← 1.4

Phase 2 (Billing)
├── Task 2.1: Stripe setup ← no deps
├── Task 2.2: Billing service ← 2.1
└── Task 2.3: Billing UI ← 2.2

Phase 3 (Onboarding & Admin)
├── Task 3.1: Onboarding ← 1.5, 2.3
├── Task 3.2: Admin dashboard ← 1.3, 2.2
└── Task 3.3: Quota enforcement ← 2.2

Phase 4 (Polish)
├── Task 4.1: Marketing site ← no deps (parallel)
├── Task 4.2: Settings integration ← 2.3
├── Task 4.3: MCP tools ← 1.5, 2.2
└── Task 4.4: Documentation ← all above
```

## Estimated Timeline

| Phase | Duration | Milestone |
|-------|----------|-----------|
| Phase 0 | Week 1 | Corelix branding live |
| Phase 1 | Week 2-3 | Servers auto-provision from UI |
| Phase 2 | Week 4-5 | Billing works end-to-end |
| Phase 3 | Week 6-8 | Sign-up → deploy flow complete |
| Phase 4 | Week 9-12 | Launch-ready with marketing site |
| Phase 5 | Month 4-6 | Metering, BYOS, multi-provider |
