<?php

namespace App\DTO;

class CaseDTO
{
    public function __construct(
        public int $caseId,
        public array $criteriaValues,
        public ?string $goalValue = null,
    ) {
    }

    public static function fromArray(array $row, array $criteriaColumns, ?string $goalColumn = null): self
    {
        $criteriaValues = [];
        foreach ($criteriaColumns as $column) {
            $criteriaValues[$column] = $row[$column] ?? null;
        }

        $goalValue = null;
        if ($goalColumn !== null && array_key_exists($goalColumn, $row) && $row[$goalColumn] !== null) {
            $goalValue = (string) $row[$goalColumn];
        }

        return new self(
            (int) ($row['case_id'] ?? 0),
            $criteriaValues,
            $goalValue,
        );
    }

    public function toArray(): array
    {
        return [
            'case_id' => $this->caseId,
            'criteria_values' => $this->criteriaValues,
            'goal_value' => $this->goalValue,
        ];
    }
}

