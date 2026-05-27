<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\Audit\AuditLogger;
use App\Services\Authorization\FieldPermissionFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CustomerController extends Controller
{
    private const RESOURCE = 'customers';

    private const BASE_FIELDS = ['id', 'name', 'type', 'level', 'source', 'industry', 'address', 'remark', 'status'];

    public function index(Request $request, FieldPermissionFilter $fieldPermissionFilter): JsonResponse
    {
        $this->authorizePermission($request, 'customers.read', self::RESOURCE);

        $query = Customer::query()->orderBy('id');

        if ($search = $request->string('search')->toString()) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        $customers = $query->paginate((int) $request->integer('per_page', 15));

        return response()->json([
            'data' => $customers->getCollection()
                ->map(fn (Customer $customer): array => $this->serializeCustomer($customer, $request, $fieldPermissionFilter))
                ->values(),
            'meta' => [
                'current_page' => $customers->currentPage(),
                'per_page' => $customers->perPage(),
                'total' => $customers->total(),
                'fields' => $fieldPermissionFilter->meta($request->user(), self::RESOURCE),
            ],
        ]);
    }

    public function show(Request $request, Customer $customer, FieldPermissionFilter $fieldPermissionFilter): JsonResponse
    {
        $this->authorizePermission($request, 'customers.read', self::RESOURCE, $customer);

        return response()->json([
            'data' => $this->serializeCustomer($customer, $request, $fieldPermissionFilter),
            'meta' => ['fields' => $fieldPermissionFilter->meta($request->user(), self::RESOURCE)],
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger, FieldPermissionFilter $fieldPermissionFilter): JsonResponse
    {
        $this->authorizePermission($request, 'customers.create', self::RESOURCE);
        $this->rejectForbiddenSensitiveFields($request, $fieldPermissionFilter);

        $customer = Customer::query()->create($request->validate($this->rules()));

        $auditLogger->record(
            actor: $request->user(),
            action: 'customers.create',
            module: self::RESOURCE,
            subject: $customer,
            after: $this->auditValues($customer),
        );

        return response()->json([
            'data' => $this->serializeCustomer($customer, $request, $fieldPermissionFilter),
        ], 201);
    }

    public function update(Request $request, Customer $customer, AuditLogger $auditLogger, FieldPermissionFilter $fieldPermissionFilter): JsonResponse
    {
        $this->authorizePermission($request, 'customers.update', self::RESOURCE, $customer);
        $this->rejectForbiddenSensitiveFields($request, $fieldPermissionFilter, $customer);

        $before = $this->auditValues($customer);
        $customer->update($request->validate($this->rules(requireName: false)));
        $customer = $customer->fresh();

        $auditLogger->record(
            actor: $request->user(),
            action: 'customers.update',
            module: self::RESOURCE,
            subject: $customer,
            before: $before,
            after: $this->auditValues($customer),
        );

        return response()->json([
            'data' => $this->serializeCustomer($customer, $request, $fieldPermissionFilter),
        ]);
    }

    public function destroy(Request $request, Customer $customer, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'customers.delete', self::RESOURCE, $customer);

        $before = $this->auditValues($customer);
        $customer->update(['status' => 'disabled']);

        $auditLogger->record(
            actor: $request->user(),
            action: 'customers.delete',
            module: self::RESOURCE,
            subject: $customer,
            before: $before,
            after: $this->auditValues($customer->fresh()),
        );

        return response()->json(['data' => $customer->fresh()]);
    }

    public function export(Request $request, AuditLogger $auditLogger, FieldPermissionFilter $fieldPermissionFilter): JsonResponse
    {
        $this->authorizePermission($request, 'customers.export', self::RESOURCE);

        $fields = $fieldPermissionFilter->exportableFields($request->user(), self::RESOURCE, ['name', 'type', 'level', 'source', 'industry', 'address', 'remark', 'status']);
        $rows = Customer::query()
            ->orderBy('id')
            ->get()
            ->map(fn (Customer $customer): array => collect($fields)->mapWithKeys(
                fn (string $field): array => [$field => $customer->{$field}]
            )->all())
            ->values();

        $auditLogger->record(
            actor: $request->user(),
            action: 'customers.export',
            module: self::RESOURCE,
            after: [
                'filters' => $request->query(),
                'columns' => $fields,
            ],
        );

        return response()->json([
            'headers' => $fields,
            'data' => $rows,
        ]);
    }

    private function rejectForbiddenSensitiveFields(Request $request, FieldPermissionFilter $fieldPermissionFilter, ?Customer $customer = null): void
    {
        $forbiddenFields = $fieldPermissionFilter->forbiddenUpdateFields($request->user(), self::RESOURCE, $request->all());

        if ($forbiddenFields === []) {
            return;
        }

        app(AuditLogger::class)->record(
            actor: $request->user(),
            action: 'authorization.denied',
            module: self::RESOURCE,
            subject: $customer,
            after: ['denied_fields' => $forbiddenFields],
        );

        throw ValidationException::withMessages(collect($forbiddenFields)->mapWithKeys(
            fn (string $field): array => [$field => ['field_update_forbidden']]
        )->all());
    }

    private function rules(bool $requireName = true): array
    {
        return [
            'name' => [$requireName ? 'required' : 'sometimes', 'string', 'max:255'],
            'credit_code' => ['nullable', 'string', 'max:64'],
            'type' => ['nullable', 'string', 'max:64'],
            'level' => ['nullable', 'string', 'max:64'],
            'source' => ['nullable', 'string', 'max:64'],
            'industry' => ['nullable', 'string', 'max:64'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'remark' => ['nullable', 'string'],
            'status' => ['nullable', 'in:active,disabled'],
        ];
    }

    private function serializeCustomer(Customer $customer, Request $request, FieldPermissionFilter $fieldPermissionFilter): array
    {
        return $fieldPermissionFilter->filterRecord($request->user(), self::RESOURCE, $this->auditValues($customer));
    }

    private function auditValues(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'credit_code' => $customer->credit_code,
            'type' => $customer->type,
            'level' => $customer->level,
            'source' => $customer->source,
            'industry' => $customer->industry,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'address' => $customer->address,
            'remark' => $customer->remark,
            'status' => $customer->status,
        ];
    }
}
