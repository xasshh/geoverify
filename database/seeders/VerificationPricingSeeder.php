<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Ledger\Enums\AccountType;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Verification\Enums\OrderUrgency;
use App\Domain\Verification\Enums\ServiceZone;
use App\Domain\Verification\Models\VerificationPrice;
use Illuminate\Database\Seeder;

/**
 * The chart of accounts and the price list.
 *
 * Both are reference data rather than sample data: nothing in the marketplace
 * works without them, so this runs in every environment including production,
 * and every row is written by updateOrCreate so running it twice is safe.
 *
 * Prices are in kobo throughout. Money is never a float here: 15000.00 in
 * binary is not 15000.00, and a rounding error in a ledger is a rounding error
 * somebody has to explain to an auditor.
 */
final class VerificationPricingSeeder extends Seeder
{
    /** Zone B is a dedicated trip rather than a detour, and costs half as much again. */
    private const ZONE_B_MULTIPLIER = 1.5;

    public function run(): void
    {
        $this->accounts();
        $this->prices();
    }

    private function accounts(): void
    {
        $accounts = [
            [LedgerAccount::CASH, 'Cash at payment provider', AccountType::Asset],
            [LedgerAccount::CUSTOMER_FUNDS_HELD, 'Customer funds held for work not yet done', AccountType::Liability],
            [LedgerAccount::VERIFICATION_INCOME, 'Verification fees', AccountType::Income],
            [LedgerAccount::REFUNDS, 'Refunds', AccountType::Expense],
        ];

        foreach ($accounts as [$code, $name, $type]) {
            LedgerAccount::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'type' => $type, 'currency' => 'NGN'],
            );
        }
    }

    private function prices(): void
    {
        // Tier, standard naira, express naira or null where the tier is not
        // sold expedited. Express is a queue concession rather than more
        // labour, which is why it is roughly 1.7x and not double.
        $tiers = [
            ['identity_verified', 2_500, null, 5, 2],
            ['location_verified', 15_000, 25_000, 10, 3],
            ['operations_verified', 35_000, 50_000, 10, 3],
            ['monitored', 9_000, null, 10, null],
        ];

        foreach ($tiers as [$tier, $standard, $express, $standardSla, $expressSla]) {
            foreach (ServiceZone::cases() as $zone) {
                $this->price($tier, OrderUrgency::Standard, $zone, $standard, $standardSla);

                if ($express !== null && $expressSla !== null) {
                    $this->price($tier, OrderUrgency::Express, $zone, $express, $expressSla);
                }
            }
        }
    }

    /**
     * One live price for a tier, urgency and zone.
     *
     * Keyed on the live row rather than on the whole combination, so a rerun
     * updates today's price instead of adding a second live one beside it. The
     * partial unique index would refuse that anyway; this keeps the seeder from
     * being the thing that discovers it.
     *
     * Identity verification is a check through a licensed channel rather than a
     * journey, so no zone multiplier applies to it: nobody travels.
     */
    private function price(
        string $tier,
        OrderUrgency $urgency,
        ServiceZone $zone,
        int $naira,
        int $slaWorkingDays,
    ): void {
        $travels = $tier !== 'identity_verified';

        $minor = (int) round(
            $naira * 100 * ($travels ? $this->multiplier($zone) : 1.0),
        );

        VerificationPrice::query()->updateOrCreate(
            [
                'tier' => $tier,
                'urgency' => $urgency->value,
                'zone' => $zone->value,
                'effective_to' => null,
            ],
            [
                'amount_minor' => $minor,
                'currency' => 'NGN',
                'sla_working_days' => $slaWorkingDays,
                'effective_from' => now(config('app.timezone'))->startOfDay(),
            ],
        );
    }

    private function multiplier(ServiceZone $zone): float
    {
        return $zone === ServiceZone::B ? self::ZONE_B_MULTIPLIER : 1.0;
    }
}
