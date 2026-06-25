<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\TestOrder;
use App\Services\TestOrders\OrderNumberService;
use App\Services\TestOrders\SyncTestOrderChildren;
use App\Services\TestOrders\TestOrderPayloadNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        $customer = Customer::query()
            ->with('contacts')
            ->where('status', 'active')
            ->where(function (Builder $query) use ($phone): void {
                $query->where('phone', $phone)
                    ->orWhereHas('contacts', fn (Builder $contactQuery): Builder => $contactQuery
                        ->where('status', 'active')
                        ->where('phone', $phone));
            })
            ->orderBy('id')
            ->first();

        if (! $customer instanceof Customer) {
            return response()->json(['data' => null]);
        }

        $matchedContact = $customer->contacts->first(fn ($contact): bool => $contact->status === 'active' && $this->normalizePhone($contact->phone) === $phone);
        $defaultContact = $customer->contacts->first(fn ($contact): bool => $contact->status === 'active' && $contact->is_default);
        $contact = $matchedContact ?? $defaultContact;

        return response()->json([
            'data' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'address' => $customer->address,
                'phone' => $customer->phone,
                'contact' => $contact === null ? null : [
                    'name' => $contact->name,
                    'phone' => $contact->phone,
                ],
            ],
        ]);
    }

    public function store(
        Request $request,
        OrderNumberService $orderNumberService,
        TestOrderPayloadNormalizer $normalizer,
        SyncTestOrderChildren $syncChildren,
    ): JsonResponse {
        $payload = $normalizer->normalize($request->validate($this->rules()));
        $payload = $this->applyMatchedCustomer($payload);
        $samples = $payload['samples'];
        unset($payload['samples']);

        $testOrder = DB::transaction(function () use ($payload, $samples, $orderNumberService, $syncChildren): TestOrder {
            $orderNo = $orderNumberService->generate();
            $testOrder = TestOrder::query()->create([
                ...$payload,
                'order_no' => $orderNo,
                'contract_no' => $orderNo,
                'order_date' => now()->toDateString(),
                'urgency' => 'normal',
                'sample_status' => 'not_received',
                'report_forms' => ['formal_report', 'electronic_report'],
                'delivery_method' => 'self_pick',
                'outsourcing_option' => 'allowed',
            ]);

            $syncChildren->sync($testOrder, [], $samples);

            return $testOrder->fresh(['samples']);
        });

        return response()->json([
            'data' => [
                'id' => $testOrder->id,
                'order_no' => $testOrder->order_no,
                'client_company' => $testOrder->client_company,
                'samples_count' => $testOrder->samples->count(),
            ],
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
            'samples' => ['required', 'array', 'min:1'],
            'samples.*.sample_name' => ['required', 'string', 'max:255'],
            'samples.*.specification' => ['nullable', 'string', 'max:255'],
            'samples.*.model' => ['nullable', 'string', 'max:255'],
            'samples.*.input_voltage' => ['nullable', 'string', 'max:255'],
            'samples.*.power' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyMatchedCustomer(array $payload): array
    {
        $phone = $this->normalizePhone($payload['client_phone'] ?? null);

        if ($phone === '') {
            return $payload;
        }

        $customer = Customer::query()
            ->where('status', 'active')
            ->where(function (Builder $query) use ($phone): void {
                $query->where('phone', $phone)
                    ->orWhereHas('contacts', fn (Builder $contactQuery): Builder => $contactQuery
                        ->where('status', 'active')
                        ->where('phone', $phone));
            })
            ->orderBy('id')
            ->first();

        if (! $customer instanceof Customer) {
            return $payload;
        }

        return [
            ...$payload,
            'client_customer_id' => $customer->id,
            'client_company' => $payload['client_company'] ?: $customer->name,
            'client_address' => $payload['client_address'] ?: $customer->address,
        ];
    }

    private function normalizePhone(mixed $phone): string
    {
        return trim((string) $phone);
    }
}
