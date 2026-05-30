<?php

namespace App\Http\Controllers;

use App\Models\Standard;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StandardController extends Controller
{
    private const RESOURCE = 'standards';

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'standards.read', self::RESOURCE);

        $standards = $this->filteredQuery($request)
            ->orderBy('id')
            ->paginate((int) $request->integer('per_page', 15));

        return response()->json([
            'data' => $standards->getCollection()
                ->map(fn (Standard $standard): array => $this->serializeStandard($standard))
                ->values(),
            'meta' => [
                'current_page' => $standards->currentPage(),
                'per_page' => $standards->perPage(),
                'total' => $standards->total(),
            ],
        ]);
    }

    public function show(Request $request, Standard $standard): JsonResponse
    {
        $this->authorizePermission($request, 'standards.read', self::RESOURCE, $standard);

        return response()->json([
            'data' => [
                ...$this->serializeStandard($standard),
                'catalogs' => $standard->catalogs->map(fn ($catalog): array => $catalog->only([
                    'id',
                    'standard_id',
                    'parent_id',
                    'code',
                    'name',
                    'content',
                    'sort_order',
                    'created_by',
                    'updated_by',
                ]))->values(),
                'items' => $standard->items->map(fn ($item): array => $item->only([
                    'id',
                    'standard_id',
                    'item_no',
                    'item_name',
                    'requirement',
                    'unit',
                    'method',
                    'remark',
                    'operator_id',
                ]))->values(),
            ],
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'standards.create', self::RESOURCE);

        $standard = Standard::query()->create([
            ...$request->validate($this->rules()),
            'operator_id' => $request->user()?->id,
        ]);

        $auditLogger->record(
            actor: $request->user(),
            action: 'standards.create',
            module: self::RESOURCE,
            subject: $standard,
            after: $this->auditValues($standard),
        );

        return response()->json(['data' => $this->serializeStandard($standard)], 201);
    }

    public function update(Request $request, Standard $standard, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'standards.update', self::RESOURCE, $standard);

        $before = $this->auditValues($standard);
        $standard->update([
            ...$request->validate($this->rules($standard->id, requireStdNo: false, requireChineseName: false)),
            'operator_id' => $request->user()?->id,
        ]);

        $auditLogger->record(
            actor: $request->user(),
            action: 'standards.update',
            module: self::RESOURCE,
            subject: $standard,
            before: $before,
            after: $this->auditValues($standard->fresh()),
        );

        return response()->json(['data' => $this->serializeStandard($standard->fresh())]);
    }

    public function destroy(Request $request, Standard $standard, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'standards.delete', self::RESOURCE, $standard);

        $before = $this->auditValues($standard);
        $standard->update(['status' => 'disabled', 'operator_id' => $request->user()?->id]);

        $auditLogger->record(
            actor: $request->user(),
            action: 'standards.delete',
            module: self::RESOURCE,
            subject: $standard,
            before: $before,
            after: $this->auditValues($standard->fresh()),
        );

        return response()->json(['data' => $this->serializeStandard($standard->fresh())]);
    }

    public function export(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'standards.export', self::RESOURCE);

        $fields = ['std_no', 'chinese_name', 'publish_date', 'implement_date', 'status', 'category', 'language'];
        $rows = $this->filteredQuery($request)
            ->orderBy('id')
            ->get()
            ->map(fn (Standard $standard): array => collect($fields)->mapWithKeys(
                fn (string $field): array => [$field => $this->serializeStandard($standard)[$field]]
            )->all())
            ->values();

        $auditLogger->record(
            actor: $request->user(),
            action: 'standards.export',
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

    private function filteredQuery(Request $request): Builder
    {
        return Standard::query()
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $search = $request->string('search')->toString();

                $query->where(fn (Builder $builder): Builder => $builder
                    ->where('std_no', 'like', "%{$search}%")
                    ->orWhere('chinese_name', 'like', "%{$search}%")
                    ->orWhere('corresponding_std', 'like', "%{$search}%"));
            })
            ->when($request->filled('status'), fn (Builder $query): Builder => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('category'), fn (Builder $query): Builder => $query->where('category', $request->string('category')->toString()))
            ->when($request->filled('language'), fn (Builder $query): Builder => $query->where('language', $request->string('language')->toString()));
    }

    private function rules(?int $standardId = null, bool $requireStdNo = true, bool $requireChineseName = true): array
    {
        return [
            'std_no' => [$requireStdNo ? 'required' : 'sometimes', 'string', 'max:255', 'unique:standards,std_no'.($standardId ? ",{$standardId}" : '')],
            'chinese_name' => [$requireChineseName ? 'required' : 'sometimes', 'string', 'max:255'],
            'publish_date' => ['nullable', 'date'],
            'implement_date' => ['nullable', 'date'],
            'status' => ['nullable', 'in:active,pending,abolished,replaced,disabled'],
            'abolish_date' => ['nullable', 'date'],
            'replaced_by' => ['nullable', 'string', 'max:255'],
            'corresponding_std' => ['nullable', 'string', 'max:500'],
            'category' => ['nullable', 'string', 'max:255'],
            'language' => ['nullable', 'string', 'max:32'],
        ];
    }

    private function serializeStandard(Standard $standard): array
    {
        return [
            'id' => $standard->id,
            'std_no' => $standard->std_no,
            'chinese_name' => $standard->chinese_name,
            'publish_date' => $standard->publish_date?->toDateString(),
            'implement_date' => $standard->implement_date?->toDateString(),
            'status' => $standard->status,
            'abolish_date' => $standard->abolish_date?->toDateString(),
            'replaced_by' => $standard->replaced_by,
            'corresponding_std' => $standard->corresponding_std,
            'category' => $standard->category,
            'language' => $standard->language,
            'operator_id' => $standard->operator_id,
        ];
    }

    private function auditValues(Standard $standard): array
    {
        return $this->serializeStandard($standard);
    }
}
