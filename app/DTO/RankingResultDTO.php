<?php

namespace App\DTO;

class RankingResultDTO
{
    public function __construct(
        public int $caseId,
        public float $score,
        public int $rank,
    ) {
    }

    public function toArray(): array
    {
        return [
            'case_id' => $this->caseId,
            'score' => round($this->score, 6),
            'rank' => $this->rank,
        ];
    }
}

