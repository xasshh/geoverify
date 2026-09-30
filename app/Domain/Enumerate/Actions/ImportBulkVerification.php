<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Actions;

use App\Domain\Enumerate\Enums\Tier;
use App\Domain\Enumerate\Models\EnumerateBatch;
use App\Domain\Enumerate\Models\EnumerateBatchRow;
use App\Domain\Enumerate\Models\EnumerateMember;
use App\Domain\Party\Models\PortalAccount;
use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Bulk verification (board 34): a CSV of businesses, one tier for all of them,
 * paid from the organisation wallet.
 *
 * Every line is read before anything is spent. A line with no name or no
 * registration number, or a number already on an earlier line, is refused
 * with the reason and costs nothing. The rest are placed only if the wallet
 * covers all of them: half a batch bought is a batch nobody can reason about.
 * Each placed line is an ordinary request, checked by the same lookups and
 * the same desk as one bought alone.
 *
 * The registration's kind is read from its prefix (BN for a business name,
 * IT for incorporated trustees, otherwise a company), which is how CAC prints
 * them; the registry check then says whether the name on the line is what
 * CAC holds for that number.
 */
final class ImportBulkVerification
{
    public const MAX_ROWS = 500;

    /** Header spellings accepted for each column, lower-cased. */
    private const COLUMNS = [
        'name' => ['business name', 'name', 'business', 'company name', 'company'],
        'rc' => ['rc/bn number', 'rc number', 'rc', 'bn', 'cac number', 'registration number', 'rc/bn'],
        'tin' => ['tin', 'tax id', 'tax identification number'],
        'address' => ['address', 'business address', 'registered address'],
    ];

    public function __construct(
        private readonly PlaceEnumerateRequest $place,
        private readonly ReadEnumeratePrices $prices,
        private readonly ManageRequesterWallet $wallets,
        private readonly MintReference $mint,
    ) {}

    public function __invoke(EnumerateMember $member, PortalAccount $account, string $csv, Tier $tier, ?int $days): EnumerateBatch
    {
        $organisation = $member->organisation()->firstOrFail();

        if (! $member->may('bulk')) {
            throw new RuntimeException('Your seat cannot buy checks for this organisation.');
        }

        if (! $organisation->approved()) {
            throw new RuntimeException('Bulk verification opens once we have approved your organisation.');
        }

        if ($tier === Tier::Activity && ! in_array($days, Tier::MONITORING_PERIODS, true)) {
            throw new RuntimeException('Choose 7, 14 or 30 days of monitoring.');
        }

        $lines = $this->read($csv);
        $price = $this->prices->priceMinor($tier, $tier === Tier::Activity ? $days : null);
        $good = array_filter($lines, static fn (array $l): bool => $l['reason'] === null);
        $total = $price * count($good);
        $wallet = $this->wallets->walletForOrganisation($organisation->id);

        if ($good === []) {
            throw new RuntimeException('No line in that file can be checked. See the template for the columns we read.');
        }

        if ($this->wallets->balanceMinor($wallet) < $total) {
            throw new RuntimeException(sprintf(
                'That batch is %d checks at ₦%s, ₦%s in all, and the wallet holds ₦%s. Fund it and upload again.',
                count($good),
                number_format($price / 100),
                number_format($total / 100),
                number_format($this->wallets->balanceMinor($wallet) / 100),
            ));
        }

        return DB::transaction(function () use ($member, $account, $organisation, $wallet, $lines, $tier, $days, $good, $total): EnumerateBatch {
            $batch = EnumerateBatch::query()->create([
                'reference' => ($this->mint)('BLK', 5),
                'organisation_id' => $organisation->id,
                'created_by' => $account->id,
                'tier' => $tier->value,
                'monitoring_days' => $tier === Tier::Activity ? $days : null,
                'rows_total' => count($lines),
                'rows_placed' => count($good),
                'rows_refused' => count($lines) - count($good),
                'total_minor' => $total,
            ]);

            foreach ($lines as $line) {
                $requestId = null;

                if ($line['reason'] === null) {
                    $requestId = ($this->place)(
                        $account,
                        ['name' => (string) $line['name'], 'rcNumber' => (string) $line['rc'], 'companyType' => $line['type'], 'address' => $line['address']],
                        $tier,
                        $tier === Tier::Activity ? $days : null,
                        $wallet,
                        $batch->id,
                    )->id;
                }

                EnumerateBatchRow::query()->create([
                    'enumerate_batch_id' => $batch->id,
                    'line' => $line['line'],
                    'name' => $line['name'] === null ? null : mb_substr($line['name'], 0, 200),
                    'rc_number' => $line['raw_rc'] === null ? null : mb_substr($line['raw_rc'], 0, 40),
                    'tin' => $line['tin'] === null ? null : mb_substr($line['tin'], 0, 40),
                    'address' => $line['address'] === null ? null : mb_substr($line['address'], 0, 300),
                    'outcome' => $requestId === null ? 'refused' : 'placed',
                    'reason' => $line['reason'],
                    'enumerate_request_id' => $requestId,
                    'created_at' => now(),
                ]);
            }

            VerificationEvent::recordForBuyer($batch, 'enumerate.batch_placed', $account, [
                'organisation_id' => $organisation->id,
                'member_id' => $member->id,
                'placed' => $batch->rows_placed,
                'refused' => $batch->rows_refused,
                'total_minor' => $total,
            ]);

            return $batch;
        });
    }

    /**
     * Every line, with the reason it cannot be checked when it cannot.
     *
     * @return list<array{line: int, name: string|null, rc: string|null, raw_rc: string|null, type: string, tin: string|null, address: string|null, reason: string|null}>
     */
    public function read(string $csv): array
    {
        $csv = (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $rows = array_values(array_filter(
            array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), (array) preg_split('/\r\n|\r|\n/', trim($csv))),
            static fn (array $cells): bool => implode('', array_map(static fn (?string $c): string => trim((string) $c), $cells)) !== '',
        ));

        if ($rows === []) {
            throw new RuntimeException('That file is empty.');
        }

        $header = array_map(static fn (?string $h): string => mb_strtolower(trim((string) $h)), $rows[0]);
        $index = [];

        foreach (self::COLUMNS as $key => $names) {
            foreach ($header as $i => $h) {
                if (in_array($h, $names, true)) {
                    $index[$key] = $i;
                    break;
                }
            }
        }

        if (! isset($index['name'], $index['rc'])) {
            throw new RuntimeException('The first line must name the columns: business name, RC/BN number, TIN, address.');
        }

        if (count($rows) - 1 > self::MAX_ROWS) {
            throw new RuntimeException(sprintf('Up to %d businesses in one file. Split it and upload the rest after.', self::MAX_ROWS));
        }

        $cell = static fn (array $row, ?int $i): ?string => $i === null || ! isset($row[$i]) || trim((string) $row[$i]) === '' ? null : trim((string) $row[$i]);
        $seen = [];
        $lines = [];

        foreach (array_slice($rows, 1) as $n => $row) {
            $name = $cell($row, $index['name']);
            $rawRc = $cell($row, $index['rc']);
            $digits = $rawRc === null ? '' : (string) preg_replace('/\D+/', '', $rawRc);
            $prefix = $rawRc === null ? '' : mb_strtoupper((string) preg_replace('/[^A-Za-z]+/', '', $rawRc));

            $reason = match (true) {
                $name === null || mb_strlen($name) < 2 => 'No business name.',
                $digits === '' => 'No RC or BN number.',
                isset($seen[$prefix.$digits]) => 'Already on line '.$seen[$prefix.$digits].'.',
                default => null,
            };

            if ($reason === null) {
                $seen[$prefix.$digits] = $n + 2;
            }

            $lines[] = [
                'line' => $n + 2,
                'name' => $name,
                'rc' => $digits === '' ? null : $digits,
                'raw_rc' => $rawRc,
                'type' => str_contains($prefix, 'BN') ? 'BUSINESS_NAME' : (str_contains($prefix, 'IT') ? 'INCORPORATED_TRUSTEES' : 'COMPANY'),
                'tin' => $cell($row, $index['tin'] ?? null),
                'address' => $cell($row, $index['address'] ?? null),
                'reason' => $reason,
            ];
        }

        return $lines;
    }
}
