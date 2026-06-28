<?php

namespace App\Http\Controllers;

use App\Models\TestOrder;
use App\Models\User;
use App\Models\UserMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TestOrderMessageController extends Controller
{
    public function recipients(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'test_orders.notify', 'test_orders');

        $users = User::query()
            ->where('status', 'active')
            ->whereKeyNot($request->user()->id)
            ->orderBy('id')
            ->limit(100)
            ->get(['id', 'name', 'email']);

        return response()->json([
            'data' => $users->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ])->values(),
        ]);
    }

    public function store(Request $request, TestOrder $testOrder): JsonResponse
    {
        $this->authorizePermission($request, 'test_orders.notify', 'test_orders', $testOrder);

        $data = $request->validate([
            'recipient_user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('status', 'active'),
            ],
        ]);

        $recipient = User::query()->findOrFail($data['recipient_user_id']);
        $message = UserMessage::query()->create([
            'recipient_user_id' => $recipient->id,
            'sender_user_id' => $request->user()->id,
            'test_order_id' => $testOrder->id,
            'title' => '委托试验单处理提醒',
            'content' => "请及时处理委托试验单 {$testOrder->order_no}。",
        ]);

        return response()->json(['data' => $this->serializeMessage($message->load(['recipient', 'sender', 'testOrder']))], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMessage(UserMessage $message): array
    {
        return [
            'id' => $message->id,
            'title' => $message->title,
            'content' => $message->content,
            'read' => $message->read_at !== null,
            'read_at' => $message->read_at?->toDateTimeString(),
            'created_at' => $message->created_at?->toDateTimeString(),
            'recipient' => $message->recipient ? [
                'id' => $message->recipient->id,
                'name' => $message->recipient->name,
                'email' => $message->recipient->email,
            ] : null,
            'sender' => $message->sender ? [
                'id' => $message->sender->id,
                'name' => $message->sender->name,
                'email' => $message->sender->email,
            ] : null,
            'test_order' => $message->testOrder ? [
                'id' => $message->testOrder->id,
                'order_no' => $message->testOrder->order_no,
                'client_company' => $message->testOrder->client_company,
            ] : null,
        ];
    }
}
