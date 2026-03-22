<?php

namespace App\Services\Topsis;

use App\DTO\RankingResultDTO;

class RankingService
{
    public function rank(array $distances): array
    {
        usort($distances, function (array $left, array $right): int {
            $scoreCompare = (float) $right['score'] <=> (float) $left['score'];
            if ($scoreCompare !== 0) {
                return $scoreCompare;
            }

            $plusCompare = (float) $left['s_plus'] <=> (float) $right['s_plus'];
            if ($plusCompare !== 0) {
                return $plusCompare;
            }

            return (int) $left['case_id'] <=> (int) $right['case_id'];
        });

        $results = [];
        $rank = 1;
        foreach ($distances as $distance) {
            $dto = new RankingResultDTO(
                (int) $distance['case_id'],
                (float) $distance['score'],
                $rank
            );

            $row = $dto->toArray();
            $row['s_plus'] = round((float) $distance['s_plus'], 6);
            $row['s_minus'] = round((float) $distance['s_minus'], 6);
            $results[] = $row;
            $rank++;
        }

        return $results;
    }
}

