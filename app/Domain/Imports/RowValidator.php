<?php

namespace App\Domain\Imports;

use App\Domain\Imports\Datasets\FieldDefinition;

/**
 * Applies the user's column mapping to a raw row and coerces each mapped
 * value to its field type. Unmapped optional fields come back as null.
 */
final class RowValidator
{
    /**
     * @param  list<FieldDefinition>  $fields
     * @param  array<string, string>  $mapping  target field => source column
     */
    public function __construct(
        private readonly array $fields,
        private readonly array $mapping,
        private readonly string $dateFormat,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     * @return array{0: array<string, mixed>, 1: list<RowError>} [values, errors]
     */
    public function validate(array $raw): array
    {
        $values = [];
        $errors = [];

        foreach ($this->fields as $field) {
            $column = $this->mapping[$field->key] ?? null;
            $input = $column === null ? null : ($raw[$column] ?? null);
            $isBlank = ValueParser::string($input) === null;

            if ($isBlank) {
                if ($field->required) {
                    $errors[] = new RowError(RowError::REQUIRED, "{$field->label} is required.", $field->key);
                }
                $values[$field->key] = null;

                continue;
            }

            [$value, $error] = $this->coerce($field, $input);
            $values[$field->key] = $value;
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        return [$values, $errors];
    }

    /** @return array{0: mixed, 1: RowError|null} */
    private function coerce(FieldDefinition $field, mixed $input): array
    {
        $shown = ValueParser::string($input);

        switch ($field->type) {
            case FieldDefinition::DECIMAL:
                $number = ValueParser::decimal($input);
                if ($number === null) {
                    return [null, new RowError(RowError::INVALID_NUMBER, "{$field->label} \"{$shown}\" is not a number.", $field->key)];
                }
                if ($field->min !== null && $number < $field->min) {
                    return [null, new RowError(RowError::OUT_OF_RANGE, "{$field->label} must be at least {$field->min}.", $field->key)];
                }

                return [$number, null];

            case FieldDefinition::DATE:
            case FieldDefinition::DATETIME:
                $date = $field->type === FieldDefinition::DATE
                    ? ValueParser::date($input, $this->dateFormat)
                    : ValueParser::datetime($input, $this->dateFormat);

                return $date === null
                    ? [null, new RowError(RowError::INVALID_DATE, "{$field->label} \"{$shown}\" does not match the date format {$this->dateFormat}.", $field->key)]
                    : [$date, null];

            case FieldDefinition::ENUM:
                $normalized = strtolower((string) $shown);
                if (! in_array($normalized, $field->options, true)) {
                    $allowed = implode(', ', $field->options);

                    return [null, new RowError(RowError::INVALID_VALUE, "{$field->label} \"{$shown}\" must be one of: {$allowed}.", $field->key)];
                }

                return [$normalized, null];

            default:
                if (mb_strlen((string) $shown) > $field->maxLength) {
                    return [null, new RowError(RowError::TOO_LONG, "{$field->label} is longer than {$field->maxLength} characters.", $field->key)];
                }

                return [$shown, null];
        }
    }
}
