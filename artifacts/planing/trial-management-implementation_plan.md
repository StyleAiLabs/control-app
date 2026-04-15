# Trial Lifecycle Management — Implementation Plan

## Goal

Enforce the two trial-end conditions (14 days OR $5 spend), surface credit/trial
status on the client dashboard, and send automated email notifications at key
thresholds. No grace period. No timezone complexity — UTC throughout.

---

## Decisions recorded

| Decision | Value |
|---|---|
| Trial start reference | `tenants.created_at` — no extra column |
| Trial length | 14 days from `created_at` in UTC |
| `trial_ends_at` | `created_at + 14 days` (UTC), stored once at signup |
| Customer timezone | **Not captured** — UTC used everywhere, display shows "X days remaining" |
| AI credit cap | $5.00 (LiteLLM `max_budget`) |
| Trial ends when | Either condition true — whichever comes first |
| Notification emails | 80% budget used, ≤3 days left, expired |
| Expired state | "Contact us" — no self-serve upgrade yet |
| Grace period | None |

---

## Proposed Changes

### 1 — Database

#### [NEW] Migration — `add_trial_and_spend_fields_to_tenants`

One migration, 6 new columns on `tenants`:

```php
$table->timestamp('trial_ends_at')->nullable();        // created_at + 14 days
$table->decimal('litellm_spend', 10, 6)->nullable();   // cached from /key/info
$table->timestamp('litellm_spend_cached_at')->nullable();
$table->timestamp('trial_80pct_notified_at')->nullable();
$table->timestamp('trial_3day_notified_at')->nullable();
$table->timestamp('trial_expired_notified_at')->nullable();
```

---

### 2 — Auth

#### [MODIFY] [RegisterController.php](file:///Users/gayanhewage/Projects/openclaw-saas/app/Http/Controllers/Auth/RegisterController.php)

After creating the Tenant, set `trial_ends_at`:

```php
$tenant->forceFill([
    'trial_ends_at' => $tenant->created_at->copy()->addDays(14),
])->save();
```

That's the only change to RegisterController.

---

### 3 — LiteLLM Service

#### [MODIFY] [LiteLlmTenantKeyService.php](file:///Users/gayanhewage/Projects/openclaw-saas/app/Services/LiteLlmTenantKeyService.php)

Add one new public method. Uses the existing `$this->request()` helper:

```php
/**
 * Fetch live key usage from LiteLLM.
 *
 * @return array{spend:float, max_budget:float, budget_reset_at:?string}
 */
public function getKeyInfo(Tenant $tenant): array
{
    $response = $this->request('get', '/key/info?key='.$tenant->litellm_virtual_key, []);

    return [
        'spend'           => (float)  data_get($response->json(), 'info.spend', 0),
        'max_budget'      => (float)  data_get($response->json(), 'info.max_budget', 5),
        'budget_reset_at' => (string) data_get($response->json(), 'info.budget_reset_at', ''),
    ];
}
```

---

### 4 — Trial Expiry Command + Schedule

#### [MODIFY] [routes/console.php](file:///Users/gayanhewage/Projects/openclaw-saas/routes/console.php)

New closure command following the existing `tenants:health-check` pattern:

```php
Artisan::command('sync360:check-trial-expiry', function () {
    // Query all trial_active tenants that have a LiteLLM key
    $tenants = Tenant::query()
        ->where('trial_status', TrialStatus::Active)
        ->whereNotNull('litellm_virtual_key')
        ->get();

    foreach ($tenants as $tenant) {
        try {
            // 1. Refresh spend cache
            $info  = app(LiteLlmTenantKeyService::class)->getKeyInfo($tenant);
            $spend = $info['spend'];
            $tenant->forceFill([
                'litellm_spend'           => $spend,
                'litellm_spend_cached_at' => now(),
            ])->save();

            // 2. Evaluate expiry
            $budgetExpired = $spend >= (float) $tenant->litellm_max_budget;
            $timeExpired   = now()->gte($tenant->trial_ends_at);
            $expired       = $budgetExpired || $timeExpired;

            // 3. Handle expired
            if ($expired) {
                $tenant->forceFill(['trial_status' => TrialStatus::Expired])->save();
                app(LiteLlmTenantKeyService::class)->suspendTenant($tenant);

                if (! $tenant->trial_expired_notified_at) {
                    $reason = $budgetExpired ? 'budget' : 'time';
                    app(TrialNotificationEmailService::class)->sendTrialExpired($tenant, $reason);
                    $tenant->forceFill(['trial_expired_notified_at' => now()])->save();
                }
                continue;
            }

            // 4. Threshold warnings
            $budgetPct  = $spend / max(0.01, (float) $tenant->litellm_max_budget) * 100;
            $daysLeft   = (int) now()->diffInDays($tenant->trial_ends_at, false);

            if ($budgetPct >= 80 && ! $tenant->trial_80pct_notified_at) {
                app(TrialNotificationEmailService::class)->sendBudgetWarning($tenant, $spend, $tenant->litellm_max_budget);
                $tenant->forceFill(['trial_80pct_notified_at' => now()])->save();
            }

            if ($daysLeft <= 3 && ! $tenant->trial_3day_notified_at) {
                app(TrialNotificationEmailService::class)->sendExpiryWarning($tenant, $daysLeft);
                $tenant->forceFill(['trial_3day_notified_at' => now()])->save();
            }

        } catch (Throwable $e) {
            Log::warning('sync360:check-trial-expiry failed for tenant.', [
                'tenant_id' => $tenant->tenant_id,
                'error'     => $e->getMessage(),
            ]);
        }
    }
})->purpose('Check trial expiry conditions and send lifecycle notifications');

Schedule::command('sync360:check-trial-expiry')->everyThirtyMinutes();
```

---

### 5 — Email Service

#### [NEW] [TrialNotificationEmailService.php](file:///Users/gayanhewage/Projects/openclaw-saas/app/Services/TrialNotificationEmailService.php)

Same Brevo direct-HTTP approach as `WorkspaceReadyEmailService`.

| Method | Trigger | Subject |
|---|---|---|
| `sendBudgetWarning(Tenant, spend, max)` | spend ≥ 80% | "Your AI credit is almost used up" |
| `sendExpiryWarning(Tenant, daysLeft)` | ≤ 3 days left | "Your Sync360 trial ends in {N} days" |
| `sendTrialExpired(Tenant, reason)` | either condition | "Your Sync360 trial has ended" |

Each email body:
- **Budget warning:** "$X.XX of $5.00 used. Once the credit runs out your digital employee will pause."
- **3-day warning:** "Your trial ends in N days. Make sure you've tested everything."
- **Expired:** Explains whether it was time or budget that ran out. Single CTA: `mailto:hello@sync360.co.nz`.

All methods guarded by `isEnabled()` (Brevo key + sender configured).

---

### 6 — Tenant Model

#### [MODIFY] [Tenant.php](file:///Users/gayanhewage/Projects/openclaw-saas/app/Models/Tenant.php)

Add new fields to `$fillable` and `$casts`. Add five accessors:

```php
public function isTrialExpired(): bool
{
    return $this->trial_status === TrialStatus::Expired;
}

public function trialDaysLeft(): int
{
    if (! $this->trial_ends_at || $this->isTrialExpired()) return 0;
    return max(0, (int) now()->diffInDays($this->trial_ends_at, absolute: false));
}

public function trialBudgetPercent(): float
{
    $max = max(0.01, (float) ($this->litellm_max_budget ?? 5.0));
    return min(100.0, round((float) ($this->litellm_spend ?? 0) / $max * 100, 1));
}

public function trialTimePercent(): float
{
    $elapsed = (int) $this->created_at->diffInDays(now());
    return min(100.0, round($elapsed / 14 * 100, 1));
}

public function trialUrgency(): string  // 'ok' | 'warning' | 'critical'
{
    $pct = max($this->trialBudgetPercent(), $this->trialTimePercent());
    return match (true) {
        $pct >= 85 => 'critical',
        $pct >= 60 => 'warning',
        default    => 'ok',
    };
}
```

---

### 7 — Dashboard Controller

#### [MODIFY] [DashboardController.php](file:///Users/gayanhewage/Projects/openclaw-saas/app/Http/Controllers/DashboardController.php)

Pass trial data to the view (no live API call — uses cached spend only):

```php
'trialData' => [
    'max_budget'      => (float) ($tenant->litellm_max_budget ?? 5.0),
    'spend'           => (float) ($tenant->litellm_spend ?? 0.0),
    'days_left'       => $tenant->trialDaysLeft(),
    'budget_percent'  => $tenant->trialBudgetPercent(),
    'time_percent'    => $tenant->trialTimePercent(),
    'urgency'         => $tenant->trialUrgency(),
    'is_expired'      => $tenant->isTrialExpired(),
    'spend_cached_at' => $tenant->litellm_spend_cached_at?->diffForHumans(),
],
```

---

### 8 — Dashboard View

#### [MODIFY] [dashboard.blade.php](file:///Users/gayanhewage/Projects/openclaw-saas/resources/views/dashboard.blade.php)

**Expired state — top banner:**
```
🔴  Your trial has ended. Your digital employee has been paused.
    Contact us to continue →  [Contact Us]
```

**Active state — "Trial & AI Usage" panel:**
```
Trial & AI Usage
──────────────────────────────────────────────────
AI Credit   ████████████░░░░  $3.20 / $5.00  64%
Trial Time  ████────────────  5 of 14 days used · 9 days remaining
──────────────────────────────────────────────────
Usage data as of 2 minutes ago
```

Colour: urgency `ok` = green, `warning` = amber, `critical` = red.
Both bars update from `$trialData` passed from the controller — no JS required.

---

### 9 — Onboarding View Guard

#### [MODIFY] [show.blade.php](file:///Users/gayanhewage/Projects/openclaw-saas/resources/views/onboarding/show.blade.php)

Step 6 — disable Go Live button when trial is expired:

```blade
@if ($tenant->isTrialExpired())
    <div class="note error" style="margin-top: 18px;">
        Your trial has ended — the Go Live action is not available.
        <a href="mailto:hello@sync360.co.nz">Contact us</a> to continue.
    </div>
    <button type="submit" disabled style="opacity: 0.5; cursor: not-allowed;">Go Live</button>
@else
    <button type="submit">
        {{ ($state['agent_status'] ?? null) === 'live' ? 'Resync Assistant' : 'Go Live' }}
    </button>
@endif
```

---

## Files summary

| # | File | Change |
|---|---|---|
| 1 | `database/migrations/..._add_trial_and_spend_fields_to_tenants` | [NEW] |
| 2 | `app/Http/Controllers/Auth/RegisterController.php` | [MODIFY] set `trial_ends_at` |
| 3 | `app/Services/LiteLlmTenantKeyService.php` | [MODIFY] add `getKeyInfo()` |
| 4 | `routes/console.php` | [MODIFY] command + schedule |
| 5 | `app/Services/TrialNotificationEmailService.php` | [NEW] |
| 6 | `app/Models/Tenant.php` | [MODIFY] fillable, casts, 5 accessors |
| 7 | `app/Http/Controllers/DashboardController.php` | [MODIFY] pass `trialData` |
| 8 | `resources/views/dashboard.blade.php` | [MODIFY] widget + expired banner |
| 9 | `resources/views/onboarding/show.blade.php` | [MODIFY] Go Live guard |

---

## Verification Plan

### Automated Tests

New `TrialExpiryTest` covering:

- `trial_ends_at` = `created_at + 14 days` set at signup
- Budget-expired path: `spend >= max_budget` → `trial_expired`, suspend called, email sent once
- Time-expired path: `trial_ends_at` in past → same
- 80% warning sent only once (idempotent on re-run)
- 3-day warning sent only once (idempotent on re-run)
- Accessor `trialDaysLeft()` = 0 when expired
- Accessor `trialUrgency()` returns correct level

```
php artisan test --filter="TrialExpiryTest|SignupFlowTest"
```

### Manual Verification

- Signup → confirm `trial_ends_at` = `created_at + 14 days`
- Seed `litellm_spend = 4.01` → run command → confirm 80% email + `trial_80pct_notified_at` set
- Run again → confirm 80% email not sent twice
- Seed `trial_ends_at = now()->subMinutes(1)` → run command → confirm `trial_expired`, `suspendTenant()` called
- Dashboard expired banner renders, Go Live disabled
