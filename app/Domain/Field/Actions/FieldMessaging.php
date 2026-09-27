<?php

declare(strict_types=1);

namespace App\Domain\Field\Actions;

use App\Domain\Field\Models\Assignment;
use App\Domain\Field\Models\FieldMessage;
use App\Domain\Registry\Models\StructureObservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Supervisor and officer, talking.
 *
 * There is no team table and this does not add one. An officer's supervisor is
 * whoever handed them the cells they hold now, which is already recorded on
 * every assignment; a supervisor's team is the officers holding cells they
 * handed out. That keeps "who do I call" true the moment work is reassigned,
 * with nothing to keep in step.
 *
 * Messages from a handset carry the client's uuid, so a send retried three
 * times over a bad connection is one message, and the time it was written, so
 * "on my way" typed at 10:07 does not read as 14:30 because that is when the
 * signal came back.
 */
final class FieldMessaging
{
    public const MAX_LENGTH = 1000;

    /** The supervisor who assigned this officer's most recent open cell. */
    public function supervisorOf(User $officer): ?User
    {
        $id = Assignment::query()
            ->where('user_id', $officer->id)
            ->whereNull('closed_at')
            ->orderByDesc('assigned_at')
            ->value('assigned_by');

        return $id === null ? null : User::query()->find($id);
    }

    /**
     * Officers holding open cells this supervisor handed out.
     *
     * @return Collection<int, User>
     */
    public function teamOf(User $supervisor): Collection
    {
        return User::query()
            ->whereIn('id', Assignment::query()
                ->where('assigned_by', $supervisor->id)
                ->whereNull('closed_at')
                ->select('user_id'))
            ->orderBy('name')
            ->get();
    }

    public function fromOfficer(User $officer, string $clientUuid, string $body, ?Carbon $sentAt = null): FieldMessage
    {
        if (! $officer->capturesInTheField()) {
            throw new RuntimeException('Only a field officer sends from the field.');
        }

        if (! Str::isUuid($clientUuid)) {
            throw new RuntimeException('A message needs the uuid the handset gave it.');
        }

        $existing = FieldMessage::query()->where('client_uuid', $clientUuid)->first();

        if ($existing instanceof FieldMessage) {
            // A replay. The same uuid from somebody else is not a replay, it
            // is a collision, and it must not be answered with their message.
            if ($existing->officer_id !== $officer->id) {
                throw new RuntimeException('That message id is already taken.');
            }

            return $existing;
        }

        try {
            return DB::transaction(fn (): FieldMessage => FieldMessage::query()->create([
                'client_uuid' => $clientUuid,
                'officer_id' => $officer->id,
                'sender_id' => $officer->id,
                'direction' => FieldMessage::FROM_OFFICER,
                'kind' => FieldMessage::KIND_TEXT,
                'body' => $this->clean($body),
                // A handset clock can be wrong, but not into the future of the
                // server by much, and never before the officer existed.
                'sent_at' => $sentAt === null || $sentAt->isFuture() ? now() : $sentAt,
            ]));
        } catch (UniqueConstraintViolationException) {
            // Two deliveries of the same send, racing.
            return FieldMessage::query()->where('client_uuid', $clientUuid)->firstOrFail();
        }
    }

    public function toOfficer(User $supervisor, User $officer, string $body): FieldMessage
    {
        $this->assertSupervisor($supervisor);

        if (! $officer->capturesInTheField()) {
            throw new RuntimeException("{$officer->name} is not an active field officer.");
        }

        return $this->write($officer, $supervisor, FieldMessage::KIND_TEXT, $body);
    }

    /**
     * One message to many officers: the whole team, chosen officers, or
     * whoever holds chosen cells. Written once per officer so each has their
     * own read receipt. Returns how many received it.
     *
     * @param  list<int>  $officerIds
     * @param  list<string>  $h3Cells
     */
    public function broadcast(User $supervisor, string $audience, string $body, array $officerIds = [], array $h3Cells = [], bool $pin = false): int
    {
        $this->assertSupervisor($supervisor);

        $team = $this->teamOf($supervisor);

        $recipients = match ($audience) {
            'team' => $team,
            'selected' => $team->whereIn('id', $officerIds)->values(),
            'cells' => $team->whereIn('id', Assignment::query()
                ->whereNull('closed_at')
                ->whereHas('gridCell', static fn ($q) => $q->whereIn(DB::raw('h3_index::h3index::text'), $h3Cells))
                ->pluck('user_id')
                ->all())->values(),
            default => throw new RuntimeException('Send to the whole team, chosen officers, or a set of cells.'),
        };

        if ($recipients->isEmpty()) {
            throw new RuntimeException('Nobody on your team matches that.');
        }

        $uuid = (string) Str::uuid7();
        $body = $this->clean($body);

        DB::transaction(function () use ($recipients, $supervisor, $body, $uuid, $pin): void {
            foreach ($recipients as $officer) {
                $this->write($officer, $supervisor, FieldMessage::KIND_BROADCAST, $body, broadcast: $uuid, pin: $pin);
            }
        });

        return $recipients->count();
    }

    /** A supervisor sent a capture back. The officer hears it in their inbox, with the reason. */
    public function returned(StructureObservation $observation, User $supervisor, ?string $reason): ?FieldMessage
    {
        $officer = User::query()->find($observation->captured_by);

        if (! $officer instanceof User) {
            return null;
        }

        return $this->write(
            $officer,
            $supervisor,
            FieldMessage::KIND_RETURNED,
            $reason ?? 'Sent back for another look.',
            observationId: $observation->id,
            assignmentId: $observation->assignment_id,
        );
    }

    /** @param  list<string>  $h3Cells */
    public function cellsAssigned(User $officer, User $supervisor, array $h3Cells): ?FieldMessage
    {
        if ($h3Cells === []) {
            return null;
        }

        $body = count($h3Cells) === 1
            ? "Cell {$h3Cells[0]} is now yours."
            : count($h3Cells).' cells are now yours: '.implode(', ', array_slice($h3Cells, 0, 6)).(count($h3Cells) > 6 ? ' and more.' : '.');

        return $this->write($officer, $supervisor, FieldMessage::KIND_CELL_ASSIGNED, $body);
    }

    /** Whoever is reading marks what was sent to them as read, up to a point. */
    public function markRead(User $reader, User $officer, int $upToId): int
    {
        $direction = $reader->id === $officer->id ? FieldMessage::TO_OFFICER : FieldMessage::FROM_OFFICER;

        if ($direction === FieldMessage::FROM_OFFICER) {
            $this->assertSupervisor($reader);
        }

        return FieldMessage::query()
            ->where('officer_id', $officer->id)
            ->where('direction', $direction)
            ->where('id', '<=', $upToId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function togglePin(User $supervisor, FieldMessage $message): FieldMessage
    {
        $this->assertSupervisor($supervisor);

        $message->update(['pinned_at' => $message->pinned_at === null ? now() : null]);

        return $message;
    }

    /**
     * An officer's thread, oldest first, as both clients draw it.
     *
     * @return list<array<string, mixed>>
     */
    public function thread(User $officer, int $afterId = 0, int $limit = 200): array
    {
        return FieldMessage::query()
            ->with(['sender:id,name,staff_ref', 'observation:id,assignment_id,structure_type,status'])
            ->where('officer_id', $officer->id)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (FieldMessage $m): array => $this->present($m))
            ->all();
    }

    /** @return array<string, mixed> */
    public function present(FieldMessage $m): array
    {
        return [
            'id' => $m->id,
            'uuid' => $m->client_uuid,
            'direction' => $m->direction,
            'kind' => $m->kind,
            'body' => $m->body,
            'sender' => $m->sender?->name,
            'senderRef' => $m->sender?->staff_ref,
            'sentAt' => $m->sent_at->toIso8601String(),
            'pinned' => $m->pinned_at !== null,
            'read' => $m->read_at !== null,
            'record' => $m->observation === null ? null : [
                'id' => $m->observation->id,
                'ref' => self::recordRef($m->observation->id),
                'structureType' => $m->observation->structure_type,
                'assignmentId' => $m->observation->assignment_id,
            ],
        ];
    }

    /** NAS-0412 in the mockup: a short, readable handle for an observation. */
    public static function recordRef(int $observationId): string
    {
        return 'REC-'.str_pad((string) $observationId, 4, '0', STR_PAD_LEFT);
    }

    private function write(
        User $officer,
        User $sender,
        string $kind,
        string $body,
        ?int $observationId = null,
        ?int $assignmentId = null,
        ?string $broadcast = null,
        bool $pin = false,
    ): FieldMessage {
        return FieldMessage::query()->create([
            'client_uuid' => (string) Str::uuid7(),
            'officer_id' => $officer->id,
            'sender_id' => $sender->id,
            'direction' => FieldMessage::TO_OFFICER,
            'kind' => $kind,
            'body' => $this->clean($body),
            'observation_id' => $observationId,
            'assignment_id' => $assignmentId,
            'broadcast_uuid' => $broadcast,
            'pinned_at' => $pin ? now() : null,
            'sent_at' => now(),
        ]);
    }

    private function clean(string $body): string
    {
        $body = trim($body);

        if ($body === '') {
            throw new RuntimeException('Say something first.');
        }

        return mb_substr($body, 0, self::MAX_LENGTH);
    }

    private function assertSupervisor(User $user): void
    {
        if (! $user->supervises()) {
            throw new RuntimeException('Only a supervisor can do that.');
        }
    }
}
