<?php

declare(strict_types=1);

namespace ZaberDev\Quota\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use ZaberDev\Quota\Exceptions\QuotaExceededException;
use ZaberDev\Quota\Http\Middleware\CheckQuota;
use ZaberDev\Quota\Tests\TestCase;

class MiddlewareTest extends TestCase
{
    #[Test]
    public function it_enforces_quota_via_middleware_and_only_consumes_on_success(): void
    {
        $middleware = new CheckQuota();
        $request = Request::create('/exports', 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);

        // First successful response consumes 1 quota
        $response1 = $middleware->handle($request, static function () {
            return new Response('Success', 200);
        }, 'export_api', '2', 'minute');

        $this->assertSame(200, $response1->getStatusCode());
        $this->assertSame('2', $response1->headers->get('X-RateLimit-Limit'));
        $this->assertSame('1', $response1->headers->get('X-RateLimit-Remaining'));

        // Second failed request (500) does NOT consume quota due to success-only triggering
        $response2 = $middleware->handle($request, static function () {
            return new Response('Error', 500);
        }, 'export_api', '2', 'minute');

        $this->assertSame(500, $response2->getStatusCode());

        // Third successful request consumes the 2nd quota
        $response3 = $middleware->handle($request, static function () {
            return new Response('Success 2', 200);
        }, 'export_api', '2', 'minute');

        $this->assertSame(200, $response3->getStatusCode());
        $this->assertSame('0', $response3->headers->get('X-RateLimit-Remaining'));

        // Fourth request throws QuotaExceededException (429)
        $this->expectException(QuotaExceededException::class);
        $middleware->handle($request, static function () {
            return new Response('Success 3', 200);
        }, 'export_api', '2', 'minute');
    }
}
