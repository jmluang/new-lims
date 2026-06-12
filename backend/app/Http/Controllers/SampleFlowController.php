<?php

namespace App\Http\Controllers;

use App\Models\Sample;
use App\Models\SampleFlow;
use App\Services\Samples\SampleFlowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SampleFlowController extends Controller
{
    public function index(Request $request, Sample $sample): JsonResponse
    {
        $this->authorizePermission($request, 'sample_flows.read', 'sample_flows', $sample);

        return response()->json([
            'data' => $sample->flows()
                ->get()
                ->map(fn (SampleFlow $flow): array => $this->serializeFlow($flow))
                ->values(),
        ]);
    }

    public function store(Request $request, Sample $sample, SampleFlowService $sampleFlowService): JsonResponse
    {
        $this->authorizePermission($request, 'sample_flows.create', 'sample_flows', $sample);
        $this->authorizePermission($request, 'samples.update', 'samples', $sample);

        $data = $request->validate([
            'action_type' => ['required', 'in:lend,transfer,return_room,send_out,receive_back,return_client,scrap,position_change'],
            'holder_to' => ['nullable', 'string', 'max:255'],
            'location_to' => ['nullable', 'string', 'max:255'],
            'remark' => ['nullable', 'string'],
        ]);

        $flow = $sampleFlowService->record($request->user(), $sample, $data);

        return response()->json(['data' => $this->serializeFlow($flow)], 201);
    }

    private function serializeFlow(SampleFlow $flow): array
    {
        return [
            'id' => $flow->id,
            'sample_id' => $flow->sample_id,
            'action_type' => $flow->action_type,
            'action_by' => $flow->action_by,
            'action_time' => $flow->action_time?->toISOString(),
            'holder_from' => $flow->holder_from,
            'holder_to' => $flow->holder_to,
            'location_from' => $flow->location_from,
            'location_to' => $flow->location_to,
            'remark' => $flow->remark,
        ];
    }
}
