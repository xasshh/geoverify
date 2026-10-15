<?php

declare(strict_types=1);

namespace App\Domain\Election\Actions;

use App\Domain\Election\Models\PollingUnit;
use App\Domain\Election\Models\PollingUnitCheck;
use App\Domain\Field\Actions\FieldMessaging;
use App\Domain\Media\Actions\StorePhotograph;
use App\Domain\Media\Models\Media;
use App\Domain\Verification\Models\VerificationEvent;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A polling unit check, from the supervisor sending an officer to the
 * supervisor's word on what they found.
 *
 * Every step appends to verification_events against the unit. Nothing an
 * officer filed is overwritten: a return closes the check with the reason and
 * opens the next one for the same officer, and checking a unit again later is
 * a new check. The unit's confirmed point is the arrival position of the check
 * a supervisor accepted, and its boundary ids are resolved from that point.
 */
final class ManagePollingUnitChecks
{
    public function __construct(private readonly FieldMessaging $messages) {}

    /**
     * Sends one officer to a set of units. A unit that already has an open
     * check is left alone (and counted), so assigning a whole ward twice is
     * harmless.
     *
     * @param  list<int>  $unitIds
     * @return array{assigned: int, skipped: int}
     */
    public function assign(int $campaignId, array $unitIds, User $agent, User $supervisor): array
    {
        if (! $supervisor->supervises()) {
            throw new RuntimeException('Only a supervisor sends an officer.');
        }

        if (! $agent->capturesInTheField()) {
            throw new RuntimeException("{$agent->name} is not an active field officer.");
        }

        if ($unitIds === []) {
            throw new RuntimeException('Choose at least one polling unit.');
        }

        return DB::transaction(function () use ($campaignId, $unitIds, $agent, $supervisor): array {
            $units = PollingUnit::query()
                ->where('campaign_id', $campaignId)
                ->whereIn('id', $unitIds)
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('polling_unit_checks as c')
                    ->whereColumn('c.polling_unit_id', 'polling_units.id')
                    ->whereIn('c.status', [PollingUnitCheck::ASSIGNED, PollingUnitCheck::SUBMITTED]))
                ->lockForUpdate()
                ->get();

            foreach ($units as $unit) {
                $this->open($unit, $agent, $supervisor);
            }

            if ($units->isNotEmpty()) {
                $this->messages->toOfficer($supervisor, $agent, sprintf(
                    '%d polling %s to check. They are on your Today screen.',
                    $units->count(),
                    $units->count() === 1 ? 'unit' : 'units',
                ));
            }

            return ['assigned' => $units->count(), 'skipped' => count(array_unique($unitIds)) - $units->count()];
        });
    }

    /**
     * Takes an officer's open checks back, before they arrive: for when the
     * wrong person was sent, or somebody is off sick.
     */
    public function withdraw(PollingUnitCheck $check, User $supervisor): void
    {
        if (! $supervisor->supervises()) {
            throw new RuntimeException('Only a supervisor withdraws a check.');
        }

        DB::transaction(function () use ($check, $supervisor): void {
            /** @var PollingUnitCheck $fresh */
            $fresh = PollingUnitCheck::query()->whereKey($check->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== PollingUnitCheck::ASSIGNED || $fresh->arrived_at !== null) {
                throw new RuntimeException('Only a check the officer has not started can be withdrawn.');
            }

            $fresh->update([
                'status' => PollingUnitCheck::RETURNED,
                'reviewed_by' => $supervisor->id,
                'reviewed_at' => now(),
                'review_note' => 'Withdrawn before the officer arrived.',
            ]);

            $unit = $fresh->unit()->lockForUpdate()->firstOrFail();
            $unit->update(['status' => $unit->settled_by_check_id === null ? PollingUnit::LISTED : $this->settledStatus($unit)]);

            VerificationEvent::record($unit, 'polling_unit.check_withdrawn', $supervisor, ['check_id' => $fresh->id]);
        });
    }

    /** The officer says they are at the unit. Distance from the listed point is measured in PostGIS. */
    public function arrive(PollingUnitCheck $check, User $agent, float $longitude, float $latitude, ?float $accuracyM): PollingUnitCheck
    {
        $this->assertAgent($check, $agent);

        if ($check->arrived_at !== null) {
            return $check;
        }

        if ($check->status !== PollingUnitCheck::ASSIGNED) {
            throw new RuntimeException('That check is closed.');
        }

        if ($latitude < 4.0 || $latitude > 14.0 || $longitude < 2.5 || $longitude > 15.0) {
            throw new RuntimeException('That position is not in Nigeria. Wait for the GPS to settle and try again.');
        }

        DB::update(<<<'SQL'
            UPDATE polling_unit_checks c
               SET arrived_at = now(),
                   arrival_accuracy_m = ?,
                   arrival_position = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
                   arrival_distance_m = (SELECT round(ST_Distance(u.listed_point, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography)::numeric, 1)
                                           FROM polling_units u WHERE u.id = c.polling_unit_id),
                   updated_at = now()
             WHERE c.id = ? AND c.arrived_at IS NULL
        SQL, [$accuracyM, $longitude, $latitude, $longitude, $latitude, $check->id]);

        VerificationEvent::record($check->unit()->firstOrFail(), 'polling_unit.arrived', $agent, [
            'check_id' => $check->id,
            'distance_m' => $check->refresh()->arrival_distance_m,
            'accuracy_m' => $accuracyM,
        ]);

        return $check;
    }

    /** @param  'site'|'entrance'|'other'  $angle */
    public function photograph(PollingUnitCheck $check, User $agent, UploadedFile $file, string $angle, string $clientUuid, ?float $longitude, ?float $latitude, StorePhotograph $storer): Media
    {
        $this->assertAgent($check, $agent);

        if ($check->status !== PollingUnitCheck::ASSIGNED) {
            throw new RuntimeException('The report is already filed.');
        }

        if ($check->arrived_at === null) {
            throw new RuntimeException('Record that you are at the unit first.');
        }

        $kind = match ($angle) {
            'site' => Media::KIND_PU_SITE,
            'entrance' => Media::KIND_PU_ENTRANCE,
            default => Media::KIND_PU_OTHER,
        };

        $media = $storer->store($file, $check, $kind, $agent, $clientUuid, $longitude, $latitude);

        DB::update(<<<'SQL'
            UPDATE media m
               SET distance_from_subject_m = round(ST_Distance(COALESCE(m.capture_point, m.device_reported_point), c.arrival_position)::numeric, 2)
              FROM polling_unit_checks c
             WHERE m.id = ? AND c.id = ? AND c.arrival_position IS NOT NULL
               AND COALESCE(m.capture_point, m.device_reported_point) IS NOT NULL
        SQL, [$media->id, $check->id]);

        return $media->refresh();
    }

    /**
     * The report, once, idempotent on the handset's uuid.
     *
     * @param  array<string, array{answer?: string|null, detail?: string|null}>  $answers
     */
    public function submit(PollingUnitCheck $check, User $agent, string $reportUuid, string $found, array $answers, ?string $notes): PollingUnitCheck
    {
        $this->assertAgent($check, $agent);

        if (! Str::isUuid($reportUuid)) {
            throw new RuntimeException('A report needs the uuid the handset gave it.');
        }

        if (! array_key_exists($found, PollingUnitCheck::FOUND)) {
            throw new RuntimeException('Say whether you found the polling unit.');
        }

        return DB::transaction(function () use ($check, $agent, $reportUuid, $found, $answers, $notes): PollingUnitCheck {
            /** @var PollingUnitCheck $fresh */
            $fresh = PollingUnitCheck::query()->whereKey($check->id)->lockForUpdate()->firstOrFail();

            if ($fresh->report_uuid === $reportUuid) {
                return $fresh;
            }

            if ($fresh->status !== PollingUnitCheck::ASSIGNED) {
                throw new RuntimeException('That check already has a report.');
            }

            if ($fresh->arrived_at === null) {
                throw new RuntimeException('Record that you are at the unit before the report.');
            }

            if ($fresh->photos()->where('kind', Media::KIND_PU_SITE)->doesntExist()) {
                throw new RuntimeException($found === 'not_found'
                    ? 'Take a photograph of where the unit should be.'
                    : 'Take at least one photograph of the polling unit from outside.');
            }

            $clean = $found === 'not_found' ? [] : $this->answers($answers);
            $note = $notes === null || trim($notes) === '' ? null : mb_substr(trim($notes), 0, 2000);

            if ($found === 'not_found' && ($note === null || mb_strlen($note) < 10)) {
                throw new RuntimeException('Say where you looked and who you asked, in the notes.');
            }

            $fresh->update([
                'status' => PollingUnitCheck::SUBMITTED,
                'report_uuid' => $reportUuid,
                'found' => $found,
                'answers' => $clean,
                'notes' => $note,
                'submitted_at' => now(),
            ]);

            $unit = $fresh->unit()->lockForUpdate()->firstOrFail();
            $unit->update(['status' => PollingUnit::FILED]);

            VerificationEvent::record($unit, 'polling_unit.check_filed', $agent, [
                'check_id' => $fresh->id,
                'found' => $found,
                'concerns' => self::concernsIn($clean),
                'photos' => $fresh->photos()->count(),
            ]);

            return $fresh;
        });
    }

    /**
     * The supervisor's word. Accepting settles the unit: confirmed at the
     * officer's position (or not found). Sending back closes the check with
     * the reason and opens the next one for the same officer.
     */
    public function review(PollingUnitCheck $check, User $supervisor, bool $accept, ?string $note): PollingUnitCheck
    {
        if (! $supervisor->supervises()) {
            throw new RuntimeException('Only a supervisor reviews a check.');
        }

        $note = $note === null ? null : trim($note);

        if (! $accept && ($note === null || mb_strlen($note) < 10)) {
            throw new RuntimeException('Tell the officer what to redo, in a sentence.');
        }

        return DB::transaction(function () use ($check, $supervisor, $accept, $note): PollingUnitCheck {
            /** @var PollingUnitCheck $fresh */
            $fresh = PollingUnitCheck::query()->whereKey($check->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== PollingUnitCheck::SUBMITTED) {
                throw new RuntimeException('There is no report waiting on that check.');
            }

            if ($fresh->agent_id === $supervisor->id) {
                throw new RuntimeException('Somebody else reviews your own check.');
            }

            $fresh->update([
                'status' => $accept ? PollingUnitCheck::ACCEPTED : PollingUnitCheck::RETURNED,
                'reviewed_by' => $supervisor->id,
                'reviewed_at' => now(),
                'review_note' => $note === null || $note === '' ? null : mb_substr($note, 0, 500),
            ]);

            /** @var PollingUnit $unit */
            $unit = PollingUnit::query()->whereKey($fresh->polling_unit_id)->lockForUpdate()->firstOrFail();

            if (! $accept) {
                $agent = $fresh->agent()->firstOrFail();
                $next = $this->open($unit, $agent, $supervisor);

                VerificationEvent::record($unit, 'polling_unit.check_returned', $supervisor, [
                    'check_id' => $fresh->id,
                    'next_check_id' => $next->id,
                    'reason' => $note,
                ]);

                $this->messages->toOfficer($supervisor, $agent, "Please check {$unit->name} ({$unit->inec_code}) again: {$note}");

                return $fresh;
            }

            if ($fresh->found === 'not_found') {
                $unit->update([
                    'status' => PollingUnit::NOT_FOUND,
                    'settled_at' => now(),
                    'settled_by_check_id' => $fresh->id,
                ]);
            } else {
                DB::update(<<<'SQL'
                    UPDATE polling_units u
                       SET status = 'confirmed',
                           confirmed_point = c.arrival_position,
                           confirmed_accuracy_m = c.arrival_accuracy_m,
                           listed_distance_m = c.arrival_distance_m,
                           settled_at = now(),
                           settled_by_check_id = c.id,
                           state_id = (SELECT b.id FROM admin_boundaries b WHERE b.level = 'state' AND ST_Contains(b.boundary, c.arrival_position::geometry) LIMIT 1),
                           lga_id = (SELECT b.id FROM admin_boundaries b WHERE b.level = 'lga' AND ST_Contains(b.boundary, c.arrival_position::geometry) LIMIT 1),
                           ward_id = (SELECT b.id FROM admin_boundaries b WHERE b.level = 'ward' AND ST_Contains(b.boundary, c.arrival_position::geometry) LIMIT 1),
                           updated_at = now()
                      FROM polling_unit_checks c
                     WHERE u.id = ? AND c.id = ?
                SQL, [$unit->id, $fresh->id]);
            }

            VerificationEvent::record($unit, 'polling_unit.check_accepted', $supervisor, [
                'check_id' => $fresh->id,
                'found' => $fresh->found,
                'distance_m' => $fresh->arrival_distance_m,
            ]);

            return $fresh;
        });
    }

    /**
     * The checklist keys with a "no" or worse, for the log and the summary.
     *
     * @param  array<string, array{answer: string, detail: string|null}>  $answers
     * @return list<string>
     */
    public static function concernsIn(array $answers): array
    {
        $concerns = [];

        foreach (PollingUnitCheck::CONCERNS as $key => $bad) {
            if (in_array($answers[$key]['answer'] ?? null, $bad, true)) {
                $concerns[] = $key;
            }
        }

        return $concerns;
    }

    private function open(PollingUnit $unit, User $agent, User $supervisor): PollingUnitCheck
    {
        $check = PollingUnitCheck::query()->create([
            'polling_unit_id' => $unit->id,
            'campaign_id' => $unit->campaign_id,
            'status' => PollingUnitCheck::ASSIGNED,
            'agent_id' => $agent->id,
            'assigned_by' => $supervisor->id,
            'assigned_at' => now(),
        ]);

        $unit->update(['status' => PollingUnit::ASSIGNED]);

        VerificationEvent::record($unit, 'polling_unit.check_assigned', $supervisor, [
            'check_id' => $check->id,
            'agent_id' => $agent->id,
            'agent_ref' => $agent->staff_ref,
        ]);

        return $check;
    }

    private function settledStatus(PollingUnit $unit): string
    {
        return PollingUnitCheck::query()->whereKey($unit->settled_by_check_id)->value('found') === 'not_found'
            ? PollingUnit::NOT_FOUND
            : PollingUnit::CONFIRMED;
    }

    /**
     * Every question answered from its own options, and nothing else.
     *
     * @param  array<string, array{answer?: string|null, detail?: string|null}>  $answers
     * @return array<string, array{answer: string, detail: string|null}>
     */
    private function answers(array $answers): array
    {
        $clean = [];

        foreach (PollingUnitCheck::CHECKLIST as $key => [$label, $options]) {
            $answer = $answers[$key]['answer'] ?? null;

            if (! is_string($answer) || ! array_key_exists($answer, $options)) {
                throw new RuntimeException("Answer \"{$label}\".");
            }

            $detail = isset($answers[$key]['detail']) ? trim((string) $answers[$key]['detail']) : '';
            $clean[$key] = ['answer' => $answer, 'detail' => $detail === '' ? null : mb_substr($detail, 0, 200)];
        }

        if (array_diff(array_keys($answers), array_keys(PollingUnitCheck::CHECKLIST)) !== []) {
            throw new RuntimeException('Answer the questions on the list, and only those.');
        }

        return $clean;
    }

    private function assertAgent(PollingUnitCheck $check, User $agent): void
    {
        if ($check->agent_id !== $agent->id) {
            throw new RuntimeException('That check is somebody else’s.');
        }
    }
}
