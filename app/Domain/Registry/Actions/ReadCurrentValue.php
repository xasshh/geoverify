<?php

declare(strict_types=1);

namespace App\Domain\Registry\Actions;

use App\Domain\Registry\Enums\CorrectableField;
use App\Domain\Registry\Models\Enterprise;
use App\Domain\Registry\Models\EnterpriseObservation;

/**
 * What the register currently says about one field.
 *
 * Two places to look, and which one depends on the field. Placement lives on
 * the enterprise; everything else is the projection of the latest observation,
 * and some of it only exists there. One reader for both, so a proposal and the
 * decision on it are always comparing the same thing.
 */
final class ReadCurrentValue
{
    public function __invoke(Enterprise $enterprise, CorrectableField $field): ?string
    {
        if (! $field->isObserved()) {
            return $this->stringify($enterprise->getAttribute($field->value));
        }

        if ($field->isProjected()) {
            return $this->stringify($enterprise->getAttribute($field->value));
        }

        // Contact details are recorded per visit and never projected forward,
        // because a phone number is a fact about a moment as much as a name is.
        $latest = EnterpriseObservation::query()
            ->where('enterprise_id', $enterprise->id)
            ->latest('observed_at')
            ->first();

        return $latest === null ? null : $this->stringify($latest->getAttribute($field->value));
    }

    private function stringify(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    }
}
