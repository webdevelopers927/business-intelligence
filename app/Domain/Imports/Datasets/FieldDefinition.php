<?php

namespace App\Domain\Imports\Datasets;

/**
 * One importable target field of a dataset.
 */
final readonly class FieldDefinition
{
    public const STRING = 'string';

    public const DECIMAL = 'decimal';

    public const DATE = 'date';

    public const DATETIME = 'datetime';

    public const ENUM = 'enum';

    /**
     * @param  'string'|'decimal'|'date'|'datetime'|'enum'  $type
     * @param  list<string>  $aliases  Alternative header spellings used by the mapping suggester.
     * @param  list<string>  $options  Allowed values for enum fields (compared case-insensitively).
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $type = self::STRING,
        public bool $required = false,
        public array $aliases = [],
        public array $options = [],
        public ?float $min = null,
        public int $maxLength = 255,
        public ?string $hint = null,
    ) {}

    /** @return array{key: string, label: string, type: string, required: bool, options: list<string>, hint: string|null} */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'required' => $this->required,
            'options' => $this->options,
            'hint' => $this->hint,
        ];
    }
}
