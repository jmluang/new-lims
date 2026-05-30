<?php

namespace App\Http\Controllers;

use App\Models\Standard;
use App\Models\StandardCatalog;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StandardCatalogController extends Controller
{
    private const RESOURCE = 'standard_catalogs';

    public function index(Request $request, Standard $standard): JsonResponse
    {
        $this->authorizePermission($request, 'standard_catalogs.read', self::RESOURCE, $standard);

        return response()->json([
            'data' => $standard->catalogs()
                ->with('children')
                ->get()
                ->map(fn (StandardCatalog $catalog): array => $this->serializeCatalog($catalog))
                ->values(),
        ]);
    }

    public function store(Request $request, Standard $standard, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'standard_catalogs.create', self::RESOURCE, $standard);

        $data = $request->validate($this->rules($standard));
        $this->assertParentBelongsToStandard($standard, $data['parent_id'] ?? null);

        $catalog = $standard->catalogs()->create([
            ...$data,
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        $auditLogger->record(
            actor: $request->user(),
            action: 'standard_catalogs.create',
            module: self::RESOURCE,
            subject: $catalog,
            after: $this->auditValues($catalog),
        );

        return response()->json(['data' => $this->serializeCatalog($catalog)], 201);
    }

    public function update(Request $request, Standard $standard, StandardCatalog $standardCatalog, AuditLogger $auditLogger): JsonResponse
    {
        $this->assertCatalogBelongsToStandard($standard, $standardCatalog);
        $this->authorizePermission($request, 'standard_catalogs.update', self::RESOURCE, $standardCatalog);

        $data = $request->validate($this->rules($standard, $standardCatalog->id));
        $this->assertParentBelongsToStandard($standard, $data['parent_id'] ?? null, $standardCatalog);

        $before = $this->auditValues($standardCatalog);
        $standardCatalog->update([
            ...$data,
            'updated_by' => $request->user()?->id,
        ]);

        $auditLogger->record(
            actor: $request->user(),
            action: 'standard_catalogs.update',
            module: self::RESOURCE,
            subject: $standardCatalog,
            before: $before,
            after: $this->auditValues($standardCatalog->fresh()),
        );

        return response()->json(['data' => $this->serializeCatalog($standardCatalog->fresh())]);
    }

    public function destroy(Request $request, Standard $standard, StandardCatalog $standardCatalog, AuditLogger $auditLogger): JsonResponse
    {
        $this->assertCatalogBelongsToStandard($standard, $standardCatalog);
        $this->authorizePermission($request, 'standard_catalogs.delete', self::RESOURCE, $standardCatalog);

        $before = $this->auditValues($standardCatalog);
        $standardCatalog->delete();

        $auditLogger->record(
            actor: $request->user(),
            action: 'standard_catalogs.delete',
            module: self::RESOURCE,
            before: $before,
        );

        return response()->json(['data' => ['deleted' => true]]);
    }

    private function rules(Standard $standard, ?int $catalogId = null): array
    {
        return [
            'parent_id' => ['nullable', 'integer', 'exists:standard_catalogs,id'],
            'code' => [
                'required',
                'string',
                'max:255',
                Rule::unique('standard_catalogs', 'code')
                    ->where('standard_id', $standard->id)
                    ->ignore($catalogId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    private function assertCatalogBelongsToStandard(Standard $standard, StandardCatalog $catalog): void
    {
        if ($catalog->standard_id !== $standard->id) {
            abort(404);
        }
    }

    private function assertParentBelongsToStandard(Standard $standard, ?int $parentId, ?StandardCatalog $catalog = null): void
    {
        if ($parentId === null) {
            return;
        }

        if ($catalog !== null && $catalog->id === $parentId) {
            throw ValidationException::withMessages([
                'parent_id' => ['catalog_parent_self_forbidden'],
            ]);
        }

        $parent = StandardCatalog::query()->find($parentId);

        if ($parent?->standard_id !== $standard->id) {
            throw ValidationException::withMessages([
                'parent_id' => ['catalog_parent_standard_mismatch'],
            ]);
        }
    }

    private function serializeCatalog(StandardCatalog $catalog): array
    {
        return [
            'id' => $catalog->id,
            'standard_id' => $catalog->standard_id,
            'parent_id' => $catalog->parent_id,
            'code' => $catalog->code,
            'name' => $catalog->name,
            'content' => $catalog->content,
            'sort_order' => $catalog->sort_order,
            'created_by' => $catalog->created_by,
            'updated_by' => $catalog->updated_by,
        ];
    }

    private function auditValues(StandardCatalog $catalog): array
    {
        return $this->serializeCatalog($catalog);
    }
}
