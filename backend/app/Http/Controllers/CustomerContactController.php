<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerContact;
use App\Services\Audit\AuditLogger;
use App\Services\Authorization\FieldPermissionFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerContactController extends Controller
{
    private const RESOURCE = 'customer_contacts';

    public function index(Request $request, Customer $customer, FieldPermissionFilter $fieldPermissionFilter): JsonResponse
    {
        $this->authorizePermission($request, 'customer_contacts.read', self::RESOURCE);

        return response()->json([
            'data' => $customer->contacts
                ->map(fn (CustomerContact $contact): array => $this->serializeContact($contact, $request, $fieldPermissionFilter))
                ->values(),
            'meta' => ['fields' => $fieldPermissionFilter->meta($request->user(), self::RESOURCE)],
        ]);
    }

    public function store(Request $request, Customer $customer, AuditLogger $auditLogger, FieldPermissionFilter $fieldPermissionFilter): JsonResponse
    {
        $this->authorizePermission($request, 'customer_contacts.create', self::RESOURCE, $customer);
        $this->rejectForbiddenSensitiveFields($request, $fieldPermissionFilter);

        $contact = DB::transaction(function () use ($request, $customer): CustomerContact {
            $data = $request->validate($this->rules());

            if ($data['is_default'] ?? false) {
                $customer->contacts()->update(['is_default' => false]);
            }

            return $customer->contacts()->create($data);
        });

        $auditLogger->record(
            actor: $request->user(),
            action: 'customer_contacts.create',
            module: self::RESOURCE,
            subject: $contact,
            after: $this->auditValues($contact),
        );

        return response()->json(['data' => $this->serializeContact($contact, $request, $fieldPermissionFilter)], 201);
    }

    public function update(Request $request, Customer $customer, CustomerContact $customerContact, AuditLogger $auditLogger, FieldPermissionFilter $fieldPermissionFilter): JsonResponse
    {
        $this->authorizePermission($request, 'customer_contacts.update', self::RESOURCE, $customerContact);
        abort_unless($customerContact->customer_id === $customer->id, 404);
        $this->rejectForbiddenSensitiveFields($request, $fieldPermissionFilter, $customerContact);

        $before = $this->auditValues($customerContact);
        $customerContact = DB::transaction(function () use ($request, $customer, $customerContact): CustomerContact {
            $data = $request->validate($this->rules(requireName: false));

            if ($data['is_default'] ?? false) {
                $customer->contacts()->whereKeyNot($customerContact->id)->update(['is_default' => false]);
            }

            $customerContact->update($data);

            return $customerContact->fresh();
        });

        $auditLogger->record(
            actor: $request->user(),
            action: 'customer_contacts.update',
            module: self::RESOURCE,
            subject: $customerContact,
            before: $before,
            after: $this->auditValues($customerContact),
        );

        return response()->json(['data' => $this->serializeContact($customerContact, $request, $fieldPermissionFilter)]);
    }

    public function destroy(Request $request, Customer $customer, CustomerContact $customerContact, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'customer_contacts.delete', self::RESOURCE, $customerContact);
        abort_unless($customerContact->customer_id === $customer->id, 404);

        if ($customerContact->is_default) {
            return response()->json([
                'message' => 'default_contact_delete_rejected',
                'errors' => ['is_default' => ['default_contact_delete_rejected']],
            ], 422);
        }

        $before = $this->auditValues($customerContact);
        $customerContact->delete();

        $auditLogger->record(
            actor: $request->user(),
            action: 'customer_contacts.delete',
            module: self::RESOURCE,
            before: $before,
        );

        return response()->json(['data' => ['deleted' => true]]);
    }

    private function rejectForbiddenSensitiveFields(Request $request, FieldPermissionFilter $fieldPermissionFilter, ?CustomerContact $contact = null): void
    {
        $forbiddenFields = $fieldPermissionFilter->forbiddenUpdateFields($request->user(), self::RESOURCE, $request->all());

        if ($forbiddenFields === []) {
            return;
        }

        app(AuditLogger::class)->record(
            actor: $request->user(),
            action: 'authorization.denied',
            module: self::RESOURCE,
            subject: $contact,
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
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_default' => ['boolean'],
            'status' => ['nullable', 'in:active,disabled'],
        ];
    }

    private function serializeContact(CustomerContact $contact, Request $request, FieldPermissionFilter $fieldPermissionFilter): array
    {
        return $fieldPermissionFilter->filterRecord($request->user(), self::RESOURCE, $this->auditValues($contact));
    }

    private function auditValues(CustomerContact $contact): array
    {
        return [
            'id' => $contact->id,
            'customer_id' => $contact->customer_id,
            'name' => $contact->name,
            'phone' => $contact->phone,
            'email' => $contact->email,
            'is_default' => $contact->is_default,
            'status' => $contact->status,
        ];
    }
}
