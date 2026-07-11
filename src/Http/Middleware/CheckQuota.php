<?php

declare(strict_types=1);

namespace ZaberDev\Quota\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use ZaberDev\Quota\Contracts\QuotaManagerContract;
use ZaberDev\Quota\Exceptions\QuotaExceededException;

/**
 * Route middleware to enforce quotas on incoming HTTP requests with success-only consumption.
 */
class CheckQuota
{
    /**
     * Handle an incoming request.
     *
     * @param Closure(Request): Response $next
     * @param string $action The action key being checked.
     * @param string $limit The maximum allowed quota limit for the period.
     * @param string $period The time window string (minute, hour, day, week, month, year).
     * @param string|null $driver Optional custom driver name.
     *
     * @throws QuotaExceededException
     */
    public function handle(Request $request, Closure $next, string $action, string $limit = '50', string $period = 'month', ?string $driver = null): Response
    {
        $target = $request->user() ?? $request->ip();
        $manager = app(QuotaManagerContract::class);

        $pending = $manager->for($action, $target)->limit((int) $limit);

        if ($driver) {
            $pending->using($driver);
        }

        match (strtolower($period)) {
            'minute', 'min' => $pending->perMinute(),
            'hour', 'hr' => $pending->perHour(),
            'day' => $pending->perDay(),
            'week' => $pending->perWeek(),
            'year' => $pending->perYear(),
            default => $pending->perMonth(),
        };

        $pending->enforce();

        $response = $next($request);

        // Success-only quota consumption: only consume when status code < 400
        if ($response->isSuccessful() || $response->isRedirection()) {
            $pending->consume(1);
        }

        $info = $pending->info();

        $response->headers->set('X-RateLimit-Limit', (string) ($info->limit ?? 0));
        $response->headers->set('X-RateLimit-Remaining', (string) $info->remaining());
        $response->headers->set('X-RateLimit-Reset', (string) $info->periodEnd->getTimestamp());

        return $response;
    }
}
