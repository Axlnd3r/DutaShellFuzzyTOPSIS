<?php

namespace App\Http\Controllers;

use App\DTO\CaseDTO;
use App\Services\Fuzzy\DefuzzificationService;
use App\Services\Fuzzy\FuzzificationService;
use App\Services\Topsis\DecisionMatrixService;
use App\Services\Topsis\DistanceService;
use App\Services\Topsis\IdealSolutionService;
use App\Services\Topsis\NormalizationService;
use App\Services\Topsis\RankingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EvaluationController extends Controller
{
    private FuzzificationService $fuzzification;
    private DefuzzificationService $defuzzification;
    private DecisionMatrixService $decisionMatrix;
    private NormalizationService $normalization;
    private IdealSolutionService $idealSolution;
    private DistanceService $distance;
    private RankingService $ranking;

    public function __construct(
        FuzzificationService $fuzzification,
        DefuzzificationService $defuzzification,
        DecisionMatrixService $decisionMatrix,
        NormalizationService $normalization,
        IdealSolutionService $idealSolution,
        DistanceService $distance,
        RankingService $ranking
    ) {
        $this->fuzzification = $fuzzification;
        $this->defuzzification = $defuzzification;
        $this->decisionMatrix = $decisionMatrix;
        $this->normalization = $normalization;
        $this->idealSolution = $idealSolution;
        $this->distance = $distance;
        $this->ranking = $ranking;
    }

    public function show()
    {
        return view('admin.menu.evaluation');
    }

    /**
     * Evaluasi dengan 2 mode otomatis:
     *
     * Mode B (Fixed Test Set) — digunakan jika case_user dan test_case_user BERBEDA:
     *   case_user_{id} = pool training, test_case_user_{id} = fixed test set
     *   80/20 → train=semua basis, test=fixed test saja
     *   70/30 → train=70% basis, test=sisa basis + fixed test, dst.
     *
     * Mode A (Self-Split) — fallback jika data overlap atau test_case tidak ada:
     *   Hanya menggunakan case_user_{id}, di-split langsung per skenario.
     *   80/20 → 80% train, 20% test dari pool yang sama.
     */
    public function run(Request $request)
    {
        $request->validate([
            'seed' => 'nullable|integer|min:1',
        ]);

        $userId = Auth::user()->user_id;
        $seed = (int) ($request->input('seed', 42));

        try {
            $tableBase = "case_user_{$userId}";
            $tableTest = "test_case_user_{$userId}";

            if (!Schema::hasTable($tableBase)) {
                return back()->with('eval_err', "Tabel {$tableBase} tidak ditemukan.");
            }

            $columns = Schema::getColumnListing($tableBase);
            $goalCol = $this->resolveGoalColumn($userId, $tableBase, $columns);
            if (!$goalCol) {
                return back()->with('eval_err', 'Goal column tidak ditemukan.');
            }

            $skip = ['case_id', 'user_id', 'case_num', 'algoritma'];
            $attrCols = array_values(array_filter($columns, fn($c) => !in_array($c, array_merge($skip, [$goalCol]), true)));
            if (!$attrCols) {
                return back()->with('eval_err', 'Tidak ada atribut kriteria.');
            }

            // Load basis kasus
            $baseCases = array_map(fn($r) => (array) $r, DB::table($tableBase)->get()->all());
            $originalBaseCount = count($baseCases);

            // Tentukan mode: B (fixed test set) atau A (self-split)
            $fixedTestCases = [];
            $removedCount = 0;
            $evalMode = 'A'; // default: self-split

            if (Schema::hasTable($tableTest)) {
                $fixedTestCases = array_map(fn($r) => (array) $r, DB::table($tableTest)->get()->all());

                if (count($fixedTestCases) > 0) {
                    // Cek overlap: keluarkan dari baseCases kasus yang case_id-nya ada di test
                    $testCaseIds = array_flip(array_filter(
                        array_map(fn($r) => $r['case_id'] ?? null, $fixedTestCases),
                        fn($id) => $id !== null
                    ));
                    $dedupBase = array_values(array_filter(
                        $baseCases,
                        fn($r) => !isset($testCaseIds[$r['case_id'] ?? null])
                    ));
                    $removedCount = $originalBaseCount - count($dedupBase);

                    // Jika setelah dedup masih cukup besar → gunakan Mode B
                    if (count($dedupBase) >= 5) {
                        $evalMode = 'B';
                        $baseCases = $dedupBase;
                    }
                    // Jika tidak cukup (overlap terlalu banyak) → fallback Mode A
                    // baseCases tetap original, fixedTestCases diabaikan
                }
            }

            $totalBase = count($baseCases);

            if ($totalBase < 10) {
                return back()->with('eval_err', "Dataset terlalu kecil ({$totalBase} kasus, minimal 10).");
            }

            $totalFixed = ($evalMode === 'B') ? count($fixedTestCases) : 0;
            $totalAll = $totalBase + $totalFixed;

            // Resolve criteria for Fuzzy TOPSIS
            $criteria = $this->resolveCriteria($userId, $tableBase, $goalCol);

            // Deterministic shuffle basis kasus (seed-based)
            mt_srand($seed);
            $shuffledBase = $baseCases;
            shuffle($shuffledBase);

            // 5 skenario
            $scenarios = [
                ['label' => '80/20', 'ratio' => 0.8],
                ['label' => '70/30', 'ratio' => 0.7],
                ['label' => '60/40', 'ratio' => 0.6],
                ['label' => '50/50', 'ratio' => 0.5],
                ['label' => '40/60', 'ratio' => 0.4],
            ];

            $ftResults = [];
            $hsResults = [];
            $jcResults = [];
            $csResults = [];

            foreach ($scenarios as $scenario) {
                $label = $scenario['label'];

                if ($evalMode === 'B') {
                    // Mode B: fixed test set
                    $trainCount = min((int) floor($totalAll * $scenario['ratio']), $totalBase);
                    $train = array_slice($shuffledBase, 0, $trainCount);
                    $unusedBase = array_slice($shuffledBase, $trainCount);
                    $test = array_merge($unusedBase, $fixedTestCases);
                } else {
                    // Mode A: self-split dari baseCases saja
                    $trainCount = (int) floor($totalBase * $scenario['ratio']);
                    $trainCount = max($trainCount, 1);
                    $train = array_slice($shuffledBase, 0, $trainCount);
                    $test = array_slice($shuffledBase, $trainCount);
                }

                if (empty($test)) continue;

                $testCount = count($test);
                $weights = $this->buildEntropyWeights($train, $attrCols);

                // --- 1. Fuzzy TOPSIS ---
                $startFT = microtime(true);
                $ftEval = $this->evaluateFuzzyTopsis($train, $test, $criteria, $goalCol, $attrCols);
                $ftEval['time'] = round(microtime(true) - $startFT, 4);
                $ftEval['label'] = $label;
                $ftEval['train_count'] = count($train);
                $ftEval['test_count'] = $testCount;
                $ftResults[] = $ftEval;

                // --- 2. Hybrid Similarity ---
                $startHS = microtime(true);
                $hsEval = $this->evaluateSimilarity($train, $test, $attrCols, $goalCol, $weights, 'hybrid', 0.5);
                $hsEval['time'] = round(microtime(true) - $startHS, 4);
                $hsEval['label'] = $label;
                $hsEval['train_count'] = count($train);
                $hsEval['test_count'] = $testCount;
                $hsResults[] = $hsEval;

                // --- 3. Jaccard Similarity ---
                $startJC = microtime(true);
                $jcEval = $this->evaluateSimilarity($train, $test, $attrCols, $goalCol, $weights, 'jaccard', 0.5);
                $jcEval['time'] = round(microtime(true) - $startJC, 4);
                $jcEval['label'] = $label;
                $jcEval['train_count'] = count($train);
                $jcEval['test_count'] = $testCount;
                $jcResults[] = $jcEval;

                // --- 4. Cosine Similarity ---
                $startCS = microtime(true);
                $csEval = $this->evaluateSimilarity($train, $test, $attrCols, $goalCol, $weights, 'cosine', 0.5);
                $csEval['time'] = round(microtime(true) - $startCS, 4);
                $csEval['label'] = $label;
                $csEval['train_count'] = count($train);
                $csEval['test_count'] = $testCount;
                $csResults[] = $csEval;
            }

            return back()
                ->with('eval_ok', true)
                ->with('ft_results', $ftResults)
                ->with('hs_results', $hsResults)
                ->with('jc_results', $jcResults)
                ->with('cs_results', $csResults)
                ->with('eval_seed', $seed)
                ->with('eval_mode', $evalMode)
                ->with('total_base', $totalBase)
                ->with('total_test', $totalFixed)
                ->with('total_all', $totalAll)
                ->with('removed_overlap', $removedCount);
        } catch (\Throwable $e) {
            return back()->with('eval_err', 'Error: ' . $e->getMessage());
        }
    }

    // ==================== FUZZY TOPSIS BATCH EVALUATION ====================

    private function evaluateFuzzyTopsis(array $train, array $test, array $criteria, string $goalCol, array $attrCols): array
    {
        $criteriaColumns = array_map(fn($c) => $c['column'], $criteria);
        $records = [];

        // Build base CaseDTOs from train set
        $baseCases = [];
        $goalMap = [];
        foreach ($train as $row) {
            $case = CaseDTO::fromArray($row, $criteriaColumns, $goalCol);
            if ($case->caseId <= 0) continue;
            $baseCases[] = $case;
            $goalMap[$case->caseId] = $case->goalValue;
        }

        if (empty($baseCases)) {
            return $this->emptyResult();
        }

        foreach ($test as $testRow) {
            $testCase = CaseDTO::fromArray($testRow, $criteriaColumns, $goalCol);
            if ($testCase->caseId <= 0) continue;

            $actualGoal = $this->normalizeLabel($testCase->goalValue);

            // Run full Fuzzy TOPSIS pipeline
            $decision = $this->decisionMatrix->build($baseCases, $testCase, $criteria);
            if (empty($decision['matrix'])) {
                $records[] = [
                    'case_id' => $testCase->caseId,
                    'actual' => $actualGoal,
                    'predicted' => '',
                    'score' => 0,
                    'top_case' => 0,
                ];
                continue;
            }

            $fuzzyMatrix = $this->fuzzification->process($decision['matrix']);
            $crispMatrix = $this->defuzzification->process($fuzzyMatrix);
            $normalizedResult = $this->normalization->calculate($crispMatrix, $decision['weights']);
            $ideal = $this->idealSolution->calculate($normalizedResult['weighted'], $decision['types']);
            $distances = $this->distance->calculate($normalizedResult['weighted'], $ideal);
            $ranking = $this->ranking->rank($distances);

            // Top-1 prediction
            $top = $ranking[0] ?? null;
            $topCaseId = $top ? (int) $top['case_id'] : 0;
            $predictedGoal = $this->normalizeLabel($goalMap[$topCaseId] ?? '');
            $ccScore = $top ? (float) $top['score'] : 0.0;

            $records[] = [
                'case_id' => $testCase->caseId,
                'actual' => $actualGoal,
                'predicted' => $predictedGoal,
                'score' => $ccScore,
                'top_case' => $topCaseId,
            ];
        }

        return $this->buildMultiClassMetrics($records);
    }

    // ==================== SIMILARITY BATCH EVALUATION (Hybrid/Jaccard/Cosine) ====================

    /**
     * Evaluasi similarity dengan mode: hybrid, jaccard, atau cosine.
     * Formula SAMA PERSIS dengan HybridSimController::computeScore()
     *
     * - jaccard = matches / (2n - matches)          ← tanpa bobot
     * - cosine  = matches / n                       ← tanpa bobot
     * - hybrid  = α×weighted_cosine + (1-α)×weighted_jaccard  ← pakai bobot entropy
     */
    private function evaluateSimilarity(array $train, array $test, array $attrCols, string $goalCol, array $weights, string $mode, float $alpha): array
    {
        $records = [];

        $normTrain = [];
        foreach ($train as $row) {
            $normTrain[] = [
                'raw' => $row,
                'norm' => $this->normalizeCaseRow($row, $attrCols),
            ];
        }

        foreach ($test as $testRow) {
            $testNorm = $this->normalizeCaseRow($testRow, $attrCols);
            $actualGoal = $this->normalizeLabel($testRow[$goalCol] ?? '');

            $scores = [];
            foreach ($normTrain as $item) {
                $score = $this->computeSimilarityScore($testNorm, $item['norm'], $attrCols, $weights, $mode, $alpha);
                $scores[] = [
                    'score' => $score,
                    'goal' => $this->normalizeLabel($item['raw'][$goalCol] ?? ''),
                    'case_id' => $item['raw']['case_id'] ?? 0,
                ];
            }

            usort($scores, fn($a, $b) => $b['score'] <=> $a['score']);
            $best = $scores[0] ?? null;

            $records[] = [
                'case_id' => $testRow['case_id'] ?? 0,
                'actual' => $actualGoal,
                'predicted' => $best ? $best['goal'] : '',
                'score' => $best ? $best['score'] : 0,
                'top_case' => $best ? $best['case_id'] : 0,
            ];
        }

        return $this->buildMultiClassMetrics($records);
    }

    // ==================== MULTI-CLASS CONFUSION MATRIX ====================

    private function buildMultiClassMetrics(array $records): array
    {
        $labels = [];
        $matrix = []; // $matrix[actual][predicted] = count
        $total = 0;
        $correct = 0;

        foreach ($records as $rec) {
            $a = $rec['actual'];
            $p = $rec['predicted'];
            if ($a === '' || $p === '') continue;

            $labels[$a] = true;
            $labels[$p] = true;
            $matrix[$a][$p] = ($matrix[$a][$p] ?? 0) + 1;
            $total++;
            if ($a === $p) $correct++;
        }

        $labelList = array_keys($labels);
        sort($labelList);

        // Per-class precision, recall, f1
        $perClass = [];
        foreach ($labelList as $cls) {
            $tp = $matrix[$cls][$cls] ?? 0;
            $fp = 0;
            $fn = 0;
            foreach ($labelList as $other) {
                if ($other !== $cls) {
                    $fp += $matrix[$other][$cls] ?? 0;
                    $fn += $matrix[$cls][$other] ?? 0;
                }
            }
            $precision = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : 0;
            $recall = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : 0;
            $f1 = ($precision + $recall) > 0 ? 2 * $precision * $recall / ($precision + $recall) : 0;
            $support = $tp + $fn;

            $perClass[$cls] = [
                'precision' => round($precision, 4),
                'recall' => round($recall, 4),
                'f1' => round($f1, 4),
                'support' => $support,
                'tp' => $tp,
                'fp' => $fp,
                'fn' => $fn,
            ];
        }

        // Macro averages
        $macroPrecision = 0;
        $macroRecall = 0;
        $macroF1 = 0;
        $n = count($labelList);
        if ($n > 0) {
            foreach ($perClass as $m) {
                $macroPrecision += $m['precision'];
                $macroRecall += $m['recall'];
                $macroF1 += $m['f1'];
            }
            $macroPrecision /= $n;
            $macroRecall /= $n;
            $macroF1 /= $n;
        }

        $accuracy = $total > 0 ? $correct / $total : 0;

        return [
            'records' => $records,
            'matrix' => $matrix,
            'labels' => $labelList,
            'total' => $total,
            'correct' => $correct,
            'accuracy' => round($accuracy, 4),
            'macro_precision' => round($macroPrecision, 4),
            'macro_recall' => round($macroRecall, 4),
            'macro_f1' => round($macroF1, 4),
            'per_class' => $perClass,
        ];
    }

    private function emptyResult(): array
    {
        return [
            'records' => [],
            'matrix' => [],
            'labels' => [],
            'total' => 0,
            'correct' => 0,
            'accuracy' => 0,
            'macro_precision' => 0,
            'macro_recall' => 0,
            'macro_f1' => 0,
            'per_class' => [],
        ];
    }

    // ==================== HELPERS ====================

    private function resolveGoalColumn(int $userId, string $table, array $columns): ?string
    {
        $goal = DB::table('atribut')
            ->select('atribut_id', 'atribut_name')
            ->where('user_id', $userId)
            ->where('goal', 'T')
            ->first();

        if ($goal) {
            $candidate = $goal->atribut_id . '_' . $goal->atribut_name;
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }
        return null;
    }

    private function resolveCriteria(int $userId, string $baseTable, string $goalCol): array
    {
        $baseColumns = Schema::getColumnListing($baseTable);
        $attributes = DB::table('atribut')
            ->where('user_id', $userId)
            ->where(function ($q) {
                $q->where('goal', '!=', 'T')
                    ->orWhere('goal', 'F')
                    ->orWhere('goal', 0)
                    ->orWhereNull('goal');
            })
            ->orderBy('atribut_id')
            ->get();

        $criteria = [];
        foreach ($attributes as $attr) {
            $col = $attr->atribut_id . '_' . $attr->atribut_name;
            if (!in_array($col, $baseColumns, true)) continue;
            if ($col === $goalCol) continue;

            $criteria[] = [
                'column' => $col,
                'weight' => (float) ($attr->weight ?? 1.0),
                'type' => (string) ($attr->type ?? 'benefit'),
            ];
        }
        return $criteria;
    }

    private function normalizeLabel(?string $value): string
    {
        if ($value === null) return '';
        $text = strtolower(trim($value));
        if ($text === '') return '';
        $text = preg_replace('/^\d+_/', '', $text) ?? $text;
        $text = str_replace(['-', '_'], ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        return trim($text);
    }

    private function normalizeCaseRow(array $row, array $attrCols): array
    {
        $out = [];
        foreach ($attrCols as $col) {
            $out[$col] = $this->normalizeLabel((string) ($row[$col] ?? ''));
        }
        return $out;
    }

    /**
     * Hitung skor similarity — logic identik dengan HybridSimController::computeScore()
     */
    private function computeSimilarityScore(array $testNorm, array $trainNorm, array $attrCols, array $weights, string $mode, float $alpha): float
    {
        $n = count($attrCols);
        if ($n === 0) return 0.0;

        $matches = 0;
        $wMatch = 0.0;
        $wTotal = 0.0;

        foreach ($attrCols as $col) {
            $w = $weights[$col] ?? 1.0;
            $equal = ($testNorm[$col] ?? '') === ($trainNorm[$col] ?? '');
            if ($equal) {
                $matches++;
                $wMatch += $w;
            }
            $wTotal += $w;
        }

        // Unweighted (untuk jaccard/cosine murni)
        $cosine = $matches / $n;
        $union = (2 * $n) - $matches;
        $jaccard = $union > 0 ? $matches / $union : 0.0;

        // Weighted (untuk hybrid)
        $wCosine = $wTotal > 0 ? $wMatch / $wTotal : $cosine;
        $wUnion = (2 * $wTotal) - $wMatch;
        $wJaccard = $wUnion > 0 ? $wMatch / $wUnion : $jaccard;
        $hybrid = ($alpha * $wCosine) + ((1 - $alpha) * $wJaccard);

        if ($mode === 'jaccard') return $jaccard;
        if ($mode === 'cosine') return $cosine;
        return $hybrid; // mode === 'hybrid'
    }

    private function buildEntropyWeights(array $baseCases, array $attrCols): array
    {
        $valueCounts = [];
        foreach ($baseCases as $row) {
            foreach ($attrCols as $col) {
                $val = $this->normalizeLabel((string) ($row[$col] ?? ''));
                $valueCounts[$col][$val] = ($valueCounts[$col][$val] ?? 0) + 1;
            }
        }

        $weights = [];
        $sumEntropy = 0.0;
        foreach ($attrCols as $col) {
            $counts = $valueCounts[$col] ?? [];
            $colTotal = array_sum($counts);
            if ($colTotal <= 0 || count($counts) <= 1) {
                $weights[$col] = 0.0;
                continue;
            }
            $entropy = 0.0;
            foreach ($counts as $c) {
                $p = $c / $colTotal;
                if ($p > 0) $entropy -= $p * log($p);
            }
            $weights[$col] = $entropy;
            $sumEntropy += $entropy;
        }

        if ($sumEntropy > 0) {
            foreach ($weights as $col => $w) {
                $weights[$col] = $w / $sumEntropy;
            }
        }
        return $weights;
    }
}
