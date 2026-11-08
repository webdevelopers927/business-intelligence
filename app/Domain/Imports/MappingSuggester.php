<?php

namespace App\Domain\Imports;

use App\Domain\Imports\Datasets\FieldDefinition;

/**
 * Suggests which file column feeds each dataset field.
 * Scoring: exact key/label match > alias match > token overlap. Each column
 * is used at most once; the user reviews every suggestion before importing.
 */
final class MappingSuggester
{
    /**
     * @param  list<FieldDefinition>  $fields
     * @param  list<string>  $headers
     * @return array<string, string|null> field key => header (or null)
     */
    public function suggest(array $fields, array $headers): array
    {
        $candidates = [];
        foreach ($fields as $field) {
            foreach ($headers as $header) {
                $score = $this->score($field, $header);
                if ($score > 0) {
                    $candidates[] = [$score, $field->key, $header];
                }
            }
        }

        usort($candidates, fn ($a, $b) => $b[0] <=> $a[0]);

        $result = array_fill_keys(array_map(fn (FieldDefinition $f) => $f->key, $fields), null);
        $usedHeaders = [];
        foreach ($candidates as [, $key, $header]) {
            if ($result[$key] === null && ! isset($usedHeaders[$header])) {
                $result[$key] = $header;
                $usedHeaders[$header] = true;
            }
        }

        return $result;
    }

    private function score(FieldDefinition $field, string $header): int
    {
        $h = self::normalize($header);
        if ($h === '') {
            return 0;
        }

        if ($h === self::normalize($field->key) || $h === self::normalize($field->label)) {
            return 100;
        }

        foreach ($field->aliases as $alias) {
            if ($h === self::normalize($alias)) {
                return 90;
            }
        }

        $headerTokens = self::tokens($header);
        $best = 0;
        foreach ([$field->label, ...$field->aliases] as $candidate) {
            $tokens = self::tokens($candidate);
            $overlap = count(array_intersect($tokens, $headerTokens));
            if ($overlap > 0 && $overlap === count($tokens)) {
                $best = max($best, 50 + $overlap);
            }
        }

        return $best;
    }

    private static function normalize(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($value)) ?? '';
    }

    /** @return list<string> */
    private static function tokens(string $value): array
    {
        return array_values(array_filter(preg_split('/[^a-z0-9]+/', strtolower($value)) ?: []));
    }
}
