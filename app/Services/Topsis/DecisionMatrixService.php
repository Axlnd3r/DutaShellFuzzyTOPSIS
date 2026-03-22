<?php

namespace App\Services\Topsis;

use App\DTO\CaseDTO;

class DecisionMatrixService
{
    public function build(array $baseCases, CaseDTO $testCase, array $criteria): array
    {
        if (empty($baseCases) || empty($criteria)) {
            return [
                'matrix' => [],
                'weights' => [],
                'types' => [],
                'ranges' => [],
                'criteria' => [],
            ];
        }

        $criteriaColumns = array_map(
            static fn (array $criterion): string => (string) $criterion['column'],
            $criteria
        );

        $weights = $this->normalizeWeights($criteria);
        $types = $this->extractTypes($criteria);
        $ranges = $this->buildRanges($baseCases, $testCase, $criteriaColumns);

        $matrix = [];
        foreach ($baseCases as $case) {
            if (!$case instanceof CaseDTO) {
                continue;
            }

            $row = [];
            foreach ($criteriaColumns as $column) {
                $baseValue = $case->criteriaValues[$column] ?? null;
                $testValue = $testCase->criteriaValues[$column] ?? null;
                $range = $ranges[$column] ?? null;

                $row[$column] = $this->similarityScore($baseValue, $testValue, $range);
            }

            $matrix[$case->caseId] = $row;
        }

        return [
            'matrix' => $matrix,
            'weights' => $weights,
            'types' => $types,
            'ranges' => $ranges,
            'criteria' => $criteriaColumns,
        ];
    }

    private function normalizeWeights(array $criteria): array
    {
        $weights = [];
        foreach ($criteria as $criterion) {
            $column = (string) ($criterion['column'] ?? '');
            if ($column === '') {
                continue;
            }

            $weight = (float) ($criterion['weight'] ?? 1.0);
            if ($weight <= 0.0) {
                $weight = 1.0;
            }
            $weights[$column] = $weight;
        }

        $sum = array_sum($weights);
        if ($sum <= 0.0) {
            $count = max(count($weights), 1);
            return array_fill_keys(array_keys($weights), 1.0 / $count);
        }

        foreach ($weights as $column => $weight) {
            $weights[$column] = $weight / $sum;
        }

        return $weights;
    }

    private function extractTypes(array $criteria): array
    {
        $types = [];
        foreach ($criteria as $criterion) {
            $column = (string) ($criterion['column'] ?? '');
            if ($column === '') {
                continue;
            }

            $type = strtolower((string) ($criterion['type'] ?? 'benefit'));
            $types[$column] = $type === 'cost' ? 'cost' : 'benefit';
        }

        return $types;
    }

    private function buildRanges(array $baseCases, CaseDTO $testCase, array $criteriaColumns): array
    {
        $ranges = [];
        foreach ($criteriaColumns as $column) {
            $numbers = [];

            foreach ($baseCases as $case) {
                if (!$case instanceof CaseDTO) {
                    continue;
                }

                $numericValue = $this->extractNumeric($case->criteriaValues[$column] ?? null);
                if ($numericValue !== null) {
                    $numbers[] = $numericValue;
                }
            }

            $testNumeric = $this->extractNumeric($testCase->criteriaValues[$column] ?? null);
            if ($testNumeric !== null) {
                $numbers[] = $testNumeric;
            }

            if ($numbers === []) {
                continue;
            }

            $ranges[$column] = [
                'min' => min($numbers),
                'max' => max($numbers),
            ];
        }

        return $ranges;
    }

    private function similarityScore(mixed $baseValue, mixed $testValue, ?array $range): float
    {
        $baseNumeric = $this->extractNumeric($baseValue);
        $testNumeric = $this->extractNumeric($testValue);

        if ($baseNumeric !== null && $testNumeric !== null) {
            $delta = 0.0;
            if ($range !== null) {
                $delta = (float) $range['max'] - (float) $range['min'];
            }

            if ($delta <= 0.0) {
                return abs($baseNumeric - $testNumeric) < 1.0e-12 ? 1.0 : 0.0;
            }

            $normalizedDifference = abs($baseNumeric - $testNumeric) / $delta;
            $score = 1.0 - $normalizedDifference;
            return $this->clamp($score);
        }

        $baseText = $this->normalizeText($baseValue);
        $testText = $this->normalizeText($testValue);

        if ($baseText === '' || $testText === '') {
            return 0.0;
        }

        return $baseText === $testText ? 1.0 : 0.0;
    }

    private function extractNumeric(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^(-?\d+(?:\.\d+)?)(?:_.+)?$/', $trimmed, $matches) === 1) {
            return (float) $matches[1];
        }

        if (preg_match('/-?\d+(?:\.\d+)?/', $trimmed, $matches) === 1) {
            return (float) $matches[0];
        }

        return null;
    }

    private function normalizeText(mixed $value): string
    {
        $text = strtolower(trim((string) $value));
        if ($text === '') {
            return '';
        }

        $text = preg_replace('/^\d+_/', '', $text) ?? $text;
        $text = str_replace(['-', '_'], ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }

    private function clamp(float $value): float
    {
        if ($value < 0.0) {
            return 0.0;
        }

        if ($value > 1.0) {
            return 1.0;
        }

        return $value;
    }
}

