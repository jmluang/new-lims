<?php

namespace App\Http\Controllers;

use App\Models\Sample;
use App\Models\SampleFlow;
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

    public function store(Request $request, Sample $sample): JsonResponse
    {
        $this->authorizePermission($request, 'sample_flows.create', 'sample_flows', $sample);
        $this->authorizePermission($request, 'samples.update', 'samples', $sample);

        $data = $request->validate([
            'action_type' => ['required', 'in:lend,transfer,return_room,send_out,receive_back,return_client,scrap,position_change'],
            'holder_to' => ['nullable', 'string', 'max:255'],
            'location_to' => ['nullable', 'string', 'max:255'],
            'remark' => ['nullable', 'string'],
        ]);
        $beforeHolder = $sample->current_holder;
        $beforeLocation = $sample->current_location;
        $updates = $this->sampleUpdates($sample, $data);
        $sample->update([
            ...$updates,
            'updated_by' => $request->user()?->id,
        ]);

        $flow = $sample->flows()->create([
            'action_type' => $data['action_type'],
            'action_by' => $request->user()?->id,
            'action_time' => now(),
            'holder_from' => $beforeHolder,
            'holder_to' => $sample->fresh()->current_holder,
            'location_from' => $beforeLocation,
            'location_to' => $sample->fresh()->current_location,
            'remark' => $data['remark'] ?? null,
        ]);

        return response()->json(['data' => $this->serializeFlow($flow)], 201);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sampleUpdates(Sample $sample, array $data): array
    {
        return match ($data['action_type']) {
            'lend' => [
                'status' => 'testing',
                'current_holder' => $data['holder_to'] ?? $sample->current_holder,
                'current_location' => $data['location_to'] ?? $sample->current_location,
            ],
            'transfer' => [
                'current_holder' => $data['holder_to'] ?? $sample->current_holder,
                'current_location' => $data['location_to'] ?? $sample->current_location,
            ],
            'send_out' => [
                'status' => 'outsourced',
                'current_holder' => $data['holder_to'] ?? $sample->current_holder,
                'current_location' => $data['location_to'] ?? $sample->current_location,
            ],
            'receive_back' => [
                'status' => 'outsource_returned',
                'current_holder' => '样品室',
                'current_location' => $data['location_to'] ?? $sample->current_location,
            ],
            'return_room' => [
                'status' => 'pending',
                'current_holder' => '样品室',
                'current_location' => $data['location_to'] ?? $sample->current_location,
            ],
            'return_client' => [
                'status' => 'returned',
                'current_holder' => $data['holder_to'] ?? '客户',
                'current_location' => $data['location_to'] ?? $sample->current_location,
            ],
            'scrap' => [
                'status' => 'scrapped',
                'current_location' => $data['location_to'] ?? $sample->current_location,
            ],
            'position_change' => [
                'current_location' => $data['location_to'] ?? $sample->current_location,
            ],
        };
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
