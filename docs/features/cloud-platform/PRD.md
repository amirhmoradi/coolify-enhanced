# Corelix.io Cloud Platform — Product Requirements Document

## Problem Statement

Coolify-enhanced is currently distributed as a self-hosted addon for Coolify v4. While it provides powerful features (granular permissions, encrypted backups, network isolation, cluster management, whitelabeling), it requires users to:

1. **Provision their own servers** — Users must manually set up VPS instances from cloud providers
2. **Install and maintain Coolify** — Self-hosting requires DevOps knowledge for installation, upgrades, and troubleshooting
3. **Manage infrastructure** — Firewall rules, SSH keys, SSL certificates, Docker configuration, backup storage
4. **No recurring revenue model** — The project generates value but lacks a sustainable SaaS revenue stream
5. **No enterprise distribution channel** — Large organizations cannot license the source code for private deployment

### Competitive Landscape

| Platform | Model | Infra Ownership | Pricing | Source Available | Enterprise Self-Host |
|----------|-------|----------------|---------|-----------------|---------------------|
| Vercel | Managed | Platform-owned (AWS) | $0/20/custom | No | No |
| Railway | Managed | Platform-owned | Usage-based ~$5+ | No | No |
| Render | Managed | Platform-owned | Tiered $0-45+ | No | No |
| Fly.io | Managed | Platform-owned (bare metal) | Usage-based | No | No |
| Heroku | Managed | AWS | $5-500+ | No | Enterprise add-on |
| Coolify Cloud | BYOS only | User-owned | $5/mo flat | Yes (open source) | N/A (self-host is default) |
| **Corelix.io** | **Hybrid** | **Platform + user** | **Tiered + server** | **Enterprise license** | **Yes (source license)** |

**Gap:** No PaaS offers the combination of managed infrastructure + BYOS escape hatch + enterprise source licensing. Coolify Cloud is BYOS-only with no server provisioning. Vercel/Railway/Render are managed-only with no self-hosting option.

## Goals

1. **Managed cloud service** — Users sign up, deploy apps, and pay monthly. No DevOps required
2. **Automated server provisioning** — Provision Hetzner servers via API; install Coolify automatically
3. **Multi-tenancy** — Isolated tenant environments with resource quotas and plan-based limits
4. **Billing and subscriptions** — Stripe-powered tiered plans with server-cost billing
5. **BYOS escape hatch** — Power users can connect their own servers alongside managed ones
6. **Enterprise distribution** — Managed private instances and source code licensing for large organizations
7. **Sustainable revenue** — Monthly recurring revenue from cloud subscriptions; annual revenue from enterprise licenses
8. **EU-first positioning** — Hetzner's EU data centers as a differentiator for GDPR-conscious customers

## Non-Goals

- Building a new PaaS engine from scratch (leverage Coolify + corelix-platform)
- Kubernetes-based infrastructure for MVP (Docker/Swarm first)
- Global edge deployment (like Vercel/Fly.io) — focus on dedicated servers first
- Free tier at launch (add after product-market fit validation)
- Mobile app (web dashboard only)
- Marketplace for third-party add-ons (Phase 3+)

## Solution Design

### Architecture: Enhanced Coolify as Cloud Platform

The platform is built on top of the existing Coolify + corelix-platform stack. Key insight: Coolify's **teams** map directly to cloud **tenants**. The cloud layer adds provisioning, billing, onboarding, and admin capabilities around the existing deployment engine.

```
┌─────────────────────────────────────────────────────────────┐
│                    corelix.io                                │
│                                                              │
│  ┌─ Marketing ──────────────────────────────────┐           │
│  │  corelix.io — Landing, pricing, docs, sign-up │           │
│  └──────────────────────────────────────────────┘           │
│                       │                                      │
│  ┌─ Cloud Control Plane (app.corelix.io) ───────┐           │
│  │                                               │           │
│  │  ┌─────────────┐ ┌──────────┐ ┌───────────┐ │           │
│  │  │ Provisioner  │ │ Billing  │ │ Metering  │ │           │
│  │  │ Service      │ │ Service  │ │ Service   │ │           │
│  │  │              │ │          │ │           │ │           │
│  │  │ Hetzner API  │ │ Stripe   │ │ Per-team  │ │           │
│  │  │ (+ future    │ │ Cashier  │ │ resource  │ │           │
│  │  │ providers)   │ │          │ │ tracking  │ │           │
│  │  └─────────────┘ └──────────┘ └───────────┘ │           │
│  │                                               │           │
│  │  ┌─────────────┐ ┌──────────┐ ┌───────────┐ │           │
│  │  │ Onboarding  │ │ Admin    │ │ Tenant    │ │           │
│  │  │ Wizard      │ │ Super-   │ │ Isolation │ │           │
│  │  │             │ │ Dashboard│ │ Layer     │ │           │
│  │  └─────────────┘ └──────────┘ └───────────┘ │           │
│  └──────────────────────────────────────────────┘           │
│                       │                                      │
│  ┌─ Platform Core ──────────────────────────────┐           │
│  │  Coolify v4 + corelix-platform                │           │
│  │  Deployments, Git, SSL, Proxy, DBs, Backups,  │           │
│  │  Permissions, Networks, Clusters, MCP          │           │
│  └──────────────────────────────────────────────┘           │
│                       │                                      │
│  ┌─ Infrastructure ────────────────────────────┐            │
│  │  ┌──────────┐ ┌───────────┐ ┌────────────┐ │            │
│  │  │ Hetzner  │ │DigitalOcean│ │   BYOS    │ │            │
│  │  │ (Phase 1)│ │ (Phase 2) │ │ (Phase 2) │ │            │
│  │  └──────────┘ └───────────┘ └────────────┘ │            │
│  └─────────────────────────────────────────────┘            │
└─────────────────────────────────────────────────────────────┘
```

### Server Provisioning

```
User: "Add Server"
    → Select size (Small/Medium/Large/XL)
    → Select region (Falkenstein/Nuremberg/Helsinki/Ashburn/Singapore)
    → ProvisionerService dispatches ProvisionServerJob
        → Hetzner API: create server (Ubuntu 24.04, SSH key, firewall)
        → Wait for server ready (poll status)
        → SSH: run Coolify install script
        → SSH: configure server for team
        → Register server in Coolify DB
        → Health check
        → Mark as ready, notify user
```

**Provider abstraction:**

```php
interface CloudProviderInterface
{
    public function createServer(ServerSpec $spec): ProvisioningResult;
    public function deleteServer(string $providerId): void;
    public function resizeServer(string $providerId, string $newType): void;
    public function getServerStatus(string $providerId): ServerStatus;
    public function listRegions(): array;
    public function listServerTypes(): array;
    public function createFirewall(FirewallSpec $spec): string;
    public function createSshKey(string $name, string $publicKey): string;
}
```

Implementations: `HetznerProvider`, `DigitalOceanProvider` (Phase 2), `VultrProvider` (Phase 2).

### Multi-Tenancy Model

For MVP, use Coolify's existing team system as the tenant boundary:

- **Tenant = Team**: Each customer gets a Coolify team. Team isolation is already enforced
- **Server ownership**: Provisioned servers belong to the tenant's team
- **Resource quotas**: New `TenantQuotaService` enforces plan limits (max servers, apps, databases, team members, storage)
- **Rate limiting**: API rate limits per team (using Laravel's built-in throttle middleware)
- **Data isolation**: Coolify already scopes all queries by team. corelix-platform adds environment-level isolation via `ProjectPermissionScope` and `EnvironmentPermissionScope`

**Phase 3 upgrade path**: Enterprise customers get dedicated Coolify instances (separate Docker containers, separate databases). The `EnterpriseTenantService` provisions isolated control planes.

### Billing Model

**Plan tiers:**

| Plan | Platform Fee | Included Servers | Server Add-on | Team Members | Apps |
|------|-------------|-----------------|----------------|-------------|------|
| Hobby | $0/mo | 1 shared (2 vCPU, 4GB) | N/A | 1 | 3 |
| Pro | $19/mo | None (pay per server) | $12-65/mo per server | 2 | Unlimited |
| Team | $49/mo per seat | None (pay per server) | Same | Unlimited | Unlimited |
| Enterprise | Custom | Dedicated infra | Custom | Unlimited | Unlimited |

**Server pricing (managed Hetzner):**

| Size | Specs | Hetzner Cost | Corelix Price | Gross Margin |
|------|-------|-------------|---------------|-------------|
| Small | 2 vCPU, 4GB, 40GB SSD | ~$5/mo | $12/mo | 58% |
| Medium | 4 vCPU, 8GB, 80GB SSD | ~$9/mo | $19/mo | 53% |
| Large | 8 vCPU, 16GB, 160GB SSD | ~$17/mo | $35/mo | 51% |
| XL | 16 vCPU, 32GB, 320GB SSD | ~$33/mo | $65/mo | 49% |

**Implementation:**
- Laravel Cashier (Stripe) for subscription management
- `SubscriptionPlan` model defines plan limits
- `BillingService` handles plan changes, proration, invoice generation
- `ServerBillingJob` tracks server usage (provisioned hours) per billing cycle
- Webhook handler for Stripe events (payment_succeeded, subscription_updated, etc.)

### User Onboarding Flow

```
1. Sign up (email + password, or GitHub/Google OAuth)
2. Email verification
3. Plan selection page (pricing comparison)
4. Payment method (Stripe checkout or card form)
5. "Deploy your first app" wizard:
   a. Server auto-provisioning (show progress)
   b. Connect Git repository (GitHub/GitLab)
   c. Configure build settings (auto-detected)
   d. Deploy → show build logs in real-time
6. Dashboard with deployed app status
```

### Admin Super-Dashboard

Platform operators (Corelix team) get a super-admin view separate from tenant dashboards:

- **Tenant overview**: All tenants, plan, MRR, server count, app count, last active
- **Revenue dashboard**: MRR, churn rate, ARPU, growth charts
- **Server fleet**: All provisioned servers across tenants, health status, utilization
- **Provisioning queue**: Pending/in-progress server provisioning jobs
- **Support flags**: Tenants with issues (failed deployments, overdue payments)
- **System health**: Platform uptime, API latency, background job queue depth

### Whitelabeling for Corelix

Use the existing whitelabeling feature to rebrand:

```
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
```

## Technical Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Build on Coolify vs from scratch | Build on Coolify | 18-24mo time savings; deployment engine already works |
| Multi-tenancy approach | Shared instance (teams = tenants) | Simpler ops; Coolify already isolates by team |
| First cloud provider | Hetzner | 60-70% cheaper than AWS; strong EU presence; clean API |
| Payment processor | Stripe (Laravel Cashier) | Best metered billing API; mature Laravel integration |
| Marketing site | Separate (Next.js or static) | Decoupled from app; different deploy cadence; SEO optimized |
| App domain | app.corelix.io | Clear separation from marketing site |
| Server provisioning | Queue-based async jobs | Provisioning takes 2-5 min; must not block HTTP request |
| SSH key management | Platform-managed keys | Each team gets a unique SSH key pair; stored encrypted |
| Hetzner PHP SDK | hetzner-cloud-php/client or hetzner-laravel | Modern, maintained, Laravel-compatible |
| Tenant quotas | Database-enforced | Middleware + model observers check quotas before writes |
| Enterprise isolation | Dedicated instances (Phase 3) | Stronger isolation than shared teams; meets compliance |
| Enterprise licensing | BSL or proprietary + annual | Prevents piracy; allows self-hosting; renewal revenue |

## User Experience

### Managed Mode (Default)

1. Sign up at corelix.io
2. Choose plan, enter payment
3. Click "Add Server" → select size and region → auto-provisioned in 2-5 min
4. Deploy apps via Git push or UI
5. Manage via Corelix dashboard (rebranded Coolify)
6. Pay monthly: platform fee + server costs

### BYOS Mode (Phase 2)

1. Sign up at corelix.io
2. Choose plan (BYOS plans have lower platform fee)
3. "Connect Your Server" → copy install command → run on your server
4. Server appears in dashboard → deploy apps
5. Pay monthly: platform fee only (no server cost)

### Enterprise Source License

1. Contact sales via corelix.io/enterprise
2. Negotiate license (annual, based on scale)
3. Receive source code access (private Git repo or archive)
4. Deploy on own infrastructure using provided guide
5. Receive updates, support, and deployment assistance
6. Annual renewal for continued updates and support

## Feature Classification (Cloud Platform)

These features are added to `config/features.php` as pro-tier:

| Key | Name | Category | Description |
|-----|------|----------|-------------|
| `SERVER_PROVISIONING` | Server Provisioning | cloud | Automated Hetzner/DO/AWS server provisioning via API |
| `BILLING` | Billing & Subscriptions | cloud | Stripe billing, tiered plans, invoices, payment methods |
| `CLOUD_ONBOARDING` | Cloud Onboarding | cloud | Self-service sign-up, guided onboarding, first-deploy wizard |
| `ADMIN_DASHBOARD` | Admin Super-Dashboard | cloud | Platform operator view: tenants, revenue, fleet, support |
| `USAGE_METERING` | Usage Metering | cloud | Per-tenant compute, storage, bandwidth tracking |
| `RESOURCE_QUOTAS` | Resource Quotas | cloud | Plan-based resource limits enforcement |

## Files Modified / Created

### New Files (Estimated)

**Server Provisioning:**

| File | Purpose |
|------|---------|
| `src/Contracts/CloudProviderInterface.php` | Provider abstraction interface |
| `src/Providers/HetznerProvider.php` | Hetzner Cloud API implementation |
| `src/Services/ProvisionerService.php` | Server provisioning orchestrator |
| `src/Jobs/ProvisionServerJob.php` | Async server provisioning job |
| `src/Jobs/DestroyServerJob.php` | Server teardown job |
| `src/Jobs/ResizeServerJob.php` | Server resize job |
| `src/Models/ProvisionedServer.php` | Provisioned server metadata (provider ID, region, size) |
| `src/Models/CloudRegion.php` | Available regions per provider |
| `src/Http/Controllers/Api/ProvisioningController.php` | Provisioning REST API |
| `src/Livewire/ServerProvisioner.php` | Server provisioning UI component |
| `src/Livewire/ServerProvisionerPage.php` | Full-page provisioning view |
| `database/migrations/*_create_provisioned_servers_table.php` | Migration |

**Billing:**

| File | Purpose |
|------|---------|
| `src/Services/BillingService.php` | Stripe billing orchestration |
| `src/Services/TenantQuotaService.php` | Plan limit enforcement |
| `src/Models/SubscriptionPlan.php` | Plan definitions with limits |
| `src/Jobs/ServerBillingJob.php` | Per-cycle server usage billing |
| `src/Http/Controllers/Api/BillingController.php` | Billing REST API |
| `src/Http/Controllers/StripeWebhookController.php` | Stripe webhook handler |
| `src/Livewire/BillingDashboard.php` | Billing management UI |
| `src/Livewire/PlanSelector.php` | Plan selection component |
| `database/migrations/*_create_subscription_plans_table.php` | Plans migration |

**Onboarding:**

| File | Purpose |
|------|---------|
| `src/Livewire/OnboardingWizard.php` | Guided onboarding flow |
| `src/Services/OnboardingService.php` | Onboarding state machine |
| `resources/views/livewire/onboarding-wizard.blade.php` | Wizard view |

**Admin Dashboard:**

| File | Purpose |
|------|---------|
| `src/Livewire/AdminDashboard.php` | Platform admin dashboard |
| `src/Livewire/TenantList.php` | Tenant management list |
| `src/Livewire/RevenueOverview.php` | Revenue metrics component |
| `src/Livewire/ServerFleet.php` | Server fleet management |
| `src/Http/Controllers/Api/AdminController.php` | Admin REST API |
| `src/Http/Middleware/PlatformAdminMiddleware.php` | Platform admin auth |

**Metering & Quotas (Phase 2):**

| File | Purpose |
|------|---------|
| `src/Services/MeteringService.php` | Resource usage tracking |
| `src/Jobs/CollectUsageMetricsJob.php` | Periodic usage collection |
| `src/Models/UsageRecord.php` | Per-tenant usage records |
| `src/Http/Middleware/QuotaEnforcementMiddleware.php` | Quota checking |

### Modified Files

| File | Change |
|------|--------|
| `src/CorelixPlatformServiceProvider.php` | Register cloud services, routes, components |
| `config/coolify-enhanced.php` | Cloud platform configuration section |
| `config/features.php` | Add cloud feature flags |
| `routes/api.php` | Cloud API routes |
| `routes/web.php` | Cloud web routes |
| `docker/Dockerfile` | Corelix build variant |
| `.github/workflows/docker-publish.yml` | Corelix image builds |
| `.free-edition-ignore` | Exclude all cloud files from free build |
| `.env.free` | Cloud feature flags set to false |
| `composer.json` | Add stripe/cashier, hetzner-cloud-php dependencies |

## Risks

| Risk | Impact | Mitigation |
|------|--------|------------|
| Coolify upstream breaking changes | High | Pin Coolify version; automated overlay sync tests |
| Hetzner API outages | Medium | Queue-based provisioning with retry; manual provisioning fallback |
| Noisy neighbor (shared instance) | Medium | cgroups limits, per-team server isolation, resource quotas |
| 3-month MVP timeline | High | Defer: metering, BYOS, multi-provider, enterprise portal |
| Coolify v5 architecture shift | High | Maintain v4 fork; contribute upstream; prepare migration |
| Multi-tenant security breach | Critical | Network isolation (exists); audit trail; penetration testing |
| Hetzner pricing changes | Low | Multi-provider support absorbs changes; margins are healthy |
| Enterprise source piracy | Medium | BSL license; legal contracts; license key enforcement |
| Stripe payment fraud | Medium | Stripe Radar fraud detection; manual review for large accounts |
| Server provisioning failures | Medium | Retry logic; admin alerting; manual intervention queue |

## Testing Checklist

### Server Provisioning
- [ ] Hetzner server created via API with correct specs
- [ ] SSH key injected and accessible
- [ ] Firewall rules applied (only Coolify-required ports)
- [ ] Coolify installed automatically on provisioned server
- [ ] Server appears in tenant's Coolify dashboard
- [ ] Server resize changes specs correctly
- [ ] Server deletion cleans up Hetzner and Coolify records
- [ ] Provisioning failure triggers retry and admin notification
- [ ] Provider abstraction allows swapping Hetzner for mock in tests

### Billing
- [ ] Stripe subscription created on plan selection
- [ ] Plan upgrade/downgrade prorates correctly
- [ ] Server costs appear as usage items on invoice
- [ ] Payment failure triggers grace period, then suspension
- [ ] Webhook handler processes all relevant Stripe events
- [ ] Invoice PDF generation works

### Multi-Tenancy
- [ ] Tenant A cannot see Tenant B's servers, apps, or data
- [ ] Resource quotas enforced (cannot exceed plan limits)
- [ ] API rate limiting works per-team
- [ ] Server ownership validated (cannot attach server to wrong team)

### Onboarding
- [ ] Sign-up creates team + Stripe customer
- [ ] Plan selection redirects to Stripe checkout
- [ ] First server auto-provisioned after payment
- [ ] Deploy wizard works end-to-end (Git connect → deploy → live)
- [ ] User can skip wizard and go directly to dashboard

### Admin Dashboard
- [ ] Shows all tenants with plan, MRR, activity
- [ ] Revenue dashboard shows correct MRR, churn, ARPU
- [ ] Server fleet shows all provisioned servers across tenants
- [ ] Admin can impersonate tenant for support
- [ ] Admin can suspend/unsuspend tenant

## Appendix: Hetzner Cloud API Reference

Key API endpoints for server provisioning:

| Endpoint | Purpose |
|----------|---------|
| `POST /servers` | Create server |
| `GET /servers/{id}` | Get server status |
| `DELETE /servers/{id}` | Delete server |
| `POST /servers/{id}/actions/resize` | Resize server |
| `GET /server_types` | List available sizes |
| `GET /locations` | List data center locations |
| `POST /ssh_keys` | Create SSH key |
| `POST /firewalls` | Create firewall |
| `POST /firewalls/{id}/actions/set_rules` | Set firewall rules |

PHP SDK: `hetzner-cloud-php/client` (modern, PSR-18) or `amar8eka/hetzner-laravel` (Laravel-native, type-safe).
