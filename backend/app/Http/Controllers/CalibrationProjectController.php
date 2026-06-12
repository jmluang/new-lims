<?php

namespace App\Http\Controllers;

use App\Models\CalibrationProject;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalibrationProjectController extends Controller
{
    private const RESOURCE = 'calibration_projects';

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'calibration_projects.read', self::RESOURCE);

        $projects = CalibrationProject::query()
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->where(fn (Builder $builder): Builder => $builder
                    ->where('project_no', 'like', "%{$search}%")
                    ->orWhere('project_name', 'like', "%{$search}%"));
            })
            ->when($request->filled('status'), fn (Builder $query): Builder => $query->where('status', $request->string('status')->toString()))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $projects->map(fn (CalibrationProject $project): array => $this->serialize($project))->values(),
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'calibration_projects.create', self::RESOURCE);

        $validated = $request->validate($this->rules());
        $project = CalibrationProject::query()->create($validated);

        $auditLogger->record(
            actor: $request->user(),
            action: 'calibration_projects.create',
            module: self::RESOURCE,
            subject: $project,
            after: $this->serialize($project),
        );

        return response()->json(['data' => $this->serialize($project)], 201);
    }

    public function update(Request $request, CalibrationProject $calibrationProject, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'calibration_projects.update', self::RESOURCE, $calibrationProject);

        $before = $this->serialize($calibrationProject);
        $validated = $request->validate($this->rules($calibrationProject->id));
        $calibrationProject->update($validated);

        $auditLogger->record(
            actor: $request->user(),
            action: 'calibration_projects.update',
            module: self::RESOURCE,
            subject: $calibrationProject,
            before: $before,
            after: $this->serialize($calibrationProject->fresh()),
        );

        return response()->json(['data' => $this->serialize($calibrationProject->fresh())]);
    }

    public function destroy(Request $request, CalibrationProject $calibrationProject, AuditLogger $auditLogger): JsonResponse
    {
        $this->authorizePermission($request, 'calibration_projects.delete', self::RESOURCE, $calibrationProject);

        $before = $this->serialize($calibrationProject);
        $calibrationProject->update(['status' => 'disabled']);

        $auditLogger->record(
            actor: $request->user(),
            action: 'calibration_projects.disable',
            module: self::RESOURCE,
            subject: $calibrationProject,
            before: $before,
            after: $this->serialize($calibrationProject->fresh()),
        );

        return response()->json(['data' => $this->serialize($calibrationProject->fresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?int $ignoreId = null): array
    {
        return [
            'project_no' => ['required', 'string', 'max:255', 'unique:calibration_projects,project_no'.($ignoreId ? ",{$ignoreId}" : '')],
            'project_name' => ['required', 'string', 'max:255'],
            'status' => ['nullable', 'in:active,disabled'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'remark' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(CalibrationProject $project): array
    {
        return [
            'id' => $project->id,
            'project_no' => $project->project_no,
            'project_name' => $project->project_name,
            'status' => $project->status,
            'sort_order' => $project->sort_order,
            'remark' => $project->remark,
            'created_at' => $project->created_at?->toISOString(),
            'updated_at' => $project->updated_at?->toISOString(),
        ];
    }
}
