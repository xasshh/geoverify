<?php

declare(strict_types=1);

namespace App\Domain\Verification\Data;

/**
 * Everything the signals are allowed to read about one capture.
 *
 * Assembled once, in PostGIS, and then passed around as plain numbers. Signals
 * take this rather than an Eloquent model on purpose: a signal that could run a
 * query could run a different query per capture, and a queue of 200 would stop
 * being explainable. It also makes every signal testable by construction, with
 * no database at all.
 *
 * A null means the device never reported it. It does not mean zero, and no
 * signal is allowed to read it as zero.
 */
final readonly class CaptureFacts
{
    /**
     * @param  int  $fixCount  Fixes in the session behind this capture.
     * @param  float|null  $meanAccuracyM  Mean reported accuracy across those fixes.
     * @param  float|null  $worstAccuracyM  The single worst reported accuracy.
     * @param  int  $fixesOverFiveMetres  How many fixes were worse than 5 m.
     * @param  float|null  $accuracyStdDev  Zero here means an accuracy that never moved.
     * @param  int  $distinctAccuracyValues  One value across many fixes is a generator, not a receiver.
     * @param  int  $mockFixCount  Fixes where the device itself reported a mock provider.
     * @param  int  $zeroSatelliteFixCount  Fixes claiming a GNSS position with nothing overhead.
     * @param  int  $satelliteAwareFixCount  Fixes that reported a satellite count at all.
     * @param  float|null  $worstNetworkDivergenceM  Furthest the network position sat from the GNSS one.
     * @param  int  $networkComparableFixCount  Fixes carrying both positions.
     * @param  float|null  $traceLengthM  Walked length of the session trace, on the ellipsoid.
     * @param  float|null  $traceEndToEndM  Straight line from first vertex to last.
     * @param  int  $traceVertexCount  Vertices in the trace.
     * @param  float|null  $segmentLengthStdDev  Zero means every step was the same size.
     * @param  float|null  $meanSegmentLengthM  Mean distance between consecutive vertices.
     * @param  float|null  $fastestImpliedSpeedMps  Fastest implied speed between consecutive fixes.
     * @param  int  $captureCount  Captures the officer made in this session.
     * @param  float|null  $captureIntervalStdDev  Zero across several captures is a script.
     * @param  float|null  $meanCaptureIntervalSeconds  Mean gap between consecutive captures.
     * @param  bool|null  $insideAssignedCell  Whether the structure stands in the cell that was assigned.
     * @param  float|null  $metresOutsideCell  How far outside, when it is outside.
     * @param  int  $photographCount  Photographs attached to this capture.
     * @param  int  $photographsFromDeviceCamera  Of those, how many carry camera metadata.
     * @param  int  $photographsWithoutProvenance  Of those, how many carry none.
     * @param  float|null  $worstPhotographDistanceM  Furthest a photograph was taken from its subject.
     */
    public function __construct(
        public int $fixCount,
        public ?float $meanAccuracyM,
        public ?float $worstAccuracyM,
        public int $fixesOverFiveMetres,
        public ?float $accuracyStdDev,
        public int $distinctAccuracyValues,
        public int $mockFixCount,
        public int $zeroSatelliteFixCount,
        public int $satelliteAwareFixCount,
        public ?float $worstNetworkDivergenceM,
        public int $networkComparableFixCount,
        public ?float $traceLengthM,
        public ?float $traceEndToEndM,
        public int $traceVertexCount,
        public ?float $segmentLengthStdDev,
        public ?float $meanSegmentLengthM,
        public ?float $fastestImpliedSpeedMps,
        public int $captureCount,
        public ?float $captureIntervalStdDev,
        public ?float $meanCaptureIntervalSeconds,
        public ?bool $insideAssignedCell,
        public ?float $metresOutsideCell,
        public int $photographCount,
        public int $photographsFromDeviceCamera,
        public int $photographsWithoutProvenance,
        public ?float $worstPhotographDistanceM,
    ) {}

    /**
     * How much the walked path exceeds the straight line between its ends.
     *
     * A person following a street grid, stopping at doorways, comes out well
     * above 1. A generated line comes out at exactly 1, because it is one.
     */
    public function sinuosity(): ?float
    {
        if ($this->traceLengthM === null || $this->traceEndToEndM === null) {
            return null;
        }

        if ($this->traceEndToEndM < 1.0) {
            // The officer finished where they started, so the ratio is
            // unbounded and says nothing. A loop is not a straight line.
            return null;
        }

        return $this->traceLengthM / $this->traceEndToEndM;
    }

    /**
     * Spread of step sizes as a share of the mean step.
     *
     * A real walk varies: traffic, doorways, a conversation. A synthesised one
     * interpolates evenly, and this comes out near zero.
     */
    public function segmentRegularity(): ?float
    {
        if ($this->segmentLengthStdDev === null || $this->meanSegmentLengthM === null) {
            return null;
        }

        if ($this->meanSegmentLengthM < 0.5) {
            return null;
        }

        return $this->segmentLengthStdDev / $this->meanSegmentLengthM;
    }
}
