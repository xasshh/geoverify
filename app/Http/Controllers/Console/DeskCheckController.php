<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Domain\Enumerate\Actions\DecideDeskCheck;
use App\Domain\Enumerate\Actions\RunRegistryChecks;
use App\Domain\Enumerate\Enums\RequestStatus;
use App\Domain\Enumerate\Models\EnumerateRequest;
use App\Domain\Enumerate\Models\RegistryCheck;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Enumerate's desk checks, from the supervisor's side.
 *
 * Oldest first: every row is somebody who has paid and is waiting, and the one
 * who has waited longest is the one to read next. The supervisor sees every
 * lookup, not only the latest, so a provider that answered differently on a
 * second run is visible rather than papered over.
 */
final class DeskCheckController
{
    public function index(Request $request): Response
    {
        $queue = EnumerateRequest::query()
            ->whereIn('status', [RequestStatus::Paid->value, RequestStatus::RegistryCheck->value])
            ->orderBy('paid_at')
            ->limit(100)
            ->get();

        $selected = null;
        $reference = $request->query('request');

        if (is_string($reference)) {
            $selected = EnumerateRequest::query()->where('reference', $reference)->with(['requester', 'deskChecker'])->first();
        }

        $selected ??= $queue->first();

        return Inertia::render('console/DeskChecks', [
            'queue' => $queue->map(static fn (EnumerateRequest $r): array => [
                'reference' => $r->reference,
                'business' => $r->subject_name,
                'rcNumber' => $r->rc_number,
                'tier' => $r->tier->value,
                'tierLabel' => $r->tier->short(),
                'status' => $r->status->value,
                'paidAt' => $r->paid_at->toIso8601String(),
            ])->values()->all(),
            'selected' => $selected === null ? null : [
                'reference' => $selected->reference,
                'business' => $selected->subject_name,
                'rcNumber' => $selected->rc_number,
                'companyType' => $selected->company_type,
                'tier' => $selected->tier->value,
                'tierLabel' => $selected->tier->short(),
                'monitoringDays' => $selected->monitoring_days,
                'status' => $selected->status->value,
                'statusLabel' => $selected->status->label(),
                'paidAt' => $selected->paid_at->toIso8601String(),
                'requester' => $selected->requester?->name,
                'outcome' => $selected->registry_outcome,
                'reason' => $selected->registry_reason,
                'decidedBy' => $selected->deskChecker?->name,
                'decidedAt' => $selected->desk_checked_at?->toIso8601String(),
                // CAC before TIN, as the report reads; newest first within each.
                'checks' => $selected->checks()->orderBy('kind')->orderByDesc('checked_at')->orderByDesc('id')->get()
                    ->map(static fn (RegistryCheck $c): array => [
                        'id' => $c->id,
                        'kind' => $c->kind,
                        'provider' => $c->provider,
                        'outcome' => $c->outcome,
                        'note' => $c->note,
                        'facts' => $c->facts,
                        'checkedAt' => $c->checked_at->toIso8601String(),
                    ])->values()->all(),
            ],
        ]);
    }

    public function decide(Request $request, string $reference, DecideDeskCheck $decide): RedirectResponse
    {
        $input = $request->validate([
            'passed' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:300'],
        ]);

        $found = EnumerateRequest::query()->where('reference', $reference)->firstOrFail();

        try {
            $decide($found, self::supervisor($request), (bool) $input['passed'], $input['reason'] ?? null);
        } catch (RuntimeException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('console.desk-checks')
            ->with('status', "{$found->reference} is decided. The requester has been told.");
    }

    public function rerun(Request $request, string $reference, RunRegistryChecks $run): RedirectResponse
    {
        self::supervisor($request);
        $found = EnumerateRequest::query()->where('reference', $reference)->firstOrFail();
        $run($found);

        return redirect()->route('console.desk-checks', ['request' => $found->reference])
            ->with('status', 'The registers were asked again.');
    }

    private static function supervisor(Request $request): User
    {
        $user = $request->user('web');
        abort_unless($user instanceof User && $user->supervises(), 403);

        return $user;
    }
}
