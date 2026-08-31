<?php

declare(strict_types=1);

namespace App\Domain\Campaign\Actions;

use App\Domain\Campaign\Models\Campaign;
use App\Domain\Campaign\Models\ClientOrganisation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * NRS-MIN-2026-01.
 *
 * Client, subject, year, and the sequence within that client and year. Unlike a
 * party code this is meant to be read: somebody says "the mining one" and
 * somebody else finds NRS-MIN-2026-01 without looking it up, which is the whole
 * reason it is not a random string.
 *
 * It encodes only what is already on the invoice, so nothing is leaked by it
 * that the client does not already know about their own exercise.
 */
final class GenerateCampaignCode
{
    public function __invoke(ClientOrganisation $client, string $subjectType, ?int $year = null): string
    {
        $year ??= (int) now(config('app.timezone'))->format('Y');

        $subject = $this->abbreviate($subjectType);
        $prefix = sprintf('%s-%s-%d-', Str::upper($client->short_code), $subject, $year);

        // Counted from what exists rather than from a counter column, so a
        // deleted draft does not leave a hole that the next code falls into and
        // two people creating at once cannot both be told they are number four.
        return DB::transaction(function () use ($prefix): string {
            $taken = Campaign::query()
                ->where('code', 'like', $prefix.'%')
                ->lockForUpdate()
                ->pluck('code')
                ->map(static fn (string $code): int => (int) Str::afterLast($code, '-'))
                ->all();

            $next = $taken === [] ? 1 : max($taken) + 1;

            return $prefix.str_pad((string) $next, 2, '0', STR_PAD_LEFT);
        });
    }

    /**
     * Three letters that mean something to the person reading them.
     *
     * "Mining companies" becomes MIN, "Hair extension traders" becomes HAI.
     * Crude on purpose: the segment exists so a human can tell two of a client's
     * campaigns apart at a glance, not so a machine can parse it back.
     */
    private function abbreviate(string $subjectType): string
    {
        $letters = (string) preg_replace('/[^A-Za-z]/', '', $subjectType);

        return Str::upper(Str::padRight(Str::substr($letters, 0, 3), 3, 'X'));
    }
}
