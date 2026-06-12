<?php

namespace App\Http\Controllers;

use App\Models\CalibrationProject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalibrationProjectLabelController extends Controller
{
    public function preview(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'calibration_project_labels.print', 'calibration_project_labels');

        $data = $request->validate([
            'project_ids' => ['required', 'array', 'min:1'],
            'project_ids.*' => ['integer', 'exists:calibration_projects,id'],
            'label_width_mm' => ['required', 'integer', 'in:40'],
            'label_height_mm' => ['required', 'integer', 'in:60'],
        ]);

        $projects = CalibrationProject::query()
            ->whereIn('id', $data['project_ids'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $projects->map(fn (CalibrationProject $project): array => [
                'project_no' => $project->project_no,
                'project_name' => $project->project_name,
                'qr_text' => $project->project_no,
                'footer' => 'XPD_LIMS',
            ])->values(),
            'meta' => [
                'label_width_mm' => $data['label_width_mm'],
                'label_height_mm' => $data['label_height_mm'],
            ],
        ]);
    }
}
