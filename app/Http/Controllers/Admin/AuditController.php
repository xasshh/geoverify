<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Verification\Actions\ReadAuditLog;
use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The log, made visible.
 *
 * verification_events has been written to since the first migration and read by
 * nothing. It is the artefact this business sells, and until now the only way to
 * look at it was psql.
 */
final class AuditController
{
    public function index(Request $request, ReadAuditLog $log): Response
    {
        $filters = $request->only(['event', 'subjectType', 'actor', 'from', 'to']);
        $page = $log($filters);

        return Inertia::render('admin/Audit', [
            'filters' => $filters,
            'vocabulary' => $log->vocabulary(),
            'total' => $page->total(),
            'links' => [
                'prev' => $page->previousPageUrl(),
                'next' => $page->nextPageUrl(),
            ],
            'page' => $page->currentPage(),
            'lastPage' => $page->lastPage(),
            'events' => array_map(static fn (VerificationEvent $event): array => [
                'id' => $event->id,
                'event' => $event->event,
                // The class name is an implementation detail. What a reader
                // wants is "Structure observation", not the namespace it lives
                // in, and the namespace changes without the meaning changing.
                'subject' => class_basename($event->subject_type),
                'subjectType' => $event->subject_type,
                'subjectId' => $event->subject_id,
                'actorType' => $event->actor_type,
                'actorLabel' => $event->actor_label,
                'occurredAt' => $event->occurred_at->toIso8601String(),
                'evidence' => $event->evidence,
            ], $page->items()),
        ]);
    }
}
