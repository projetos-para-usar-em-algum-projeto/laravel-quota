# Learn: Building Application-Level Quota Management & Usage Limits in Laravel

Welcome to the architectural learning guide for **Laravel Quota** (`zaber-dev/laravel-quota`). Whether you are evaluating this package for your enterprise application or studying modern Laravel package engineering for GitHub Community Exchange, this document provides a deep dive into the design patterns, abstractions, and developer ergonomics behind the project.

---

## 1. Core Architectural Goals

When building **Laravel Quota**, we aimed to solve several historical problems with application-level quota management and usage tracking:
1. **Coupling to a Single Storage Backend**: Many packages hardcode `Cache::increment()` or `DB::table()`, forcing developers to use either volatile memory or high-latency database operations exclusively.
2. **Ad-Hoc Period Window Calculations**: Calculating exact start and end timestamps for calendar boundaries (`perMonth()`, `perWeek()`, `perDay()`) often leads to timezone discrepancies across distributed servers.
3. **Inconsistent Polymorphic Target Scoping**: Metering a User (`App\Models\User:12`) vs a Team (`App\Models\Team:5`) vs an API Token usually requires disparate key formatting logic across application layers.
4. **Endpoint Middleware Gotchas**: Traditional rate-limiting middleware consumes quota units *before* the controller executes. If the controller throws a validation error or fails (`HTTP 400/500`), the user unfairly loses a unit from their monthly budget while trying to correct their input.

Let's explore how our architecture solves each of these challenges cleanly.

---

## 2. The Storage Backend Manager Pattern (`Illuminate\Support\Manager`)

At the heart of the package is the `QuotaManager`, extending `Illuminate\Support\Manager` and implementing `QuotaManagerContract`.

```
                    +-----------------------+
                    |    Quota (Facade)     |
                    +-----------+-----------+
                                |
                                v
                   +------------------------+
                   |      QuotaManager      |
                   +----+--------------+----+
                        |              |
         +--------------+              +--------------+
         | driver('cache')                            | driver('database')
         v                                            v
+-----------------------+                    +-----------------------+
|   CacheQuotaDriver    |                    |  DatabaseQuotaDriver  |
+-----------------------+                    +-----------------------+
| ->increment() / ->get |                    | ->increment() / ->get |
| (Redis/Memcached/Arr) |                    | (Eloquent / Prunable) |
+-----------------------+                    +-----------------------+
```

### Why a Manager?
By leveraging Laravel's `Manager` class, we decouple the API surface (`Quota::for()`) from the storage implementation (`CacheQuotaDriver` / `DatabaseQuotaDriver`).
- **Zero Overhead Resolution**: Storage backends (`$this->drivers[$name]`) are lazily instantiated and cached.
- **Runtime Swapping**: Developers can switch storage backends at runtime without changing application logic.
- **Strict Interface Contracts**: Every storage backend implements the same QuotaDriverContract, ensuring identical behavior regardless of where usage data is stored.

---

## 3. Polymorphic Target Key & Period Resolution

How does `$user->quota('pdf_exports')->perMonth()` know how to construct a unique, collision-free storage key that auto-resets on the first day of every month?

In `PendingQuota::resolveStorageKey()`, we combine the action, target identity, and precise period boundary timestamps:
```php
// Resolved Cache/DB Key:
"quotas:pdf_exports:App\Models\User:12:1782864000:1785542399"
```
- When passed an **Eloquent Model**, `getMorphClass()` respects custom morph maps defined in `Relation::enforceMorphMap()`, ensuring database migrations and cache keys remain stable even if class namespaces change.
- When `CarbonImmutable::now()->startOfMonth()` produces a new start timestamp (`1785542400`), `resolveStorageKey` automatically targets a brand new, isolated counter starting at `0`, providing zero-maintenance calendar rollovers without midnight cron jobs.

---

## 4. Immutable Data Transfer Objects (`QuotaInfo`)

Instead of returning raw integers or associative arrays that can be accidentally mutated or misparsed, every storage backend read or increment returns a strongly typed `QuotaInfo` DTO:

```php
final class QuotaInfo
{
    public function __construct(
        public readonly string $key,
        public readonly int $used,
        public readonly ?int $limit,
        public readonly CarbonImmutable $periodStart,
        public readonly CarbonImmutable $periodEnd,
    ) {}

    public function remaining(): int
    {
        if ($this->limit === null) {
            return PHP_INT_MAX;
        }

        return max(0, $this->limit - $this->used);
    }
}
```

### Why `CarbonImmutable` & Strict Bounds?
1. **Immutability**: `CarbonImmutable` guarantees that downstream controllers or event listeners cannot accidentally alter period boundaries when performing date math.
2. **Reliable Header Reporting**: By encapsulating `used`, `remaining`, and `periodEnd`, our API accurately populates HTTP `X-RateLimit-Limit`, `X-RateLimit-Remaining`, and `X-RateLimit-Reset` headers with absolute mathematical consistency.

---

## 5. Smart Endpoint Middleware (`CheckQuota`)

Our route middleware (`CheckQuota`) introduces an important behavioral improvement over naive rate limiters: **Success-Only Triggering**.

```php
public function handle(Request $request, Closure $next, string $action, string $limit, string $period = 'day', ?string $driver = null): Response
{
    $manager = app(QuotaManagerContract::class);
    $target = $request->user() ?? $request->ip();
    $pending = $manager->for($action, $target)->limit((int) $limit);

    // 1. Configure period window and enforce existing limit before execution
    $this->applyPeriod($pending, $period);
    $pending->enforce();

    // 2. Execute downstream controller inside try...finally
    $response = $next($request);

    // 3. ONLY consume quota units if request succeeded or redirected (HTTP 2xx or 3xx)
    if ($response->isSuccessful() || $response->isRedirection()) {
        $pending->consume(1);
    }

    return $this->addHeaders($response, $pending->info());
}
```
If a user submits a form and receives an HTTP `422 Unprocessable Entity` due to validation errors, the quota unit is never deducted. They can immediately fix their mistake and resubmit without unfairly depleting their monthly budget.

### Architectural Evolution: Check-Lock-Execute-Consume via Atomic In-Flight Reservations
To solve concurrency race conditions (`SELECT ... FOR UPDATE` or cache mutexes) during heavy multi-step jobs, `laravel-quota` also exposes the `$pending->block(Closure $callback, int $amount = 1, int $lockSeconds = 15)` API:

1. **Check (`enforce()`)**: Verify if capacity exists for the requested `$amount`.
2. **Lock (`acquireLock(15)`)**: Atomically acquire a short, temporary in-flight reservation lock across the storage backend. If another thread is currently executing inside the controller for the same target, `acquireLock` fails and throws `HTTP 429`.
3. **Execute (`$callback()`)**: Run the closure inside a `try...finally` block.
4. **Consume (`consume($amount)`) & Release (`releaseLock()`)**: If the closure completes without throwing an exception, the quota units are atomically deducted. Regardless of outcome, the `finally` block releases the short in-flight reservation lock (`releaseLock()`).

---

## 6. Eloquent `Prunable` Integration

When using database-backed quota tracking, historical period records can accumulate rapidly over months. Rather than requiring custom cron jobs or complex cleanup queries, the `ZaberDev\Quota\Models\Quota` model integrates directly with Laravel's native `Illuminate\Database\Eloquent\Prunable` trait:

```php
class Quota extends Model
{
    use Prunable;

    public function prunable(): Builder
    {
        return static::query()->where('period_end', '<=', CarbonImmutable::now());
    }
}
```
This allows developers to run `php artisan model:prune` or schedule it via `Schedule::command('model:prune', ['--model' => Quota::class])->daily()` with zero custom code.

---

## 7. Agent-Aware Architecture (Laravel Boost Skills)

With the rise of AI-assisted engineering and [Laravel Boost](https://github.com/laravel/boost), modern Laravel packages are no longer consumed solely by human engineers—they are actively queried, integrated, and refactored by autonomous AI agents (Cursor, Claude Code, Copilot).

To ensure high-precision code generation and eliminate hallucinations, `laravel-quota` implements an **Agent-Aware Architecture**:
1. **Bundled Skill (`resources/boost/skills/laravel-quota/SKILL.md`)**: We ship a comprehensive, structured Markdown and YAML skill guide right inside the package repository. When an AI agent connects via the Boost MCP server (`php artisan boost:install`), Boost auto-discovers and indexes this skill.
2. **Auto-Synchronization Hook (`QuotaServiceProvider::autoPublishBoostSkill`)**: When our package boots in console (`php artisan ...`), our service provider checks if Laravel Boost or the `.ai/skills/` directory is present in the host application. If detected, it automatically synchronizes our skill folder directly into `base_path('.ai/skills/laravel-quota')` if it hasn't already been published.
3. **Explicit Composer Metadata (`composer.json` -> `extra.boost.skills`)**: We declare our skill paths inside `composer.json` so external AI tooling and discovery agents can instantly resolve and verify available domain skills without scanning arbitrary directories.

By packaging operational rules, architectural gotchas, and idiomatic API patterns (`->block()`, `HasQuotas` trait, polymorphic key resolution) directly alongside source code, we ensure that both human developers and their AI assistants write robust, concurrency-safe quota patterns from day one.

---

## 8. Summary for Contributors & Students

By studying this codebase, you will see how modern Laravel 13 packages combine:
- **Service Providers** (`QuotaServiceProvider`) for dependency container registration, configuration publishing, and auto-discovering AI skills.
- **Contract-first engineering** (`QuotaDriverContract`, `QuotaManagerContract`) for mockable, highly testable code.
- **Fluent APIs** (`PendingQuota`) to provide a delightful, readable developer experience (`Quota::for('action', $user)->limit(50)->perMonth()->consume()`).
- **Orchestra Testbench 11** (`tests/TestCase.php`) for testing packages against real Laravel container pipelines in memory.

We invite you to explore the `src/` directory and see these patterns in action!
