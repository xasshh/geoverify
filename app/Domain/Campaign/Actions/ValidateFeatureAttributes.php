<?php

declare(strict_types=1);

namespace App\Domain\Campaign\Actions;

use App\Domain\Campaign\Enums\AttributeType;
use App\Domain\Campaign\Models\FeatureClassVersion;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Checks a feature's answers against the exact version of the form it was
 * captured with, and returns them typed.
 *
 * Against the version, not the class: a handset that went offline before a
 * revision is still answering the form it was given, and that answer is valid.
 *
 * Questions marked field only (a crop actually seen, a pump that actually
 * works) cannot be answered from imagery, so a desk capture may leave them
 * empty even when they are required. The officer who verifies on the ground
 * fills them.
 */
final class ValidateFeatureAttributes
{
    /**
     * @param  array<string, mixed>  $answers
     * @return array<string, string|int|float|bool|list<string>>
     *
     * @throws ValidationException
     */
    public function __invoke(FeatureClassVersion $version, array $answers, bool $inTheField): array
    {
        $errors = [];
        $clean = [];
        $known = [];

        foreach ($version->attribute_schema as $attribute) {
            $key = $attribute['key'];
            $known[$key] = true;
            $value = $answers[$key] ?? null;
            $empty = $value === null || $value === '' || $value === [];

            if ($empty) {
                $mustAnswer = $attribute['required'] && ($inTheField || ! $attribute['field_only']);

                if ($mustAnswer) {
                    $errors["attributes.{$key}"] = "{$attribute['label']} is required.";
                }

                continue;
            }

            $typed = $this->typed(AttributeType::from($attribute['type']), $value, $attribute['options'] ?? []);

            if ($typed === null) {
                $errors["attributes.{$key}"] = "{$attribute['label']} is not a valid answer.";

                continue;
            }

            $clean[$key] = $typed;
        }

        // An answer to a question the form never asked is either a client on a
        // different version than it claims, or something being smuggled in.
        foreach (array_keys($answers) as $key) {
            if (! isset($known[$key])) {
                $errors["attributes.{$key}"] = "This form does not ask {$key}.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    /**
     * @param  list<string>  $options
     * @return string|int|float|bool|list<string>|null null when the answer is not valid
     */
    private function typed(AttributeType $type, mixed $value, array $options): string|int|float|bool|array|null
    {
        return match ($type) {
            AttributeType::Text => is_scalar($value) && mb_strlen(trim((string) $value)) <= 500
                ? trim((string) $value) : null,
            AttributeType::Number => is_numeric($value)
                ? $value + 0 : null,
            AttributeType::Date => $this->date($value),
            AttributeType::Boolean => is_bool($value) ? $value : null,
            AttributeType::Select => is_string($value) && in_array($value, $options, true) ? $value : null,
            AttributeType::MultiSelect => is_array($value)
                && $value === array_values($value)
                && array_diff($value, $options) === []
                && count($value) === count(array_unique($value))
                ? array_values(array_map('strval', $value)) : null,
        };
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return Carbon::createFromFormat('!Y-m-d', $value)?->format('Y-m-d') === $value ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }
}
