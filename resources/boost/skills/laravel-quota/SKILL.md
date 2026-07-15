---
name: laravel-quota
description: Expert AI guidance, architectural best practices, and exact API patterns for implementing application-level quota management, usage limits, budgets, and rate boundaries using `zaber-dev/laravel-quota` (`Quota` facade, `HasQuotas` trait, polymorphic targeting, calendar periods, drivers, atomic `block()`, exception enforcing, and route middleware).
---

# Laravel Quota (`zaber-dev/laravel-quota`) Skill Guide

`zaber-dev/laravel-quota` is an application-level quota and usage limit tracking package for Laravel (11, 12 & 13+) designed for calendar-period boundary allocations (`perMonth()`, `perDay()`, `perHour()`), usage budgets, and rate limiting with swappable cache and database storage.

When working with or generating code using `laravel-quota`, strictly adhere to the patterns and architectural rules outlined below.

---

## 1. Core Architecture & Concepts

Unlike generic rate limiters, `laravel-quota` provides:
1. **Polymorphic Entity Targeting & Calendar Periods**: Attach quotas directly to Eloquent models (`$user->quota('pdf_exports')->perMonth()->consume()`) or scalar targets (`$tenantId`). When passed an Eloquent model, it resolves to `action:App_Models_User:12:{periodStart}:{periodEnd}` respecting `Relation::enforceMorphMap()`.
2. **Automatic Period Rollover**: Calendar periods (`perMonth()`, `perWeek()`, `perDay()`, `perHour()`, `perMinute()`, `perYear()`) automatically compute boundary timestamps using `CarbonImmutable`, ensuring zero-maintenance period resets without midnight cron jobs.
3. **Multi-Driver Storage (`Manager` pattern)**: Swappable storage mediums per request (`cache` vs `database`).
4. **Immutable DTOs (`QuotaInfo`)**: Encapsulates `used`, `limit`, `remaining()`, `periodStart`, and `periodEnd` for precise calculations and HTTP header reporting.
5. **Atomic In-Flight Execution (`block()`)**: Prevents race conditions and budget overruns during heavy operations by acquiring temporary reservation locks (`acquireLock`) before running callbacks.
6. **Smart Route Middleware (`CheckQuota`)**: Verifies capacity before controller execution and only consumes quota units upon successful (`2xx`/`3xx`) responses, preventing failed validation (`422`) or server errors (`500`) from unfair quota deduction.

---

## 2. When to Use Which Driver

The package supports two primary drivers configured in `config/quotas.php`:
- **`cache` (Default)**: Uses Laravel's cache stores (Redis, Memcached, Array). Ideal for high-throughput API rate limits, per-minute/hourly quotas, and volatile usage constraints.
- **`database`**: Stores records in the `quotas` table with `target_type`, `target_id`, `used`, `limit`, `period_start`, and `period_end`. Ideal when quota history and usage metrics must survive server restarts, audit logs/billing tier enforcement are required, or across microservices sharing a relational database.

```php
// Use cache driver explicitly
Quota::for('api_calls', $apiKey)->using('cache')->limit(1000)->perDay()->consume();

// Use database driver explicitly
Quota::for('team_members', $team)->using('database')->limit(25)->unlimited()->consume();
```

---

## 3. Eloquent Model Integration (`HasQuotas` Trait)

Always attach the `HasQuotas` trait to Eloquent models that consume usage limits (e.g., `User`, `Team`, `Tenant`, `ApiKey`):

```php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use ZaberDev\Quota\HasQuotas;

class User extends Authenticatable
{
    use HasQuotas;
}
```

### Model Quota Methods
```php
// Check capacity and consume 1 unit
$user->quota('pdf_exports')->limit(50)->perMonth()->consume(); // returns QuotaInfo DTO

// Consume multiple units or check capacity without consuming
if ($user->quota('ai_tokens')->limit(100000)->perMonth()->hasCapacity(500)) {
    $user->quota('ai_tokens')->limit(100000)->perMonth()->consume(500);
}

// Attempt to consume without throwing an exception (returns bool)
if ($user->quota('api_requests')->limit(1000)->perDay()->attempt()) {
    // Action performed successfully...
}

// Check if quota limit has been exceeded
if ($user->quota('pdf_exports')->limit(50)->perMonth()->isExceeded()) {
    // ...
}

// Enforce quota (throws QuotaExceededException if exceeded or locked)
$user->quota('pdf_exports')->limit(50)->perMonth()->enforce();
$user->quota('pdf_exports')->limit(50)->perMonth()->enforce('You have reached your monthly PDF export limit.');

// Check remaining usage
$remaining = $user->quota('pdf_exports')->limit(50)->perMonth()->remaining(); // int (PHP_INT_MAX if unlimited)
$used = $user->quota('pdf_exports')->limit(50)->perMonth()->used(); // int

// Reset or modify usage directly
$user->quota('pdf_exports')->perMonth()->reset();
$user->quota('pdf_exports')->perMonth()->set(10);
$user->quota('pdf_exports')->perMonth()->decrement(2);

// Access persistent database records via Eloquent relation (when using database driver)
$user->quotas()->where('action', 'pdf_exports')->get();
```

---

## 4. Facade & Direct Usage (`Quota::`)

For global actions or scalar targets (`$ip`, `$tenantId`, `$id`), use the `Quota` facade:

```php
use ZaberDev\Quota\Facades\Quota;

// Initiate quota tracking
Quota::for('global_emails')->limit(5000)->perDay()->consume();
Quota::on('report_generation', $tenantId)->limit(10)->perWeek()->consume(); // `on()` is an alias for `for()`

// Inspect quota
$info = Quota::for('report_generation', $tenantId)->limit(10)->perWeek()->info();
if ($info->hasCapacity()) {
    // ...
}
```

---

## 5. Atomic Execution & Double-Click Protection (`->block()`)

**Best Practice:** Whenever performing an action that takes time to execute (such as AI model generation, heavy file exports, or external API charges) and you want to prevent race conditions or double-click bursts while guaranteeing quota is only consumed on success, use `block()`:

```php
use ZaberDev\Quota\Facades\Quota;
use ZaberDev\Quota\Exceptions\QuotaExceededException;

try {
    $result = Quota::for('generate_video', $user)->limit(5)->perMonth()->block(
        callback: function () use ($videoService, $user) {
            return $videoService->render($user);
        },
        amount: 1,          // Quota units to consume upon successful callback completion
        lockSeconds: 15     // Temporary atomic reservation lock during callback execution
    );
} catch (QuotaExceededException $e) {
    return response()->json([
        'error' => $e->getMessage(),
        'quota_info' => [
            'used' => $e->getQuotaInfo()?->used,
            'limit' => $e->getQuotaInfo()?->limit,
            'period_end' => $e->getQuotaInfo()?->periodEnd,
        ],
    ], 429);
}
```

---

## 6. Route & Endpoint Middleware (`quota`)

The package automatically registers the `quota` route middleware alias:

```php
use Illuminate\Support\Facades\Route;

// Syntax: quota:{action},{limit},{period},{optional_driver}
Route::post('/ai/generate', [AiController::class, 'store'])
    ->middleware('quota:ai_generation,20,day');

Route::post('/export/csv', [ExportController::class, 'download'])
    ->middleware('quota:csv_export,5,month,database');
```

**Supported Periods for Middleware:**
`minute`, `hour`, `day`, `week`, `month`, `year` (defaults to `day` if omitted).

**Key Behavior:**
- Automatically targets `$request->user() ?? $request->ip()`.
- Enforces quota limits before controller execution (`HTTP 429` thrown if exceeded or locked).
- Consumes quota units ONLY if `$response->isSuccessful() || $response->isRedirection()`. If validation fails (`HTTP 422`) or a server error occurs (`HTTP 500`), no quota units are deducted.

---

## 7. Exception Handling & HTTP 429 Responses

`ZaberDev\Quota\Exceptions\QuotaExceededException` extends `Symfony\Component\HttpKernel\Exception\HttpException` (`HTTP 429`).
When thrown in web or API controllers, Laravel automatically renders a `429 Too Many Requests` response.

```php
use ZaberDev\Quota\Exceptions\QuotaExceededException;

try {
    $tenant->quota('monthly_invites')->limit(100)->perMonth()->enforce();
} catch (QuotaExceededException $e) {
    // $e->getQuotaInfo()  -> returns QuotaInfo DTO
    // $e->getStatusCode() -> 429
}
```

---

## 8. Database Migration & Pruning

When using the `database` driver, ensure the migration is published and run:
```bash
php artisan vendor:publish --tag=quotas-migrations
php artisan migrate
```

To automatically clean up expired database records whose `period_end` has passed, schedule the pruning command in `routes/console.php` (Laravel 11+) or `app/Console/Kernel.php`:

```php
use Illuminate\Support\Facades\Schedule;
use ZaberDev\Quota\Models\Quota;

Schedule::command('model:prune', ['--model' => Quota::class])->daily();
```

---

## Summary Checklist for AI Agents

1. Always use `use ZaberDev\Quota\Facades\Quota;` when referencing the facade.
2. Ensure models use `use ZaberDev\Quota\HasQuotas;` before calling `$model->quota()`.
3. Explicitly chain a period window (`->perMonth()`, `->perDay()`, `->perHour()`) before checking or consuming quotas unless the default (`perMonth()`) is specifically desired.
4. Prefer `$pending->block(fn() => ..., amount: 1)` for heavy or state-mutating actions to gain concurrency locking and success-only quota consumption.
5. Rely on the `quota` middleware (`quota:action,limit,period`) for standard HTTP endpoint rate boundaries.
