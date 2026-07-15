<p align="center">
    <img src="art/header.png" alt="Laravel Quota Banner" width="100%">
</p>

# Laravel Quota

[![Latest Version on Packagist](https://img.shields.io/packagist/v/zaber-dev/laravel-quota.svg?style=flat-square)](https://packagist.org/packages/zaber-dev/laravel-quota)
[![Run Tests](https://img.shields.io/github/actions/workflow/status/zaber-dev/laravel-quota/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/zaber-dev/laravel-quota/actions/workflows/run-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/zaber-dev/laravel-quota.svg?style=flat-square)](https://packagist.org/packages/zaber-dev/laravel-quota)
[![PHP Version Require](https://img.shields.io/packagist/dependency-v/zaber-dev/laravel-quota/php.svg?style=flat-square)](https://packagist.org/packages/zaber-dev/laravel-quota)
[![License](https://img.shields.io/packagist/l/zaber-dev/laravel-quota.svg?style=flat-square)](https://packagist.org/packages/zaber-dev/laravel-quota)

**Supports:** Laravel 11, 12 & 13+ • PHP 8.2+ • Redis • Memcached • Database

**Application-level quota management for Laravel.** Track and enforce usage limits across daily, weekly, monthly, or custom time periods.

Manage quotas with a clean, expressive API using cache or persistent database storage, attach them directly to Eloquent models, protect routes with declarative middleware, and extend the package with custom storage backends.

> Unlike Laravel's `RateLimiter`, which focuses on request throttling, Laravel Quota manages cumulative usage budgets over calendar periods such as daily, weekly, monthly, or custom windows.

---

## Quick Example

```php
Quota::for('monthly_exports', $user)
    ->limit(50)
    ->perMonth()
    ->consume();

echo Quota::for('monthly_exports', $user)
    ->remaining();
```

---

## Common Use Cases

Laravel Quota is ideal for:

- Monthly PDF export budgets
- AI credits & prompt limits
- API monthly request quotas
- Tier-based billing restrictions
- Team / workspace creation caps
- File storage upload thresholds
- Weekly newsletter dispatch budgets
- Daily transaction volume limits

---

## Why not RateLimiter?

Laravel's `RateLimiter` is excellent for protecting endpoints from bursts of traffic. Laravel Quota doesn't replace RateLimiter. Instead, it complements it by managing cumulative usage across longer calendar periods.

Laravel Quota focuses on application-level usage budgets with:

- Monthly allocations
- Daily limits
- Team budgets
- Usage metering
- Calendar windows
- Remaining capacity
- Rich DTOs
- Eloquent integration
- Database persistence

| Feature | Laravel RateLimiter | Laravel Quota |
| :--- | :---: | :---: |
| **Primary Purpose** | High-frequency traffic protection | **Application-level quota management & usage limits** |
| **Fluent Builder API** (`Quota::for()->perMonth()`) | ❌ | ✅ **Expressive & Clean** |
| **First-Class Eloquent Integration** (`$user->quota()`) | ❌ | ✅ **Native (`HasQuotas`)** |
| **Storage Backends** (`cache` & `database`) | ❌ Cache Only | ✅ **Both Supported** |
| **Atomic In-Flight Locking** (`block()`) | ❌ | ✅ **Built-in** |
| **Success-Only Middleware Triggering** | ❌ | ✅ **Only on 2xx / 3xx** |
| **Structured Time Windows** | Sliding window | ✅ **Calendar periods (`perMonth()`, `perDay()`, etc.)** |
| **Immutable DTOs (`QuotaInfo`)** | ❌ | ✅ **Strict (`CarbonImmutable`)** |
| **Automatic Database Pruning** (`model:prune`) | N/A | ✅ **Built-in (`Prunable`)** |
| **Polymorphic Target Scoping** | ❌ Manual Keys | ✅ **Automatic Key Mapping** |
| **Custom Storage Backend Extensibility** (`Quota::extend()`) | ❌ | ✅ **Closure / Container** |
| **Event Dispatching** (`QuotaConsumed` / `Exceeded`) | ❌ | ✅ **Configurable Events** |

---

## Features

- **Expressive Fluent API**: Chain expressive calls like `Quota::for('pdf_exports', $user)->using('database')->limit(100)->perMonth()->consume()`.
- **Calendar Periods**: Enforce quotas over exact calendar time periods (`perMinute()`, `perHour()`, `perDay()`, `perWeek()`, `perMonth()`, `perYear()`, or custom start/end windows).
- **Atomic Consumption**: Prevent concurrent usage overages and race conditions using atomic execution (`block()`) or declarative route middleware.
- **Native Eloquent Integration**: Attach the `HasQuotas` trait to any model for scoped action metering (`$user->quota('pdf_exports')->limit(50)->perMonth()->consume()`).
- **Route Middleware**: Protect endpoints automatically using `quota:action_name,limit,period` with automatic HTTP `429` enforcement and `Retry-After` headers.
- **Multiple Storage Backends**: Switch seamlessly between high-performance `cache` stores (Redis, Memcached, Array) and persistent `database` storage with automatic cleanup.
- **Immutable DTOs**: Work safely with strict `QuotaInfo` Data Transfer Objects returning exact period boundaries (`used`, `remaining`, `periodStart`, `periodEnd`).
- **Custom Storage Backend Extensibility**: Register custom storage backends on the fly with closure-based creators via `Quota::extend()`.
- **Prunable Database Storage**: Built-in `Prunable` trait integration ensures expired database records never clutter your database.

---

## Documentation

- [Installation](#installation)
- [Configuration](#configuration)
- [Usage Guide](#usage-guide)
- [LEARN.md](LEARN.md)

---

## Installation

Ready to get started? Install the package with Composer:

```bash
composer require zaber-dev/laravel-quota
```

Publish the configuration and database migrations:

```bash
php artisan vendor:publish --provider="ZaberDev\Quota\QuotaServiceProvider"
```

Run migrations if you intend to use the `database` storage backend:

```bash
php artisan migrate
```

---

## Configuration

The configuration file `config/quotas.php` allows you to define your default storage backend, backend parameters, and event dispatching behaviors:

```php
return [
    /*
    |--------------------------------------------------------------------------
    | Default Quota Driver
    |--------------------------------------------------------------------------
    |
    | Supported drivers: "cache", "database"
    |
    */
    'default' => env('QUOTA_DRIVER', 'cache'),

    'drivers' => [
        'cache' => [
            'driver' => 'cache',
            'store' => env('QUOTA_CACHE_STORE', null),
            'prefix' => 'quotas:',
        ],
        'database' => [
            'driver' => 'database',
            'table' => 'quotas',
        ],
    ],

    'events' => [
        'dispatch' => true,
    ],
];
```

---

## Usage Guide

### 1. The Fluent Quota API

The `Quota` facade provides an expressive builder interface for setting, checking, enforcing, and consuming quotas.

#### Setting & Consuming a Quota
```php
use ZaberDev\Quota\Facades\Quota;

$builder = Quota::for('api_queries', $user)
    ->limit(1000)
    ->perDay(); // Available periods: perMinute(), perHour(), perDay(), perWeek(), perMonth(), perYear(), period($start, $end)

// Check current usage stats
$used = $builder->used();             // int (e.g. 240)
$remaining = $builder->remaining();   // int (e.g. 760)
$isExceeded = $builder->isExceeded(); // bool (false)
$hasCapacity = $builder->hasCapacity(10); // bool (true)

// Consume quota (throws QuotaExceededException with HTTP 429 if insufficient capacity)
$info = $builder->consume(5);
```

#### Enforcing Quotas (`enforce`)
If you want to automatically halt execution and throw an HTTP `429 Too Many Requests` exception without consuming any units when the budget is exhausted, call `enforce()`:

```php
Quota::for('api_queries', $user)->limit(1000)->perDay()->enforce();
```

#### Atomic Block Execution (`block`)
For operations vulnerable to concurrent bursts or long-running tasks, use the `block()` helper. `block()` acquires a temporary atomic lock while the callback executes and only deducts quota units if execution completes successfully:

```php
Quota::for('pdf_generation', $user)
    ->limit(50)
    ->perMonth()
    ->block(function () use ($pdfService, $user) {
        $pdfService->generate($user);
    }, amount: 1, lockSeconds: 30);
```

#### Resetting / Flushing Quotas
```php
// Immediately reset the quota counter for the current period window
Quota::for('api_queries', $user)->reset();

// Flush all historical quota records for this action and target
Quota::for('api_queries', $user)->flush();
```

---

### 2. Eloquent Model Integration (`HasQuotas`)

Add the `HasQuotas` trait to any Eloquent model to scope quotas directly to that entity:

```php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use ZaberDev\Quota\HasQuotas;

class User extends Authenticatable
{
    use HasQuotas;
}
```

You can now interact directly with your model instance:

```php
$user = User::find(1);

// Consume 1 PDF export quota from the user's monthly budget
$user->quota('pdf_exports')->limit(25)->perMonth()->consume();

// Check remaining budget
$remaining = $user->quota('pdf_exports')->limit(25)->perMonth()->remaining();
```

#### Polymorphic Database Querying
When using the `database` storage backend, `HasQuotas` also exposes a `quotas()` polymorphic relationship, allowing direct querying and bulk management:

```php
// Get all database quota records assigned to this user
$activeQuotas = $user->quotas()->where('period_end', '>', now())->get();

// Delete all quota records for this user
$user->quotas()->delete();
```

---

### 3. Route Middleware

Protect routes declaratively without writing boilerplate checks in your controllers using the `CheckQuota` middleware:

```php
use Illuminate\Support\Facades\Route;

// Enforce a monthly allowance of 50 exports per User / IP address
Route::post('/exports/generate', [ExportController::class, 'store'])
    ->middleware('quota:exports,50,month');

// Use a specific storage backend
Route::post('/api/v1/query', [ApiController::class, 'query'])
    ->middleware('quota:api_query,1000,day,database');
```

**How the Middleware Works:**
- Before executing your controller, `CheckQuota` verifies remaining capacity (`HTTP 429`).
- When your controller completes successfully (`2xx` or `3xx`), the quota unit is consumed (`consume(1)`). If the controller fails due to validation (`4xx`) or server errors (`5xx`), no quota is deducted so the user can immediately correct their input and retry.

---

### 4. Working with Storage Backends (`using`)

By default, the package uses the storage backend defined in `config/quotas.php`. You can switch storage backends on the fly per request or action:

```php
// Store high-frequency API checks in fast cache/Redis
Quota::for('api_ping', $ip)->using('cache')->limit(5000)->perDay()->consume();

// Store critical billing tier budgets in SQL database
Quota::for('monthly_exports', $user)->using('database')->limit(50)->perMonth()->consume();
```

#### Registering Custom Storage Backends
You can extend the `QuotaManager` with your own storage backends (e.g., DynamoDB, MongoDB) in your `AppServiceProvider`:

```php
use ZaberDev\Quota\Contracts\QuotaDriverContract;
use ZaberDev\Quota\Facades\Quota;

public function boot(): void
{
    Quota::extend('dynamodb', function ($app) {
        return new MyDynamoDbQuotaDriver($app['config']['quotas.drivers.dynamodb']);
    });
}
```

---

### 5. Database Pruning (`Prunable`)

When using the `database` storage backend, expired records are automatically marked for pruning via Laravel's `Prunable` trait on the `ZaberDev\Quota\Models\Quota` model.

To clean up old records automatically, schedule Laravel's `model:prune` command in your `console.php` or `Kernel.php`:

```php
use Illuminate\Support\Facades\Schedule;
use ZaberDev\Quota\Models\Quota;

Schedule::command('model:prune', ['--model' => Quota::class])->daily();
```

---

### 6. Events

Whenever a quota is consumed, exceeded, or reset, the package dispatches strongly typed events if enabled (`quotas.events.dispatch = true`):

- **`ZaberDev\Quota\Events\QuotaConsumed`**: Dispatched when `consume()` deducts units (`$key`, `$amount`, `$remaining`, `$info`).
- **`ZaberDev\Quota\Events\QuotaExceeded`**: Dispatched when capacity is exceeded (`$key`, `$limit`, `$info`).
- **`ZaberDev\Quota\Events\QuotaReset`**: Dispatched when `reset()` clears a period window (`$key`, `$info`).

You can listen to these in your `EventServiceProvider` for logging, monitoring, or webhook triggers.

---

## Agentic Development with Laravel Boost

**Laravel Quota** includes built-in AI support and architectural skills engineered for [Laravel Boost](https://github.com/laravel/boost).

When using AI coding assistants (such as Cursor, Claude Code, or GitHub Copilot connected via the Boost MCP server), your AI agent can automatically load specialized design patterns and exact API rules for implementing application-level quotas, usage budgets, and calendar-based usage limits with our package.

### Automatic Skill Installation

When Laravel Boost (`laravel/boost`) is installed in your application, the `laravel-quota` AI skill is **automatically discovered and published** during package installation and updates (`php artisan boost:install` or `php artisan boost:update`).

If you install `zaber-dev/laravel-quota` into an existing Boost-enabled project, our service provider also automatically synchronizes the skill directly into your `.ai/skills/laravel-quota` directory on boot with zero configuration required.

### Manual Skill Installation

If you prefer to install or update the AI skill manually, you can use either of the following commands:

```bash
# Using Laravel Boost
php artisan boost:add-skill zaber-dev/laravel-quota

# Using Vendor Publish
php artisan vendor:publish --tag=quotas-skill
```

### What the AI Skill Teaches Your Assistant

By enabling our skill, your AI assistant will strictly follow package conventions, including:

- Utilizing `Quota::for('action', $target)->block(...)` for atomic quota reservations, concurrency protection, and success-only quota consumption.
- Applying `use ZaberDev\Quota\HasQuotas;` directly to Eloquent models (`$user->quota('pdf_exports')->limit(50)->perMonth()->consume()`).
- Choosing the appropriate storage driver (`cache` for high-throughput transient quotas or `database` for persistent usage tracking and auditability).
- Applying calendar-based quota periods (`perMinute()`, `perHour()`, `perDay()`, `perWeek()`, `perMonth()`, and `perYear()`) instead of implementing manual reset logic.
- Enforcing route middleware (`middleware('quota:action,limit,period')`) that only consumes quota for successful (`2xx`/`3xx`) responses while respecting validation failures.
- Handling `QuotaExceededException` (`HTTP 429`) and leveraging the `QuotaInfo` DTO for accurate usage, remaining quota, and period boundary information.

---

## Related Packages

This package is part of the **[ZaberDev Laravel Ecosystem](https://github.com/zaber-dev/laravel-ecosystem)** (Laravel Productivity Toolkit) — a cohesive suite of high-level application primitives engineered for concurrency, state management, and resource allocation.

Explore the complete directory of packages, detailed use cases, and documentation in our **[Ecosystem Index Hub](https://github.com/zaber-dev/laravel-ecosystem/blob/main/PACKAGES.md)**.

---

## Testing & Quality

Run the comprehensive PHPUnit test suite locally:

```bash
composer test
```

---

## Contributing

Thank you for considering contributing! Please ensure any pull requests include thorough PHPUnit tests covering unit, feature, and storage backend integration scenarios.

---

## License

The MIT License (MIT). Please see [LICENSE.md](LICENSE.md) for more information.

---

<p align="center">
    <b>Built with ❤️ as part of the ZaberDev Laravel Ecosystem.</b>
</p>

