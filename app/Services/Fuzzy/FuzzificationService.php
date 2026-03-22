<?php

namespace App\Services\Fuzzy;

class FuzzificationService
{
    /**
     * Konversi matriks keputusan (crisp) → matriks fuzzy (TFN).
     *
     * Setiap nilai crisp x dikonversi menjadi Triangular Fuzzy Number (a, b, c):
     *   a = max(0, x - spread)   // lower bound
     *   b = x                    // middle (nilai asli)
     *   c = min(1, x + spread)   // upper bound
     *
     * Spread default 0.1 menangani ketidakpastian ±10% dari skala [0,1].
     */
    public function process(array $matrix, float $spread = 0.1): array
    {
        $fuzzyMatrix = [];

        foreach ($matrix as $caseId => $criteriaValues) {
            foreach ($criteriaValues as $criterion => $value) {
                $x = $this->clamp((float) $value, 0.0, 1.0);

                // Triangular Fuzzy Number (lower, middle, upper)
                $a = max(0.0, $x - $spread);  // lower bound
                $b = $x;                        // middle (nilai asli)
                $c = min(1.0, $x + $spread);   // upper bound

                $fuzzyMatrix[$caseId][$criterion] = [$a, $b, $c];
            }
        }

        return $fuzzyMatrix;
    }

    private function clamp(float $value, float $min, float $max): float
    {
        if ($value < $min) {
            return $min;
        }

        if ($value > $max) {
            return $max;
        }

        return $value;
    }
}
