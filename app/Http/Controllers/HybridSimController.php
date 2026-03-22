<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HybridSimController extends Controller
{
    public function show()
    {
        return view('admin.menu.HybridSim');
    }

    /**
     * Generate = Evaluasi similarity (LOOCV / k-fold / split) langsung via Laravel.
     * Tanpa eksekusi CLI agar konsisten lintas environment.
     */
    public function generate(Request $request)
    {
        $request->validate([
            'mode'  => 'required|in:hybrid,jaccard,cosine',
            'eval'  => 'required|in:loocv,kfold,split',
            'param' => 'nullable|string',
            'alpha' => 'nullable|numeric|min:0|max:1',
        ]);

        $userId = Auth::user()->user_id;
        $mode   = $request->input('mode', 'hybrid');
        $eval   = $request->input('eval', 'loocv');
        $param  = $request->input('param');
        $alpha  = $request->input('alpha', 0.5);

        try {
            $tableBase = "case_user_{$userId}";
            $tableTest = "test_case_user_{$userId}";

            if (!Schema::hasTable($tableBase)) {
                if (Schema::hasTable($tableTest)) {
                    $tableBase = $tableTest;
                } else {
                    return back()->with('hs_err', "Training table tidak ditemukan: {$tableBase}");
                }
            }

            $columns = Schema::getColumnListing($tableBase);
            $goalCol = $this->resolveGoalColumn($userId, $tableBase, $columns);
            if (!$goalCol) {
                return back()->with('hs_err', 'Goal column tidak dapat ditentukan dari tabel training.');
            }

            $skip = ['case_id', 'user_id', 'case_num', 'algoritma', $goalCol];
            $attrCols = array_values(array_filter($columns, fn ($c) => !in_array($c, $skip, true)));
            if (!$attrCols) {
                return back()->with('hs_err', 'Tidak ada atribut untuk evaluasi (selain goal).');
            }

            $baseCases = array_map(static fn ($r) => (array) $r, DB::table($tableBase)->get()->all());
            if (count($baseCases) < 2) {
                return back()->with('hs_err', "Dataset training di {$tableBase} minimal harus 2 baris.");
            }

            $alpha = (float) $alpha;
            $alpha = max(0.0, min(1.0, $alpha));
            $weights = $this->buildAttributeWeights($baseCases, $attrCols);
            [$results, $records] = $this->runEvaluation($baseCases, $attrCols, $goalCol, $mode, $eval, $param, $weights, $alpha);

            $lines = ["Mode: {$mode} | Eval: {$eval}"];
            foreach ($results as $r) {
                $lines[] = sprintf(
                    '%s: total=%d | acc=%.4f | top3_hit=%.4f | thr_acc=%.4f | thr_cov=%.4f',
                    $r['label'],
                    $r['total'],
                    $r['accuracy'],
                    $r['topk_hit_rate'],
                    $r['thr_accuracy'],
                    $r['thr_coverage']
                );
            }

            $matrix = $this->buildMatrix($records, ucfirst($mode) . ' Similarity');

            return back()
                ->with('hs_ok', implode("\n", $lines))
                ->with('hs_matrix', $matrix);
        } catch (\Throwable $e) {
            return back()->with('hs_err', "Exception: " . $e->getMessage());
        }
    }

    private function resolveGoalColumn(int $userId, string $tableBase, array $columns): ?string
    {
        if (!$columns) {
            return null;
        }

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

            foreach ($columns as $col) {
                if (in_array($col, ['case_id', 'user_id', 'case_num', 'algoritma'], true)) {
                    continue;
                }
                $parts = explode('_', $col, 2);
                if (count($parts) === 2 && strtolower($parts[1]) === strtolower((string) $goal->atribut_name)) {
                    return $col;
                }
            }
        }

        foreach ($columns as $col) {
            if (!in_array($col, ['case_id', 'user_id', 'case_num', 'algoritma'], true)) {
                return $col;
            }
        }

        return null;
    }

    private function normalizeValue($value): string
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/^\d+_/', '', $value);
        $value = str_replace(['-', '_'], ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value);
        $value = trim($value);

        $map = [
            'y' => 'yes', 'ya' => 'yes', 'yes' => 'yes', 'true' => 'yes', 'benar' => 'yes',
            'n' => 'no', 'tidak' => 'no', 't' => 'no', 'no' => 'no', 'false' => 'no', 'salah' => 'no', 'f' => 'no',
        ];

        return $map[$value] ?? $value;
    }

    private function normalizeCaseRow(array $row, array $attrCols): array
    {
        $out = [];
        foreach ($attrCols as $col) {
            $out[$col] = $this->normalizeValue($row[$col] ?? '');
        }
        return $out;
    }

    private function buildAttributeWeights(array $baseCases, array $attrCols): array
    {
        $valueCounts = [];
        foreach ($baseCases as $row) {
            foreach ($attrCols as $col) {
                $val = $this->normalizeValue($row[$col] ?? '');
                if (!isset($valueCounts[$col])) {
                    $valueCounts[$col] = [];
                }
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
                if ($p > 0) {
                    $entropy -= $p * log($p);
                }
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

    private function computeScore(array $testNorm, array $trainNorm, array $attrCols, array $weights, string $mode, float $alpha): float
    {
        $n = count($attrCols);
        if ($n === 0) {
            return 0.0;
        }

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

        $cosine = $matches / $n;
        $union = (2 * $n) - $matches;
        $jaccard = $union > 0 ? $matches / $union : 0.0;

        $wCosine = $wTotal > 0 ? $wMatch / $wTotal : $cosine;
        $wUnion = (2 * $wTotal) - $wMatch;
        $wJaccard = $wUnion > 0 ? $wMatch / $wUnion : $jaccard;
        $hybrid = ($alpha * $wCosine) + ((1 - $alpha) * $wJaccard);

        if ($mode === 'jaccard') {
            return $jaccard;
        }
        if ($mode === 'cosine') {
            return $cosine;
        }
        return $hybrid;
    }

    private function evaluateSet(array $train, array $test, array $attrCols, string $goalCol, string $mode, array $weights, float $alpha): array
    {
        $topK = 3;
        $threshold = 0.5;

        $normTrain = [];
        foreach ($train as $row) {
            $normTrain[] = [
                'raw' => $row,
                'norm' => $this->normalizeCaseRow($row, $attrCols),
            ];
        }

        $total = 0;
        $correct = 0;
        $topkTotal = 0;
        $topkHit = 0;
        $thrTotal = 0;
        $thrCorrect = 0;
        $records = [];

        foreach ($test as $trow) {
            if (!$normTrain) {
                continue;
            }
            $startedAt = microtime(true);
            $total++;
            $tNorm = $this->normalizeCaseRow($trow, $attrCols);
            $scores = [];

            foreach ($normTrain as $item) {
                $scores[] = [
                    'score' => $this->computeScore($tNorm, $item['norm'], $attrCols, $weights, $mode, $alpha),
                    'goal' => $item['raw'][$goalCol] ?? '',
                    'rule_id' => $item['raw']['case_id'] ?? null,
                ];
            }

            usort($scores, static fn ($a, $b) => $b['score'] <=> $a['score']);
            $best = $scores[0];

            $actualRaw = (string) ($trow[$goalCol] ?? '');
            $predRaw = (string) ($best['goal'] ?? '');
            $actual = $this->normalizeValue($actualRaw);
            $pred = $this->normalizeValue($predRaw);
            if ($actual === $pred) {
                $correct++;
            }

            if ($best['score'] >= $threshold) {
                $thrTotal++;
                if ($actual === $pred) {
                    $thrCorrect++;
                }
            }

            $topkTotal++;
            $hit = false;
            foreach (array_slice($scores, 0, $topK) as $cand) {
                if ($actual === $this->normalizeValue($cand['goal'] ?? '')) {
                    $hit = true;
                    break;
                }
            }
            if ($hit) {
                $topkHit++;
            }

            $records[] = [
                'case_id' => isset($trow['case_id']) ? (string) $trow['case_id'] : null,
                'case_goal' => $goalCol . '=' . $actualRaw,
                'rule_id' => isset($best['rule_id']) ? (string) $best['rule_id'] : null,
                'rule_goal' => $goalCol . '=' . $predRaw,
                'match_value' => (float) ($best['score'] ?? 0),
                'cocok' => $actual === $pred ? '1' : '0',
                'waktu' => microtime(true) - $startedAt,
                'actual' => $actual,
                'pred' => $pred,
            ];
        }

        return [
            'total' => $total,
            'correct' => $correct,
            'accuracy' => $total > 0 ? $correct / $total : 0.0,
            'topk_hit_rate' => $topkTotal > 0 ? $topkHit / $topkTotal : 0.0,
            'thr_accuracy' => $thrTotal > 0 ? $thrCorrect / $thrTotal : 0.0,
            'thr_coverage' => $total > 0 ? $thrTotal / $total : 0.0,
            'records' => $records,
        ];
    }

    private function runEvaluation(array $baseCases, array $attrCols, string $goalCol, string $mode, string $eval, $param, array $weights, float $alpha): array
    {
        $results = [];
        $allRecords = [];
        $totalRows = count($baseCases);

        if ($eval === 'loocv') {
            $correct = 0;
            $topkRateSum = 0.0;
            $thrAccSum = 0.0;
            $thrCovSum = 0.0;
            $valid = 0;

            foreach ($baseCases as $i => $testRow) {
                $train = $baseCases;
                array_splice($train, $i, 1);
                $m = $this->evaluateSet($train, [$testRow], $attrCols, $goalCol, $mode, $weights, $alpha);
                if ($m['total'] === 0) {
                    continue;
                }
                $valid += $m['total'];
                $correct += $m['correct'];
                $topkRateSum += $m['topk_hit_rate'];
                $thrAccSum += $m['thr_accuracy'];
                $thrCovSum += $m['thr_coverage'];
                $allRecords = array_merge($allRecords, $m['records']);
            }

            $den = max($valid, 1);
            $results[] = [
                'label' => 'LOO-CV',
                'total' => $valid,
                'accuracy' => $correct / $den,
                'topk_hit_rate' => $topkRateSum / $den,
                'thr_accuracy' => $thrAccSum / $den,
                'thr_coverage' => $thrCovSum / $den,
            ];

            return [$results, $allRecords];
        }

        if ($eval === 'kfold') {
            $k = (int) ($param ?: 5);
            $k = max(2, min($k, $totalRows));
            $shuffled = $baseCases;
            shuffle($shuffled);

            $folds = array_fill(0, $k, []);
            foreach ($shuffled as $idx => $row) {
                $folds[$idx % $k][] = $row;
            }

            $sumAcc = 0.0;
            $sumTopk = 0.0;
            $sumThrAcc = 0.0;
            $sumThrCov = 0.0;
            $sumTotal = 0;
            $usedFolds = 0;

            for ($i = 0; $i < $k; $i++) {
                $test = $folds[$i];
                $train = [];
                for ($j = 0; $j < $k; $j++) {
                    if ($j === $i) {
                        continue;
                    }
                    $train = array_merge($train, $folds[$j]);
                }

                $m = $this->evaluateSet($train, $test, $attrCols, $goalCol, $mode, $weights, $alpha);
                if ($m['total'] === 0) {
                    continue;
                }

                $usedFolds++;
                $sumAcc += $m['accuracy'];
                $sumTopk += $m['topk_hit_rate'];
                $sumThrAcc += $m['thr_accuracy'];
                $sumThrCov += $m['thr_coverage'];
                $sumTotal += $m['total'];
                $allRecords = array_merge($allRecords, $m['records']);

                $results[] = [
                    'label' => 'Fold' . ($i + 1),
                    'total' => $m['total'],
                    'accuracy' => $m['accuracy'],
                    'topk_hit_rate' => $m['topk_hit_rate'],
                    'thr_accuracy' => $m['thr_accuracy'],
                    'thr_coverage' => $m['thr_coverage'],
                ];
            }

            if ($usedFolds === 0) {
                throw new \RuntimeException('K-Fold tidak dapat dijalankan pada dataset saat ini.');
            }

            $results[] = [
                'label' => 'FoldAvg',
                'total' => $sumTotal,
                'accuracy' => $sumAcc / $usedFolds,
                'topk_hit_rate' => $sumTopk / $usedFolds,
                'thr_accuracy' => $sumThrAcc / $usedFolds,
                'thr_coverage' => $sumThrCov / $usedFolds,
            ];

            return [$results, $allRecords];
        }

        // split
        $ratio = (float) ($param ?: 0.8);
        $ratio = max(0.1, min(0.95, $ratio));
        $shuffled = $baseCases;
        shuffle($shuffled);
        $cut = (int) floor(count($shuffled) * $ratio);
        $cut = max(1, min($cut, count($shuffled) - 1));
        $train = array_slice($shuffled, 0, $cut);
        $test = array_slice($shuffled, $cut);
        $m = $this->evaluateSet($train, $test, $attrCols, $goalCol, $mode, $weights, $alpha);
        $results[] = [
            'label' => 'Split',
            'total' => $m['total'],
            'accuracy' => $m['accuracy'],
            'topk_hit_rate' => $m['topk_hit_rate'],
            'thr_accuracy' => $m['thr_accuracy'],
            'thr_coverage' => $m['thr_coverage'],
        ];
        $allRecords = array_merge($allRecords, $m['records']);

        return [$results, $allRecords];
    }

    private function buildMatrix(array $records, string $algo): array
    {
        $counts = [];
        $actuals = [];
        $preds = [];
        $total = 0;
        $correct = 0;

        foreach ($records as $rec) {
            $a = (string) ($rec['actual'] ?? '');
            $p = (string) ($rec['pred'] ?? '');
            if ($a === '' || $p === '') {
                continue;
            }

            $counts[$a][$p] = ($counts[$a][$p] ?? 0) + 1;
            $actuals[$a] = true;
            $preds[$p] = true;
            $total++;
            if ($a === $p) {
                $correct++;
            }
        }

        return [
            'algo' => $algo,
            'matrix' => [
                'counts' => $counts,
                'actuals' => array_keys($actuals),
                'preds' => array_keys($preds),
                'total' => $total,
                'correct' => $correct,
            ],
        ];
    }
}
