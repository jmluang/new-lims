<?php

namespace App\Services\Pdf;

use App\Jobs\ExecutePdfSigningOperation;
use App\Jobs\ExecutePdfWorkflowControlOperation;
use App\Jobs\ResumePdfOperationFromJavaResult;
use App\Models\PdfOperationOutbox;
use App\Models\PdfSigningOperation;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PdfOperationOutboxDispatcher
{
    public function dispatchPending(int $limit = 100): int
    {
        $ids = PdfOperationOutbox::query()
            ->where('state', 'pending')
            ->where('available_at', '<=', now())
            ->orderBy('id')
            ->limit(max(1, min($limit, 1000)))
            ->pluck('id');
        $dispatched = 0;

        foreach ($ids as $id) {
            $candidate = DB::transaction(function () use ($id): ?array {
                $outbox = PdfOperationOutbox::query()->findOrFail($id);
                $operation = PdfSigningOperation::query()->lockForUpdate()->findOrFail($outbox->operation_id);
                $lockedOutbox = PdfOperationOutbox::query()->lockForUpdate()->findOrFail($outbox->id);

                if ($lockedOutbox->state !== 'pending') {
                    return null;
                }
                if (! in_array($operation->state, ['claimed', 'processing', 'promoted'], true)) {
                    $lockedOutbox->update(['state' => 'cancelled']);

                    return null;
                }
                if (! in_array($lockedOutbox->job_type, [
                    'execute_pdf_signing_operation',
                    'execute_pdf_workflow_control_operation',
                    'resume_pdf_operation_from_java_result',
                ], true)) {
                    throw new RuntimeException('PDF operation outbox job type is not allowed.');
                }
                $expectedHash = hash('sha256', CanonicalJson::encode([
                    'job_type' => $lockedOutbox->job_type,
                    'operation_uuid' => $operation->operation_uuid,
                ]));
                if (! hash_equals($expectedHash, $lockedOutbox->payload_hash)) {
                    throw new RuntimeException('PDF operation outbox payload hash mismatch.');
                }

                return [
                    'job_type' => $lockedOutbox->job_type,
                    'outbox_id' => $lockedOutbox->id,
                    'operation_uuid' => $operation->operation_uuid,
                ];
            }, 3);

            if ($candidate === null) {
                continue;
            }

            if ($candidate['job_type'] === 'resume_pdf_operation_from_java_result') {
                ResumePdfOperationFromJavaResult::dispatch($candidate['operation_uuid']);
            } elseif ($candidate['job_type'] === 'execute_pdf_workflow_control_operation') {
                ExecutePdfWorkflowControlOperation::dispatch($candidate['operation_uuid']);
            } else {
                ExecutePdfSigningOperation::dispatch($candidate['operation_uuid']);
            }
            PdfOperationOutbox::query()
                ->whereKey($candidate['outbox_id'])
                ->where('state', 'pending')
                ->update([
                    'state' => 'dispatched',
                    'dispatched_at' => now(),
                    'attempt_count' => DB::raw('attempt_count + 1'),
                ]);
            $dispatched++;
        }

        return $dispatched;
    }
}
