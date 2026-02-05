<?php
// Evaluate Hybrid/Jaccard/Cosine Similarity with CV options
// Usage: php eval_similarity.php {user_id} [mode=hybrid|jaccard|cosine] [eval=loocv|kfold|split|testcase] [param]
//  - kfold: param = k (default 5)
//  - split: param = ratio (default 0.8 train)
//  - testcase: uses test_case_user_{user} as test, case_user_{user} as train
//  - loocv: ignores param

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "expertt";

$userId = $argv[1] ?? null;
$mode = strtolower($argv[2] ?? 'hybrid');
$evalMode = strtolower($argv[3] ?? 'loocv');
$param = $argv[4] ?? null; // kfold:k, split:ratio
$alphaInput = $argv[5] ?? null; // bobot hybrid; bisa "auto" atau list "0.2,0.5,0.8"
$alpha = 0.5;
$alphaList = [];
if ($alphaInput === null) {
    $alphaList = [$alpha];
} else {
    if (strtolower($alphaInput) === 'auto') {
        $alphaList = [0.2, 0.5, 0.8];
    } elseif (strpos($alphaInput, ',') !== false) {
        $alphaList = array_filter(array_map('trim', explode(',', $alphaInput)), 'strlen');
        $alphaList = array_map('floatval', $alphaList);
    } else {
        $alphaList = [floatval($alphaInput)];
    }
    // clamp
    foreach ($alphaList as &$a) {
        if ($a < 0) $a = 0;
        if ($a > 1) $a = 1;
    }
    unset($a);
    if (empty($alphaList)) $alphaList = [$alpha];
}
$topK = 3; // for top-k hit rate
$threshold = 0.5; // for thresholded accuracy

if (!$userId || !in_array($mode, ['hybrid','jaccard','cosine'])) {
    fwrite(STDERR, "Usage: php eval_similarity.php {user_id} [mode] [eval] [param] [alpha|auto|a,b,c]\n");
    exit(1);
}

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

function get_goal_column($conn, $userId, $tableBase) {
    $goalCol = null;
    $goalQuery = $conn->query("SELECT atribut_id, atribut_name FROM atribut WHERE user_id = $userId AND goal = 'T' LIMIT 1");
    if ($goalQuery && $goalQuery->num_rows > 0) {
        $g = $goalQuery->fetch_assoc();
        $goalCol = $g['atribut_id'] . '_' . $g['atribut_name'];
    }
    if ($goalCol === null) {
        $res = $conn->query("SELECT * FROM $tableBase LIMIT 1");
        if ($res) {
            $fieldcount = $res->field_count;
            $fields = $res->fetch_fields();
            if ($fieldcount >= 4) {
                $goalCol = $fields[$fieldcount - 3]->name;
            }
        }
    }
    return $goalCol;
}

function normalize_value($value)
{
    $value = strtolower(trim((string)$value));
    $value = preg_replace('/^\d+_/', '', $value);
    $value = str_replace(['-', '_'], ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value);
    $value = trim($value);

    $map = [
        'y' => 'yes', 'ya' => 'yes', 'yes' => 'yes', 'true' => 'yes', 'benar' => 'yes',
        'n' => 'no', 'tidak' => 'no', 't' => 'no', 'no' => 'no', 'false' => 'no', 'salah' => 'no', 'f' => 'no'
    ];
    if (isset($map[$value])) {
        return $map[$value];
    }
    return $value;
}

function normalize_case_row($row, $attrCols)
{
    $norm = [];
    foreach ($attrCols as $col) {
        $norm[$col] = normalize_value($row[$col] ?? '');
    }
    return $norm;
}

function compute_similarity($testRow, $baseRow, $attrCols, $attrWeights, $alpha) {
    $n = count($attrCols);
    $matches = 0;
    $wTotal = 0.0;
    $wMatch = 0.0;
    foreach ($attrCols as $col) {
        $w = $attrWeights[$col] ?? 1.0;
        $tv = $testRow[$col] ?? '';
        $bv = $baseRow[$col] ?? '';
        $equal = ($tv === $bv);
        if ($equal) {
            $matches += 1;
            $wMatch += $w;
        }
        $wTotal += $w;
    }
    if ($n === 0 || $wTotal == 0.0) return [0.0, 0.0, 0.0];
    // unweighted cosine/jaccard
    $cosine = $matches / $n;
    $union = 2 * $n - $matches;
    $jaccard = $union > 0 ? ($matches / $union) : 0.0;
    // weighted for hybrid
    $wCosine = $wMatch / $wTotal;
    $wUnion = 2 * $wTotal - $wMatch;
    $wJaccard = $wUnion > 0 ? ($wMatch / $wUnion) : 0.0;
    $hybrid = $alpha * $wCosine + (1 - $alpha) * $wJaccard;
    return [$hybrid, $cosine, $jaccard];
}

function evaluate($train, $test, $attrCols, $goalCol, $mode, $topK, $threshold, $attrWeights, $alpha, $withRecords = false) {
    $total = 0; $correct = 0;
    $topkHit = 0; $topkTotal = 0;
    $thrTotal = 0; $thrCorrect = 0;
    $records = [];

    $normTrain = [];
    foreach ($train as $row) {
        $normTrain[] = ['raw' => $row, 'norm' => normalize_case_row($row, $attrCols)];
    }

    foreach ($test as $trow) {
        $total++;
        $tNorm = normalize_case_row($trow, $attrCols);
        $scores = [];
        foreach ($normTrain as $item) {
            list($hyb,$cos,$jac) = compute_similarity($tNorm, $item['norm'], $attrCols, $attrWeights, $alpha);
            $score = $hyb;
            if ($mode === 'jaccard') $score = $jac;
            elseif ($mode === 'cosine') $score = $cos;
            $scores[] = ['score' => $score, 'goal' => $item['raw'][$goalCol] ?? '', 'case_id' => $item['raw']['case_id'] ?? null];
        }
        usort($scores, function($a,$b){ return $b['score'] <=> $a['score']; });
        $best = $scores[0];
        $actual = normalize_value($trow[$goalCol] ?? '');
        $pred = normalize_value($best['goal']);

        if ($actual === $pred) $correct++;

        if ($best['score'] >= $threshold) {
            $thrTotal++;
            if ($actual === $pred) $thrCorrect++;
        }

        $topkTotal++;
        $hit = false;
        foreach (array_slice($scores, 0, $topK) as $cand) {
            if ($actual === normalize_value($cand['goal'])) { $hit = true; break; }
        }
        if ($hit) $topkHit++;

        if ($withRecords) {
            $records[] = [
                'actual' => $actual,
                'pred' => $pred,
                'score' => $best['score'],
                'case_id' => $trow['case_id'] ?? null,
            ];
        }
    }

    return [
        'total' => $total,
        'correct' => $correct,
        'accuracy' => $total ? $correct / $total : 0,
        'topk_total' => $topkTotal,
        'topk_hit' => $topkHit,
        'topk_hit_rate' => $topkTotal ? $topkHit / $topkTotal : 0,
        'thr_total' => $thrTotal,
        'thr_correct' => $thrCorrect,
        'thr_accuracy' => $thrTotal ? $thrCorrect / $thrTotal : 0,
        'thr_coverage' => $total ? $thrTotal / $total : 0,
        'records' => $records,
    ];
}

$tableBase = "case_user_" . $userId;
$tableTest = "test_case_user_" . $userId;
$goalCol = get_goal_column($conn, $userId, $tableBase);
if (!$goalCol) die("Cannot determine goal column\n");

$baseRes = $conn->query("SELECT * FROM $tableBase LIMIT 1");
$attrCols = [];
if ($baseRes) {
    $fields = $baseRes->fetch_fields();
    foreach ($fields as $f) {
        $name = $f->name;
        if (in_array($name, ['case_id','user_id','case_num'])) continue;
        if ($name === $goalCol) continue;
        $attrCols[] = $name;
    }
}
if (!$attrCols) die("No attribute columns found\n");

$baseCases = [];
$resBase = $conn->query("SELECT * FROM $tableBase");
if ($resBase) { while ($r = $resBase->fetch_assoc()) { $baseCases[] = $r; } }

// attribute weights (same entropy-based idea as hybrid_similarity.php)
$attrWeights = [];
if ($baseCases) {
    $valueCounts = [];
    foreach ($baseCases as $row) {
        foreach ($attrCols as $col) {
            $val = normalize_value($row[$col] ?? '');
            if (!isset($valueCounts[$col])) {
                $valueCounts[$col] = [];
            }
            $valueCounts[$col][$val] = ($valueCounts[$col][$val] ?? 0) + 1;
        }
    }
    $sumEntropy = 0.0;
    foreach ($attrCols as $col) {
        if (!isset($valueCounts[$col])) {
            $attrWeights[$col] = 0.0;
            continue;
        }
        $counts = $valueCounts[$col];
        $colTotal = array_sum($counts);
        if ($colTotal == 0 || count($counts) <= 1) {
            $attrWeights[$col] = 0.0;
            continue;
        }
        $entropy = 0.0;
        foreach ($counts as $c) {
            $p = $c / $colTotal;
            if ($p > 0) {
                $entropy -= $p * log($p);
            }
        }
        $attrWeights[$col] = $entropy;
        $sumEntropy += $entropy;
    }
    if ($sumEntropy > 0) {
        foreach ($attrCols as $col) {
            $attrWeights[$col] = $attrWeights[$col] / $sumEntropy;
        }
    }
}

$testCases = [];
if ($evalMode === 'testcase') {
    $modeLabel = ucfirst($mode) . ' Similarity';
    $resTest = $conn->query("SELECT * FROM $tableTest WHERE algoritma = '" . $conn->real_escape_string($modeLabel) . "'");
    if ($resTest) { while ($r = $resTest->fetch_assoc()) { $testCases[] = $r; } }
}

function shuffled($arr) {
    $idx = array_keys($arr);
    shuffle($idx);
    $out = [];
    foreach ($idx as $i) $out[] = $arr[$i];
    return $out;
}

$results = [];
$matrixRecords = [];

switch ($evalMode) {
    case 'loocv':
        $total = count($baseCases);
        $correct = $topk = $thr = $thrCov = 0;
        foreach ($baseCases as $i => $testRow) {
            $train = $baseCases;
            array_splice($train, $i, 1);
            $m = evaluate($train, [$testRow], $attrCols, $goalCol, $mode, $topK, $threshold, $attrWeights, $alpha, true);
            $correct += $m['correct'];
            $topk += $m['topk_hit'];
            $thr += $m['thr_correct'];
            $thrCov += $m['thr_total'];
            $matrixRecords = array_merge($matrixRecords, $m['records']);
        }
        $results[] = [
            'label' => 'LOO-CV',
            'total' => $total,
            'accuracy' => $total ? $correct / $total : 0,
            'topk_hit_rate' => $total ? $topk / $total : 0,
            'thr_accuracy' => $thrCov ? $thr / $thrCov : 0,
            'thr_coverage' => $total ? $thrCov / $total : 0,
        ];
        break;
    case 'kfold':
        $k = $param ? (int)$param : 5;
        $shuffled = shuffled($baseCases);
        $foldSize = max(1, floor(count($shuffled) / $k));
        $sumAcc = $sumTopk = $sumThrAcc = $sumThrCov = 0;
        $sumTotal = 0;
        for ($fold=0; $fold<$k; $fold++) {
            $test = array_slice($shuffled, $fold*$foldSize, $foldSize);
            $train = array_merge(array_slice($shuffled, 0, $fold*$foldSize), array_slice($shuffled, ($fold+1)*$foldSize));
            $m = evaluate($train, $test, $attrCols, $goalCol, $mode, $topK, $threshold, $attrWeights, $alpha, true);
            $results[] = ['label' => "Fold".($fold+1), 'accuracy' => $m['accuracy'], 'topk_hit_rate' => $m['topk_hit_rate'], 'thr_accuracy' => $m['thr_accuracy'], 'thr_coverage' => $m['thr_coverage'], 'total'=>$m['total']];
            $matrixRecords = array_merge($matrixRecords, $m['records']);
            $sumAcc += $m['accuracy'];
            $sumTopk += $m['topk_hit_rate'];
            $sumThrAcc += $m['thr_accuracy'];
            $sumThrCov += $m['thr_coverage'];
            $sumTotal += $m['total'];
        }
        if ($k > 0) {
            $results[] = [
                'label' => 'FoldAvg',
                'accuracy' => $sumAcc / $k,
                'topk_hit_rate' => $sumTopk / $k,
                'thr_accuracy' => $sumThrAcc / $k,
                'thr_coverage' => $sumThrCov / $k,
                'total' => $sumTotal
            ];
        }
        break;
    case 'split':
        $ratio = $param ? floatval($param) : 0.8;
        $shuffled = shuffled($baseCases);
        $cut = (int) floor(count($shuffled) * $ratio);
        $train = array_slice($shuffled, 0, $cut);
        $test = array_slice($shuffled, $cut);
        $m = evaluate($train, $test, $attrCols, $goalCol, $mode, $topK, $threshold, $attrWeights, $alpha, true);
        $results[] = ['label' => 'Split', 'accuracy' => $m['accuracy'], 'topk_hit_rate' => $m['topk_hit_rate'], 'thr_accuracy' => $m['thr_accuracy'], 'thr_coverage' => $m['thr_coverage'], 'total'=>$m['total']];
        $matrixRecords = array_merge($matrixRecords, $m['records']);
        break;
    case 'testcase':
        $m = evaluate($baseCases, $testCases, $attrCols, $goalCol, $mode, $topK, $threshold, $attrWeights, $alpha, true);
        $results[] = ['label' => 'TestCase', 'accuracy' => $m['accuracy'], 'topk_hit_rate' => $m['topk_hit_rate'], 'thr_accuracy' => $m['thr_accuracy'], 'thr_coverage' => $m['thr_coverage'], 'total'=>$m['total']];
        $matrixRecords = array_merge($matrixRecords, $m['records']);
        break;
    default:
        die("Unknown eval mode\n");
}

echo "Mode: $mode | Eval: $evalMode\n";
foreach ($results as $r) {
    echo sprintf("%s: total=%d | acc=%.4f | top%d_hit=%.4f | thr_acc=%.4f | thr_cov=%.4f\n",
        $r['label'], $r['total'] ?? 0, $r['accuracy'] ?? 0, $topK, $r['topk_hit_rate'] ?? 0, $r['thr_accuracy'] ?? 0, $r['thr_coverage'] ?? 0);
}

// Build confusion matrix JSON from matrixRecords
function build_matrix($records) {
    $counts = [];
    $actuals = [];
    $preds = [];
    $total = 0;
    $correct = 0;
    foreach ($records as $rec) {
        $a = $rec['actual'] ?? null;
        $p = $rec['pred'] ?? null;
        if ($a === null || $p === null) continue;
        $counts[$a][$p] = ($counts[$a][$p] ?? 0) + 1;
        $actuals[$a] = true;
        $preds[$p] = true;
        $total++;
        if ($a === $p) $correct++;
    }
    return [
        'counts' => $counts,
        'actuals' => array_keys($actuals),
        'preds' => array_keys($preds),
        'total' => $total,
        'correct' => $correct,
    ];
}

$modeLabel = ucfirst($mode) . ' Similarity';
$bestSummary = null;
$bestMatrix = null;
$bestAlpha = null;

// Fungsi eval per alpha
function eval_for_alpha($alpha, $mode, $evalMode, $param, $baseCases, $testCases, $attrCols, $goalCol, $topK, $threshold, $attrWeights) {
    $results = [];
    $matrixRecords = [];

    switch ($evalMode) {
        case 'loocv':
            $total = count($baseCases);
            $correct = $topk = $thr = $thrCov = 0;
            foreach ($baseCases as $i => $testRow) {
                $train = $baseCases;
                array_splice($train, $i, 1);
                $m = evaluate($train, [$testRow], $attrCols, $goalCol, $mode, $topK, $threshold, $attrWeights, $alpha, true);
                $correct += $m['correct'];
                $topk += $m['topk_hit'];
                $thr += $m['thr_correct'];
                $thrCov += $m['thr_total'];
                $matrixRecords = array_merge($matrixRecords, $m['records']);
            }
            $results[] = [
                'label' => 'LOO-CV',
                'total' => $total,
                'accuracy' => $total ? $correct / $total : 0,
                'topk_hit_rate' => $total ? $topk / $total : 0,
                'thr_accuracy' => $thrCov ? $thr / $thrCov : 0,
                'thr_coverage' => $total ? $thrCov / $total : 0,
            ];
            break;
        case 'kfold':
            $k = $param ? (int)$param : 5;
            $shuffled = shuffled($baseCases);
            $foldSize = max(1, floor(count($shuffled) / $k));
            $sumAcc = $sumTopk = $sumThrAcc = $sumThrCov = 0;
            $sumTotal = 0;
            for ($fold=0; $fold<$k; $fold++) {
                $test = array_slice($shuffled, $fold*$foldSize, $foldSize);
                $train = array_merge(array_slice($shuffled, 0, $fold*$foldSize), array_slice($shuffled, ($fold+1)*$foldSize));
                $m = evaluate($train, $test, $attrCols, $goalCol, $mode, $topK, $threshold, $attrWeights, $alpha, true);
                $results[] = ['label' => "Fold".($fold+1), 'accuracy' => $m['accuracy'], 'topk_hit_rate' => $m['topk_hit_rate'], 'thr_accuracy' => $m['thr_accuracy'], 'thr_coverage' => $m['thr_coverage'], 'total'=>$m['total']];
                $matrixRecords = array_merge($matrixRecords, $m['records']);
                $sumAcc += $m['accuracy'];
                $sumTopk += $m['topk_hit_rate'];
                $sumThrAcc += $m['thr_accuracy'];
                $sumThrCov += $m['thr_coverage'];
                $sumTotal += $m['total'];
            }
            if ($k > 0) {
                $results[] = [
                    'label' => 'FoldAvg',
                    'accuracy' => $sumAcc / $k,
                    'topk_hit_rate' => $sumTopk / $k,
                    'thr_accuracy' => $sumThrAcc / $k,
                    'thr_coverage' => $sumThrCov / $k,
                    'total' => $sumTotal
                ];
            }
            break;
        case 'split':
            $ratio = $param ? floatval($param) : 0.8;
            $shuffled = shuffled($baseCases);
            $cut = (int) floor(count($shuffled) * $ratio);
            $train = array_slice($shuffled, 0, $cut);
            $test = array_slice($shuffled, $cut);
            $m = evaluate($train, $test, $attrCols, $goalCol, $mode, $topK, $threshold, $attrWeights, $alpha, true);
            $results[] = ['label' => 'Split', 'accuracy' => $m['accuracy'], 'topk_hit_rate' => $m['topk_hit_rate'], 'thr_accuracy' => $m['thr_accuracy'], 'thr_coverage' => $m['thr_coverage'], 'total'=>$m['total']];
            $matrixRecords = array_merge($matrixRecords, $m['records']);
            break;
        case 'testcase':
            $m = evaluate($baseCases, $testCases, $attrCols, $goalCol, $mode, $topK, $threshold, $attrWeights, $alpha, true);
            $results[] = ['label' => 'TestCase', 'accuracy' => $m['accuracy'], 'topk_hit_rate' => $m['topk_hit_rate'], 'thr_accuracy' => $m['thr_accuracy'], 'thr_coverage' => $m['thr_coverage'], 'total'=>$m['total']];
            $matrixRecords = array_merge($matrixRecords, $m['records']);
            break;
        default:
            die("Unknown eval mode\n");
    }
    return [$results, $matrixRecords];
}

$finalResults = [];
$finalMatrixRecords = [];
$bestScore = -1;
$bestAlpha = null;

foreach ($alphaList as $a) {
    list($res, $records) = eval_for_alpha($a, $mode, $evalMode, $param, $baseCases, $testCases, $attrCols, $goalCol, $topK, $threshold, $attrWeights);
    // Ambil skor terbaik dari hasil (pakai accuracy terbesar dari list res)
    $localBest = -1;
    foreach ($res as $r) {
        $acc = $r['accuracy'] ?? 0;
        if ($acc > $localBest) $localBest = $acc;
    }
    if ($localBest > $bestScore) {
        $bestScore = $localBest;
        $bestAlpha = $a;
        $finalResults = $res;
        $finalMatrixRecords = $records;
    }
}

echo "Mode: $mode | Eval: $evalMode | Best alpha: $bestAlpha\n";
foreach ($finalResults as $r) {
    echo sprintf("%s: total=%d | acc=%.4f | top%d_hit=%.4f | thr_acc=%.4f | thr_cov=%.4f\n",
        $r['label'], $r['total'] ?? 0, $r['accuracy'] ?? 0, $topK, $r['topk_hit_rate'] ?? 0, $r['thr_accuracy'] ?? 0, $r['thr_coverage'] ?? 0);
}

$matrixJson = [
    'algo' => $modeLabel . ' (alpha=' . $bestAlpha . ')',
    'matrix' => build_matrix($finalMatrixRecords),
];
echo "MATRIX_JSON:" . json_encode($matrixJson) . "\n";

$conn->close();
?>
