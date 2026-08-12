<?php

namespace App\Http\Controllers;

use App\Models\PublicTestOrderSubmission;
use App\Models\TestOrder;
use App\Services\Audit\AuditLogger;
use App\Services\TestOrders\OrderNumberService;
use App\Services\TestOrders\SyncTestOrderChildren;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PublicTestOrderSubmissionReviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'test_orders.read', 'test_orders');

        $submissions = PublicTestOrderSubmission::query()
            ->with('testOrder')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->orderByDesc('id')
            ->paginate((int) $request->integer('per_page', 15));

        return response()->json([
            'data' => $submissions->getCollection()
                ->map(fn (PublicTestOrderSubmission $submission): array => $this->serializeSubmission($submission))
                ->values(),
            'meta' => [
                'current_page' => $submissions->currentPage(),
                'per_page' => $submissions->perPage(),
                'total' => $submissions->total(),
            ],
        ]);
    }

    public function accept(
        Request $request,
        PublicTestOrderSubmission $publicTestOrderSubmission,
        OrderNumberService $orderNumberService,
        SyncTestOrderChildren $syncChildren,
        AuditLogger $auditLogger,
    ): JsonResponse {
        $this->authorizePermission($request, 'test_orders.create', 'test_orders');

        $result = DB::transaction(function () use ($request, $publicTestOrderSubmission, $orderNumberService, $syncChildren): array {
            $submission = PublicTestOrderSubmission::query()
                ->lockForUpdate()
                ->findOrFail($publicTestOrderSubmission->id);
            $this->ensurePending($submission);

            $orderNo = $orderNumberService->generate();
            $testOrder = TestOrder::query()->create([
                'order_no' => $orderNo,
                'contract_no' => $orderNo,
                'order_date' => now()->toDateString(),
                'urgency' => 'normal',
                'client_customer_id' => $submission->matched_customer_id,
                'client_company' => $submission->client_company,
                'client_address' => $submission->client_address,
                'client_contact' => $submission->client_contact,
                'client_phone' => $submission->client_phone,
                'sample_status' => 'not_received',
                'report_forms' => ['formal_report', 'electronic_report'],
                'delivery_method' => 'self_pick',
                'outsourcing_option' => 'allowed',
                'address_lab_name' => '中山市鑫普达检测有限公司',
                'address_contact' => '鑫普达检测',
                'address_detail' => '广东省中山市古镇镇东兴东路33号7栋1层之一',
                'created_by' => $request->user()?->id,
                'updated_by' => $request->user()?->id,
            ]);

            $syncChildren->sync($testOrder, [], $submission->samples ?? []);

            $submission->update([
                'status' => 'accepted',
                'test_order_id' => $testOrder->id,
                'accepted_by' => $request->user()?->id,
                'accepted_at' => now(),
            ]);

            return [
                'submission' => $submission->fresh('testOrder.samples'),
                'test_order' => $testOrder->fresh('samples'),
            ];
        });

        $auditLogger->record(
            actor: $request->user(),
            action: 'public_test_order_submissions.accept',
            module: 'public_test_order_submissions',
            subject: $result['submission'],
            after: [
                'submission' => $this->serializeSubmission($result['submission']),
                'test_order_id' => $result['test_order']->id,
                'order_no' => $result['test_order']->order_no,
            ],
        );

        return response()->json([
            'data' => [
                ...$this->serializeSubmission($result['submission']),
                'test_order' => $this->serializeTestOrder($result['test_order']),
            ],
        ], 201);
    }

    public function reject(Request $request, PublicTestOrderSubmission $publicTestOrderSubmission, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'test_orders.create', 'test_orders');

        $payload = $request->validate([
            'review_remark' => ['nullable', 'string', 'max:1000'],
        ]);

        $submission = DB::transaction(function () use ($request, $publicTestOrderSubmission, $payload): PublicTestOrderSubmission {
            $submission = PublicTestOrderSubmission::query()
                ->lockForUpdate()
                ->findOrFail($publicTestOrderSubmission->id);
            $this->ensurePending($submission);

            $submission->update([
                'status' => 'rejected',
                'review_remark' => $payload['review_remark'] ?? null,
                'rejected_by' => $request->user()?->id,
                'rejected_at' => now(),
            ]);

            return $submission->fresh();
        });

        $auditLogger->record(
            actor: $request->user(),
            action: 'public_test_order_submissions.reject',
            module: 'public_test_order_submissions',
            subject: $submission,
            after: $this->serializeSubmission($submission),
        );

        return response()->json(['data' => $this->serializeSubmission($submission)]);
    }

    private function ensurePending(PublicTestOrderSubmission $submission): void
    {
        if ($submission->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => ['public_test_order_submission_not_pending'],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSubmission(PublicTestOrderSubmission $submission): array
    {
        return [
            'id' => $submission->id,
            'submission_no' => $submission->submission_no,
            'client_company' => $submission->client_company,
            'client_address' => $submission->client_address,
            'client_contact' => $submission->client_contact,
            'client_phone' => $submission->client_phone,
            'samples' => $submission->samples ?? [],
            'samples_count' => count($submission->samples ?? []),
            'status' => $submission->status,
            'test_order_id' => $submission->test_order_id,
            'test_order' => $submission->testOrder ? $this->serializeTestOrder($submission->testOrder) : null,
            'review_remark' => $submission->review_remark,
            'submitted_at' => $submission->submitted_at?->format('Y-m-d H:i:s'),
            'accepted_at' => $submission->accepted_at?->format('Y-m-d H:i:s'),
            'rejected_at' => $submission->rejected_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTestOrder(TestOrder $testOrder): array
    {
        return [
            'id' => $testOrder->id,
            'order_no' => $testOrder->order_no,
            'client_company' => $testOrder->client_company,
            'sample_status' => $testOrder->sample_status,
            'address_lab_name' => $testOrder->address_lab_name,
            'address_contact' => $testOrder->address_contact,
            'address_detail' => $testOrder->address_detail,
            'samples' => $testOrder->samples
                ->map(fn ($sample): array => [
                    'id' => $sample->id,
                    'sample_name' => $sample->sample_name,
                    'specification' => $sample->specification,
                    'model' => $sample->model,
                    'input_voltage' => $sample->input_voltage,
                    'power' => $sample->power,
                    'quantity' => $sample->quantity,
                ])
                ->values(),
        ];
    }
}
