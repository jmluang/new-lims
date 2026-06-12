<?php

namespace App\Http\Controllers;

use App\Models\Sample;
use App\Models\SampleFlow;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SampleFlowCardController extends Controller
{
    public function show(Request $request, Sample $sample): JsonResponse
    {
        $this->authorizePermission($request, 'samples.read', 'samples', $sample);
        $this->authorizePermission($request, 'sample_flows.read', 'sample_flows', $sample);

        $sample->load('testOrder', 'flows');

        $operatorNames = User::query()
            ->whereIn('id', $sample->flows->pluck('action_by')->filter()->unique()->values())
            ->pluck('name', 'id');

        return response()->json([
            'data' => [
                'sample' => [
                    'id' => $sample->id,
                    'sample_no' => $sample->sample_no,
                    'sample_name' => $sample->sample_name,
                    'specification' => $sample->specification,
                    'model' => $sample->model,
                    'order_no' => $sample->testOrder?->order_no,
                    'client_company' => $sample->testOrder?->client_company,
                    'status' => $sample->status,
                    'current_holder' => $sample->current_holder,
                    'current_location' => $sample->current_location,
                    'received_date' => $sample->received_date?->toDateString(),
                    'storage_condition' => $sample->storage_condition,
                    'batch_no' => $sample->batch_no,
                ],
                'flows' => $sample->flows->map(fn (SampleFlow $flow): array => [
                    'id' => $flow->id,
                    'action_type' => $flow->action_type,
                    'action_by' => $flow->action_by,
                    'action_by_name' => $operatorNames[$flow->action_by] ?? null,
                    'action_time' => $flow->action_time?->toISOString(),
                    'holder_from' => $flow->holder_from,
                    'holder_to' => $flow->holder_to,
                    'location_from' => $flow->location_from,
                    'location_to' => $flow->location_to,
                    'remark' => $flow->remark,
                ])->values(),
            ],
        ]);
    }
}
