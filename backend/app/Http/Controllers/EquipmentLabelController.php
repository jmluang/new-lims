<?php

namespace App\Http\Controllers;

use App\Models\Equipment;
use App\Models\EquipmentLabelPrintJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EquipmentLabelController extends Controller
{
    public function preview(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'equipment_labels.print', 'equipment_labels');

        $data = $request->validate([
            'equipment_ids' => ['required', 'array', 'min:1'],
            'equipment_ids.*' => ['integer', 'exists:equipment,id'],
            'label_width_mm' => ['required', 'integer', 'in:40'],
            'label_height_mm' => ['required', 'integer', 'in:60'],
        ]);

        EquipmentLabelPrintJob::query()->create([
            'equipment_ids' => $data['equipment_ids'],
            'label_width_mm' => $data['label_width_mm'],
            'label_height_mm' => $data['label_height_mm'],
            'created_by' => $request->user()?->id,
        ]);

        $equipment = Equipment::query()
            ->whereIn('id', $data['equipment_ids'])
            ->orderBy('equipment_no')
            ->get();

        return response()->json([
            'data' => $equipment->map(fn (Equipment $equipment): array => [
                'equipment_no' => $equipment->equipment_no,
                'name' => $equipment->name,
                'qr_text' => $equipment->equipment_no,
                'footer' => 'XPD_LIMS',
            ])->values(),
            'meta' => [
                'label_width_mm' => $data['label_width_mm'],
                'label_height_mm' => $data['label_height_mm'],
            ],
        ]);
    }
}
