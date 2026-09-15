<?php

namespace App\Services\Pdf;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class PdfOperationQueue
{
    public static function connection(): string
    {
        return (string) config('queue.default');
    }

    public static function isAsynchronous(): bool
    {
        return ! in_array(self::connection(), ['sync', 'null'], true);
    }

    public static function assertAsynchronous(): void
    {
        if (app()->runningUnitTests() || self::isAsynchronous()) {
            return;
        }

        throw new ServiceUnavailableHttpException(
            null,
            'PDF_SIGNING_ASYNC_QUEUE_REQUIRED',
        );
    }
}
