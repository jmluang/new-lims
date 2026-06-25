<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\PublicTestOrderSubmission;
use App\Services\Audit\AuditLogger;
use App\Services\TestOrders\TestOrderPayloadNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PublicTestOrderSubmissionController extends Controller
{
    public function lookupCustomer(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'phone' => ['required', 'string', 'max:64'],
        ]);

        $phone = $this->normalizePhone($payload['phone']);

        if ($phone === '') {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => null]);
    }

    public function store(
        Request $request,
        TestOrderPayloadNormalizer $normalizer,
        AuditLogger $auditLogger,
    ): JsonResponse {
        $payload = $normalizer->normalize($request->validate($this->rules()));
        $customer = $this->findMatchedCustomer($this->normalizePhone($payload['client_phone'] ?? null));

        $submission = PublicTestOrderSubmission::query()->create([
            'submission_no' => $this->generateSubmissionNo(),
            'matched_customer_id' => $customer?->id,
            'client_company' => $payload['client_company'],
            'client_address' => $payload['client_address'] ?? null,
            'client_contact' => $payload['client_contact'] ?? null,
            'client_phone' => $payload['client_phone'],
            'samples' => $payload['samples'],
            'status' => 'pending',
            'submitted_ip' => $request->ip(),
            'submitted_user_agent' => $request->userAgent(),
            'submitted_at' => now(),
        ]);

        $auditLogger->record(
            actor: null,
            action: 'public_test_order_submissions.create',
            module: 'public_test_order_submissions',
            subject: $submission,
            after: $this->serializeSubmission($submission),
        );

        return response()->json([
            'data' => $this->serializeSubmission($submission),
        ], 201);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function rules(): array
    {
        return [
            'client_company' => ['required', 'string', 'max:255'],
            'client_address' => ['nullable', 'string', 'max:255'],
            'client_contact' => ['nullable', 'string', 'max:255'],
            'client_phone' => ['required', 'string', 'max:64'],
            'samples' => ['required', 'array', 'min:1', 'max:20'],
            'samples.*.sample_name' => ['required', 'string', 'max:255'],
            'samples.*.specification' => ['nullable', 'string', 'max:255'],
            'samples.*.model' => ['nullable', 'string', 'max:255'],
            'samples.*.input_voltage' => ['nullable', 'string', 'max:255'],
            'samples.*.power' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function findMatchedCustomer(string $phone): ?Customer
    {
        if ($phone === '') {
            return null;
        }

        return Customer::query()
            ->where('status', 'active')
            ->where(function (Builder $query) use ($phone): void {
                $query->where('phone', $phone)
                    ->orWhereHas('contacts', fn (Builder $contactQuery): Builder => $contactQuery
                        ->where('status', 'active')
                        ->where('phone', $phone));
            })
            ->orderBy('id')
            ->first();
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
            'client_phone' => $submission->client_phone,
            'samples_count' => count($submission->samples ?? []),
            'status' => $submission->status,
            'submitted_at' => $submission->submitted_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function generateSubmissionNo(): string
    {
        do {
            $submissionNo = 'PUB'.now()->format('YmdHis').Str::upper(Str::random(6));
        } while (PublicTestOrderSubmission::query()->where('submission_no', $submissionNo)->exists());

        return $submissionNo;
    }

    private function normalizePhone(mixed $phone): string
    {
        return trim((string) $phone);
    }
}
