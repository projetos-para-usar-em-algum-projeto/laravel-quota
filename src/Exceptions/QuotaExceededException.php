<?php

declare(strict_types=1);

namespace ZaberDev\Quota\Exceptions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\HttpException;
use ZaberDev\Quota\DTO\QuotaInfo;

/**
 * Exception thrown when an action check or attempt exceeds the allowed quota limit.
 */
class QuotaExceededException extends HttpException
{
    /**
     * @param QuotaInfo $info The current quota DTO.
     * @param string $action The action identifier.
     * @param Model|string|int|null $target The target entity.
     * @param string|null $message Custom exception message.
     */
    public function __construct(
        public readonly QuotaInfo $info,
        public readonly string $action,
        public readonly Model|string|int|null $target = null,
        ?string $message = null,
    ) {
        $retryAfterSeconds = (int) ceil(max(0, CarbonImmutable::now()->floatDiffInSeconds($info->periodEnd, false)));

        $headers = [
            'Retry-After' => (string) $retryAfterSeconds,
            'X-RateLimit-Limit' => (string) ($info->limit ?? 0),
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset' => (string) $info->periodEnd->getTimestamp(),
        ];

        $message ??= sprintf(
            'Quota exceeded for action [%s]. Limit is %d per period. Try again in %s.',
            $action,
            $info->limit ?? 0,
            $info->resetsInForHumans()
        );

        parent::__construct(429, $message, null, $headers);
    }
}
