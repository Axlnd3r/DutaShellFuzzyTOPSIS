<?php

namespace App\Services\Topsis;

class NormalizationService
{
    public function calculate(array $matrix, array $weights): array
    {
        $criteria = $this->collectCriteria($matrix);
        $denominators = $this->buildDenominators($matrix, $criteria);

        $normalized = [];
        $weighted = [];

        foreach ($matrix as $caseId => $criteriaValues) {
            foreach ($criteria as $criterion) {
                $value = (float) ($criteriaValues[$criterion] ?? 0.0);
                $denominator = $denominators[$criterion] ?? 0.0;
                $normalizedValue = $denominator > 0.0 ? ($value / $denominator) : 0.0;
                $weight = (float) ($weights[$criterion] ?? 0.0);

                $normalized[$caseId][$criterion] = $normalizedValue;
                $weighted[$caseId][$criterion] = $normalizedValue * $weight;
            }
        }

        return [
            'normalized' => $normalized,
            'weighted' => $weighted,
            'denominators' => $denominators,
        ];
    }

    private function collectCriteria(array $matrix): array
    {
        $criteria = [];
        foreach ($matrix as $criteriaValues) {
            foreach ($criteriaValues as $criterion => $_) {
                $criteria[$criterion] = true;
            }
        }

        return array_keys($criteria);
    }

    private function buildDenominators(array $matrix, array $criteria): array
    {
        $denominators = [];
        foreach ($criteria as $criterion) {
            $sum = 0.0;
            foreach ($matrix as $criteriaValues) {
                $value = (float) ($criteriaValues[$criterion] ?? 0.0);
                $sum += $value * $value;
            }
            $denominators[$criterion] = sqrt($sum);
        }

        return $denominators;
    }
}

