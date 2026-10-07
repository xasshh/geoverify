<?php

declare(strict_types=1);

namespace App\Domain\Campaign\Actions;

use App\Domain\Campaign\Enums\AttributeType;
use Illuminate\Validation\ValidationException;

/**
 * Checks a feature class's attribute form and returns it in one canonical shape.
 *
 * Canonical matters: a new version is written only when the form actually
 * changed, and that is decided by comparing two normalised forms. Key order, a
 * stray empty option or a missing false would otherwise read as a revision.
 */
final class NormaliseAttributeSchema
{
    public const MAX_ATTRIBUTES = 40;

    public const MAX_OPTIONS = 60;

    /**
     * @param  array<int, mixed>  $attributes
     * @return list<array{key: string, label: string, type: string, options?: list<string>, required: bool, help_text?: string, unit?: string, field_only: bool}>
     *
     * @throws ValidationException
     */
    public function __invoke(array $attributes): array
    {
        if (count($attributes) > self::MAX_ATTRIBUTES) {
            $this->fail('attributes', 'A class may ask at most '.self::MAX_ATTRIBUTES.' questions.');
        }

        $normalised = [];
        $seen = [];

        foreach (array_values($attributes) as $i => $attribute) {
            if (! is_array($attribute)) {
                $this->fail("attributes.{$i}", 'Each attribute must be an object.');
            }

            $key = trim((string) ($attribute['key'] ?? ''));
            $label = trim((string) ($attribute['label'] ?? ''));
            $type = AttributeType::tryFrom((string) ($attribute['type'] ?? ''));

            if (preg_match('/^[a-z][a-z0-9_]{0,47}$/', $key) !== 1) {
                $this->fail("attributes.{$i}.key", 'A key is lower case letters, digits and underscores, starting with a letter.');
            }

            // Two answers under one name is an export that silently drops one.
            if (isset($seen[$key])) {
                $this->fail("attributes.{$i}.key", "The key {$key} is used twice.");
            }
            $seen[$key] = true;

            if ($label === '' || mb_strlen($label) > 120) {
                $this->fail("attributes.{$i}.label", 'Every attribute needs a label of up to 120 characters.');
            }

            if ($type === null) {
                $this->fail("attributes.{$i}.type", "The attribute {$key} has an unknown type.");
            }

            $entry = [
                'key' => $key,
                'label' => $label,
                'type' => $type->value,
                'required' => (bool) ($attribute['required'] ?? false),
                'field_only' => (bool) ($attribute['field_only'] ?? false),
            ];

            if ($type->takesOptions()) {
                $options = array_values(array_unique(array_filter(
                    array_map(static fn (mixed $o): string => trim((string) $o), (array) ($attribute['options'] ?? [])),
                    static fn (string $o): bool => $o !== '',
                )));

                // A choice with nothing to choose is a text box that lies about itself.
                if ($options === []) {
                    $this->fail("attributes.{$i}.options", "The choice {$key} needs at least one option.");
                }

                if (count($options) > self::MAX_OPTIONS) {
                    $this->fail("attributes.{$i}.options", "The choice {$key} has more than ".self::MAX_OPTIONS.' options.');
                }

                $entry['options'] = $options;
            }

            $help = trim((string) ($attribute['help_text'] ?? ''));

            if ($help !== '') {
                $entry['help_text'] = mb_substr($help, 0, 300);
            }

            $unit = trim((string) ($attribute['unit'] ?? ''));

            if ($unit !== '' && $type === AttributeType::Number) {
                $entry['unit'] = mb_substr($unit, 0, 16);
            }

            $normalised[] = $entry;
        }

        return $normalised;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
