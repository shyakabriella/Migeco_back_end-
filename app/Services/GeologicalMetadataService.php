<?php

namespace App\Services;

use App\Models\MetadataSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class GeologicalMetadataService
{
    /**
     * Validate and normalize dynamic metadata using the selected schema.
     *
     * This service does not change existing document metadata.
     * It only validates the new geological custom_metadata payload.
     *
     * @throws ValidationException
     */
    public function validateAndNormalize(
        ?MetadataSchema $schema,
        array $customMetadata
    ): array {
        if (!$schema) {
            return $this->normalizeWithoutSchema($customMetadata);
        }

        $schema->loadMissing('fields');

        $rules = [];
        $attributes = [];

        foreach ($schema->fields as $field) {
            $fieldRules = [];

            if ($field->is_required) {
                $fieldRules[] = 'required';
            } else {
                $fieldRules[] = 'nullable';
            }

            $fieldRules = array_merge(
                $fieldRules,
                $this->rulesForFieldType(
                    $field->field_type,
                    $field->options ?? []
                )
            );

            if (is_array($field->validation_rules)) {
                $fieldRules = array_merge(
                    $fieldRules,
                    array_values($field->validation_rules)
                );
            }

            $rules[$field->field_key] = $fieldRules;
            $attributes[$field->field_key] = $field->label;
        }

        $validator = Validator::make(
            $customMetadata,
            $rules,
            [],
            $attributes
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();
        $normalized = [];

        foreach ($schema->fields as $field) {
            if (!array_key_exists($field->field_key, $validated)) {
                continue;
            }

            $normalized[$field->field_key] = $this->normalizeValue(
                $field->field_type,
                $validated[$field->field_key]
            );
        }

        return $normalized;
    }

    private function rulesForFieldType(
        string $fieldType,
        array $options
    ): array {
        return match ($fieldType) {
            'number' => ['numeric'],
            'date' => ['date'],
            'boolean' => ['boolean'],
            'select' => [
                'string',
                function (string $attribute, mixed $value, \Closure $fail) use ($options) {
                    if ($value !== null && $options !== [] && !in_array($value, $options, true)) {
                        $fail("The selected {$attribute} is invalid.");
                    }
                },
            ],
            'multi_select' => [
                'array',
                function (string $attribute, mixed $value, \Closure $fail) use ($options) {
                    if (!is_array($value) || $options === []) {
                        return;
                    }

                    foreach ($value as $selectedValue) {
                        if (!in_array($selectedValue, $options, true)) {
                            $fail("The selected {$attribute} contains an invalid value.");
                            return;
                        }
                    }
                },
            ],
            'textarea', 'text' => ['string'],
            default => ['string'],
        };
    }

    private function normalizeValue(
        string $fieldType,
        mixed $value
    ): mixed {
        if ($value === null) {
            return null;
        }

        return match ($fieldType) {
            'number' => (float) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'multi_select' => array_values(array_unique((array) $value)),
            default => is_string($value) ? trim($value) : $value,
        };
    }

    private function normalizeWithoutSchema(array $metadata): array
    {
        $normalized = [];

        foreach ($metadata as $key => $value) {
            $safeKey = trim((string) $key);

            if ($safeKey === '') {
                continue;
            }

            if (is_string($value)) {
                $normalized[$safeKey] = trim($value);
                continue;
            }

            $normalized[$safeKey] = $value;
        }

        return $normalized;
    }
}
