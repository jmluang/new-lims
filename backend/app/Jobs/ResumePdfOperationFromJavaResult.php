<?php

namespace App\Jobs;

use App\Services\Pdf\PdfImmutableFileStore;
use App\Services\Pdf\PdfRendererClient;
use App\Services\Pdf\PdfRevisionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class ResumePdfOperationFromJavaResult implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    /** @var list<int> */
    public array $backoff = [2, 5, 15];

    public function __construct(public readonly string $operationUuid) {}

    public function handle(
        PdfRendererClient $renderer,
        PdfImmutableFileStore $files,
        PdfRevisionService $revisions,
    ): void {
        (new ExecutePdfSigningOperation($this->operationUuid))
            ->resumeFromJavaResult($renderer, $files, $revisions);
    }

    public function failed(Throwable $exception): void
    {
        (new ExecutePdfSigningOperation($this->operationUuid))->failed($exception);
    }
}
