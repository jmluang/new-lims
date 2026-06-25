<?php

namespace App\Http\Controllers;

use App\Models\Sample;
use App\Models\SampleFlow;
use App\Services\Authorization\PermissionAccess;
use App\Services\Samples\SampleFlowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SampleScanController extends Controller
{
    public function lookup(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'sample_flows.create', 'sample_flows');

        $data = $request->validate(['sample_no' => ['required', 'string', 'max:255']]);
        $sample = Sample::query()->where('sample_no', $data['sample_no'])->firstOrFail();

        return response()->json([
            'data' => [
                'sample' => $this->serializeSample($sample),
                'available_actions' => $this->availableActions($sample, $request),
            ],
        ]);
    }

    public function store(Request $request, Sample $sample, SampleFlowService $sampleFlowService): JsonResponse
    {
        $this->authorizePermission($request, 'sample_flows.create', 'sample_flows', $sample);
        $this->authorizePermission($request, 'samples.update', 'samples', $sample);

        $data = $request->validate([
            'action_type' => ['required', 'in:lend,transfer,return_room,receive_back'],
            'holder_to' => ['nullable', 'string', 'max:255'],
            'location_to' => ['nullable', 'string', 'max:255'],
            'remark' => ['nullable', 'string'],
        ]);

        if (in_array($data['action_type'], ['lend', 'transfer'], true)) {
            $data['holder_to'] = $request->user()->name;
        }

        if ($data['action_type'] === 'return_room') {
            $this->authorizePermission($request, 'sample_flows.return_room', 'sample_flows', $sample);
        }

        if (! in_array($data['action_type'], $this->availableActions($sample, $request), true)) {
            throw ValidationException::withMessages(['action_type' => ['sample_flow_action_not_available']]);
        }

        $flow = $sampleFlowService->record($request->user(), $sample, $data);

        return response()->json(['data' => $this->serializeFlow($flow)], 201);
    }

    /**
     * @return array<int, string>
     */
    private function availableActions(Sample $sample, Request $request): array
    {
        if ($sample->status === 'pending' && $sample->current_holder === '样品室') {
            return ['lend'];
        }

        if ($sample->status === 'testing' && $sample->current_holder !== '样品室') {
            return $this->filterAuthorizedActions(['transfer', 'return_room'], $request);
        }

        if ($sample->status === 'outsourced') {
            return ['receive_back'];
        }

        return [];
    }

    /**
     * @param  array<int, string>  $actions
     * @return array<int, string>
     */
    private function filterAuthorizedActions(array $actions, Request $request): array
    {
        return array_values(array_filter($actions, function (string $action) use ($request): bool {
            if ($action !== 'return_room') {
                return true;
            }

            return app(PermissionAccess::class)->userCan($request->user(), 'sample_flows.return_room');
        }));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSample(Sample $sample): array
    {
        return [
            'id' => $sample->id,
            'sample_no' => $sample->sample_no,
            'sample_name' => $sample->sample_name,
            'specification' => $sample->specification,
            'model' => $sample->model,
            'status' => $sample->status,
            'current_holder' => $sample->current_holder,
            'current_location' => $sample->current_location,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeFlow(SampleFlow $flow): array
    {
        return [
            'id' => $flow->id,
            'sample_id' => $flow->sample_id,
            'action_type' => $flow->action_type,
            'action_by' => $flow->action_by,
            'action_time' => $flow->action_time?->format('Y-m-d H:i:s'),
            'holder_from' => $flow->holder_from,
            'holder_to' => $flow->holder_to,
            'location_from' => $flow->location_from,
            'location_to' => $flow->location_to,
            'remark' => $flow->remark,
        ];
    }
}
