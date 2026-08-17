<?php

namespace App\Http\Controllers;

use App\Models\UserMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserMessageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $limit = min(max((int) $request->integer('limit', 20), 1), 50);
        $unreadCount = UserMessage::query()
            ->where('recipient_user_id', $user->id)
            ->whereNull('read_at')
            ->count();

        $messages = UserMessage::query()
            ->with(['sender', 'testOrder'])
            ->where('recipient_user_id', $user->id)
            ->orderByRaw('read_at is null desc')
            ->latest()
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $messages->map(fn (UserMessage $message): array => $this->serializeMessage($message))->values(),
            'meta' => ['unread_count' => $unreadCount],
        ]);
    }

    public function markRead(Request $request, UserMessage $message): JsonResponse
    {
        if ((int) $message->recipient_user_id !== (int) $request->user()->id) {
            abort(403);
        }

        if ($message->read_at === null) {
            $message->forceFill(['read_at' => now()])->save();
        }

        return response()->json(['data' => $this->serializeMessage($message->fresh(['sender', 'testOrder']))]);
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Only ever hand the client an in-app path.
     *
     * The client navigates with this, so a value that turned out to be an
     * absolute URL — or a protocol-relative one — would send a reader off the
     * application entirely. Anything that is not a single-slash path is dropped.
     */
    private static function safeLinkPath(?string $path): ?string
    {
        if ($path === null || ! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return null;
        }

        return $path;
    }

    private function serializeMessage(UserMessage $message): array
    {
        return [
            'id' => $message->id,
            'title' => $message->title,
            'content' => $message->content,
            'link_path' => self::safeLinkPath($message->link_path),
            'read' => $message->read_at !== null,
            'read_at' => $message->read_at?->toDateTimeString(),
            'created_at' => $message->created_at?->toDateTimeString(),
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
