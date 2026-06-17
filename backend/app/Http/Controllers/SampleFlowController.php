<?php

namespace App\Http\Controllers;

use App\Models\Sample;
use App\Models\SampleFlow;
use App\Models\User;
use App\Services\Samples\SampleFlowService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class SampleFlowController extends Controller
{
    public function globalIndex(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'sample_flows.read', 'sample_flows');

        $flows = $this->filteredQuery($request)
            ->with('sample.testOrder')
            ->orderByDesc('action_time')
            ->orderByDesc('id')
            ->paginate((int) $request->integer('per_page', 15));

        $operatorNames = User::query()
            ->whereIn('id', $flows->getCollection()->pluck('action_by')->filter()->unique()->values())
            ->pluck('name', 'id');

        return response()->json([
            'data' => $flows->getCollection()
                ->map(fn (SampleFlow $flow): array => $this->serializeFlow($flow, includeSample: true, operatorNames: $operatorNames))
                ->values(),
            'meta' => [
                'current_page' => $flows->currentPage(),
                'per_page' => $flows->perPage(),
                'total' => $flows->total(),
            ],
        ]);
    }

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

    private function filteredQuery(Request $request): Builder
    {
        return SampleFlow::query()
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $search = $request->string('search')->toString();

                $query->whereHas('sample', fn (Builder $sampleQuery): Builder => $sampleQuery
                    ->where('sample_no', 'like', "%{$search}%")
                    ->orWhere('sample_name', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%")
                    ->orWhereHas('testOrder', fn (Builder $orderQuery): Builder => $orderQuery
                        ->where('order_no', 'like', "%{$search}%")
                        ->orWhere('client_company', 'like', "%{$search}%")));
            })
            ->when($request->filled('action_type'), fn (Builder $query): Builder => $query->where('action_type', $request->string('action_type')->toString()))
            ->when($request->filled('action_time_from'), fn (Builder $query): Builder => $query->whereDate('action_time', '>=', $request->date('action_time_from')))
            ->when($request->filled('action_time_to'), fn (Builder $query): Builder => $query->whereDate('action_time', '<=', $request->date('action_time_to')));
    }

    /**
     * @param  Collection<int, string>|null  $operatorNames
     */
    private function serializeFlow(SampleFlow $flow, bool $includeSample = false, ?Collection $operatorNames = null): array
    {
        $record = [
            'id' => $flow->id,
            'sample_id' => $flow->sample_id,
            'action_type' => $flow->action_type,
            'action_by' => $flow->action_by,
            'action_by_name' => $operatorNames?->get($flow->action_by),
            'action_time' => $flow->action_time?->format('Y-m-d H:i:s'),
            'holder_from' => $flow->holder_from,
            'holder_to' => $flow->holder_to,
            'location_from' => $flow->location_from,
            'location_to' => $flow->location_to,
            'remark' => $flow->remark,
        ];

        if ($includeSample) {
            $record['sample'] = $this->serializeSampleSnapshot($flow->sample);
        }

        return $record;
    }

    private function serializeSampleSnapshot(?Sample $sample): ?array
    {
        if (! $sample) {
            return null;
        }

        return [
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
        ];
    }
}
