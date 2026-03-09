# Corelix.io — Cloud Platform

## Overview

Corelix.io is a managed PaaS (Platform-as-a-Service) built on top of the corelix-platform codebase. It transforms the self-hosted corelix-platform addon into a cloud service where users sign up, deploy apps, and pay monthly — with no DevOps knowledge required.

**Key differentiators:**
- **Hybrid model**: Fully managed servers (Hetzner) + BYOS escape hatch
- **EU-first**: Hetzner's EU data centers for GDPR-conscious customers
- **60-70% cheaper** than AWS-based competitors (Vercel, Railway, Render)
- **Enterprise source licensing**: Large organizations can buy the source code for self-hosting
- **Full-stack**: Apps + databases + services (not frontend-only like Vercel)

## Architecture

Corelix.io extends corelix-platform rather than building from scratch. Coolify's existing **teams** map to cloud **tenants**. New services handle provisioning, billing, metering, and administration.

```
┌──────────────────────────────────────────────┐
│  Cloud Control Plane                          │
│  ┌─────────────┐ ┌──────────┐ ┌───────────┐ │
│  │ Provisioner  │ │ Billing  │ │ Metering  │ │
│  │ (Hetzner)    │ │ (Stripe) │ │ (Usage)   │ │
│  └─────────────┘ └──────────┘ └───────────┘ │
├──────────────────────────────────────────────┤
│  Multi-Tenancy Layer (quotas, rate limiting)  │
├──────────────────────────────────────────────┤
│  Coolify Core + corelix-platform              │
│  (Deployments, Git, SSL, DBs, Backups, etc.)  │
├──────────────────────────────────────────────┤
│  Infrastructure                               │
│  Hetzner (MVP) → DigitalOcean → BYOS          │
└──────────────────────────────────────────────┘
```

## Components

### Server Provisioning

Automated server lifecycle management via cloud provider APIs.

**Provider abstraction:**
```php
use CorelixIo\Platform\Contracts\CloudProviderInterface;

// Hetzner implementation (MVP)
$provider = app(CloudProviderInterface::class); // resolves HetznerProvider
$result = $provider->createServer(new ServerSpec(
    name: 'my-server',
    type: 'cx22',
    region: 'fsn1',
    image: 'ubuntu-24.04',
    sshKeyId: $keyId,
));
```

**Provisioning flow:**
1. User selects server size and region
2. `ProvisionServerJob` dispatched (async, 2-5 min)
3. Hetzner API creates server
4. SSH: Coolify installed automatically
5. Server registered in Coolify DB, assigned to team
6. User notified, server appears in dashboard

**API:**
```
POST   /api/v1/cloud/servers          — Provision server
GET    /api/v1/cloud/servers          — List provisioned servers
GET    /api/v1/cloud/servers/{uuid}   — Get server status
DELETE /api/v1/cloud/servers/{uuid}   — Destroy server
POST   /api/v1/cloud/servers/{uuid}/resize — Resize server
GET    /api/v1/cloud/regions          — Available regions
GET    /api/v1/cloud/server-types     — Available sizes
```

### Billing & Subscriptions

Stripe-powered subscription management via Laravel Cashier.

**Plans:**

| Plan | Platform Fee | Server Add-on | Team Members | Apps |
|------|-------------|----------------|-------------|------|
| Hobby | $0/mo | 1 included | 1 | 3 |
| Pro | $19/mo | $12-65/mo each | 2 | Unlimited |
| Team | $49/mo/seat | Same | Unlimited | Unlimited |
| Enterprise | Custom | Custom | Unlimited | Unlimited |

**Key services:**
- `BillingService` — subscription CRUD, plan changes, invoice access
- `TenantQuotaService` — plan limit enforcement, usage tracking
- `StripeWebhookController` — payment events, subscription lifecycle

### User Onboarding

Guided multi-step wizard from sign-up to first deployment:

```
Sign-up → Plan selection → Payment → Server provisioning → Git connect → First deploy → Dashboard
```

State machine tracked in DB. Users can skip steps and return later.

### Admin Super-Dashboard

Platform operator view (Corelix team only):

- **Tenant overview**: all customers, plans, MRR, activity
- **Revenue dashboard**: MRR, churn rate, ARPU, growth
- **Server fleet**: all provisioned servers, health, utilization
- **Support flags**: failed payments, stuck provisioning, errors
- **Tenant actions**: impersonate, suspend/unsuspend

### Multi-Tenancy

- **Tenant = Coolify Team**: existing team isolation reused
- **Resource quotas**: enforced via middleware per plan limits
- **Rate limiting**: per-team API throttling
- **Server ownership**: servers belong to teams, validated on access
- **Data isolation**: Coolify + corelix-platform scopes all queries by team

### Whitelabeling

Corelix uses the existing whitelabeling feature to rebrand:

```bash
PAAS_WHITELABEL=true
PAAS_BRAND_NAME=Corelix
PAAS_DOCS_URL=https://docs.corelix.io
PAAS_WEBSITE_URL=https://corelix.io
PAAS_HIDE_SPONSORSHIP=true
```

## Feature Flags

All cloud features are registered in `config/features.php` as `pro` tier:

| Key | Description | Parent |
|-----|-------------|--------|
| `SERVER_PROVISIONING` | Automated cloud server provisioning | — |
| `BILLING` | Stripe subscriptions and invoices | — |
| `CLOUD_ONBOARDING` | Self-service sign-up and onboarding wizard | — |
| `ADMIN_DASHBOARD` | Platform operator super-dashboard | — |
| `USAGE_METERING` | Per-tenant resource usage tracking | `BILLING` |
| `RESOURCE_QUOTAS` | Plan-based resource limits | `BILLING` |

## Enterprise Options

### Managed Private Instance

- Dedicated Corelix control plane (isolated container + database)
- Single-tenant: no shared resources with other customers
- SLA: 99.9%+ uptime guarantee
- Pricing: $500-5K+/mo depending on scale

### Source Code License

- Full pro source code (private Git access or archive)
- Self-host on customer's own infrastructure
- Includes deployment support and update notifications
- Pricing: $50K-200K+/yr annual license

## Phased Rollout

| Phase | Timeline | Deliverables |
|-------|----------|-------------|
| 0: Foundation | Week 1 | Whitelabel as Corelix, register feature flags |
| 1: Provisioning | Week 2-3 | Hetzner API integration, server lifecycle |
| 2: Billing | Week 4-5 | Stripe subscriptions, plans, quotas |
| 3: Onboarding | Week 6-8 | Sign-up flow, admin dashboard |
| 4: Launch | Week 9-12 | Marketing site, docs, MCP tools, polish |
| 5: Post-MVP | Month 4-6 | Usage metering, BYOS, multi-provider |

## Environment Variables

| Variable | Default | Purpose |
|----------|---------|---------|
| `FEATURE_SERVER_PROVISIONING` | `true` | Gate: server provisioning |
| `FEATURE_BILLING` | `true` | Gate: billing and subscriptions |
| `FEATURE_CLOUD_ONBOARDING` | `true` | Gate: onboarding wizard |
| `FEATURE_ADMIN_DASHBOARD` | `true` | Gate: admin super-dashboard |
| `FEATURE_USAGE_METERING` | `true` | Gate: usage metering |
| `FEATURE_RESOURCE_QUOTAS` | `true` | Gate: resource quotas |
| `CLOUD_PROVIDER` | `hetzner` | Default cloud provider |
| `HETZNER_API_TOKEN` | — | Hetzner Cloud API token |
| `HETZNER_DEFAULT_REGION` | `fsn1` | Default Hetzner region |
| `HETZNER_DEFAULT_TYPE` | `cx22` | Default Hetzner server type |
| `STRIPE_KEY` | — | Stripe publishable key |
| `STRIPE_SECRET` | — | Stripe secret key |
| `STRIPE_WEBHOOK_SECRET` | — | Stripe webhook signing secret |

## File List

### New Files

| File | Purpose |
|------|---------|
| `src/Contracts/CloudProviderInterface.php` | Cloud provider abstraction |
| `src/Providers/HetznerProvider.php` | Hetzner API implementation |
| `src/Services/ProvisionerService.php` | Server provisioning orchestrator |
| `src/Services/BillingService.php` | Stripe billing management |
| `src/Services/TenantQuotaService.php` | Plan limit enforcement |
| `src/Services/OnboardingService.php` | Onboarding state machine |
| `src/Services/MeteringService.php` | Usage metrics collection (Phase 5) |
| `src/Models/ProvisionedServer.php` | Provisioned server model |
| `src/Models/SubscriptionPlan.php` | Subscription plan model |
| `src/Models/UsageRecord.php` | Usage tracking model (Phase 5) |
| `src/Jobs/ProvisionServerJob.php` | Async server provisioning |
| `src/Jobs/DestroyServerJob.php` | Async server teardown |
| `src/Jobs/InstallCoolifyOnServerJob.php` | Coolify installation on new server |
| `src/Jobs/ServerBillingJob.php` | Per-cycle server usage billing |
| `src/Jobs/CollectUsageMetricsJob.php` | Usage collection cron (Phase 5) |
| `src/Http/Controllers/Api/ProvisioningController.php` | Provisioning REST API |
| `src/Http/Controllers/Api/BillingController.php` | Billing REST API |
| `src/Http/Controllers/Api/AdminController.php` | Admin REST API |
| `src/Http/Controllers/StripeWebhookController.php` | Stripe webhooks |
| `src/Http/Middleware/PlatformAdminMiddleware.php` | Platform admin auth |
| `src/Http/Middleware/QuotaEnforcementMiddleware.php` | Quota checking |
| `src/DataTransferObjects/ServerSpec.php` | Server specification DTO |
| `src/DataTransferObjects/ProvisioningResult.php` | Provisioning result DTO |
| `src/DataTransferObjects/ServerStatus.php` | Server status DTO |
| `src/Livewire/ServerProvisioner.php` | Server provisioning UI |
| `src/Livewire/BillingDashboard.php` | Billing management UI |
| `src/Livewire/PlanSelector.php` | Plan selection component |
| `src/Livewire/OnboardingWizard.php` | Onboarding flow component |
| `src/Livewire/AdminDashboard.php` | Admin super-dashboard |
| `src/Livewire/TenantList.php` | Tenant management list |
| `docker/brands/corelix/` | Corelix brand assets |
| `mcp-server/src/tools/cloud.ts` | MCP cloud management tools |

### Modified Files

| File | Change |
|------|--------|
| `config/features.php` | Add 6 cloud feature entries |
| `config/coolify-enhanced.php` | Add cloud provider configuration section |
| `src/CorelixPlatformServiceProvider.php` | Register cloud services, routes, Livewire components |
| `routes/api.php` | Cloud API routes |
| `routes/web.php` | Cloud web routes |
| `composer.json` | Add laravel/cashier, hetzner-cloud-php dependencies |
| `docker/Dockerfile` | Corelix build variant |
| `.github/workflows/docker-publish.yml` | Corelix image build |
| `.free-edition-ignore` | Exclude cloud files from free build |
| `.env.free` | Cloud flags set to false |

## Related Documentation

- [PRD](PRD.md) — Product Requirements Document
- [Implementation Plan](plan.md) — Detailed task-by-task plan
- [Feature Flag Gating](../feature-flag-gating/README.md) — How pro features are gated
- [Whitelabeling](../whitelabeling/README.md) — Brand replacement used for Corelix branding
- Corelix platform operational and account-specific documentation is delivered through the Corelix customer docs experience.
