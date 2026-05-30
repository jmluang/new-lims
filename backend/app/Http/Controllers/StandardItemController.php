<?php

namespace App\Http\Controllers;

use App\Models\Standard;
use App\Models\StandardItem;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StandardItemController extends Controller
{
    private const RESOURCE = 'standard_items';

    public function index(Request $request, Standard $standard): JsonResponse
    {
        $this->authorizePermission($request, 'standard_items.read', self::RESOURCE, $standard);

        return response()->json([
            'data' => $standard->items()
                ->get()
                ->map(fn (StandardItem $item): array => $this->serializeItem($item))
                ->values(),
        ]);
    }

    public function store(Request $request, Standard $standard, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'standard_items.create', self::RESOURCE, $standard);

        $item = $standard->items()->create([
            ...$request->validate($this->rules($standard)),
            'operator_id' => $request->user()?->id,
        ]);

        $auditLogger->record(
            actor: $request->user(),
            action: 'standard_items.create',
            module: self::RESOURCE,
            subject: $item,
            after: $this->auditValues($item),
        );

        return response()->json(['data' => $this->serializeItem($item)], 201);
    }

    public function update(Request $request, Standard $standard, StandardItem $standardItem, AuditLogger $auditLogger): JsonResponse
    {
        $this->assertItemBelongsToStandard($standard, $standardItem);
        $this->authorizePermission($request, 'standard_items.update', self::RESOURCE, $standardItem);

        $before = $this->auditValues($standardItem);
        $standardItem->update([
            ...$request->validate($this->rules($standard, $standardItem->id)),
            'operator_id' => $request->user()?->id,
        ]);

        $auditLogger->record(
            actor: $request->user(),
            action: 'standard_items.update',
            module: self::RESOURCE,
            subject: $standardItem,
            before: $before,
            after: $this->auditValues($standardItem->fresh()),
        );

        return response()->json(['data' => $this->serializeItem($standardItem->fresh())]);
    }

    public function destroy(Request $request, Standard $standard, StandardItem $standardItem, AuditLogger $auditLogger): JsonResponse
    {
        $this->assertItemBelongsToStandard($standard, $standardItem);
        $this->authorizePermission($request, 'standard_items.delete', self::RESOURCE, $standardItem);

        $before = $this->auditValues($standardItem);
        $standardItem->delete();

        $auditLogger->record(
            actor: $request->user(),
            action: 'standard_items.delete',
            module: self::RESOURCE,
            before: $before,
        );

        return response()->json(['data' => ['deleted' => true]]);
    }

    private function rules(Standard $standard, ?int $itemId = null): array
    {
        return [
            'item_no' => [
                'required',
                'string',
                'max:255',
                Rule::unique('standard_items', 'item_no')
                    ->where('standard_id', $standard->id)
                    ->ignore($itemId),
            ],
            'item_name' => ['required', 'string', 'max:255'],
            'requirement' => ['nullable', 'string'],
            'unit' => ['nullable', 'string', 'max:64'],
            'method' => ['nullable', 'string', 'max:255'],
            'remark' => ['nullable', 'string'],
        ];
    }

    private function assertItemBelongsToStandard(Standard $standard, StandardItem $item): void
    {
        if ($item->standard_id !== $standard->id) {
            abort(404);
        }
    }

    private function serializeItem(StandardItem $item): array
    {
        return [
            'id' => $item->id,
            'standard_id' => $item->standard_id,
            'item_no' => $item->item_no,
            'item_name' => $item->item_name,
            'requirement' => $item->requirement,
            'unit' => $item->unit,
            'method' => $item->method,
            'remark' => $item->remark,
            'operator_id' => $item->operator_id,
        ];
    }

    private function auditValues(StandardItem $item): array
    {
        return $this->serializeItem($item);
    }
}
