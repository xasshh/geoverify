<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Enums;

/**
 * Where a request stands, as the progress bar on its page reads it.
 *
 * The check constraint on enumerate_requests holds exactly these values, and
 * grows with the milestone that moves a request into a new one.
 */
enum RequestStatus: string
{
    /** Taken from the wallet; the registry lookups are queued. */
    case Paid = 'paid';

    /** The registry answered; a supervisor has to read it. */
    case RegistryCheck = 'registry_check';

    /** Tier 1, done: the registers agree. */
    case Passed = 'passed';

    /** The registers disagree, or say there is no such business. Final at any tier. */
    case Failed = 'failed';

    /** Tier 2 or 3, desk check passed, waiting for an officer. */
    case AwaitingAgent = 'awaiting_agent';

    /** An officer has the visit on their phone. */
    case AgentAssigned = 'agent_assigned';

    /** The officer has recorded their arrival; the report follows. */
    case OnSite = 'on_site';

    /** Tier 3: the site visit was accepted and the daily visits run (E3). */
    case Monitoring = 'monitoring';

    /** Tier 2 or 3, done: the officer's work was accepted. */
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Paid',
            self::RegistryCheck => 'Registry check',
            self::Passed => 'Passed',
            self::Failed => 'Failed',
            self::AwaitingAgent => 'Awaiting agent',
            self::AgentAssigned => 'Agent assigned',
            self::OnSite => 'Agent on site',
            self::Monitoring => 'Monitoring',
            self::Completed => 'Completed',
        };
    }

    /** Whether nothing more will happen to it. */
    public function finished(): bool
    {
        return in_array($this, [self::Passed, self::Failed, self::Completed], true);
    }

    /** @return list<string> */
    public static function finishedValues(): array
    {
        return [self::Passed->value, self::Failed->value, self::Completed->value];
    }
}
