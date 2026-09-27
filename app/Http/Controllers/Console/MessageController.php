<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Domain\Field\Actions\FieldMessaging;
use App\Domain\Field\Models\FieldMessage;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/** The supervisor's side of the field inbox: threads, replies, broadcasts, pins. */
final class MessageController
{
    public function __construct(private readonly FieldMessaging $messages) {}

    public function index(Request $request, ?User $officer = null): Response
    {
        $supervisor = $this->supervisor($request);
        $team = $this->messages->teamOf($supervisor);

        $last = DB::table('field_messages')
            ->whereIn('officer_id', $team->pluck('id'))
            ->selectRaw('officer_id, max(id) AS last_id, count(*) FILTER (WHERE direction = ? AND read_at IS NULL) AS unread', [FieldMessage::FROM_OFFICER])
            ->groupBy('officer_id')
            ->get()
            ->keyBy('officer_id');

        $bodies = FieldMessage::query()->whereIn('id', $last->pluck('last_id'))->get()->keyBy('officer_id');

        $selected = $officer ?? $team->first();

        if ($selected instanceof User) {
            $this->messages->markRead($supervisor, $selected, PHP_INT_MAX);
        }

        return Inertia::render('console/Messages', [
            'officers' => $team->map(static fn (User $o): array => [
                'id' => $o->id,
                'name' => $o->name,
                'staffRef' => $o->staff_ref,
                'unread' => (int) ($last[$o->id]->unread ?? 0),
                'last' => $bodies->get($o->id)?->body,
                'lastAt' => $bodies->get($o->id)?->sent_at->toIso8601String(),
            ])->values()->all(),
            'selected' => $selected === null ? null : ['id' => $selected->id, 'name' => $selected->name, 'staffRef' => $selected->staff_ref, 'phone' => $selected->phone],
            'thread' => $selected === null ? [] : $this->messages->thread($selected),
        ]);
    }

    public function send(Request $request, User $officer): RedirectResponse
    {
        $body = (string) $request->validate(['body' => ['required', 'string', 'max:'.FieldMessaging::MAX_LENGTH]])['body'];

        return $this->attempt(fn () => $this->messages->toOfficer($this->supervisor($request), $officer, $body), 'Sent.');
    }

    public function broadcast(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'audience' => ['required', Rule::in(['team', 'selected', 'cells'])],
            'body' => ['required', 'string', 'max:'.FieldMessaging::MAX_LENGTH],
            'officer_ids' => ['array'],
            'officer_ids.*' => ['integer'],
            'cells' => ['array'],
            'cells.*' => ['string', 'max:20'],
            'pin' => ['boolean'],
        ]);

        try {
            $count = $this->messages->broadcast(
                $this->supervisor($request),
                $input['audience'],
                $input['body'],
                array_map('intval', $input['officer_ids'] ?? []),
                $input['cells'] ?? [],
                (bool) ($input['pin'] ?? false),
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['broadcast' => $e->getMessage()]);
        }

        return back()->with('status', "Sent to {$count} ".($count === 1 ? 'officer' : 'officers').'.');
    }

    public function pin(Request $request, FieldMessage $message): RedirectResponse
    {
        return $this->attempt(fn () => $this->messages->togglePin($this->supervisor($request), $message), $message->pinned_at === null ? 'Pinned.' : 'Unpinned.');
    }

    /** @param  callable(): mixed  $action */
    private function attempt(callable $action, string $done): RedirectResponse
    {
        try {
            $action();
        } catch (RuntimeException $e) {
            return back()->withErrors(['body' => $e->getMessage()]);
        }

        return back()->with('status', $done);
    }

    private function supervisor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->supervises(), 403);

        return $user;
    }
}
