<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Registry;

/** One candidate in the search results. */
final readonly class RegistryMatch
{
    public function __construct(
        public string $name,
        public string $rcNumber,
        public string $companyType,
        /** Active, Inactive, or null when the search does not say. */
        public ?string $status = null,
        /** Where the register places it, as far as the search says. */
        public ?string $place = null,
        /** The registered address in full, when the search returns it. */
        public ?string $address = null,
        /** The date of registration, Y-m-d. */
        public ?string $registeredOn = null,
        /** The register's own word for the entity: "Limited company", "Business name". */
        public ?string $kind = null,
        /** The line of business the register holds, when it holds one. */
        public ?string $business = null,
    ) {}

    /** @return array{name: string, rcNumber: string, companyType: string, status: string|null, place: string|null, address: string|null, registeredOn: string|null, kind: string|null, business: string|null} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'rcNumber' => $this->rcNumber,
            'companyType' => $this->companyType,
            'status' => $this->status,
            'place' => $this->place,
            'address' => $this->address,
            'registeredOn' => $this->registeredOn,
            'kind' => $this->kind,
            'business' => $this->business,
        ];
    }

    /** @param  array<string, mixed>  $a */
    public static function fromArray(array $a): self
    {
        $text = static fn (string $key): ?string => is_string($a[$key] ?? null) ? $a[$key] : null;

        return new self(
            (string) $text('name'), (string) $text('rcNumber'), (string) $text('companyType'),
            $text('status'), $text('place'), $text('address'), $text('registeredOn'), $text('kind'), $text('business'),
        );
    }
}
