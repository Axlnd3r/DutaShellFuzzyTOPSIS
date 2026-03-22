<?php

namespace App\Services\Fuzzy;

class MembershipFunctionService
{
    public function triangular(float $x, float $a, float $b, float $c): float
    {
        if ($x <= $a) {
            return 0.0;
        }

        if ($x <= $b) {
            return $this->safeDivide($x - $a, $b - $a);
        }

        if ($x <= $c) {
            return $this->safeDivide($c - $x, $c - $b);
        }

        return 0.0;
    }

    private function safeDivide(float $numerator, float $denominator): float
    {
        if (abs($denominator) < 1.0e-12) {
            return 0.0;
        }

        return $numerator / $denominator;
    }
}

