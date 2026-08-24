<?php

declare(strict_types=1);

use App\Domain\Verification\Actions\ScoreObservation;
use App\Domain\Verification\Data\CaptureFacts;
use App\Domain\Verification\Enums\ReviewQuestion;
use App\Domain\Verification\Enums\Verdict;
use App\Domain\Verification\Signals\AccuracyVarianceSignal;
use App\Domain\Verification\Signals\CaptureIntervalSignal;
use App\Domain\Verification\Signals\CellContainmentSignal;
use App\Domain\Verification\Signals\ImpliedSpeedSignal;
use App\Domain\Verification\Signals\MockLocationSignal;
use App\Domain\Verification\Signals\NetworkDivergenceSignal;
use App\Domain\Verification\Signals\PhotographProvenanceSignal;
use App\Domain\Verification\Signals\PositionAccuracySignal;
use App\Domain\Verification\Signals\SatelliteVisibilitySignal;
use App\Domain\Verification\Signals\TraceNaturalnessSignal;

/*
|--------------------------------------------------------------------------
| Confidence signals
|--------------------------------------------------------------------------
|
| Each signal is a class that takes assembled facts and returns a verdict, so
| each is tested here with no database at all. Whether the facts themselves are
| assembled correctly is a different question, asked in ConfidenceScoringTest
| against real PostGIS.
|
*/

/**
 * A capture with nothing wrong with it.
 *
 * Every test below names only the facts it is about and inherits the rest, so a
 * test that fails is failing for the reason it names.
 */
function facts(mixed ...$overrides): CaptureFacts
{
    /** @var array<string, mixed> $clean */
    $clean = [
        'fixCount' => 12,
        'meanAccuracyM' => 3.4,
        'worstAccuracyM' => 4.8,
        'fixesOverFiveMetres' => 0,
        'accuracyStdDev' => 0.9,
        'distinctAccuracyValues' => 9,
        'mockFixCount' => 0,
        'zeroSatelliteFixCount' => 0,
        'satelliteAwareFixCount' => 12,
        'worstNetworkDivergenceM' => 120.0,
        'networkComparableFixCount' => 12,
        'traceLengthM' => 640.0,
        'traceEndToEndM' => 300.0,
        'traceVertexCount' => 12,
        'segmentLengthStdDev' => 18.0,
        'meanSegmentLengthM' => 58.0,
        'fastestImpliedSpeedMps' => 1.6,
        'captureCount' => 9,
        'captureIntervalStdDev' => 190.0,
        'meanCaptureIntervalSeconds' => 420.0,
        'insideAssignedCell' => true,
        'metresOutsideCell' => 0.0,
        'photographCount' => 3,
        'photographsFromDeviceCamera' => 3,
        'photographsWithoutProvenance' => 0,
        'worstPhotographDistanceM' => 12.0,
    ];

    /** @var array<string, mixed> $merged */
    $merged = array_merge($clean, $overrides);

    return new CaptureFacts(...$merged);
}

describe('the mock location signal', function (): void {
    it('fails on a single mock fix, because one is enough', function (): void {
        $result = (new MockLocationSignal)->evaluate(facts(mockFixCount: 1));

        expect($result->verdict)->toBe(Verdict::Fail)
            ->and($result->message)->toContain('1 fix');
    });

    it('passes when no mock provider was reported', function (): void {
        expect((new MockLocationSignal)->evaluate(facts())->verdict)->toBe(Verdict::Ok);
    });

    it('knows nothing when there were no fixes at all', function (): void {
        expect((new MockLocationSignal)->evaluate(facts(fixCount: 0))->verdict)
            ->toBe(Verdict::Unknown);
    });
});

describe('the trace naturalness signal', function (): void {
    it('fails a trace that is both straight and evenly stepped', function (): void {
        $result = (new TraceNaturalnessSignal)->evaluate(facts(
            traceLengthM: 300.5,
            traceEndToEndM: 300.0,
            segmentLengthStdDev: 0.2,
            meanSegmentLengthM: 27.0,
        ));

        expect($result->verdict)->toBe(Verdict::Fail);
    });

    it('only warns on a straight walk with uneven steps, because roads are straight', function (): void {
        $result = (new TraceNaturalnessSignal)->evaluate(facts(
            traceLengthM: 300.5,
            traceEndToEndM: 300.0,
            segmentLengthStdDev: 14.0,
            meanSegmentLengthM: 27.0,
        ));

        expect($result->verdict)->toBe(Verdict::Warn);
    });

    it('passes a walk that turns and varies', function (): void {
        expect((new TraceNaturalnessSignal)->evaluate(facts())->verdict)->toBe(Verdict::Ok);
    });

    it('refuses to judge a trace with too few vertices', function (): void {
        expect((new TraceNaturalnessSignal)->evaluate(facts(traceVertexCount: 3))->verdict)
            ->toBe(Verdict::Unknown);
    });

    it('refuses to judge a loop, where end to end says nothing', function (): void {
        $result = (new TraceNaturalnessSignal)->evaluate(facts(
            traceLengthM: 800.0,
            traceEndToEndM: 0.4,
            segmentLengthStdDev: 20.0,
            meanSegmentLengthM: 60.0,
        ));

        expect($result->verdict)->toBe(Verdict::Ok)
            ->and($result->evidence['sinuosity'])->toBeNull();
    });
});

describe('the network divergence signal', function (): void {
    it('fails when the two positions are kilometres apart', function (): void {
        $result = (new NetworkDivergenceSignal)->evaluate(facts(worstNetworkDivergenceM: 4_200.0));

        expect($result->verdict)->toBe(Verdict::Fail)
            ->and($result->message)->toContain('4.2 km');
    });

    it('warns at a few hundred metres', function (): void {
        expect((new NetworkDivergenceSignal)->evaluate(facts(worstNetworkDivergenceM: 900.0))->verdict)
            ->toBe(Verdict::Warn);
    });

    it('accepts the coarseness of a tower derived position', function (): void {
        expect((new NetworkDivergenceSignal)->evaluate(facts(worstNetworkDivergenceM: 380.0))->verdict)
            ->toBe(Verdict::Ok);
    });

    it('does not punish a device that reports no network position', function (): void {
        $result = (new NetworkDivergenceSignal)->evaluate(facts(
            worstNetworkDivergenceM: null,
            networkComparableFixCount: 0,
        ));

        expect($result->verdict)->toBe(Verdict::Unknown)
            ->and($result->verdict->penalty())->toBe(0.0);
    });
});

describe('the position accuracy signal', function (): void {
    it('fails accuracy that cannot site a building', function (): void {
        expect((new PositionAccuracySignal)->evaluate(facts(meanAccuracyM: 44.0))->verdict)
            ->toBe(Verdict::Fail);
    });

    it('warns on loose fixes and says how many', function (): void {
        $result = (new PositionAccuracySignal)->evaluate(facts(
            fixesOverFiveMetres: 2,
            worstAccuracyM: 9.1,
        ));

        expect($result->verdict)->toBe(Verdict::Warn)
            ->and($result->message)->toContain('2 of 12 fixes');
    });

    it('passes a tight session', function (): void {
        expect((new PositionAccuracySignal)->evaluate(facts())->verdict)->toBe(Verdict::Ok);
    });
});

describe('the accuracy variance signal', function (): void {
    it('fails an accuracy that never once changed', function (): void {
        $result = (new AccuracyVarianceSignal)->evaluate(facts(
            accuracyStdDev: 0.0,
            distinctAccuracyValues: 1,
        ));

        expect($result->verdict)->toBe(Verdict::Fail)
            ->and($result->message)->toContain('12 fixes');
    });

    it('warns on an accuracy that barely moved', function (): void {
        expect((new AccuracyVarianceSignal)->evaluate(facts(
            accuracyStdDev: 0.01,
            distinctAccuracyValues: 2,
        ))->verdict)->toBe(Verdict::Warn);
    });

    it('will not call a coincidence across four fixes a finding', function (): void {
        expect((new AccuracyVarianceSignal)->evaluate(facts(
            fixCount: 4,
            distinctAccuracyValues: 1,
            accuracyStdDev: 0.0,
        ))->verdict)->toBe(Verdict::Unknown);
    });
});

describe('the satellite visibility signal', function (): void {
    it('fails a session that claimed every position with nothing overhead', function (): void {
        expect((new SatelliteVisibilitySignal)->evaluate(facts(
            zeroSatelliteFixCount: 12,
            satelliteAwareFixCount: 12,
        ))->verdict)->toBe(Verdict::Fail);
    });

    it('warns when some fixes saw nothing', function (): void {
        expect((new SatelliteVisibilitySignal)->evaluate(facts(zeroSatelliteFixCount: 3))->verdict)
            ->toBe(Verdict::Warn);
    });

    it('does not punish a handset that cannot report a count', function (): void {
        expect((new SatelliteVisibilitySignal)->evaluate(facts(satelliteAwareFixCount: 0))->verdict)
            ->toBe(Verdict::Unknown);
    });
});

describe('the photograph provenance signal', function (): void {
    it('fails a capture with no photograph at all', function (): void {
        expect((new PhotographProvenanceSignal)->evaluate(facts(photographCount: 0))->verdict)
            ->toBe(Verdict::Fail);
    });

    it('fails when nothing carries camera metadata', function (): void {
        expect((new PhotographProvenanceSignal)->evaluate(facts(
            photographsFromDeviceCamera: 0,
            photographsWithoutProvenance: 3,
        ))->verdict)->toBe(Verdict::Fail);
    });

    it('warns when only some do', function (): void {
        expect((new PhotographProvenanceSignal)->evaluate(facts(
            photographsFromDeviceCamera: 2,
            photographsWithoutProvenance: 1,
        ))->verdict)->toBe(Verdict::Warn);
    });

    it('warns about a facade photographed from down the road', function (): void {
        $result = (new PhotographProvenanceSignal)->evaluate(facts(worstPhotographDistanceM: 210.0));

        expect($result->verdict)->toBe(Verdict::Warn)
            ->and($result->message)->toContain('210 m');
    });

    it('passes photographs taken on the device at the subject', function (): void {
        expect((new PhotographProvenanceSignal)->evaluate(facts())->verdict)->toBe(Verdict::Ok);
    });
});

describe('the cell containment signal', function (): void {
    it('fails a structure captured on ground that was not assigned', function (): void {
        expect((new CellContainmentSignal)->evaluate(facts(
            insideAssignedCell: false,
            metresOutsideCell: 900.0,
        ))->verdict)->toBe(Verdict::Fail);
    });

    it('forgives a doorway that straddles an H3 edge', function (): void {
        expect((new CellContainmentSignal)->evaluate(facts(
            insideAssignedCell: false,
            metresOutsideCell: 8.0,
        ))->verdict)->toBe(Verdict::Ok);
    });

    it('warns in between', function (): void {
        expect((new CellContainmentSignal)->evaluate(facts(
            insideAssignedCell: false,
            metresOutsideCell: 120.0,
        ))->verdict)->toBe(Verdict::Warn);
    });

    it('has nothing to say about a capture with no assignment', function (): void {
        expect((new CellContainmentSignal)->evaluate(facts(insideAssignedCell: null))->verdict)
            ->toBe(Verdict::Unknown);
    });
});

describe('the capture interval signal', function (): void {
    it('fails captures that arrived on a metronome', function (): void {
        $result = (new CaptureIntervalSignal)->evaluate(facts(
            meanCaptureIntervalSeconds: 180.0,
            captureIntervalStdDev: 2.0,
        ));

        expect($result->verdict)->toBe(Verdict::Fail)
            ->and($result->message)->toContain('180 s');
    });

    it('warns at a pace too quick for a doorstep interview', function (): void {
        expect((new CaptureIntervalSignal)->evaluate(facts(
            meanCaptureIntervalSeconds: 14.0,
            captureIntervalStdDev: 6.0,
        ))->verdict)->toBe(Verdict::Warn);
    });

    it('passes an uneven human pace', function (): void {
        expect((new CaptureIntervalSignal)->evaluate(facts())->verdict)->toBe(Verdict::Ok);
    });

    it('reads no rhythm from three captures', function (): void {
        expect((new CaptureIntervalSignal)->evaluate(facts(captureCount: 3))->verdict)
            ->toBe(Verdict::Unknown);
    });
});

describe('the implied speed signal', function (): void {
    it('fails a speed no journey explains', function (): void {
        $result = (new ImpliedSpeedSignal)->evaluate(facts(fastestImpliedSpeedMps: 140.0));

        expect($result->verdict)->toBe(Verdict::Fail)
            ->and($result->message)->toContain('504 km/h');
    });

    it('only warns at vehicle speed, because officers take vehicles', function (): void {
        expect((new ImpliedSpeedSignal)->evaluate(facts(fastestImpliedSpeedMps: 12.0))->verdict)
            ->toBe(Verdict::Warn);
    });

    it('passes a walking pace', function (): void {
        expect((new ImpliedSpeedSignal)->evaluate(facts())->verdict)->toBe(Verdict::Ok);
    });
});

describe('the signal set as a whole', function (): void {
    it('weighs exactly 100, so the score is a percentage', function (): void {
        $total = array_sum(array_map(
            static fn ($signal): int => $signal->weight(),
            ScoreObservation::signals(),
        ));

        expect($total)->toBe(100);
    });

    it('gives every signal a distinct key, because the key is stored', function (): void {
        $keys = array_map(static fn ($signal): string => $signal->key(), ScoreObservation::signals());

        expect($keys)->toHaveCount(count(array_unique($keys)));
    });

    it('asks all three questions', function (): void {
        $questions = array_unique(array_map(
            static fn ($signal): string => $signal->question()->value,
            ScoreObservation::signals(),
        ));

        expect($questions)->toHaveCount(count(ReviewQuestion::cases()));
    });

    it('passes a clean capture with nothing flagged', function (): void {
        foreach (ScoreObservation::signals() as $signal) {
            expect($signal->evaluate(facts())->verdict->isFlag())
                ->toBeFalse("{$signal->key()} flagged a clean capture");
        }
    });
});
