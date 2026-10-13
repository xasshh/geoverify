<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Staff\Actions\DecideAgentApplication;
use App\Domain\Staff\Actions\PresentAgentApplication;
use App\Domain\Staff\Models\AgentApplication;
use App\Domain\Staff\Models\AgentApplicationFile;
use App\Domain\Verification\Models\VerificationEvent;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Applications to become a field agent, for an administrator to rule on.
 *
 * Administrators only, behind `administers`: the documents here are identity
 * papers and somebody else's guarantor, which the onboarding brief keeps to
 * the people who decide. Opening a document is itself recorded.
 */
final class AgentApplicationController
{
    public function __construct(private readonly PresentAgentApplication $present) {}

    public function index(Request $request): Response
    {
        $status = $request->query('status');
        $status = is_string($status) && in_array($status, [AgentApplication::SUBMITTED, AgentApplication::CHANGES_REQUESTED, AgentApplication::APPROVED, AgentApplication::REJECTED, AgentApplication::DRAFT], true)
            ? $status
            : AgentApplication::SUBMITTED;

        $rows = AgentApplication::query()
            ->with('account:id,email')
            ->where('status', $status)
            ->orderByDesc('submitted_at')->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (AgentApplication $a): array => [
                'id' => $a->id,
                'reference' => $a->reference,
                'name' => $a->fullName() === '' ? $a->account?->email : $a->fullName(),
                'phone' => $a->phone,
                'submittedAt' => $a->submitted_at?->toIso8601String(),
                'languages' => $a->languages,
            ])->all();

        $counts = AgentApplication::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return Inertia::render('admin/AgentApplications', [
            'status' => $status,
            'counts' => $counts,
            'rows' => $rows,
            'selected' => null,
        ]);
    }

    public function show(AgentApplication $application): Response
    {
        return Inertia::render('admin/AgentApplications', [
            'status' => $application->status,
            'counts' => AgentApplication::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'rows' => [],
            'selected' => ['id' => $application->id] + $this->present->page($application),
            'choices' => PresentAgentApplication::choices(),
        ]);
    }

    /** One document, decrypted for the administrator reading it. */
    public function file(AgentApplication $application, AgentApplicationFile $file): HttpResponse
    {
        abort_unless($file->agent_application_id === $application->id, 404);

        VerificationEvent::record($application, 'agent_application.document_opened', $this->admin(), ['kind' => $file->kind, 'file_id' => $file->id]);

        $bytes = Crypt::decryptString((string) Storage::disk('local')->get($file->path));

        return response($bytes, 200, [
            'Content-Type' => $file->mime,
            'Content-Disposition' => 'inline; filename="'.$application->reference.'-'.$file->kind.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function decide(Request $request, AgentApplication $application, DecideAgentApplication $decide): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'send_back', 'reject'])],
            'note' => ['nullable', 'string', 'max:1000'],
            'role' => ['nullable', Rule::in([Role::Officer->value, Role::DeskDigitiser->value])],
            'capture_modes' => ['array'],
            'capture_modes.*' => ['string', Rule::in(AgentApplication::CAPTURE_MODES)],
            'assessment_score' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $note = $data['note'] ?? null;

        try {
            if ($data['decision'] === 'approve') {
                /** @var list<string> $modes */
                $modes = array_values($data['capture_modes'] ?? []);
                $result = $decide->approve(
                    $this->admin(),
                    $application,
                    Role::from($data['role'] ?? Role::Officer->value),
                    $modes,
                    isset($data['assessment_score']) ? (int) $data['assessment_score'] : null,
                    $note,
                );

                // A generated password is shown once, as on the People page, and
                // never stored readable. Usually there is none: the applicant
                // keeps the password they signed up with.
                return redirect()->route('admin.agent-applications')->with('status', $result['password'] === null
                    ? sprintf('%s approved as %s. They sign in at /agent with the email and password they applied with.', $result['user']->name, $result['user']->staff_ref ?? '')
                    : sprintf('%s approved as %s. First password: %s', $result['user']->name, $result['user']->staff_ref ?? '', $result['password']));
            }

            $data['decision'] === 'send_back'
                ? $decide->sendBack($this->admin(), $application, (string) $note)
                : $decide->reject($this->admin(), $application, (string) $note);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['decision' => $e->getMessage()]);
        }

        return redirect()->route('admin.agent-applications')->with('status', 'Decision recorded.');
    }

    private function admin(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
