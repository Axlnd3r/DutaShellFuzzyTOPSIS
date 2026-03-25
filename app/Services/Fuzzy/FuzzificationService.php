<?php

namespace App\Services\Fuzzy;

class FuzzificationService
{
    /**
     * Konversi matriks keputusan (crisp) → matriks fuzzy (TFN).
     *
     * Semua nilai sudah dalam skala [0,1] (baik cost maupun benefit),
     * sehingga spread uniform 0.1 diterapkan ke semua kriteria.
     *
     * TFN(x) = (max(0, x - 0.1), x, min(1, x + 0.1))
     *
     * Parameter $types dan $ranges diterima untuk kompatibilitas
     * dengan pemanggil, namun tidak digunakan karena skala sudah uniform.
     */
    public function process(array $matrix, array $types = [], array $ranges = [], float $spread = 0.1): array
    {
        $fuzzyMatrix = [];

        foreach ($matrix as $caseId => $criteriaValues) {
            foreach ($criteriaValues as $criterion => $value) {
                $x = $this->clamp((float) $value, 0.0, 1.0);

                $a = max(0.0, $x - $spread);
                $b = $x;
                $c = min(1.0, $x + $spread);

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