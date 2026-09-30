<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Registry;

/**
 * The official registers, as a licensed provider answers for them.
 *
 * Drivers report what the register said and nothing more. Whether that agrees
 * with the business somebody asked about is decided in RunRegistryChecks, so a
 * second provider cannot quietly bring a second idea of what "matches" means.
 *
 * A driver throws RegistryUnavailable when the provider did not answer, and
 * returns null when it answered that there is no such record: "we could not
 * ask" and "there is nothing there" are different findings on a report.
 */
interface RegistryLookup
{
    /** A short name for the record: dojah, fake. */
    public function provider(): string;

    /**
     * Candidates for the search box, by business name or by RC/BN number.
     *
     * @param  'name'|'rc'  $by
     * @return list<RegistryMatch>
     */
    public function search(string $by, string $term): array;

    /** The full CAC record: registration, address, directors. */
    public function company(string $rcNumber, string $companyType): ?CompanyRecord;

    /** The tax identification number FIRS holds against the company. */
    public function tin(string $rcNumber, string $companyType): ?TinRecord;
}
