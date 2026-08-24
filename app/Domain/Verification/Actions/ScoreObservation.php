<?php

declare(strict_types=1);

namespace App\Domain\Verification\Actions;

use App\Domain\Registry\Models\StructureObservation;
use App\Domain\Verification\Data\CaptureFacts;
use App\Domain\Verification\Models\ObservationSignal;
use App\Domain\Verification\Models\VerificationEvent;
use App\Domain\Verification\Signals\AccuracyVarianceSignal;
use App\Domain\Verification\Signals\CaptureIntervalSignal;
use App\Domain\Verification\Signals\CellContainmentSignal;
use App\Domain\Verification\Signals\ImpliedSpeedSignal;
use App\Domain\Verification\Signals\MockLocationSignal;
use App\Domain\Verification\Signals\NetworkDivergenceSignal;
use App\Domain\Verification\Signals\PhotographProvenanceSignal;
use App\Domain\Verification\Signals\PositionAccuracySignal;
use App\Domain\Verification\Signals\SatelliteVisibilitySignal;
use App\Domain\Verification\Signals\Signal;
use App\Domain\Verification\Signals\SignalResult;
use App\Domain\Verification\Signals\TraceNaturalnessSignal;
use Illuminate\Support\Facades\DB;

/**
 * Turns a capture into a number a supervisor can sort by.
 *
 * The score starts at 100 and every signal spends against it: a failure costs
 * that signal's full weight, a warning costs half, and a signal that had nothing
 * to read costs nothing. The weights sum to 100, so the score is a percentage
 * rather than a scale someone has to learn, and a capture that fails everything
 * lands on zero rather than somewhere arbitrary.
 *
 * Deliberately not a machine learning model. A supervisor returning a day's work
 * to an officer has to be able to say why, and "trace_naturalness took 14 off
 * because the walk was a straight line at a constant step" is a sentence they can
 * say out loud. A gradient is not.
 */
final class ScoreObservation
{
    /** @var list<class-string<Signal>> */
    private const SIGNALS = [
        MockLocationSignal::class,
        TraceNaturalnessSignal::class,
        NetworkDivergenceSignal::class,
        PositionAccuracySignal::class,
        AccuracyVarianceSignal::class,
        SatelliteVisibilitySignal::class,
        PhotographProvenanceSignal::class,
        CellContainmentSignal::class,
        CaptureIntervalSignal::class,
        ImpliedSpeedSignal::class,
    ];

    public function __construct(private readonly AssembleCaptureFacts $facts) {}

    /**
     * Scores the capture, stores what each signal concluded, and returns the score.
     *
     * The signal rows are replaced rather than added to. They are a machine's
     * current reading, not a record of anything a person did: what a supervisor
     * decided, and what the score was when they decided it, goes to
     * verification_events, which is the log that matters.
     */
    public function __invoke(StructureObservation $observation): int
    {
        $facts = ($this->facts)($observation);

        return DB::transaction(function () use ($observation, $facts): int {
            $readings = $this->read($facts);
            $score = $this->scoreFrom($readings);

            ObservationSignal::query()
                ->where('structure_observation_id', $observation->id)
                ->delete();

            foreach ($readings as $reading) {
                ObservationSignal::query()->create([
                    'structure_observation_id' => $observation->id,
                    'signal' => $reading['signal']->key(),
                    'question' => $reading['signal']->question(),
                    'verdict' => $reading['result']->verdict,
                    'weight' => $reading['signal']->weight(),
                    'deduction' => $reading['deduction'],
                    'message' => $reading['result']->message,
                    'evidence' => $reading['result']->evidence === []
                        ? null
                        : $reading['result']->evidence,
                ]);
            }

            $was = $observation->confidence_score;
            $observation->update(['confidence_score' => $score]);

            // Scored by the system, so the actor is the system. A score that
            // appeared with no record of appearing is not evidence of anything.
            VerificationEvent::record($observation, 'observation.scored', null, [
                'score' => $score,
                'previous_score' => $was,
                'flags' => array_values(array_map(
                    static fn (array $reading): array => [
                        'signal' => $reading['signal']->key(),
                        'verdict' => $reading['result']->verdict->value,
                        'deduction' => $reading['deduction'],
                    ],
                    array_filter(
                        $readings,
                        static fn (array $reading): bool => $reading['result']->verdict->isFlag(),
                    ),
                )),
            ], VerificationEvent::ACTOR_SYSTEM);

            return $score;
        });
    }

    /**
     * Runs every signal over the assembled facts.
     *
     * @return list<array{signal: Signal, result: SignalResult, deduction: int}>
     */
    private function read(CaptureFacts $facts): array
    {
        $readings = [];

        foreach (self::SIGNALS as $class) {
            $signal = new $class;
            $result = $signal->evaluate($facts);

            $readings[] = [
                'signal' => $signal,
                'result' => $result,
                // Rounded up, so a half weight warning on an odd weight costs
                // the officer the larger half rather than the system.
                'deduction' => (int) ceil($signal->weight() * $result->verdict->penalty()),
            ];
        }

        return $readings;
    }

    /**
     * @param  list<array{signal: Signal, result: SignalResult, deduction: int}>  $readings
     */
    private function scoreFrom(array $readings): int
    {
        $spent = array_sum(array_column($readings, 'deduction'));

        return max(0, min(100, 100 - (int) $spent));
    }

    /**
     * The signals in the order they are read.
     *
     * Exposed so the weights can be asserted to sum to 100. That invariant is
     * what makes the score a percentage rather than a scale someone has to
     * learn, and it is one careless new signal away from quietly breaking.
     *
     * @return list<Signal>
     */
    public static function signals(): array
    {
        return array_map(static fn (string $class): Signal => new $class, self::SIGNALS);
    }
}
