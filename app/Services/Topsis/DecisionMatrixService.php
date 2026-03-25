<?php

namespace App\Services\Topsis;

use App\DTO\CaseDTO;

class DecisionMatrixService
{
    /**
     * Precompute ranges dari training set saja (tanpa test case).
     * Gunakan sebelum loop test case untuk menghindari O(N×M) range computation.
     */
    public function buildRangesOnly(array $baseCases, array $criteriaColumns): array
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

    public function build(array $baseCases, CaseDTO $testCase, array $criteria, ?array $precomputedRanges = null): array
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
        $ranges = $precomputedRanges ?? $this->buildRanges($baseCases, $testCase, $criteriaColumns);

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
                $type = $types[$column] ?? 'benefit';

                $row[$column] = $this->similarityScore($baseValue, $testValue, $range, $type);
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

    /**
     * Hitung skor per sel matriks keputusan berdasarkan tipe kriteria.
     *
     * Kedua tipe menghasilkan nilai [0,1] namun dengan ARAH BERLAWANAN:
     *
     * COST:    |base - test| / range → [0,1] dimana 0 = identik (BAIK)
     *          Cocok dengan TOPSIS: A⁺ = min(vij), A⁻ = max(vij)
     *
     * BENEFIT: 1 - |base - test| / range → [0,1] dimana 1 = identik (BAIK)
     *          Cocok dengan TOPSIS: A⁺ = max(vij), A⁻ = min(vij)
     *
     * Skala [0,1] yang uniform memastikan vector normalization tidak bias.
     */
    private function similarityScore(mixed $baseValue, mixed $testValue, ?array $range, string $type): float
    {
        $baseNumeric = $this->extractNumeric($baseValue);
        $testNumeric = $this->extractNumeric($testValue);

        if ($baseNumeric !== null && $testNumeric !== null) {
            $delta = 0.0;
            if ($range !== null) {
                $delta = (float) $range['max'] - (float) $range['min'];
            }

            if ($delta <= 0.0) {
                $identical = abs($baseNumeric - $testNumeric) < 1.0e-12;
                if ($type === 'cost') {
                    return $identical ? 0.0 : 1.0;  // cost: 0 = sama = baik
                }
                return $identical ? 1.0 : 0.0;  // benefit: 1 = sama = baik
            }

            $normalizedDifference = abs($baseNumeric - $testNumeric) / $delta;

            if ($type === 'cost') {
                // COST: normalized distance [0,1] — 0 = identik (baik), 1 = beda jauh
                return $this->clamp($normalizedDifference);
            }

            // BENEFIT: similarity [0,1] — 1 = identik (baik), 0 = beda jauh
            return $this->clamp(1.0 - $normalizedDifference);
        }

        // Kategorikal: exact match
        $baseText = $this->normalizeText($baseValue);
        $testText = $this->normalizeText($testValue);

        if ($baseText === '' || $testText === '') {
            return ($type === 'cost') ? 1.0 : 0.0;
        }

        if ($type === 'cost') {
            return $baseText === $testText ? 0.0 : 1.0;  // cost: match = 0 (baik)
        }

        return $baseText === $testText ? 1.0 : 0.0;  // benefit: match = 1 (baik)
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