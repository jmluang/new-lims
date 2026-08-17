<?php

namespace App\Services\Pdf;

use App\Models\PdfSigningRequest;
use App\Models\UserMessage;
use Illuminate\Support\Facades\DB;

/**
 * Tells a signer their turn has come.
 *
 * Signing is sequential, so being assigned is not the same as being able to
 * sign: only the request that is `available` is actionable, and the ones behind
 * it stay `pending` until the signature in front lands. Notifying everyone at
 * planning time would tell two of three people to do something they cannot do
 * yet, so each signer hears from us when their own request opens.
 *
 * Messages are written in the same transaction as the state change that caused
 * them; a notification for a signature that rolled back would be worse than
 * none at all.
 */
final class PdfSigningNotifier
{
    public function notifyAvailable(PdfSigningRequest $request): void
    {
        $request->loadMissing(['act', 'workflow.document']);
        $document = $request->workflow?->document;

        if ($document === null || $request->assigned_user_id === null) {
            return;
        }

        $role = self::roleLabel($request->act?->semantic_role);
        $reportNumber = $document->authoritative_report_number;

        // One message per request, so a reconciler replaying the same transition
        // does not notify twice.
        $already = UserMessage::query()
            ->where('recipient_user_id', $request->assigned_user_id)
            ->where('title', '手写签名待处理')
            ->where('content', 'like', "%{$request->request_uuid}%")
            ->exists();

        if ($already) {
            return;
        }

        UserMessage::query()->create([
            'recipient_user_id' => $request->assigned_user_id,
            'sender_user_id' => null,
            'title' => '手写签名待处理',
            'content' => "报告 {$reportNumber} 需要你完成{$role}手写签名（第 {$request->sequence} 步）。任务编号 {$request->request_uuid}。",
        ]);
    }

    /**
     * Notify whichever request became available inside the current transaction.
     *
     * Callers advance the chain and then hand us the workflow; finding the open
     * request here keeps the sequencing rule in one place.
     */
    public function notifyNextInWorkflow(int $workflowId): void
    {
        $next = PdfSigningRequest::query()
            ->where('workflow_id', $workflowId)
            ->where('status', 'available')
            ->orderBy('sequence')
            ->first();

        if ($next !== null) {
            $this->notifyAvailable($next);
        }
    }

    /** Told after a rejection, so the planner is not left waiting on it. */
    public function notifyRejected(PdfSigningRequest $request, string $reasonCode): void
    {
        $request->loadMissing(['act', 'workflow.document']);
        $document = $request->workflow?->document;

        if ($document === null || $document->created_by_id === null) {
            return;
        }

        $role = self::roleLabel($request->act?->semantic_role);
        UserMessage::query()->create([
            'recipient_user_id' => $document->created_by_id,
            'sender_user_id' => $request->assigned_user_id,
            'title' => '手写签名被拒绝',
            'content' => "报告 {$document->authoritative_report_number} 的{$role}签名被拒绝，原因代码 {$reasonCode}。",
        ]);
    }

    private static function roleLabel(?string $semanticRole): string
    {
        return match ($semanticRole) {
            'inspector' => '主检',
            'reviewer' => '审核',
            'issuer' => '签发',
            default => '',
        };
    }

    /** Run after the surrounding transaction commits, never before. */
    public function afterCommit(callable $notify): void
    {
        DB::afterCommit($notify);
    }
}
