<?php

namespace App\Services\Inference;

use App\DTO\CaseDTO;
use App\Models\FuzzyTopsisInference;
use App\Services\Evaluation\ConfusionMatrixService;
use App\Services\Fuzzy\DefuzzificationService;
use App\Services\Fuzzy\FuzzificationService;
use App\Services\Topsis\DecisionMatrixService;
use App\Services\Topsis\DistanceService;
use App\Services\Topsis\IdealSolutionService;
use App\Services\Topsis\NormalizationService;
use App\Services\Topsis\RankingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class FuzzyTopsisService
{
    public function __construct(
        private FuzzificationService $fuzzification,
        private DefuzzificationService $defuzzification,
        private DecisionMatrixService $decisionMatrix,
        private NormalizationService $normalization,
        private IdealSolutionService $idealSolution,
        private DistanceService $distance,
        private RankingService $ranking,
        private ConfusionMatrixService $confusionMatrix,
    ) {
    }

    public function infer(array $input): array
    {
        $dataset = $this->loadCases($input);

        $decision = $this->decisionMatrix->build(
            $dataset['base_cases'],
            $dataset['test_case'],
            $dataset['criteria']
        );

        $fuzzyMatrix = $this->fuzzification->process($decision['matrix']);
        $crispMatrix = $this->defuzzification->process($fuzzyMatrix);

        $normalizedResult = $this->normalization->calculate($crispMatrix, $decision['weights']);
        $ideal = $this->idealSolution->calculate($normalizedResult['weighted'], $decision['types']);
        $distances = $this->distance->calculate($normalizedResult['weighted'], $ideal);
        $ranking = $this->ranking->rank($distances);

        $topK = max(1, (int) ($input['top_k'] ?? count($ranking)));
        $rankingOutput = array_slice($ranking, 0, $topK);

        $positiveStrategy = strtolower((string) ($input['positive_strategy'] ?? 'top1'));
        $threshold = (float) ($input['threshold'] ?? 0.6);

        $actual = $this->buildActualLabels($ranking, $dataset['goal_map'], $dataset['test_case']->goalValue);
        $predicted = $this->buildPredictedLabels($ranking, $positiveStrategy, $threshold);

        $evaluation = null;
        $evaluationNote = null;
        if ($actual !== [] && $predicted !== []) {
            $evaluation = $this->confusionMatrix->evaluate($actual, $predicted);
        } else {
            $evaluationNote = 'Goal test case kosong, confusion matrix tidak dihitung.';
        }

        $intermediate = [
            'decision_matrix' => $decision['matrix'],
            'fuzzy_matrix' => $fuzzyMatrix,
            'crisp_matrix' => $crispMatrix,
            'normalized_matrix' => $normalizedResult['normalized'],
            'weighted_matrix' => $normalizedResult['weighted'],
            'ideal_solution' => $ideal,
            'distance' => $distances,
        ];

        $debugFile = null;
        $saveDebug = $this->toBool($input['save_debug'] ?? true, true);
        if ($saveDebug) {
            $debugFile = $this->storeDebugSnapshot(
                $dataset['user_id'],
                $dataset['test_case']->caseId,
                [
                    'meta' => [
                        'user_id' => $dataset['user_id'],
                        'test_case_id' => $dataset['test_case']->caseId,
                        'positive_strategy' => $positiveStrategy,
                        'threshold' => $threshold,
                        'criteria' => $decision['criteria'],
                        'weights' => $decision['weights'],
                        'types' => $decision['types'],
                        'ranges' => $decision['ranges'],
                    ],
                    'intermediate' => $intermediate,
                    'ranking' => $ranking,
                    'evaluation' => $evaluation,
                ]
            );
        }

        $startTime = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);
        $executionTime = microtime(true) - $startTime;

        $this->storeToDatabase(
            $dataset['user_id'],
            $dataset['test_case'],
            $ranking,
            $dataset['goal_map'],
            $evaluation,
            $executionTime
        );

        $response = [
            'user_id' => $dataset['user_id'],
            'test_case_id' => $dataset['test_case']->caseId,
            'criteria' => $decision['criteria'],
            'weights' => $decision['weights'],
            'ranking' => $rankingOutput,
            'evaluation' => $evaluation,
            'evaluation_note' => $evaluationNote,
            'debug_file' => $debugFile,
        ];

        $includeIntermediate = $this->toBool($input['include_intermediate'] ?? true, true);
        if ($includeIntermediate) {
            $response['intermediate'] = $intermediate;
        }

        return $response;
    }

    private function loadCases(array $input): array
    {
        $userId = isset($input['user_id'])
            ? (int) $input['user_id']
            : (int) (Auth::id() ?? 0);

        if ($userId <= 0) {
            throw new InvalidArgumentException('user_id tidak valid. Pastikan user login atau kirim user_id.');
        }

        $baseTable = 'case_user_' . $userId;
        $testTable = 'test_case_user_' . $userId;

        if (!Schema::hasTable($baseTable)) {
            throw new InvalidArgumentException("Tabel {$baseTable} tidak ditemukan.");
        }
        if (!Schema::hasTable($testTable)) {
            throw new InvalidArgumentException("Tabel {$testTable} tidak ditemukan.");
        }

        $goalColumn = $this->resolveGoalColumn($userId);
        $criteria = $this->resolveCriteria($userId, $baseTable, $goalColumn);
        if ($criteria === []) {
            throw new InvalidArgumentException('Kriteria inferensi tidak ditemukan untuk Fuzzy TOPSIS.');
        }

        $criteriaColumns = array_map(
            static fn (array $criterion): string => (string) $criterion['column'],
            $criteria
        );

        $baseCases = [];
        $goalMap = [];
        $baseRows = DB::table($baseTable)->get();
        foreach ($baseRows as $rowObject) {
            $row = (array) $rowObject;
            $case = CaseDTO::fromArray($row, $criteriaColumns, $goalColumn);
            if ($case->caseId <= 0) {
                continue;
            }

            $baseCases[] = $case;
            $goalMap[$case->caseId] = $case->goalValue;
        }

        if ($baseCases === []) {
            throw new InvalidArgumentException("Tabel {$baseTable} tidak memiliki data kasus.");
        }

        $testRow = $this->resolveTestCaseRow($testTable, $input);
        $testCase = CaseDTO::fromArray($testRow, $criteriaColumns, $goalColumn);
        if ($testCase->caseId <= 0) {
            throw new InvalidArgumentException('Test case tidak valid.');
        }

        return [
            'user_id' => $userId,
            'base_table' => $baseTable,
            'test_table' => $testTable,
            'goal_column' => $goalColumn,
            'criteria' => $criteria,
            'base_cases' => $baseCases,
            'test_case' => $testCase,
            'goal_map' => $goalMap,
        ];
    }

    private function resolveGoalColumn(int $userId): ?string
    {
        $goalAttribute = DB::table('atribut')
            ->where('user_id', $userId)
            ->where(function ($query) {
                $query->where('goal', 'T')
                    ->orWhere('goal', 1)
                    ->orWhere('goal', true);
            })
            ->orderBy('atribut_id')
            ->first();

        if (!$goalAttribute) {
            return null;
        }

        return $this->buildAttributeColumnName(
            (int) $goalAttribute->atribut_id,
            (string) $goalAttribute->atribut_name
        );
    }

    private function resolveCriteria(int $userId, string $baseTable, ?string $goalColumn): array
    {
        $baseColumns = Schema::getColumnListing($baseTable);
        $hasWeight = Schema::hasColumn('atribut', 'weight');
        $hasType = Schema::hasColumn('atribut', 'type');

        $attributes = DB::table('atribut')
            ->where('user_id', $userId)
            ->where(function ($query) {
                $query->where('goal', '!=', 'T')
                    ->orWhere('goal', 'F')
                    ->orWhere('goal', 0)
                    ->orWhereNull('goal');
            })
            ->orderBy('atribut_id')
            ->get();

        $criteria = [];
        foreach ($attributes as $attribute) {
            $column = $this->buildAttributeColumnName(
                (int) $attribute->atribut_id,
                (string) $attribute->atribut_name
            );

            if (!in_array($column, $baseColumns, true)) {
                continue;
            }
            if ($goalColumn !== null && $column === $goalColumn) {
                continue;
            }

            $criteria[] = [
                'column' => $column,
                'weight' => $hasWeight ? (float) ($attribute->weight ?? 1.0) : 1.0,
                'type' => $hasType ? (string) ($attribute->type ?? 'benefit') : 'benefit',
            ];
        }

        if ($criteria !== []) {
            return $criteria;
        }

        foreach ($baseColumns as $column) {
            if (in_array($column, ['case_id', 'user_id', 'case_num'], true)) {
                continue;
            }
            if ($goalColumn !== null && $column === $goalColumn) {
                continue;
            }

            $criteria[] = [
                'column' => $column,
                'weight' => 1.0,
                'type' => 'benefit',
            ];
        }

        return $criteria;
    }

    private function resolveTestCaseRow(string $testTable, array $input): array
    {
        $caseId = isset($input['case_id']) ? (int) $input['case_id'] : null;
        if ($caseId !== null && $caseId > 0) {
            $row = DB::table($testTable)->where('case_id', $caseId)->first();
            if ($row === null) {
                throw new InvalidArgumentException("Test case {$caseId} tidak ditemukan.");
            }
            return (array) $row;
        }

        $algorithm = trim((string) ($input['algorithm'] ?? 'Fuzzy TOPSIS'));
        $query = DB::table($testTable);

        if (Schema::hasColumn($testTable, 'algoritma') && $algorithm !== '') {
            $query->where('algoritma', $algorithm);
        }

        $row = $query->orderByDesc('case_id')->first();

        if ($row === null) {
            $fallback = DB::table($testTable)->orderByDesc('case_id')->first();
            if ($fallback === null) {
                throw new InvalidArgumentException("Tabel {$testTable} tidak memiliki test case.");
            }
            return (array) $fallback;
        }

        return (array) $row;
    }

    private function buildActualLabels(array $ranking, array $goalMap, ?string $testGoal): array
    {
        $normalizedTestGoal = $this->normalizeLabel($testGoal);
        if ($normalizedTestGoal === null) {
            return [];
        }

        $actual = [];
        foreach ($ranking as $item) {
            $caseId = (int) ($item['case_id'] ?? 0);
            $caseGoal = $goalMap[$caseId] ?? null;
            $normalizedCaseGoal = $this->normalizeLabel($caseGoal);
            $actual[] = $normalizedCaseGoal !== null && $normalizedCaseGoal === $normalizedTestGoal ? 1 : 0;
        }

        return $actual;
    }

    private function buildPredictedLabels(array $ranking, string $strategy, float $threshold): array
    {
        if ($ranking === []) {
            return [];
        }

        if ($strategy === 'threshold') {
            $labels = [];
            foreach ($ranking as $item) {
                $labels[] = ((float) ($item['score'] ?? 0.0) >= $threshold) ? 1 : 0;
            }
            return $labels;
        }

        $labels = array_fill(0, count($ranking), 0);
        $labels[0] = 1; // TOP-1 sebagai prediksi positif
        return $labels;
    }

    private function normalizeLabel(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = strtolower(trim((string) $value));
        if ($text === '') {
            return null;
        }

        $text = preg_replace('/^\d+_/', '', $text) ?? $text;
        $text = str_replace(['-', '_'], ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $text = trim($text);

        $map = [
            'y' => 'yes',
            'ya' => 'yes',
            'yes' => 'yes',
            'true' => 'yes',
            'benar' => 'yes',
            '1' => 'yes',
            'n' => 'no',
            'tidak' => 'no',
            't' => 'no',
            'no' => 'no',
            'false' => 'no',
            'salah' => 'no',
            '0' => 'no',
        ];

        return $map[$text] ?? $text;
    }

    private function storeToDatabase(
        int $userId,
        CaseDTO $testCase,
        array $ranking,
        array $goalMap,
        ?array $evaluation,
        float $executionTime
    ): void {
        $tableName = FuzzyTopsisInference::ensureTable($userId);

        $testGoal = $testCase->goalValue;

        $rows = [];
        foreach ($ranking as $item) {
            $caseId = (int) ($item['case_id'] ?? 0);
            $score = (float) ($item['score'] ?? 0.0);
            $rank = (int) ($item['rank'] ?? 0);
            $sPlus = (float) ($item['s_plus'] ?? 0.0);
            $sMinus = (float) ($item['s_minus'] ?? 0.0);

            $baseGoal = $goalMap[$caseId] ?? null;
            $normalizedTestGoal = $this->normalizeLabel($testGoal);
            $normalizedBaseGoal = $this->normalizeLabel($baseGoal);
            $cocok = ($normalizedTestGoal !== null && $normalizedBaseGoal !== null && $normalizedTestGoal === $normalizedBaseGoal) ? '1' : '0';

            $rows[] = [
                'case_id' => $testCase->caseId,
                'case_goal' => $testGoal ?? '',
                'rule_id' => 'FT-' . $caseId,
                'rule_goal' => $baseGoal ?? '',
                'match_value' => round($score, 6),
                'score' => round($score, 6),
                'rank' => $rank,
                's_plus' => round($sPlus, 6),
                's_minus' => round($sMinus, 6),
                'cocok' => $cocok,
                'user_id' => $userId,
                'waktu' => round($executionTime, 14),
            ];
        }

        if ($rows !== []) {
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table($tableName)->insert($chunk);
            }
        }
    }

    private function storeDebugSnapshot(int $userId, int $testCaseId, array $payload): ?string
    {
        $timestamp = now()->format('Ymd_His_u');
        $relativePath = "fuzzy_topsis/user_{$userId}_case_{$testCaseId}_{$timestamp}.json";

        $json = json_encode($payload, JSON_PRETTY_PRINT);
        if ($json === false) {
            return null;
        }

        Storage::disk('local')->put($relativePath, $json);
        return 'storage/app/private/' . $relativePath;
    }

    private function buildAttributeColumnName(int $attributeId, string $attributeName): string
    {
        return $attributeId . '_' . $attributeName;
    }

    private function toBool(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null) {
            return $default;
        }

        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'n', 'off'], true)) {
            return false;
        }

        return $default;
    }
}

