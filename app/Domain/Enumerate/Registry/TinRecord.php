<?php

declare(strict_types=1);

namespace App\Domain\Enumerate\Registry;

/** A company's TIN, and the name FIRS holds it under. */
final readonly class TinRecord
{
    public function __construct(
        public string $tin,
        public string $nameOnTin,
    ) {}

    /** @return array{tin: string, nameOnTin: string} */
    public function toFacts(): array
    {
        return ['tin' => $this->tin, 'nameOnTin' => $this->nameOnTin];
    }
}
