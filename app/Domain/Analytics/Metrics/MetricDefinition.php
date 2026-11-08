<?php

namespace App\Domain\Analytics\Metrics;

use App\Domain\Metrics\Formula\Formula;

/**
 * A metric known to the analytics engine: either code-defined (base) or a
 * custom formula defined by the organization (docs/ARCHITECTURE.md §7).
 *
 * `facts` lists which fact tables its value depends on: a metric can only be
 * broken down by a dimension that every one of its facts supports.
 */
final readonly class MetricDefinition
{
    public const CURRENCY = 'currency';

    public const NUMBER = 'number';

    public const PERCENT = 'percent';

    /**
     * @param  'currency'|'number'|'percent'  $format
     * @param  'up_good'|'up_bad'|'neutral'  $polarity
     * @param  list<'sales'|'expenses'>  $facts
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $format,
        public string $polarity,
        public array $facts,
        public string $description,
        public ?Formula $formula = null,
        public int $decimals = 1,
    ) {}

    public function isCustom(): bool
    {
        return $this->formula !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'format' => $this->format,
            'polarity' => $this->polarity,
            'description' => $this->description,
            'custom' => $this->isCustom(),
        ];
    }
}
