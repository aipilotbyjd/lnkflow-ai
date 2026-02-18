# LinkFlow Pricing & Metering System — Production Plan

> Based on analysis of Make.com, Zapier, and n8n pricing models, mapped to LinkFlow's existing architecture.

---

## Table of Contents

1. [Current State Audit](#1-current-state-audit)
2. [Target Architecture](#2-target-architecture)
3. [Plan Tiers & Feature Matrix](#3-plan-tiers--feature-matrix)
4. [Credit System Design](#4-credit-system-design)
5. [Database Schema](#5-database-schema)
6. [Enforcement Layer](#6-enforcement-layer)
7. [Stripe Integration](#7-stripe-integration)
8. [Usage Tracking & Analytics](#8-usage-tracking--analytics)
9. [Notification System](#9-notification-system)
10. [Engine-Side Enforcement](#10-engine-side-enforcement)
11. [API Endpoints](#11-api-endpoints)
12. [Implementation Phases](#12-implementation-phases)

---

## 1. Current State Audit

### What Exists ✅

| Component | Status | Location |
|-----------|--------|----------|
| Plan model (name, slug, JSON limits/features) | ✅ Built | `app/Models/Plan.php` |
| Subscription model (Stripe IDs, status enum) | ✅ Built | `app/Models/Subscription.php` |
| SubscriptionStatus enum (active/trialing/past_due/canceled/expired) | ✅ Built | `app/Enums/SubscriptionStatus.php` |
| Workspace → Plan (HasOneThrough) | ✅ Built | `app/Models/Workspace.php:220` |
| `canUseFeature()` / `getLimit()` helpers | ✅ Built | `app/Models/Workspace.php:232-244` |
| Stripe checkout, portal, cancel, resume, change plan | ✅ Built | `app/Services/StripeService.php` |
| BillingController (checkout, portal, cancel, resume, change) | ✅ Built | `app/Http/Controllers/Api/V1/BillingController.php` |
| Redis rate limiter on dispatch (100 req/min, flat) | ✅ Built | `app/Services/WorkflowDispatchService.php:130` |
| 3 plans seeded (Free/Pro/Business) | ✅ Built | `database/seeders/PlanSeeder.php` |
| Execution model with `estimated_cost_usd` | ✅ Built | `app/Models/Execution.php` |
| ExecutionNode tracking per node | ✅ Built | `app/Models/ExecutionNode.php` |
| ConnectorCallAttempts + daily metrics | ✅ Built | migration `130703` |
| WorkspacePolicy (allowed/blocked nodes, cost limits) | ✅ Built | migration `130703` |
| ResolveWorkspaceRole middleware | ✅ Built | `app/Http/Middleware/ResolveWorkspaceRole.php` |
| Gate::before for permission checks | ✅ Built | `app/Providers/AppServiceProvider.php:37` |

### What's Missing ❌

| Component | Impact | Priority |
|-----------|--------|----------|
| **Credit/operation metering** — no per-node counting | No usage-based billing possible | 🔴 Critical |
| **Plan enforcement** — `canUseFeature()`/`getLimit()` never called | Plans exist but nothing is restricted | 🔴 Critical |
| **Stripe webhook handler** — no sync from Stripe → DB | Subscriptions don't update on payment events | 🔴 Critical |
| **Usage tracking table** — no period-based aggregation | Can't show dashboards or enforce monthly limits | 🔴 Critical |
| **Plan-aware rate limiting** — flat 100/min for all | Free and Enterprise get same rate | 🟡 High |
| **Overage handling** — no soft/hard limit distinction | Free users never blocked, Business never overage-billed | 🟡 High |
| **Credit pack / add-on purchasing** | No upsell path when credits run out | 🟡 High |
| **Usage notifications** (80%, 100% alerts) | Users hit limits with no warning | 🟡 High |
| **Execution time enforcement** | No max timeout per plan | 🟡 High |
| **Log retention enforcement** | All plans see all logs forever | 🟢 Medium |
| **Data transfer metering** | No tracking of payload sizes | 🟢 Medium |
| **Enterprise plan (custom/contact sales)** | Missing top tier | 🟢 Medium |
| **Annual credit flexibility** (yearly = pool credits) | No billing interval differentiation | 🟢 Medium |

---

## 2. Target Architecture

```
┌──────────────────────────────────────────────────────────┐
│                    REQUEST FLOW                          │
│                                                          │
│  Client Request                                          │
│       │                                                  │
│       ▼                                                  │
│  ┌─────────────┐                                         │
│  │  auth:api   │  (existing)                             │
│  └──────┬──────┘                                         │
│         ▼                                                │
│  ┌──────────────────┐                                    │
│  │ ResolveWorkspace  │  (existing)                       │
│  │ Role              │                                   │
│  └──────┬───────────┘                                    │
│         ▼                                                │
│  ┌──────────────────┐     ┌─────────────────────┐        │
│  │ EnforcePlanLimits │────▶│ PlanEnforcementSvc  │        │
│  │ (NEW middleware)  │     │  - checkCredits()   │        │
│  └──────┬───────────┘     │  - checkFeature()   │        │
│         │                 │  - checkQuota()     │        │
│         │                 │  - checkRateLimit() │        │
│         │                 └─────────┬───────────┘        │
│         ▼                           │                    │
│  ┌──────────────────┐               │                    │
│  │   Controller      │               ▼                    │
│  │  (existing)       │     ┌─────────────────────┐        │
│  └──────┬───────────┘     │ CreditMeterService   │        │
│         │                 │  - increment()       │        │
│         ▼                 │  - remaining()       │        │
│  ┌──────────────────┐     │  - resetPeriod()     │        │
│  │ Engine Callback   │────▶│  - usage()           │        │
│  │ (node completed)  │     └─────────┬───────────┘        │
│  └──────────────────┘               │                    │
│                                     ▼                    │
│                            ┌─────────────────────┐        │
│                            │ workspace_usage_     │        │
│                            │ periods (DB)         │        │
│                            └─────────────────────┘        │
│                                                          │
│  ┌──────────────────────────────────────────────────┐    │
│  │              STRIPE SYNC LAYER                    │    │
│  │                                                    │    │
│  │  Webhook ──▶ StripeWebhookController               │    │
│  │               ├─ checkout.session.completed        │    │
│  │               ├─ invoice.payment_succeeded         │    │
│  │               ├─ invoice.payment_failed            │    │
│  │               ├─ customer.subscription.updated     │    │
│  │               ├─ customer.subscription.deleted     │    │
│  │               └─ invoice.created (overage)         │    │
│  └──────────────────────────────────────────────────┘    │
└──────────────────────────────────────────────────────────┘
```

---

## 3. Plan Tiers & Feature Matrix

### 3A. Pricing Tiers (5 tiers, inspired by Make.com + n8n hybrid)

| | **Free** | **Starter** | **Pro** | **Teams** | **Enterprise** |
|---|---|---|---|---|---|
| **Target** | Individuals exploring | Solo builders | Power users | SMB teams | Large orgs |
| **Credits/month** | 1,000 | 10,000 | 50,000 | 200,000 | Custom |
| **Price (monthly)** | $0 | $9 | $29 | $99 | Contact Sales |
| **Price (yearly/mo)** | $0 | $7 | $24 | $79 | Custom |
| **Stripe Price ID** | — | `price_starter_*` | `price_pro_*` | `price_teams_*` | Custom |

### 3B. Quantitative Limits

| Limit | Free | Starter | Pro | Teams | Enterprise |
|-------|------|---------|-----|-------|------------|
| **Credits/month** | 1,000 | 10,000 | 50,000 | 200,000 | Custom |
| **Active workflows** | 3 | 20 | Unlimited | Unlimited | Unlimited |
| **Team members** | 1 | 3 | 10 | 50 | Unlimited |
| **Min schedule interval** | 15 min | 5 min | 1 min | 1 min | 1 min |
| **Max execution time** | 5 min | 15 min | 40 min | 40 min | 60 min |
| **Max file size** | 5 MB | 50 MB | 150 MB | 500 MB | 1 GB |
| **Data transfer/month** | 500 MB | 5 GB | 25 GB | 100 GB | Custom |
| **Concurrent executions** | 1 | 5 | 20 | 50 | 200+ |
| **API rate limit (req/min)** | 0 (no API) | 30 | 60 | 240 | 1,000 |
| **Execution log retention** | 3 days | 14 days | 30 days | 90 days | 365 days |
| **Max saved executions** | 500 | 5,000 | 25,000 | 100,000 | Unlimited |
| **Webhooks** | 1 | 10 | 50 | Unlimited | Unlimited |
| **Credentials** | 5 | 20 | 100 | Unlimited | Unlimited |
| **Variables** | 10 | 50 | 200 | Unlimited | Unlimited |
| **Workflow versions** | 3 | 10 | 50 | Unlimited | Unlimited |
| **AI generation credits** | 5/day | 50/day | 200/day | 1,000/day | Unlimited |

### 3C. Feature Flags (Boolean ON/OFF)

| Feature | Free | Starter | Pro | Teams | Enterprise |
|---------|------|---------|-----|-------|------------|
| **Webhook triggers** | ❌ | ✅ | ✅ | ✅ | ✅ |
| **API access** | ❌ | ✅ | ✅ | ✅ | ✅ |
| **Custom variables** | ❌ | ❌ | ✅ | ✅ | ✅ |
| **Sub-workflows** | ❌ | ❌ | ✅ | ✅ | ✅ |
| **Parallel execution** | ❌ | ❌ | ✅ | ✅ | ✅ |
| **Priority execution** | ❌ | ❌ | `high` | `priority` | `priority` |
| **Full-text log search** | ❌ | ❌ | ✅ | ✅ | ✅ |
| **Deterministic replay** | ❌ | ❌ | ✅ | ✅ | ✅ |
| **Workflow templates** | ✅ (use) | ✅ (use) | ✅ (create+share) | ✅ (create+share) | ✅ (create+share) |
| **Workflow import/export** | ❌ | ✅ | ✅ | ✅ | ✅ |
| **AI auto-fix** | ❌ | ❌ | ✅ | ✅ | ✅ |
| **AI workflow generation** | ❌ | Basic | Full | Full | Full |
| **Approval workflows** | ❌ | ❌ | ❌ | ✅ | ✅ |
| **Workspace policies** | ❌ | ❌ | ❌ | ✅ | ✅ |
| **Environments (dev/staging/prod)** | ❌ | ❌ | ❌ | ✅ | ✅ |
| **Version control (Git)** | ❌ | ❌ | ❌ | ✅ | ✅ |
| **Analytics dashboard** | ❌ | ❌ | Basic | Full | Full |
| **Connector reliability metrics** | ❌ | ❌ | ❌ | ✅ | ✅ |
| **Execution debugger** | ❌ | Basic | Full | Full | Full |
| **Execution runbooks** | ❌ | ❌ | ❌ | ✅ | ✅ |
| **Audit logs** | ❌ | ❌ | ❌ | ❌ | ✅ |
| **SSO / SAML** | ❌ | ❌ | ❌ | ❌ | ✅ |
| **2FA enforcement** | ❌ | ❌ | ❌ | ❌ | ✅ |
| **Custom node types** | ❌ | ❌ | ❌ | ❌ | ✅ |
| **On-prem agent** | ❌ | ❌ | ❌ | ❌ | ✅ |
| **Overage protection** | ❌ (hard block) | ❌ (hard block) | ❌ (hard block) | ✅ (soft limit) | ✅ (soft limit) |
| **Dedicated support** | Community | Email | Email | Priority | 24/7 Dedicated |

### 3D. Credit Pricing Tiers (Scalable, like Make.com)

Credits can be purchased in tiers. Higher tiers = lower cost per credit.

| Credits/Month | Starter | Pro | Teams |
|---------------|---------|-----|-------|
| 10,000 | $9 | $29 | $99 |
| 20,000 | $16 | $49 | $159 |
| 40,000 | $29 | $79 | $249 |
| 80,000 | — | $129 | $399 |
| 150,000 | — | $199 | $599 |
| 300,000 | — | — | $999 |
| 500,000 | — | — | $1,499 |
| 1,000,000 | — | — | $2,499 |

---

## 4. Credit System Design

### 4A. What Counts as a Credit

| Action | Credits | Notes |
|--------|---------|-------|
| **Node execution** (HTTP, Transform, etc.) | 1 | Core unit — each node that runs = 1 credit |
| **AI node execution** | 3 | Higher cost due to LLM usage |
| **Code execution node** (per second) | 2 | Like Make.com's Code App |
| **Webhook receive** | 1 | Trigger counts as 1 credit |
| **Sub-workflow call** | 0 | Only child nodes count (no double billing) |
| **Manual test run** | 1 per node | Testing uses real credits |
| **Retry** | Full cost | Each retry is a new execution |
| **Replay** | Full cost | Deterministic replays count |

### 4B. What Does NOT Count

| Action | Credits |
|--------|---------|
| Workflow create/edit/delete | 0 |
| Viewing execution logs | 0 |
| API calls to LinkFlow API (listing, etc.) | 0 |
| Trigger polling (checking for new data) | 0 |
| Failed node (error before actual API call) | 0 |
| Approval waiting time | 0 |

### 4C. Credit Calculation Flow

```
Execution starts
  └─▶ Engine processes Node #1
        └─▶ Engine callback: POST /api/engine/callback/node-completed
              └─▶ CreditMeterService::increment(workspace_id, credits: 1)
                    ├─▶ Redis INCRBY (real-time counter, fast path)
                    └─▶ Async job: persist to workspace_usage_periods (durability)

  └─▶ Engine processes Node #2 (AI node)
        └─▶ callback → CreditMeterService::increment(workspace_id, credits: 3)

  └─▶ Execution complete
        └─▶ execution_nodes count = 2 → total_credits_used = 4
        └─▶ Update execution.credits_consumed = 4
```

### 4D. Real-Time vs Durable Counting

| Layer | Purpose | Storage |
|-------|---------|---------|
| **Real-time** (hot path) | Fast reads for enforcement (`hasCredits?`) | Redis Hash: `usage:{workspace_id}:{period}` |
| **Durable** (cold path) | Billing accuracy, dashboards, disputes | PostgreSQL: `workspace_usage_periods` table |
| **Sync** | Reconcile Redis ↔ Postgres | Cron job every 5 minutes + on period reset |

---

## 5. Database Schema

### 5A. Update `plans` Table (Migration)

```php
Schema::table('plans', function (Blueprint $table) {
    // Stripe price IDs for scalable credit tiers
    $table->string('stripe_product_id')->nullable()->after('sort_order');
    $table->json('stripe_prices')->nullable()->after('stripe_product_id');
    // stripe_prices = {
    //   "monthly": { "10000": "price_xxx", "20000": "price_yyy", ... },
    //   "yearly":  { "10000": "price_xxx", "20000": "price_yyy", ... }
    // }

    // Credit tier options available for this plan
    $table->json('credit_tiers')->nullable()->after('stripe_prices');
    // credit_tiers = [10000, 20000, 40000] — valid options for this plan
]);
```

### 5B. Update `subscriptions` Table (Migration)

```php
Schema::table('subscriptions', function (Blueprint $table) {
    $table->string('billing_interval', 10)->default('monthly')->after('status');
    // 'monthly' | 'yearly'

    $table->unsignedInteger('credits_monthly')->default(0)->after('billing_interval');
    // The purchased credit tier (e.g., 10000, 50000)

    $table->unsignedInteger('credits_yearly_pool')->default(0)->after('credits_monthly');
    // For yearly billing: total annual pool (credits_monthly * 12)
    // Yearly subscribers can spread usage unevenly across months

    $table->string('stripe_price_id')->nullable()->after('stripe_subscription_id');
    // The specific Stripe price they're on (for the tier)
]);
```

### 5C. New `workspace_usage_periods` Table

```php
Schema::create('workspace_usage_periods', function (Blueprint $table) {
    $table->id();
    $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
    $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
    $table->date('period_start');
    $table->date('period_end');

    // Credit consumption
    $table->unsignedInteger('credits_limit')->default(0);
    $table->unsignedInteger('credits_used')->default(0);
    $table->unsignedInteger('credits_overage')->default(0);
    // credits_overage = max(0, credits_used - credits_limit) — only for soft-limit plans

    // Execution counts
    $table->unsignedInteger('executions_total')->default(0);
    $table->unsignedInteger('executions_succeeded')->default(0);
    $table->unsignedInteger('executions_failed')->default(0);

    // Node-level breakdown
    $table->unsignedInteger('nodes_executed')->default(0);
    $table->unsignedInteger('ai_nodes_executed')->default(0);

    // Data transfer
    $table->unsignedBigInteger('data_transfer_bytes')->default(0);

    // Cost tracking
    $table->decimal('estimated_cost_usd', 10, 4)->default(0);

    // Active workflow snapshot (denormalized)
    $table->unsignedSmallInteger('active_workflows_count')->default(0);
    $table->unsignedSmallInteger('members_count')->default(0);

    // State
    $table->boolean('is_current')->default(false);
    $table->boolean('is_overage_billed')->default(false);
    $table->string('stripe_invoice_id')->nullable();

    $table->timestamps();

    $table->unique(['workspace_id', 'period_start']);
    $table->index(['workspace_id', 'is_current']);
    $table->index(['period_end', 'is_current']);
});
```

### 5D. New `credit_transactions` Table (Ledger)

```php
Schema::create('credit_transactions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
    $table->foreignId('usage_period_id')->constrained('workspace_usage_periods')->cascadeOnDelete();

    $table->enum('type', [
        'execution',      // Normal node execution
        'ai_execution',   // AI node (costs 3 credits)
        'code_execution', // Code node (per second)
        'webhook',        // Webhook trigger
        'refund',         // Manual refund by admin
        'adjustment',     // Admin adjustment
        'credit_pack',    // Purchased add-on credits
        'bonus',          // Promotional credits
    ]);

    $table->integer('credits');
    // Positive = consumed, Negative = refund/bonus/pack

    $table->string('description')->nullable();

    // Link to source
    $table->foreignId('execution_id')->nullable()->constrained()->nullOnDelete();
    $table->foreignId('execution_node_id')->nullable()->constrained()->nullOnDelete();

    $table->timestamp('created_at');

    $table->index(['workspace_id', 'created_at']);
    $table->index(['usage_period_id', 'type']);
    $table->index('execution_id');
});
```

### 5E. New `credit_packs` Table (Add-on Purchases)

```php
Schema::create('credit_packs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
    $table->foreignId('purchased_by')->constrained('users')->cascadeOnDelete();

    $table->unsignedInteger('credits_amount');
    $table->unsignedInteger('credits_remaining');
    $table->integer('price_cents');
    $table->string('currency', 3)->default('usd');

    $table->string('stripe_payment_intent_id')->nullable();
    $table->string('stripe_invoice_id')->nullable();
    $table->enum('status', ['pending', 'active', 'exhausted', 'expired', 'refunded'])->default('pending');

    $table->timestamp('purchased_at');
    $table->timestamp('expires_at')->nullable();
    $table->timestamps();

    $table->index(['workspace_id', 'status']);
    $table->index(['workspace_id', 'expires_at']);
});
```

### 5F. New `usage_daily_snapshots` Table (Analytics)

```php
Schema::create('usage_daily_snapshots', function (Blueprint $table) {
    $table->id();
    $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
    $table->date('day');

    $table->unsignedInteger('credits_used')->default(0);
    $table->unsignedInteger('executions_total')->default(0);
    $table->unsignedInteger('executions_succeeded')->default(0);
    $table->unsignedInteger('executions_failed')->default(0);
    $table->unsignedInteger('nodes_executed')->default(0);
    $table->unsignedBigInteger('data_transfer_bytes')->default(0);
    $table->unsignedSmallInteger('active_workflows')->default(0);
    $table->unsignedSmallInteger('peak_concurrent_executions')->default(0);

    $table->timestamps();

    $table->unique(['workspace_id', 'day']);
});
```

---

## 6. Enforcement Layer

### 6A. PlanEnforcementService

```
app/Services/PlanEnforcementService.php

Methods:
├── checkCredits(Workspace): void              → throws PlanLimitException
├── checkFeature(Workspace, string): void      → throws FeatureNotAvailableException
├── checkQuota(Workspace, string): void         → throws QuotaExceededException
├── checkActiveWorkflows(Workspace): void       → throws QuotaExceededException
├── checkMembers(Workspace): void               → throws QuotaExceededException
├── checkWebhooks(Workspace): void              → throws QuotaExceededException
├── checkCredentials(Workspace): void           → throws QuotaExceededException
├── checkFileSize(int bytes): void              → throws FileSizeLimitException
├── checkDataTransfer(Workspace, int bytes): void → throws DataTransferLimitException
├── checkScheduleInterval(Workspace, int min): void → throws ScheduleIntervalException
├── checkConcurrentExecutions(Workspace): void   → throws ConcurrentLimitException
├── getRateLimitPerMinute(Workspace): int
├── getExecutionPriority(Workspace): string      → 'default' | 'high' | 'priority'
├── getMaxExecutionTime(Workspace): int          → seconds
├── getLogRetentionDays(Workspace): int
└── canOverage(Workspace): bool                  → Teams/Enterprise = true
```

### 6B. New Middleware: `EnforcePlanLimits`

```
app/Http/Middleware/EnforcePlanLimits.php

Usage in routes:
Route::middleware('plan.enforce:credits')          → check credits before execution
Route::middleware('plan.enforce:feature,webhooks')  → check feature flag
Route::middleware('plan.enforce:quota,workflows')   → check workflow count
Route::middleware('plan.enforce:api_access')        → check API access
```

### 6C. Where Enforcement Is Applied

| Action | Enforcement Point | Check |
|--------|------------------|-------|
| **Create workflow** | `WorkflowController@store` | `checkActiveWorkflows()` |
| **Activate workflow** | `WorkflowController@activate` | `checkActiveWorkflows()` |
| **Execute workflow** | `ExecutionController@store` | `checkCredits()` + `checkConcurrentExecutions()` |
| **Dispatch via engine** | `WorkflowDispatchService@dispatch` | `checkCredits()` + plan-aware rate limit |
| **Create webhook** | `WebhookController@store` | `checkFeature('webhooks')` + `checkQuota('webhooks')` |
| **Create credential** | `CredentialController@store` | `checkQuota('credentials')` |
| **Create variable** | `VariableController@store` | `checkFeature('custom_variables')` + `checkQuota('variables')` |
| **Invite member** | `InvitationController@store` | `checkMembers()` |
| **Set schedule < min** | `WorkflowController@update` | `checkScheduleInterval()` |
| **Upload file** | Engine callback | `checkFileSize()` |
| **AI generation** | `AiWorkflowGeneratorController` | `checkFeature('ai_generation')` + daily AI limit |
| **AI auto-fix** | `AiAutoFixController` | `checkFeature('ai_autofix')` |
| **Deterministic replay** | `ExecutionController@rerunDeterministic` | `checkFeature('deterministic_replay')` + `checkCredits()` |
| **Full-text log search** | `ExecutionDebuggerController` | `checkFeature('full_text_log_search')` |
| **Approval workflows** | `WorkflowApprovalController` | `checkFeature('approval_workflows')` |
| **Workspace policies** | `WorkspacePolicyController` | `checkFeature('workspace_policies')` |
| **Environments** | `WorkspaceEnvironmentController` | `checkFeature('environments')` |
| **Import/Export** | `WorkflowImportExportController` | `checkFeature('import_export')` |
| **Connector metrics** | `ConnectorReliabilityController` | `checkFeature('connector_metrics')` |
| **Execution runbooks** | `ExecutionRunbookController` | `checkFeature('execution_runbooks')` |
| **API requests** | API middleware | `checkFeature('api_access')` + plan-aware rate limit |

### 6D. Soft vs Hard Limits

| Plan | Behavior When Credits Exhausted |
|------|-------------------------------|
| **Free** | Hard block. Workflows pause. Show upgrade prompt. |
| **Starter** | Hard block. Workflows pause. Email notification + in-app banner. |
| **Pro** | Hard block. Offer one-time credit pack purchase ($5/1000 credits). |
| **Teams** | Soft limit (overage protection). Continue running. Bill overage at end of period. |
| **Enterprise** | Soft limit. Continue running. Quarterly true-up with account manager. |

---

## 7. Stripe Integration

### 7A. Stripe Product/Price Structure

```
Stripe Products:
├── prod_linkflow_starter
│     ├── price_starter_monthly_10k   → $9/mo
│     ├── price_starter_monthly_20k   → $16/mo
│     ├── price_starter_monthly_40k   → $29/mo
│     ├── price_starter_yearly_10k    → $84/yr ($7/mo)
│     ├── price_starter_yearly_20k    → $156/yr
│     └── price_starter_yearly_40k    → $276/yr
├── prod_linkflow_pro
│     ├── price_pro_monthly_10k       → $29/mo
│     ├── price_pro_monthly_50k       → $79/mo
│     ├── ...
│     └── price_pro_yearly_*
├── prod_linkflow_teams
│     ├── price_teams_monthly_10k     → $99/mo
│     └── ...
├── prod_linkflow_credit_pack         (one-time)
│     ├── price_pack_1000             → $5
│     ├── price_pack_5000             → $20
│     └── price_pack_10000            → $35
└── prod_linkflow_overage             (metered)
      └── price_overage_per_credit    → $0.001/credit (usage_type: metered)
```

### 7B. Stripe Webhook Handler (CRITICAL — Currently Missing)

```
app/Http/Controllers/Api/StripeWebhookController.php

Events to handle:
├── checkout.session.completed
│     → Create/activate subscription
│     → Set plan_id, credits_monthly, billing_interval
│     → Create first workspace_usage_period
│     → Send welcome email
│
├── invoice.payment_succeeded
│     → Update current_period_start / current_period_end
│     → Create new workspace_usage_period
│     → Reset Redis credit counter
│     → Send receipt email
│
├── invoice.payment_failed
│     → Mark subscription as past_due
│     → Send payment failed email
│     → After 3 failures → mark expired, pause workflows
│
├── customer.subscription.updated
│     → Sync plan_id, credits, billing_interval
│     → Handle upgrade: increase limits immediately
│     → Handle downgrade: apply at period end
│
├── customer.subscription.deleted
│     → Mark subscription as canceled
│     → Downgrade to Free plan at period end
│     → Deactivate excess workflows
│
├── payment_intent.succeeded (credit packs)
│     → Activate credit pack
│     → Add credits to current period
│
└── invoice.created (overage)
      → Attach metered usage line items
      → Calculate overage charges
```

### 7C. Overage Billing Flow (Teams/Enterprise)

```
1. Workspace hits credit limit
2. PlanEnforcementService::canOverage() → true (Teams/Enterprise)
3. Executions continue → credits_overage incremented
4. End of billing period (or invoice.created webhook):
   a. Calculate: overage_credits = credits_used - credits_limit
   b. Report to Stripe: StripeSubscription::createUsageRecord(quantity: overage_credits)
   c. Stripe generates invoice with overage line item
   d. Mark usage_period.is_overage_billed = true
```

---

## 8. Usage Tracking & Analytics

### 8A. CreditMeterService

```php
class CreditMeterService
{
    // === HOT PATH (Redis) ===

    public function increment(int $workspaceId, int $credits = 1, ?string $type = 'execution'): void
    // INCRBY on Redis hash. Dispatches async CreditTransaction job.

    public function remaining(int $workspaceId): int
    // credits_limit - Redis HGET. Falls back to DB if Redis miss.

    public function hasCredits(int $workspaceId, int $needed = 1): bool
    // Quick check: remaining() >= needed. Considers credit packs.

    public function usage(int $workspaceId): array
    // Returns: { used, limit, remaining, percentage, overage, period_start, period_end }

    // === COLD PATH (PostgreSQL) ===

    public function createPeriod(Workspace $workspace, Carbon $start, Carbon $end, int $limit): WorkspaceUsagePeriod

    public function resetForNewPeriod(int $workspaceId): void
    // Called by Stripe webhook (invoice.payment_succeeded).
    // Close current period, create new one, flush Redis counter.

    public function reconcile(int $workspaceId): void
    // Sync Redis counter with actual credit_transactions sum.
    // Called by cron every 5 min + on period boundaries.

    // === CREDIT PACKS ===

    public function availablePackCredits(int $workspaceId): int
    // Sum of non-expired, active credit packs' remaining credits.

    public function consumePackCredits(int $workspaceId, int $credits): int
    // Consume from oldest pack first (FIFO). Returns actually consumed.
}
```

### 8B. Usage Dashboard Data

```
GET /api/v1/workspaces/{workspace}/usage

Response:
{
  "current_period": {
    "start": "2026-02-01",
    "end": "2026-02-28",
    "credits": {
      "used": 7823,
      "limit": 10000,
      "remaining": 2177,
      "percentage": 78.23,
      "overage": 0,
      "pack_credits_remaining": 500
    },
    "executions": {
      "total": 1245,
      "succeeded": 1198,
      "failed": 47,
      "success_rate": 96.23
    },
    "data_transfer": {
      "used_bytes": 2147483648,
      "limit_bytes": 5368709120,
      "percentage": 40.0
    },
    "active_workflows": {
      "current": 12,
      "limit": 20
    },
    "members": {
      "current": 3,
      "limit": 10
    }
  },
  "daily_breakdown": [
    { "day": "2026-02-01", "credits": 312, "executions": 52, "data_bytes": 102400 },
    { "day": "2026-02-02", "credits": 287, "executions": 48, "data_bytes": 98304 },
    ...
  ],
  "plan": {
    "name": "Pro",
    "slug": "pro",
    "billing_interval": "monthly",
    "next_renewal": "2026-03-01"
  }
}
```

---

## 9. Notification System

### 9A. Usage Threshold Alerts

| Trigger | Channel | Action |
|---------|---------|--------|
| Credits hit **50%** | In-app banner | Yellow warning banner on dashboard |
| Credits hit **80%** | Email + In-app | "You've used 80% of credits. Upgrade or buy pack." |
| Credits hit **100%** (hard limit) | Email + In-app + Pause | Workflows paused. "Upgrade to continue." |
| Credits hit **100%** (soft limit) | Email + In-app | "Overage billing active. Consider upgrading." |
| Credits hit **150%** overage | Email to billing admin | "Significant overage detected." |
| Trial ending in **3 days** | Email | "Your trial ends in 3 days. Choose a plan." |
| Trial ended | Email + In-app | Downgrade to Free. Deactivate excess workflows. |
| Payment failed | Email | "Payment failed. Update payment method within 7 days." |
| Payment failed (3rd attempt) | Email + In-app | "Account suspended. Update payment to reactivate." |
| Approaching annual limit (yearly billing) | Email | "You've used 80% of your annual credit pool." |

### 9B. Implementation

```
app/Notifications/
├── CreditUsageWarningNotification.php     (50%, 80%)
├── CreditLimitReachedNotification.php     (100% hard)
├── OverageActivatedNotification.php       (100% soft)
├── OverageWarningNotification.php         (150%)
├── TrialEndingNotification.php            (3 days before)
├── TrialEndedNotification.php
├── PaymentFailedNotification.php
├── AccountSuspendedNotification.php
└── UpgradePromptNotification.php

Dispatched by:
├── CreditMeterService::increment()        → check thresholds after increment
├── Cron: CheckUsageThresholds (daily)     → batch check all workspaces
└── StripeWebhookController               → payment events
```

---

## 10. Engine-Side Enforcement (Go)

### 10A. Plan Context in Engine Requests

When the API dispatches to the Engine, include plan limits in the execution context:

```json
// Payload sent to Engine via Redis Stream / gRPC
{
  "execution_id": 123,
  "workflow_id": 456,
  "workspace_id": 789,
  "plan_limits": {
    "max_execution_time_seconds": 2400,
    "max_file_size_bytes": 157286400,
    "max_concurrent_executions": 20,
    "execution_priority": "high",
    "credits_remaining": 2177,
    "max_data_transfer_bytes": 26843545600,
    "features": {
      "parallel_execution": true,
      "sub_workflows": true
    }
  }
}
```

### 10B. Engine Enforcement Points

```go
// internal/worker/executor/executor.go
// Before executing each node:
func (e *Executor) executeNode(ctx context.Context, node *Node) error {
    // 1. Check execution timeout
    if time.Since(e.startTime) > e.planLimits.MaxExecutionTime {
        return ErrExecutionTimeLimitExceeded
    }

    // 2. Check credits remaining (decremented locally)
    if e.creditsRemaining <= 0 {
        // Callback to API: "credits exhausted"
        return ErrCreditsExhausted
    }

    // 3. Check file size on data payloads
    if payloadSize > e.planLimits.MaxFileSize {
        return ErrFileSizeLimitExceeded
    }

    // Execute node...
    e.creditsRemaining -= e.creditCost(node)

    // 4. Callback to API: node completed, credits consumed
    e.callback.NodeCompleted(node, creditCost)
}
```

### 10C. Priority Queue Routing

```go
// internal/matching/matcher.go
func (m *Matcher) routeExecution(exec *Execution) string {
    switch exec.PlanLimits.ExecutionPriority {
    case "priority":
        return "queue:priority"    // Processed first, dedicated workers
    case "high":
        return "queue:high"        // Processed before default
    default:
        return "queue:default"     // Standard FIFO
    }
}
```

---

## 11. API Endpoints

### 11A. New Endpoints

```
# Usage & Metering
GET    /workspaces/{workspace}/usage                    → Current period usage summary
GET    /workspaces/{workspace}/usage/history            → Past 12 months
GET    /workspaces/{workspace}/usage/daily              → Daily breakdown (current period)
GET    /workspaces/{workspace}/usage/credits/transactions → Credit ledger

# Plans
GET    /plans                                            → (existing) List active plans
GET    /plans/{plan}/tiers                               → Available credit tiers for a plan

# Billing (extend existing BillingController)
POST   /workspaces/{workspace}/billing/checkout          → (existing) Create checkout session
POST   /workspaces/{workspace}/billing/portal            → (existing) Billing portal
POST   /workspaces/{workspace}/billing/change-plan       → (existing) Change plan
POST   /workspaces/{workspace}/billing/change-tier       → NEW: Change credit tier within same plan
DELETE /workspaces/{workspace}/billing/cancel             → (existing) Cancel subscription
POST   /workspaces/{workspace}/billing/resume            → (existing) Resume

# Credit Packs
GET    /workspaces/{workspace}/credit-packs              → List active/available packs
POST   /workspaces/{workspace}/credit-packs/purchase     → Buy a credit pack
GET    /workspaces/{workspace}/credit-packs/{pack}       → Pack details

# Webhooks (Stripe)
POST   /webhooks/stripe                                  → Stripe webhook handler

# Admin (optional)
POST   /admin/workspaces/{workspace}/credits/adjust      → Manual credit adjustment
POST   /admin/workspaces/{workspace}/credits/refund       → Refund credits
```

### 11B. Updated Route File Structure

```php
// routes/api.php additions:

// Public
Route::get('plans', [PlanController::class, 'index']);
Route::get('plans/{plan}/tiers', [PlanController::class, 'tiers']);
Route::post('webhooks/stripe', [StripeWebhookController::class, 'handle']);

// Workspace-scoped (inside auth + workspace middleware)
Route::prefix('usage')->as('usage.')->group(function () {
    Route::get('/', [UsageController::class, 'show']);
    Route::get('/history', [UsageController::class, 'history']);
    Route::get('/daily', [UsageController::class, 'daily']);
    Route::get('/credits/transactions', [UsageController::class, 'transactions']);
});

Route::prefix('credit-packs')->as('credit-packs.')->group(function () {
    Route::get('/', [CreditPackController::class, 'index']);
    Route::post('/purchase', [CreditPackController::class, 'purchase']);
    Route::get('/{creditPack}', [CreditPackController::class, 'show']);
});

Route::post('billing/change-tier', [BillingController::class, 'changeTier']);
```

---

## 12. Implementation Phases

### Phase 1: Foundation (Week 1-2) — 🔴 CRITICAL

| Task | Files | Description |
|------|-------|-------------|
| 1.1 | Migration | Create `workspace_usage_periods`, `credit_transactions`, `credit_packs`, `usage_daily_snapshots` tables |
| 1.2 | Migration | Update `plans` table (add `stripe_product_id`, `stripe_prices`, `credit_tiers`) |
| 1.3 | Migration | Update `subscriptions` table (add `billing_interval`, `credits_monthly`, `credits_yearly_pool`, `stripe_price_id`) |
| 1.4 | Migration | Add `credits_consumed` column to `executions` table |
| 1.5 | Model | Create `WorkspaceUsagePeriod`, `CreditTransaction`, `CreditPack`, `UsageDailySnapshot` models |
| 1.6 | Service | Build `CreditMeterService` (Redis hot path + Postgres cold path) |
| 1.7 | Seeder | Update `PlanSeeder` with 5-tier plan data (Free/Starter/Pro/Teams/Enterprise) |
| 1.8 | Tests | Unit tests for `CreditMeterService` |

### Phase 2: Stripe Webhook Sync (Week 2-3) — 🔴 CRITICAL

| Task | Files | Description |
|------|-------|-------------|
| 2.1 | Controller | Create `StripeWebhookController` handling all events |
| 2.2 | Service | Extend `StripeService` with `reportUsage()`, `createUsageRecord()` for metered billing |
| 2.3 | Routes | Register `POST /webhooks/stripe` (public, signature-verified) |
| 2.4 | Jobs | `SyncStripeSubscription` job for async processing |
| 2.5 | Tests | Feature tests for each webhook event |

### Phase 3: Enforcement (Week 3-4) — 🔴 CRITICAL

| Task | Files | Description |
|------|-------|-------------|
| 3.1 | Service | Build `PlanEnforcementService` with all check methods |
| 3.2 | Middleware | Create `EnforcePlanLimits` middleware |
| 3.3 | Exceptions | Create `PlanLimitException`, `FeatureNotAvailableException`, `QuotaExceededException` |
| 3.4 | Routes | Apply middleware to all relevant route groups |
| 3.5 | Controllers | Add enforcement calls to `WorkflowController`, `ExecutionController`, `WebhookController`, etc. |
| 3.6 | Service | Update `WorkflowDispatchService` with plan-aware rate limiting |
| 3.7 | Tests | Feature tests for each enforcement point |

### Phase 4: Credit Metering Integration (Week 4-5) — 🟡 HIGH

| Task | Files | Description |
|------|-------|-------------|
| 4.1 | Callback | Integrate `CreditMeterService::increment()` in `JobCallbackController` (engine callbacks) |
| 4.2 | Job | Create `RecordCreditTransaction` async job |
| 4.3 | Command | Create `credits:reconcile` artisan command (cron every 5 min) |
| 4.4 | Command | Create `credits:snapshot-daily` artisan command (nightly) |
| 4.5 | Command | Create `usage:reset-period` artisan command (handles period rollovers) |
| 4.6 | Engine | Pass `plan_limits` in execution payload to Go engine |
| 4.7 | Engine | Enforce `max_execution_time`, `max_file_size`, priority routing in Go |

### Phase 5: Usage Dashboard & Credit Packs (Week 5-6) — 🟡 HIGH

| Task | Files | Description |
|------|-------|-------------|
| 5.1 | Controller | Create `UsageController` (show, history, daily, transactions) |
| 5.2 | Controller | Create `CreditPackController` (index, purchase, show) |
| 5.3 | Controller | Add `changeTier` to `BillingController` |
| 5.4 | Controller | Add `tiers` method to `PlanController` |
| 5.5 | Resources | Create `UsageResource`, `CreditTransactionResource`, `CreditPackResource` |
| 5.6 | Service | Integrate credit pack purchasing with Stripe (one-time payments) |
| 5.7 | Tests | Feature tests for usage API + credit packs |

### Phase 6: Notifications & Monitoring (Week 6-7) — 🟡 HIGH

| Task | Files | Description |
|------|-------|-------------|
| 6.1 | Notifications | Create all notification classes (usage warnings, payment, trial) |
| 6.2 | Command | Create `usage:check-thresholds` artisan command (runs hourly) |
| 6.3 | Listeners | Create event listeners for threshold crossings |
| 6.4 | Mail | Email templates for all notification types |
| 6.5 | Config | Add notification preferences to workspace settings |
| 6.6 | Tests | Test notification dispatching |

### Phase 7: Advanced Features (Week 7-8) — 🟢 MEDIUM

| Task | Files | Description |
|------|-------|-------------|
| 7.1 | Service | Log retention cleanup command (`logs:prune --plan-aware`) |
| 7.2 | Service | Data transfer tracking in engine callbacks |
| 7.3 | Service | Concurrent execution limiting (Redis semaphore) |
| 7.4 | Service | Annual credit pool logic (yearly billing flexibility) |
| 7.5 | Migration | Add `downgrade_scheduled_at`, `downgrade_to_plan_id` to subscriptions |
| 7.6 | Command | `subscriptions:process-downgrades` (deactivate excess workflows, etc.) |
| 7.7 | Admin | Admin endpoints for credit adjustments/refunds |
| 7.8 | Tests | Integration tests for full billing lifecycle |

---

## Summary: New Files to Create

```
app/
├── Exceptions/
│   ├── PlanLimitException.php
│   ├── FeatureNotAvailableException.php
│   └── QuotaExceededException.php
├── Http/
│   ├── Controllers/Api/
│   │   ├── StripeWebhookController.php
│   │   └── V1/
│   │       ├── UsageController.php
│   │       └── CreditPackController.php
│   └── Middleware/
│       └── EnforcePlanLimits.php
├── Jobs/
│   ├── RecordCreditTransaction.php
│   └── SyncStripeSubscription.php
├── Models/
│   ├── WorkspaceUsagePeriod.php
│   ├── CreditTransaction.php
│   ├── CreditPack.php
│   └── UsageDailySnapshot.php
├── Notifications/
│   ├── CreditUsageWarningNotification.php
│   ├── CreditLimitReachedNotification.php
│   ├── OverageActivatedNotification.php
│   ├── TrialEndingNotification.php
│   ├── PaymentFailedNotification.php
│   └── AccountSuspendedNotification.php
├── Services/
│   ├── CreditMeterService.php
│   └── PlanEnforcementService.php
└── Console/Commands/
    ├── ReconcileCredits.php
    ├── SnapshotDailyUsage.php
    ├── CheckUsageThresholds.php
    ├── PruneExecutionLogs.php
    └── ProcessScheduledDowngrades.php

database/migrations/
├── xxxx_create_workspace_usage_periods_table.php
├── xxxx_create_credit_transactions_table.php
├── xxxx_create_credit_packs_table.php
├── xxxx_create_usage_daily_snapshots_table.php
├── xxxx_update_plans_table_add_stripe_fields.php
├── xxxx_update_subscriptions_table_add_billing_fields.php
└── xxxx_add_credits_consumed_to_executions_table.php

database/seeders/
└── PlanSeeder.php (updated)
```
