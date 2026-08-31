<?php

declare(strict_types=1);

namespace App\Domain\Verification\Actions;

use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The log, read back.
 *
 * verification_events is the product: every capture, decision, claim, sign in,
 * export and consent, appended and never amended. It has been written to from
 * the first migration and read by nothing, which means the one artefact this
 * business sells has never been visible to the people who sell it.
 *
 * Filtered rather than searched. A full text search over evidence would invite
 * someone to go looking for a person, and the shape of the questions worth
 * asking here is narrow: what happened to this thing, or what did this person
 * do, or what happened on this day.
 */
final class ReadAuditLog
{
    public const PER_PAGE = 60;

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, VerificationEvent>
     */
    public function __invoke(array $filters = []): LengthAwarePaginator
    {
        $event = $this->stringOrNull($filters, 'event');
        $subjectType = $this->stringOrNull($filters, 'subjectType');
        $actor = $this->stringOrNull($filters, 'actor');
        $from = $this->stringOrNull($filters, 'from');
        $to = $this->stringOrNull($filters, 'to');

        return VerificationEvent::query()
            ->when($event !== null, fn ($query) => $query->where('event', $event))
            ->when($subjectType !== null, fn ($query) => $query->where('subject_type', $subjectType))
            ->when($actor !== null, fn ($query) => $query->where('actor_type', $actor))
            ->when($from !== null, fn ($query) => $query->where('occurred_at', '>=', $from))
            // Inclusive of the day named, which is what a person means by "to
            // the 30th". An exclusive bound here silently drops a day's events
            // and is the kind of error nobody notices in an audit.
            ->when($to !== null, fn ($query) => $query->where('occurred_at', '<', $to.' 23:59:59.999999'))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * The distinct values actually present, for the filter controls.
     *
     * Read from the table rather than from a list of constants: an event this
     * build does not know about still has to be filterable, or the log stops
     * being complete the first time somebody adds one.
     *
     * @return array{events: list<string>, subjects: list<string>, actors: list<string>}
     */
    public function vocabulary(): array
    {
        return [
            'events' => VerificationEvent::query()->distinct()->orderBy('event')->pluck('event')->all(),
            'subjects' => VerificationEvent::query()->distinct()->orderBy('subject_type')->pluck('subject_type')->all(),
            'actors' => VerificationEvent::query()->distinct()->orderBy('actor_type')->pluck('actor_type')->all(),
        ];
    }

    /** @param array<string, mixed> $filters */
    private function stringOrNull(array $filters, string $key): ?string
    {
        $value = $filters[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
