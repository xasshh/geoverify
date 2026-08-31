<?php

declare(strict_types=1);

namespace App\Domain\Registry\Enums;

/**
 * What a party may ask to have corrected, and nothing else.
 *
 * A closed list rather than free text. Free text would let a proposal name a
 * column that does not exist, or one a party has no business touching, and the
 * review screen would have to work out what to do about it at the worst
 * possible moment: while somebody is deciding whether to believe a stranger.
 *
 * Notably absent: anything an officer established by being there. A party
 * cannot propose a correction to the position, the structure type, the
 * confidence score or the photographs. Those are the record of a visit, and a
 * business that disagrees with them is asking for a re-visit, not an edit.
 */
enum CorrectableField: string
{
    case TradingName = 'trading_name';
    case RegisteredName = 'registered_name';
    case SectorCode = 'sector_code';
    case OperatingStatus = 'operating_status';
    case Phone = 'phone';
    case Email = 'email';
    case Website = 'website';
    case OpeningHours = 'opening_hours';
    case UnitLabel = 'unit_label';
    case Floor = 'floor';

    public function label(): string
    {
        return match ($this) {
            self::TradingName => 'Trading name',
            self::RegisteredName => 'Registered name',
            self::SectorCode => 'Sector',
            self::OperatingStatus => 'Operating status',
            self::Phone => 'Phone number',
            self::Email => 'Email',
            self::Website => 'Website',
            self::OpeningHours => 'Opening hours',
            self::UnitLabel => 'Unit',
            self::Floor => 'Floor',
        };
    }

    /**
     * Whether this field is part of what an observation records.
     *
     * The ones that are get a new party-authored observation when a correction
     * is accepted, so the officer's account and the party's sit side by side and
     * the register can still answer "what did this look like in March".
     *
     * The ones that are not are placement: where in a building the business
     * sits. That is not something anybody observed on a morning, it is a fact
     * about the record, and it moves on the enterprise alone.
     */
    public function isObserved(): bool
    {
        return match ($this) {
            self::UnitLabel, self::Floor => false,
            default => true,
        };
    }

    /** Whether the projection on `enterprises` carries this field too. */
    public function isProjected(): bool
    {
        return match ($this) {
            self::Phone, self::Email, self::Website, self::OpeningHours => false,
            default => true,
        };
    }

    /**
     * How a proposed value has to be shaped to be worth reviewing.
     *
     * Checked before a supervisor ever sees it. A phone number that is not a
     * phone number wastes somebody's morning, and refusing it at the point of
     * typing is kinder than refusing it three days later.
     *
     * @return list<string>
     */
    public function rules(): array
    {
        return match ($this) {
            self::TradingName => ['required', 'string', 'max:255'],
            self::RegisteredName => ['nullable', 'string', 'max:255'],
            self::SectorCode => ['required', 'string', 'exists:isic_classes,code'],
            self::OperatingStatus => ['required', 'string', 'in:operating,closed,seasonal,relocated'],
            self::Phone => ['required', 'string', 'max:32'],
            self::Email => ['nullable', 'email', 'max:255'],
            self::Website => ['nullable', 'string', 'max:255'],
            self::OpeningHours => ['nullable', 'string', 'max:255'],
            self::UnitLabel => ['nullable', 'string', 'max:32'],
            self::Floor => ['nullable', 'integer', 'min:-5', 'max:200'],
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(static fn (self $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
        ], self::cases());
    }
}
