<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Standard;
use App\Models\TestOrder;
use App\Services\Audit\AuditLogger;
use App\Services\Authorization\PermissionAccess;
use App\Services\TestOrders\OrderNumberService;
use App\Services\TestOrders\SyncTestOrderChildren;
use App\Services\TestOrders\TestOrderPayloadNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TestOrderController extends Controller
{
    private const RESOURCE = 'test_orders';

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'test_orders.read', self::RESOURCE);

        $orders = $this->filteredQuery($request)
            ->with(['standards', 'samples'])
            ->orderBy('id')
            ->paginate((int) $request->integer('per_page', 15));

        return response()->json([
            'data' => $orders->getCollection()
                ->map(fn (TestOrder $order): array => $this->serializeOrder($order))
                ->values(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    public function show(Request $request, TestOrder $testOrder): JsonResponse
    {
        $this->authorizePermission($request, 'test_orders.read', self::RESOURCE, $testOrder);

        return response()->json(['data' => $this->serializeOrder($testOrder->load(['standards', 'samples']))]);
    }

    public function store(
        Request $request,
        OrderNumberService $orderNumberService,
        TestOrderPayloadNormalizer $normalizer,
        SyncTestOrderChildren $syncChildren,
        AuditLogger $auditLogger,
    ): JsonResponse {
        $this->authorizePermission($request, 'test_orders.create', self::RESOURCE);

        $payload = $normalizer->normalize($request->validate($this->rules()));
        $this->authorizeChildPayload($request, $payload, creating: true);
        $payload = $this->applyCustomerSnapshots($payload);
        $standards = $payload['standards'] ?? [];
        $samples = $payload['samples'] ?? [];
        unset($payload['standards'], $payload['samples']);

        $testOrder = DB::transaction(function () use ($request, $payload, $standards, $samples, $orderNumberService, $syncChildren): TestOrder {
            $orderNo = $orderNumberService->generate();
            $testOrder = TestOrder::query()->create([
                ...$payload,
                'order_no' => $orderNo,
                'contract_no' => $payload['contract_no'] ?? $orderNo,
                'sample_status' => $payload['sample_status'] ?? 'not_received',
                'created_by' => $request->user()?->id,
                'updated_by' => $request->user()?->id,
            ]);

            $syncChildren->sync($testOrder, $standards, $samples);

            return $testOrder->fresh(['standards', 'samples']);
        });

        $auditLogger->record(
            actor: $request->user(),
            action: 'test_orders.create',
            module: self::RESOURCE,
            subject: $testOrder,
            after: $this->auditValues($testOrder),
        );

        return response()->json(['data' => $this->serializeOrder($testOrder)], 201);
    }

    public function update(
        Request $request,
        TestOrder $testOrder,
        TestOrderPayloadNormalizer $normalizer,
        SyncTestOrderChildren $syncChildren,
        AuditLogger $auditLogger,
    ): JsonResponse {
        $this->authorizePermission($request, 'test_orders.update', self::RESOURCE, $testOrder);

        $payload = $normalizer->normalize($request->validate($this->rules(updating: true)));
        $this->authorizeChildPayload($request, $payload, creating: false);
        $payload = $this->applyCustomerSnapshots($payload);
        $hasStandards = array_key_exists('standards', $payload);
        $hasSamples = array_key_exists('samples', $payload);
        $standards = $payload['standards'] ?? [];
        $samples = $payload['samples'] ?? [];
        unset($payload['standards'], $payload['samples']);

        $before = $this->auditValues($testOrder->load(['standards', 'samples']));

        $testOrder = DB::transaction(function () use ($request, $testOrder, $payload, $hasStandards, $hasSamples, $standards, $samples, $syncChildren): TestOrder {
            if ($payload !== []) {
                $testOrder->update([
                    ...$payload,
                    'updated_by' => $request->user()?->id,
                ]);
            }

            if ($hasStandards || $hasSamples) {
                $syncChildren->sync(
                    $testOrder,
                    $hasStandards ? $standards : $testOrder->standards()->get()->toArray(),
                    $hasSamples ? $samples : $testOrder->samples()->get()->toArray(),
                );
            }

            return $testOrder->fresh(['standards', 'samples']);
        });

        $auditLogger->record(
            actor: $request->user(),
            action: 'test_orders.update',
            module: self::RESOURCE,
            subject: $testOrder,
            before: $before,
            after: $this->auditValues($testOrder),
        );

        return response()->json(['data' => $this->serializeOrder($testOrder)]);
    }

    public function destroy(Request $request, TestOrder $testOrder, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'test_orders.delete', self::RESOURCE, $testOrder);

        $before = $this->auditValues($testOrder->load(['standards', 'samples']));
        $testOrder->delete();

        $auditLogger->record(
            actor: $request->user(),
            action: 'test_orders.delete',
            module: self::RESOURCE,
            before: $before,
        );

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function export(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'test_orders.export', self::RESOURCE);

        $fields = ['order_no', 'contract_no', 'order_date', 'client_company', 'urgency', 'sample_status'];
        $rows = $this->filteredQuery($request)
            ->orderBy('id')
            ->get()
            ->map(fn (TestOrder $order): array => collect($fields)->mapWithKeys(
                fn (string $field): array => [$field => $this->serializeOrder($order)[$field]]
            )->all())
            ->values();

        $auditLogger->record(
            actor: $request->user(),
            action: 'test_orders.export',
            module: self::RESOURCE,
            after: [
                'filters' => $request->query(),
                'columns' => $fields,
            ],
        );

        return response()->json(['headers' => $fields, 'data' => $rows]);
    }

    public function sampleOptions(Request $request, TestOrder $testOrder): JsonResponse
    {
        $this->authorizePermission($request, 'samples.receive', 'samples', $testOrder);

        return response()->json([
            'data' => [
                'order' => [
                    'id' => $testOrder->id,
                    'order_no' => $testOrder->order_no,
                    'client_company' => $testOrder->client_company,
                ],
                'samples' => $testOrder->samples()
                    ->get()
                    ->map(fn ($sample): array => [
                        'id' => $sample->id,
                        'sample_name' => $sample->sample_name,
                        'specification' => $sample->specification,
                        'model' => $sample->model,
                        'quantity' => $sample->quantity,
                    ])
                    ->values(),
            ],
        ]);
    }

    public function formOptions(Request $request): JsonResponse
    {
        $this->authorizeFormOptions($request);

        return response()->json([
            'data' => [
                'customers' => Customer::query()
                    ->with('contacts')
                    ->where('status', 'active')
                    ->orderBy('id')
                    ->limit((int) $request->integer('limit', 100))
                    ->get()
                    ->map(fn (Customer $customer): array => $this->serializeFormCustomer($customer))
                    ->values(),
                'standards' => Standard::query()
                    ->where('status', 'active')
                    ->orderBy('id')
                    ->limit((int) $request->integer('limit', 100))
                    ->get()
                    ->map(fn (Standard $standard): array => [
                        'id' => $standard->id,
                        'std_no' => $standard->std_no,
                        'chinese_name' => $standard->chinese_name,
                        'status' => $standard->status,
                    ])
                    ->values(),
            ],
        ]);
    }

    private function filteredQuery(Request $request): Builder
    {
        return TestOrder::query()
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $search = $request->string('search')->toString();

                $query->where(fn (Builder $builder): Builder => $builder
                    ->where('order_no', 'like', "%{$search}%")
                    ->orWhere('contract_no', 'like', "%{$search}%")
                    ->orWhere('client_company', 'like', "%{$search}%"));
            })
            ->when($request->filled('sample_status'), fn (Builder $query): Builder => $query->where('sample_status', $request->string('sample_status')->toString()))
            ->when($request->filled('client_customer_id'), fn (Builder $query): Builder => $query->where('client_customer_id', $request->integer('client_customer_id')))
            ->when($request->filled('client_company'), fn (Builder $query): Builder => $query->where('client_company', 'like', '%'.$request->string('client_company')->toString().'%'))
            ->when($request->filled('order_date_from'), fn (Builder $query): Builder => $query->whereDate('order_date', '>=', $request->date('order_date_from')))
            ->when($request->filled('order_date_to'), fn (Builder $query): Builder => $query->whereDate('order_date', '<=', $request->date('order_date_to')));
    }

    private function authorizeFormOptions(Request $request): void
    {
        $access = app(PermissionAccess::class);

        foreach (['test_orders.create', 'test_orders.update'] as $permission) {
            if ($access->userCan($request->user(), $permission)) {
                return;
            }
        }

        $this->authorizePermission($request, 'test_orders.create', self::RESOURCE);
    }

    private function authorizeChildPayload(Request $request, array $payload, bool $creating): void
    {
        if (array_key_exists('standards', $payload)) {
            $this->authorizePermission($request, 'test_order_standards.read', 'test_order_standards');
            $this->authorizePermission($request, $creating ? 'test_order_standards.create' : 'test_order_standards.update', 'test_order_standards');
        }

        if (array_key_exists('samples', $payload)) {
            $this->authorizePermission($request, 'test_order_samples.read', 'test_order_samples');
            $this->authorizePermission($request, $creating ? 'test_order_samples.create' : 'test_order_samples.update', 'test_order_samples');
        }
    }

    private function applyCustomerSnapshots(array $payload): array
    {
        foreach (['client', 'manufacturer', 'maker'] as $prefix) {
            $idKey = "{$prefix}_customer_id";

            if (! isset($payload[$idKey])) {
                continue;
            }

            $customer = Customer::query()->find($payload[$idKey]);

            if ($customer === null) {
                continue;
            }

            $payload["{$prefix}_company"] ??= $customer->name;
            $payload["{$prefix}_address"] ??= $customer->address;
            $payload["{$prefix}_phone"] ??= $customer->phone;
        }

        return $payload;
    }

    private function serializeFormCustomer(Customer $customer): array
    {
        $contacts = $customer->contacts
            ->filter(fn ($contact): bool => $contact->status === 'active')
            ->map(fn ($contact): array => [
                'id' => $contact->id,
                'customer_id' => $contact->customer_id,
                'name' => $contact->name,
                'phone' => $contact->phone,
                'email' => $contact->email,
                'is_default' => $contact->is_default,
                'status' => $contact->status,
            ])
            ->values();
        $defaultContact = $contacts->firstWhere('is_default', true);

        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'credit_code' => $customer->credit_code,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'address' => $customer->address,
            'status' => $customer->status,
            'default_contact' => $defaultContact,
            'contacts' => $contacts,
        ];
    }

    private function rules(bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return [
            'contract_no' => ['nullable', 'string', 'max:255'],
            'order_date' => [$required, 'date'],
            'planned_end_date' => ['nullable', 'date'],
            'urgency' => ['nullable', 'in:normal,urgent,critical'],
            'client_customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'client_company' => [$required, 'string', 'max:255'],
            'client_address' => ['nullable', 'string', 'max:255'],
            'client_contact' => ['nullable', 'string', 'max:255'],
            'client_phone' => ['nullable', 'string', 'max:64'],
            'manufacturer_customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'manufacturer_company' => ['nullable', 'string', 'max:255'],
            'manufacturer_address' => ['nullable', 'string', 'max:255'],
            'manufacturer_contact' => ['nullable', 'string', 'max:255'],
            'manufacturer_phone' => ['nullable', 'string', 'max:64'],
            'maker_customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'maker_company' => ['nullable', 'string', 'max:255'],
            'maker_address' => ['nullable', 'string', 'max:255'],
            'maker_contact' => ['nullable', 'string', 'max:255'],
            'maker_phone' => ['nullable', 'string', 'max:64'],
            'report_forms' => ['nullable', 'array'],
            'report_forms.*' => ['string', 'max:64'],
            'delivery_method' => ['nullable', 'string', 'max:255'],
            'outsourcing_option' => ['nullable', 'string', 'max:255'],
            'remark' => ['nullable', 'string'],
            'sample_status' => ['nullable', 'in:not_received,partially_received,received,testing,completed'],
            'address_lab_name' => ['nullable', 'string', 'max:255'],
            'address_contact' => ['nullable', 'string', 'max:255'],
            'address_detail' => ['nullable', 'string', 'max:255'],
            'address_phone' => ['nullable', 'string', 'max:64'],
            'client_signature' => ['nullable', 'string', 'max:255'],
            'client_sign_date' => ['nullable', 'date'],
            'dept_confirm' => ['nullable', 'string', 'max:255'],
            'dept_confirm_date' => ['nullable', 'date'],
            'lab_confirm' => ['nullable', 'string', 'max:255'],
            'lab_confirm_date' => ['nullable', 'date'],
            'standards' => ['sometimes', 'array'],
            'standards.*.id' => ['nullable', 'integer'],
            'standards.*.standard_id' => ['nullable', 'integer', 'exists:standards,id'],
            'standards.*.standard_code' => ['required_with:standards', 'string', 'max:255'],
            'standards.*.standard_name' => ['required_with:standards', 'string', 'max:255'],
            'standards.*.report_language' => ['nullable', 'string', 'max:64'],
            'standards.*.qualifications' => ['nullable', 'array'],
            'standards.*.qualifications.*' => ['string', 'max:64'],
            'standards.*.requirement' => ['nullable', 'string'],
            'samples' => ['sometimes', 'array'],
            'samples.*.id' => ['nullable', 'integer'],
            'samples.*.sample_name' => ['required_with:samples', 'string', 'max:255'],
            'samples.*.specification' => ['nullable', 'string', 'max:255'],
            'samples.*.model' => ['nullable', 'string', 'max:255'],
            'samples.*.status' => ['nullable', 'in:pending,partially_received,received,rejected,cancelled'],
            'samples.*.quantity' => ['required_with:samples', 'integer', 'min:1'],
            'samples.*.detail_content' => ['nullable', 'string'],
            'samples.*.remark' => ['nullable', 'string'],
        ];
    }

    private function serializeOrder(TestOrder $order): array
    {
        return [
            'id' => $order->id,
            'order_no' => $order->order_no,
            'contract_no' => $order->contract_no,
            'order_date' => $order->order_date?->toDateString(),
            'planned_end_date' => $order->planned_end_date?->toDateString(),
            'urgency' => $order->urgency,
            'client_customer_id' => $order->client_customer_id,
            'client_company' => $order->client_company,
            'client_address' => $order->client_address,
            'client_contact' => $order->client_contact,
            'client_phone' => $order->client_phone,
            'manufacturer_customer_id' => $order->manufacturer_customer_id,
            'manufacturer_company' => $order->manufacturer_company,
            'manufacturer_address' => $order->manufacturer_address,
            'manufacturer_contact' => $order->manufacturer_contact,
            'manufacturer_phone' => $order->manufacturer_phone,
            'maker_customer_id' => $order->maker_customer_id,
            'maker_company' => $order->maker_company,
            'maker_address' => $order->maker_address,
            'maker_contact' => $order->maker_contact,
            'maker_phone' => $order->maker_phone,
            'report_forms' => $order->report_forms ?? [],
            'delivery_method' => $order->delivery_method,
            'outsourcing_option' => $order->outsourcing_option,
            'remark' => $order->remark,
            'sample_status' => $order->sample_status,
            'address_lab_name' => $order->address_lab_name,
            'address_contact' => $order->address_contact,
            'address_detail' => $order->address_detail,
            'address_phone' => $order->address_phone,
            'client_signature' => $order->client_signature,
            'client_sign_date' => $order->client_sign_date?->toDateString(),
            'dept_confirm' => $order->dept_confirm,
            'dept_confirm_date' => $order->dept_confirm_date?->toDateString(),
            'lab_confirm' => $order->lab_confirm,
            'lab_confirm_date' => $order->lab_confirm_date?->toDateString(),
            'standards' => $order->standards->map(fn ($standard): array => [
                'id' => $standard->id,
                'standard_id' => $standard->standard_id,
                'standard_code' => $standard->standard_code,
                'standard_name' => $standard->standard_name,
                'report_language' => $standard->report_language,
                'qualifications' => $standard->qualifications ?? [],
                'requirement' => $standard->requirement,
                'sort_order' => $standard->sort_order,
            ])->values(),
            'samples' => $order->samples->map(fn ($sample): array => [
                'id' => $sample->id,
                'sample_name' => $sample->sample_name,
                'specification' => $sample->specification,
                'model' => $sample->model,
                'status' => $sample->status,
                'quantity' => $sample->quantity,
                'detail_content' => $sample->detail_content,
                'remark' => $sample->remark,
                'sort_order' => $sample->sort_order,
            ])->values(),
        ];
    }

    private function auditValues(TestOrder $order): array
    {
        return $this->serializeOrder($order->loadMissing(['standards', 'samples']));
    }
}
