<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Registry\Models\StructureObservation;
use App\Domain\Verification\Actions\AssembleReviewRecord;
use App\Domain\Verification\Actions\BuildEscalationQueue;
use App\Domain\Verification\Actions\ResolveEscalation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The other end of the Escalate button.
 *
 * A supervisor who suspects a capture was fabricated should not be the person
 * who decides it, so escalation moves the record here. Until this existed the
 * button set a status nothing read, which meant raising a concern about an
 * officer quietly buried the record instead of raising it.
 */
final class EscalationController
{
    public function index(BuildEscalationQueue $queue): Response
    {
        return Inertia::render('admin/Escalations', ['escalations' => $queue()]);
    }

    public function show(StructureObservation $observation, AssembleReviewRecord $record): Response
    {
        // The same evidence a supervisor saw, assembled by the same action. An
        // admin ruling on a person's honesty on a thinner view than the one that
        // raised the question would be deciding on less than the accuser had.
        return Inertia::render('admin/Escalation', [
            'record' => $record($observation),
        ]);
    }

    public function decide(
        Request $request,
        StructureObservation $observation,
        ResolveEscalation $resolve,
    ): RedirectResponse {
        $data = $request->validate([
            'outcome' => ['required', 'in:upheld,dismissed'],
            'note' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        try {
            $resolve($observation, $this->admin(), $data['outcome'], $data['note']);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['outcome' => $e->getMessage()]);
        }

        return to_route('admin.escalations')->with('status', 'Escalation resolved.');
    }

    private function admin(): User
    {
        /** @var User $user */
        $user = Auth::guard('web')->user();

        return $user;
    }
}
