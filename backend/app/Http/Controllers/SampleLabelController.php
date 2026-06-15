<?php

namespace App\Http\Controllers;

use App\Models\Sample;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SampleLabelController extends Controller
{
    public function preview(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'sample_labels.print', 'sample_labels');

        $data = $request->validate([
            'sample_ids' => ['required', 'array', 'min:1'],
            'sample_ids.*' => ['integer', 'exists:samples,id'],
            'label_width_mm' => ['required', 'integer', 'in:40'],
            'label_height_mm' => ['required', 'integer', 'in:60'],
        ]);

        $samples = Sample::query()
            ->with('testOrder')
            ->whereIn('id', $data['sample_ids'])
            ->orderBy('sample_no')
            ->get();

        return response()->json([
            'data' => $samples->map(fn (Sample $sample): array => [
                'client_company' => $sample->testOrder?->client_company,
                'sample_name' => $sample->sample_name,
                'model' => $sample->model,
                'sample_no' => $sample->sample_no,
                'status' => $sample->status,
                'qr_text' => $sample->sample_no,
            ])->values(),
            'meta' => [
                'label_width_mm' => $data['label_width_mm'],
                'label_height_mm' => $data['label_height_mm'],
            ],
        ]);
    }
}
