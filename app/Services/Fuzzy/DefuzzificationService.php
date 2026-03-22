<?php

namespace App\Services\Fuzzy;

class DefuzzificationService
{
    public function process(array $fuzzyMatrix): array
    {
        $crispMatrix = [];

        foreach ($fuzzyMatrix as $caseId => $criteriaValues) {
            foreach ($criteriaValues as $criterion => $fuzzyNumber) {
                $a = (float) ($fuzzyNumber[0] ?? 0.0);
                $b = (float) ($fuzzyNumber[1] ?? 0.0);
                $c = (float) ($fuzzyNumber[2] ?? 0.0);

                $crispMatrix[$caseId][$criterion] = ($a + $b + $c) / 3.0;
            }
        }

        return $crispMatrix;
    }
}

