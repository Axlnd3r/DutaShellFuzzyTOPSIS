<?php

namespace App\Services\Topsis;

class DistanceService
{
    public function calculate(array $weightedMatrix, array $ideal): array
    {
        $positiveIdeal = $ideal['positive'] ?? [];
        $negativeIdeal = $ideal['negative'] ?? [];

        $distances = [];

        foreach ($weightedMatrix as $caseId => $criteriaValues) {
            $sumPlus = 0.0;
            $sumMinus = 0.0;

            foreach ($criteriaValues as $criterion => $value) {
                $vij = (float) $value;
                $aPlus = (float) ($positiveIdeal[$criterion] ?? 0.0);
                $aMinus = (float) ($negativeIdeal[$criterion] ?? 0.0);

                $sumPlus += ($vij - $aPlus) * ($vij - $aPlus);
                $sumMinus += ($vij - $aMinus) * ($vij - $aMinus);
            }

            $sPlus = sqrt($sumPlus);
            $sMinus = sqrt($sumMinus);
            $divider = $sPlus + $sMinus;
            $score = $divider > 0.0 ? ($sMinus / $divider) : 0.0;

            $distances[] = [
                'case_id' => (int) $caseId,
                's_plus' => $sPlus,
                's_minus' => $sMinus,
                'score' => $score,
            ];
        }

        return $distances;
    }
}

