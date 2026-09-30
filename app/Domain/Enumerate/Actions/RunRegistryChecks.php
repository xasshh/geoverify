<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Actions;

use App\Domain\Enumerate\Enums\RequestStatus;
use App\Domain\Enumerate\Models\EnumerateRequest;
use App\Domain\Enumerate\Models\RegistryCheck;
use App\Domain\Enumerate\Registry\RegistryLookup;
use App\Domain\Enumerate\Registry\RegistryUnavailable;
use App\Domain\Verification\Models\VerificationEvent;
use Illuminate\Support\Facades\DB;

/**
 * Asks CAC and FIRS about the business, and writes down what they said.
 *
 * This is where "matches" is decided, for every provider alike. CAC must hold
 * the number, under the name the person picked, as a live company. FIRS must
 * hold a TIN for it under the same name. Anything else is written as found,
 * with the reason in words a requester will read on their report.
 *
 * It does not pass or fail the request. A supervisor reads these answers and
 * decides (DecideDeskCheck), and a provider that did not answer leaves the
 * request waiting for them rather than failing a business we never asked about.
 * Safe to run again: each run appends its answers, and the latest are read.
 */
final class RunRegistryChecks
{
    public function __construct(private readonly RegistryLookup $registry) {}

    public function __invoke(EnumerateRequest $request): EnumerateRequest
    {
        if (! in_array($request->status, [RequestStatus::Paid, RequestStatus::RegistryCheck], true)) {
            return $request;
        }

        $cac = $this->cac($request);
        $tin = $this->tin($request, $cac->facts['name'] ?? null);

        return DB::transaction(function () use ($request, $cac, $tin): EnumerateRequest {
            $cac->save();
            $tin->save();

            // The address CAC holds replaces the town the search result gave:
            // it is what the officer is sent to and the report prints.
            $address = $cac->facts['address'] ?? null;

            $request->update([
                'status' => RequestStatus::RegistryCheck,
                'registered_address' => is_string($address) && $address !== '' ? mb_substr($address, 0, 300) : $request->registered_address,
            ]);

            VerificationEvent::record($request, 'enumerate.registry_checked', null, [
                'provider' => $this->registry->provider(),
                'cac' => $cac->outcome,
                'tin' => $tin->outcome,
            ], VerificationEvent::ACTOR_EXTERNAL);

            return $request;
        });
    }

    private function cac(EnumerateRequest $request): RegistryCheck
    {
        $check = $this->check($request, 'cac');

        try {
            $company = $this->registry->company($request->rc_number, $request->company_type);
        } catch (RegistryUnavailable $e) {
            return $check->fill(['outcome' => RegistryCheck::UNAVAILABLE, 'note' => $e->getMessage()]);
        }

        if ($company === null) {
            return $check->fill(['outcome' => RegistryCheck::NOT_FOUND, 'note' => "CAC has no record of number {$request->rc_number}."]);
        }

        $check->facts = $company->toFacts();

        if (! self::sameName($company->name, $request->subject_name)) {
            return $check->fill(['outcome' => RegistryCheck::MISMATCHED, 'note' => "CAC holds that number for {$company->name}."]);
        }

        if (strtolower($company->status) !== 'active') {
            return $check->fill(['outcome' => RegistryCheck::MISMATCHED, 'note' => "CAC status is {$company->status}."]);
        }

        return $check->fill(['outcome' => RegistryCheck::MATCHED]);
    }

    private function tin(EnumerateRequest $request, mixed $cacName): RegistryCheck
    {
        $check = $this->check($request, 'tin');

        try {
            $tin = $this->registry->tin($request->rc_number, $request->company_type);
        } catch (RegistryUnavailable $e) {
            return $check->fill(['outcome' => RegistryCheck::UNAVAILABLE, 'note' => $e->getMessage()]);
        }

        if ($tin === null) {
            return $check->fill(['outcome' => RegistryCheck::NOT_FOUND, 'note' => 'FIRS holds no TIN for this registration.']);
        }

        $check->facts = $tin->toFacts();
        $against = is_string($cacName) ? $cacName : $request->subject_name;

        return self::sameName($tin->nameOnTin, $against)
            ? $check->fill(['outcome' => RegistryCheck::MATCHED])
            : $check->fill(['outcome' => RegistryCheck::MISMATCHED, 'note' => 'TIN does not match CAC name.']);
    }

    private function check(EnumerateRequest $request, string $kind): RegistryCheck
    {
        return new RegistryCheck([
            'enumerate_request_id' => $request->id,
            'kind' => $kind,
            'provider' => $this->registry->provider(),
            'checked_at' => now(),
        ]);
    }

    /**
     * The same registered name, give or take how people write "Limited".
     *
     * Deliberately narrow: punctuation, case and the usual suffix spellings,
     * and nothing fuzzier. A near miss is for the supervisor to judge, not for
     * a similarity score to wave through.
     */
    public static function sameName(string $a, string $b): bool
    {
        $normal = static function (string $name): string {
            $name = mb_strtoupper($name);
            $name = str_replace('&', ' AND ', $name);
            $name = (string) preg_replace('/[^A-Z0-9 ]+/', ' ', $name);
            $name = (string) preg_replace('/\b(LTD|LIMITED)\b/', 'LIMITED', $name);
            $name = (string) preg_replace('/\b(NIG|NIGERIA)\b/', 'NIGERIA', $name);
            $name = (string) preg_replace('/\b(PLC|PUBLIC LIMITED COMPANY)\b/', 'PLC', $name);

            return trim((string) preg_replace('/\s+/', ' ', $name));
        };

        return $normal($a) === $normal($b);
    }
}
