<?php

declare(strict_types=1);

namespace App\Http\Controllers\Field;

use App\Domain\Field\Actions\FieldMessaging;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

/** The field client's inbox: read the thread, reply, mark as read. Its own channel, not the sync contract. */
final class FieldMessageController
{
    public function __construct(private readonly FieldMessaging $messages) {}

    public function index(Request $request): JsonResponse
    {
        return new JsonResponse([
            'messages' => $this->messages->thread($this->officer($request), max(0, $request->integer('after'))),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_uuid' => ['required', 'uuid'],
            'body' => ['required', 'string', 'max:'.FieldMessaging::MAX_LENGTH],
            'sent_at' => ['nullable', 'date'],
        ]);

        try {
            $message = $this->messages->fromOfficer(
                $this->officer($request),
                $validated['client_uuid'],
                $validated['body'],
                isset($validated['sent_at']) ? Carbon::parse($validated['sent_at']) : null,
            );
        } catch (RuntimeException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 422);
        }

        return new JsonResponse(['message' => $this->messages->present($message->load('sender'))]);
    }

    public function read(Request $request): JsonResponse
    {
        $officer = $this->officer($request);

        return new JsonResponse([
            'read' => $this->messages->markRead($officer, $officer, max(0, $request->integer('up_to'))),
        ]);
    }

    private function officer(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
