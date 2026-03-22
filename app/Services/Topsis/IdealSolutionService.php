<?php

namespace App\Services\Topsis;

class IdealSolutionService
{
    public function calculate(array $weightedMatrix, array $types): array
    {
        $criteria = $this->collectCriteria($weightedMatrix);
        $positive = [];
        $negative = [];

        foreach ($criteria as $criterion) {
            $values = [];
            foreach ($weightedMatrix as $criteriaValues) {
                $values[] = (float) ($criteriaValues[$criterion] ?? 0.0);
            }

            if ($values === []) {
                $positive[$criterion] = 0.0;
                $negative[$criterion] = 0.0;
                continue;
            }

            $type = strtolower((string) ($types[$criterion] ?? 'benefit'));
            if ($type === 'cost') {
                $positive[$criterion] = min($values);
                $negative[$criterion] = max($values);
            } else {
                $positive[$criterion] = max($values);
                $negative[$criterion] = min($values);
            }
        }

        return [
            'positive' => $positive,
            'negative' => $negative,
        ];
    }

    private function collectCriteria(array $weightedMatrix): array
    {
        $criteria = [];
        foreach ($weightedMatrix as $criteriaValues) {
            foreach ($criteriaValues as $criterion => $_) {
                $criteria[$criterion] = true;
            }
        }

        return array_keys($criteria);
    }
}

