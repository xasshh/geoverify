<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Investment\Actions\ReadOpportunities;
use App\Domain\Registry\Actions\ResolveNextRung;
use App\Domain\Registry\Models\Enterprise;
use App\Domain\Verification\Actions\ResolveServiceZone;
use App\Domain\Verification\Actions\ResolveVerificationPrice;
use App\Domain\Verification\Enums\OrderStatus;
use App\Domain\Verification\Enums\OrderUrgency;
use App\Domain\Verification\Models\VerificationOrder;
use App\Http\Controllers\Portal\Concerns\ActsForBusiness;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Verification: what this business has established, what it can establish
 * next and for how much, and every certificate it holds.
 */
final class VerificationController
{
    use ActsForBusiness;

    public function show(
        Request $request,
        Enterprise $enterprise,
        ReadOpportunities $ladder,
        ResolveNextRung $nextRung,
        ResolveServiceZone $zones,
        ResolveVerificationPrice $prices,
    ): Response {
        $membership = $this->controlling($request, $enterprise);
        $enterprise->loadMissing('structure');

        $orders = VerificationOrder::query()
            ->where('enterprise_id', $enterprise->id)
            ->orderBy('created_at')
            ->get();

        $rungs = $ladder->rungs(
            (string) $enterprise->structure->origin,
            (string) $enterprise->structure->status,
            Carbon::parse($enterprise->captured_at),
            $orders->map(static fn (VerificationOrder $o): object => (object) [
                'tier' => $o->tier,
                'status' => $o->status->value,
                'completed_at' => $o->completed_at?->toIso8601String(),
            ])->all(),
        );

        $zone = $zones($enterprise->structure);
        $offers = [];

        foreach (['location_verified' => 'Location verification', 'operations_verified' => 'Operations verification'] as $tier => $name) {
            try {
                $price = $prices($tier, OrderUrgency::Standard, $zone);
            } catch (RuntimeException) {
                continue;
            }

            $offers[] = [
                'tier' => $tier,
                'name' => $name,
                'feeNaira' => (int) round($price->amount_minor / 100),
                'within' => $price->sla_working_days.' working days',
                'live' => $orders->contains(static fn (VerificationOrder $o): bool => $o->tier === $tier && ! $o->status->isSettled()),
            ];
        }

        return Inertia::render('portal/Verification', [
            'business' => ['id' => $enterprise->id, 'name' => $enterprise->trading_name],
            'canBuy' => $membership->role->spends(),
            'rungs' => $rungs,
            'next' => $nextRung($enterprise),
            'offers' => $offers,
            'certificates' => $orders
                ->filter(static fn (VerificationOrder $o): bool => $o->status === OrderStatus::Completed)
                ->sortByDesc('completed_at')
                ->map(static fn (VerificationOrder $o): array => [
                    'orderId' => $o->id,
                    'reference' => $o->reference,
                    'tier' => str_replace('_', ' ', $o->tier),
                    'completedAt' => $o->completed_at?->toDateString(),
                    'ownOrder' => $o->party_id !== null,
                ])
                ->values()
                ->all(),
        ]);
    }
}
