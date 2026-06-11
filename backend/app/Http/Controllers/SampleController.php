<?php

namespace App\Http\Controllers;

use App\Models\EquipmentLocation;
use App\Models\Sample;
use App\Models\TestOrder;
use App\Services\Samples\ReceiveSamples;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SampleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'samples.read', 'samples');

        $samples = $this->filteredQuery($request)
            ->with('testOrder')
            ->orderByDesc('id')
            ->paginate((int) $request->integer('per_page', 15));

        return response()->json([
            'data' => $samples->getCollection()
                ->map(fn (Sample $sample): array => $this->serializeSample($sample))
                ->values(),
            'meta' => [
                'current_page' => $samples->currentPage(),
                'per_page' => $samples->perPage(),
                'total' => $samples->total(),
            ],
        ]);
    }

    public function show(Request $request, Sample $sample): JsonResponse
    {
        $this->authorizePermission($request, 'samples.read', 'samples', $sample);

        return response()->json(['data' => $this->serializeSample($sample->load('testOrder'))]);
    }

    public function receiveOptions(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'samples.receive', 'samples');

        $orders = TestOrder::query()
            ->whereIn('sample_status', ['not_received', 'partially_received'])
            ->orderBy('id')
            ->limit((int) $request->integer('limit', 100))
            ->get();

        return response()->json([
            'data' => $orders->map(fn (TestOrder $order): array => [
                'id' => $order->id,
                'order_no' => $order->order_no,
                'client_company' => $order->client_company,
            ])->values(),
            'meta' => [
                'locations' => $this->receiveLocationOptions(),
            ],
        ]);
    }

    public function receive(Request $request, ReceiveSamples $receiveSamples): JsonResponse
    {
        $this->authorizePermission($request, 'samples.receive', 'samples');

        $payload = $request->validate([
            'test_order_id' => ['required', 'integer', 'exists:test_orders,id'],
            'received_date' => ['nullable', 'date'],
            'storage_condition' => ['nullable', 'string', 'max:255'],
            'current_location' => ['required', 'string', 'max:255'],
            'batch_no' => ['nullable', 'string', 'max:255'],
            'samples' => ['required', 'array', 'min:1'],
            'samples.*.test_order_sample_id' => ['nullable', 'integer', 'exists:test_order_samples,id'],
            'samples.*.sample_name' => ['required', 'string', 'max:255'],
            'samples.*.specification' => ['nullable', 'string', 'max:255'],
            'samples.*.model' => ['nullable', 'string', 'max:255'],
            'samples.*.appearance_check' => ['nullable', 'string'],
            'samples.*.reject_reason' => ['nullable', 'string'],
        ]);
        $testOrder = TestOrder::query()->findOrFail($payload['test_order_id']);
        $result = $receiveSamples->receive($request->user(), $testOrder, $payload);

        return response()->json([
            'data' => collect($result['samples'])
                ->map(fn (Sample $sample): array => $this->serializeSample($sample))
                ->values(),
            'meta' => [
                'delivery_received_count' => $result['delivery_received_count'],
                'rejected_count' => $result['rejected_count'],
            ],
        ], 201);
    }

    private function filteredQuery(Request $request): Builder
    {
        return Sample::query()
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $search = $request->string('search')->toString();

                $query->where(fn (Builder $builder): Builder => $builder
                    ->where('sample_no', 'like', "%{$search}%")
                    ->orWhere('sample_name', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%"));
            })
            ->when($request->filled('status'), fn (Builder $query): Builder => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('current_holder'), fn (Builder $query): Builder => $query->where('current_holder', 'like', '%'.$request->string('current_holder')->toString().'%'));
    }

    private function serializeSample(Sample $sample): array
    {
        return [
            'id' => $sample->id,
            'test_order_id' => $sample->test_order_id,
            'test_order_sample_id' => $sample->test_order_sample_id,
            'order_no' => $sample->testOrder?->order_no,
            'client_company' => $sample->testOrder?->client_company,
            'delivery_sequence' => $sample->delivery_sequence,
            'sample_no' => $sample->sample_no,
            'sample_name' => $sample->sample_name,
            'specification' => $sample->specification,
            'model' => $sample->model,
            'quantity' => $sample->quantity,
            'status' => $sample->status,
            'current_holder' => $sample->current_holder,
            'current_location' => $sample->current_location,
            'storage_condition' => $sample->storage_condition,
            'received_date' => $sample->received_date?->toDateString(),
            'appearance_check' => $sample->appearance_check,
            'batch_no' => $sample->batch_no,
            'sort_order' => $sample->sort_order,
            'delivery_received_count' => $sample->delivery_received_count,
        ];
    }

    private function receiveLocationOptions(): array
    {
        return EquipmentLocation::query()
            ->whereNull('parent_id')
            ->with('children')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->flatMap(fn (EquipmentLocation $location): array => $this->flattenLocationOptions($location))
            ->filter(fn (array $location): bool => $location['status'] === 'active')
            ->map(fn (array $location): array => [
                'id' => $location['id'],
                'name' => $location['name'],
                'label' => $location['label'],
            ])
            ->values()
            ->all();
    }

    private function flattenLocationOptions(EquipmentLocation $location, array $parents = []): array
    {
        $path = [...$parents, $location->name];
        $current = [[
            'id' => $location->id,
            'name' => $location->name,
            'label' => implode(' / ', $path),
            'status' => $location->status,
        ]];

        return [
            ...$current,
            ...$location->children
                ->flatMap(fn (EquipmentLocation $child): array => $this->flattenLocationOptions($child, $path))
                ->all(),
        ];
    }
}
