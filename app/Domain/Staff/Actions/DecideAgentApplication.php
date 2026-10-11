<?php

declare(strict_types=1);

namespace App\Domain\Staff\Actions;

use App\Domain\Staff\Models\AgentApplication;
use App\Domain\Verification\Models\VerificationEvent;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * An administrator's ruling on an application to become a field agent.
 *
 * Approving is the only way an applicant becomes staff, and it goes through
 * CreateStaffMember like any other new colleague, so the officer gets the same
 * generated first password and the next number in the series. The application
 * keeps which officer it became; the portal account stays what it was.
 *
 * Sending it back or turning it down needs a reason, because the applicant
 * reads it.
 */
final class DecideAgentApplication
{
    public function __construct(private readonly CreateStaffMember $create) {}

    /**
     * @param  list<string>  $captureModes
     * @return array{user: User, password: string}
     */
    public function approve(User $admin, AgentApplication $application, Role $role, array $captureModes, ?int $assessmentScore, ?string $note): array
    {
        $this->mustAdminister($admin);
        $this->mustBeWaiting($application);

        if (! in_array($role, [Role::Officer, Role::DeskDigitiser], true)) {
            throw new RuntimeException('An application becomes an officer or a desk digitiser.');
        }

        $account = $application->account;
        if ($account === null || $account->email === null) {
            throw new RuntimeException('This application has no email address to make the account with.');
        }

        return DB::transaction(function () use ($admin, $application, $role, $captureModes, $assessmentScore, $note, $account): array {
            $result = ($this->create)($admin, $application->fullName(), (string) $account->email, $role, $application->phone);

            $application->forceFill([
                'status' => AgentApplication::APPROVED,
                'decided_by' => $admin->id,
                'decided_at' => now(),
                'decision_note' => $note,
                'capture_modes' => array_values(array_intersect(AgentApplication::CAPTURE_MODES, $captureModes)),
                'assessment_score' => $assessmentScore,
                'user_id' => $result['user']->id,
            ])->save();

            VerificationEvent::record($application, 'agent_application.approved', $admin, [
                'role' => $role->value,
                'staff_ref' => $result['user']->staff_ref,
            ]);

            return $result;
        });
    }

    public function sendBack(User $admin, AgentApplication $application, string $note): void
    {
        $this->rule($admin, $application, AgentApplication::CHANGES_REQUESTED, $note, 'agent_application.changes_requested');
    }

    public function reject(User $admin, AgentApplication $application, string $note): void
    {
        $this->rule($admin, $application, AgentApplication::REJECTED, $note, 'agent_application.rejected');
    }

    private function rule(User $admin, AgentApplication $application, string $status, string $note, string $event): void
    {
        $this->mustAdminister($admin);
        $this->mustBeWaiting($application);

        if (mb_strlen(trim($note)) < 10) {
            throw new RuntimeException('Say why, in a sentence the applicant will understand.');
        }

        $application->forceFill([
            'status' => $status,
            'decided_by' => $admin->id,
            'decided_at' => now(),
            'decision_note' => trim($note),
        ])->save();

        VerificationEvent::record($application, $event, $admin, ['note' => trim($note)]);
    }

    private function mustAdminister(User $admin): void
    {
        if (! $admin->administers()) {
            throw new RuntimeException('Only an administrator can decide an application.');
        }
    }

    private function mustBeWaiting(AgentApplication $application): void
    {
        if ($application->status !== AgentApplication::SUBMITTED) {
            throw new RuntimeException('Only an application that has been sent and not yet decided can be decided.');
        }
    }
}
