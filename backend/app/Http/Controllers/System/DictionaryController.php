<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Models\DictionaryItem;
use App\Models\DictionarySet;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DictionaryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'system.dictionaries.read');

        return response()->json([
            'data' => DictionarySet::query()->with('items')->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'system.dictionaries.create');

        $dictionarySet = DictionarySet::query()->create($request->validate($this->setRules()));

        $auditLogger->record(
            actor: $request->user(),
            action: 'system.dictionaries.create',
            module: 'system.dictionaries',
            subject: $dictionarySet,
            after: $dictionarySet->only(['code', 'name', 'description', 'status']),
        );

        return response()->json(['data' => $dictionarySet->load('items')], 201);
    }

    public function update(Request $request, DictionarySet $dictionarySet, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'system.dictionaries.update');

        $before = $dictionarySet->only(['code', 'name', 'description', 'status']);
        $dictionarySet->update($request->validate($this->setRules(ignoreId: $dictionarySet->id)));

        $auditLogger->record(
            actor: $request->user(),
            action: 'system.dictionaries.update',
            module: 'system.dictionaries',
            subject: $dictionarySet,
            before: $before,
            after: $dictionarySet->fresh()->only(['code', 'name', 'description', 'status']),
        );

        return response()->json(['data' => $dictionarySet->fresh()->load('items')]);
    }

    public function storeItem(Request $request, DictionarySet $dictionarySet, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'system.dictionaries.update');

        $item = $dictionarySet->items()->create($request->validate($this->itemRules($dictionarySet->id)));

        $auditLogger->record(
            actor: $request->user(),
            action: 'system.dictionary_items.create',
            module: 'system.dictionaries',
            subject: $item,
            after: $item->only(['label', 'value', 'color', 'sort_order', 'is_default', 'status']),
        );

        return response()->json(['data' => $item], 201);
    }

    public function updateItem(Request $request, DictionarySet $dictionarySet, DictionaryItem $dictionaryItem, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'system.dictionaries.update');
        abort_unless($dictionaryItem->dictionary_set_id === $dictionarySet->id, 404);

        $before = $dictionaryItem->only(['label', 'value', 'color', 'sort_order', 'is_default', 'status']);
        $dictionaryItem->update($request->validate($this->itemRules($dictionarySet->id, $dictionaryItem->id)));

        $auditLogger->record(
            actor: $request->user(),
            action: 'system.dictionary_items.update',
            module: 'system.dictionaries',
            subject: $dictionaryItem,
            before: $before,
            after: $dictionaryItem->fresh()->only(['label', 'value', 'color', 'sort_order', 'is_default', 'status']),
        );

        return response()->json(['data' => $dictionaryItem->fresh()]);
    }

    private function setRules(?int $ignoreId = null): array
    {
        return [
            'code' => ['required', 'string', 'max:255', 'unique:dictionary_sets,code'.($ignoreId ? ",{$ignoreId}" : '')],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['required', 'in:active,disabled'],
        ];
    }

    private function itemRules(int $setId, ?int $ignoreId = null): array
    {
        $valueRule = Rule::unique('dictionary_items', 'value')
            ->where(fn ($query) => $query->where('dictionary_set_id', $setId));

        if ($ignoreId !== null) {
            $valueRule->ignore($ignoreId);
        }

        return [
            'label' => ['required', 'string', 'max:255'],
            'value' => ['required', 'string', 'max:255', $valueRule],
            'color' => ['nullable', 'string', 'max:32'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_default' => ['boolean'],
            'status' => ['required', 'in:active,disabled'],
        ];
    }
}
