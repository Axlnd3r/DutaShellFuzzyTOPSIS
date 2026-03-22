<?php

namespace App\Services\Evaluation;

class ConfusionMatrixService
{
    public function evaluate(array $actual, array $predicted): array
    {
        $tp = 0;
        $tn = 0;
        $fp = 0;
        $fn = 0;

        foreach ($actual as $index => $actualValue) {
            $actualClass = (int) $actualValue;
            $predictedClass = (int) ($predicted[$index] ?? 0);

            if ($actualClass === 1 && $predictedClass === 1) {
                $tp++;
            } elseif ($actualClass === 0 && $predictedClass === 0) {
                $tn++;
            } elseif ($actualClass === 0 && $predictedClass === 1) {
                $fp++;
            } else {
                $fn++;
            }
        }

        $total = $tp + $tn + $fp + $fn;

        return [
            'tp' => $tp,
            'tn' => $tn,
            'fp' => $fp,
            'fn' => $fn,
            'accuracy' => $this->safeDivide($tp + $tn, $total),
            'precision' => $this->safeDivide($tp, $tp + $fp),
            'recall' => $this->safeDivide($tp, $tp + $fn),
            'f1' => $this->safeDivide(2 * $tp, (2 * $tp) + $fp + $fn),
        ];
    }

    private function safeDivide(int|float $numerator, int|float $denominator): float
    {
        if ((float) $denominator === 0.0) {
            return 0.0;
        }

        return (float) $numerator / (float) $denominator;
    }
}

