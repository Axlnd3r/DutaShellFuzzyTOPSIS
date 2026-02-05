<?php
// Hybrid Similarity (Cosine+Jaccard) with entropy-based attribute weights and configurable alpha

// DB connection (ikuti konvensi skrip lain)
$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "expertt";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// inputs
$user_id = $argv[1];
$case_num = $argv[2];
$awal = microtime(true);

// mode: default 'hybrid', atau 'jaccard' / 'cosine'
$mode = isset($argv[3]) ? strtolower(trim($argv[3])) : 'hybrid';
if (!in_array($mode, ['hybrid', 'jaccard', 'cosine'])) {
    $mode = 'hybrid';
}
// alpha: bobot campuran cosine/jaccard untuk Hybrid (default 0.5)
$alpha = isset($argv[4]) ? floatval($argv[4]) : 0.5;
if ($alpha < 0) $alpha = 0;
if ($alpha > 1) $alpha = 1;
$GLOBALS['alpha'] = $alpha;

// dynamic tables
$table_test = "test_case_user_" . $user_id; // consultation input
$table_base = "case_user_" . $user_id;      // basis kasus
// output table depends on mode
if ($mode === 'jaccard') {
    $table_inf = "inferensi_jc_user_" . $user_id;
    $algoFilter = 'Jaccard Similarity';
} elseif ($mode === 'cosine') {
    $table_inf = "inferensi_cs_user_" . $user_id;
    $algoFilter = 'Cosine Similarity';
} else {
    $table_inf = "inferensi_hs_user_" . $user_id;
    $algoFilter = 'Hybrid Similarity';
}

// ensure output table
$result = $conn->query("SHOW TABLES LIKE '$table_inf'");
if ($result && $result->num_rows > 0) {
    $conn->query("TRUNCATE TABLE $table_inf");
} else {
    $sql = "CREATE TABLE $table_inf (
        `inf_id` INT(11) PRIMARY KEY NOT NULL AUTO_INCREMENT,
        `case_id` VARCHAR(100) NOT NULL,
        `case_goal` VARCHAR(200) NOT NULL,
        `rule_id` VARCHAR(100) NOT NULL,
        `rule_goal` VARCHAR(200) NOT NULL,
        `match_value` DECIMAL(6,5) NOT NULL,
        `cocok` ENUM('1','0') NOT NULL,
        `user_id` INT(11) NOT NULL,
        `waktu` DECIMAL(16,14) NOT NULL DEFAULT 0
    )";
    if (!$conn->query($sql)) {
        die("Error creating table: " . $conn->error);
    }
}

// goal column
$goalCol = null;
$goalQuery = $conn->query("SELECT atribut_id, atribut_name FROM atribut WHERE user_id = $user_id AND goal = 'T' LIMIT 1");
if ($goalQuery && $goalQuery->num_rows > 0) {
    $g = $goalQuery->fetch_assoc();
    $goalCol = $g['atribut_id'] . '_' . $g['atribut_name'];
}
if ($goalCol === null) {
    $res = $conn->query("SELECT * FROM $table_base LIMIT 1");
    if ($res) {
        $fieldcount = $res->field_count;
        $fields = $res->fetch_fields();
        if ($fieldcount >= 4) {
            $goalCol = $fields[$fieldcount - 3]->name; // heuristik seperti matching_rule.php
        }
    }
}
if ($goalCol === null) {
    die("Cannot determine goal column");
}

// attribute columns (non-goal, non-id)
$baseRes = $conn->query("SELECT * FROM $table_base LIMIT 1");
$attrCols = [];
if ($baseRes) {
    $fields = $baseRes->fetch_fields();
    foreach ($fields as $f) {
        $name = $f->name;
        if (in_array($name, ['case_id', 'user_id', 'case_num'])) continue;
        if ($name === $goalCol) continue;
        $attrCols[] = $name;
    }
}
if (count($attrCols) === 0) {
    die("No attribute columns found");
}

// select test cases for the requested algorithm
$algoEscaped = $conn->real_escape_string($algoFilter);
$testSQL = "SELECT * FROM $table_test WHERE algoritma = '$algoEscaped'";
$testRes = $conn->query($testSQL);
if (!$testRes) {
    die("Query test cases failed: " . $conn->error);
}

// normalize utilities
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
    if (isset($map[$value])) return $map[$value];
    return $value;
}
function normalize_case_row($row, $attrCols)
{
    $normalized = [];
    foreach ($attrCols as $col) {
        $normalized[$col] = normalize_value($row[$col] ?? '');
    }
    return $normalized;
}

// attribute weights (entropy-based)
$attrWeights = [];
$allBaseResForWeight = $conn->query("SELECT * FROM $table_base");
if ($allBaseResForWeight) {
    $valueCounts = [];
    while ($row = $allBaseResForWeight->fetch_assoc()) {
        foreach ($attrCols as $col) {
            $val = normalize_value($row[$col] ?? '');
            if (!isset($valueCounts[$col])) $valueCounts[$col] = [];
            $valueCounts[$col][$val] = ($valueCounts[$col][$val] ?? 0) + 1;
        }
    }
    $sumEntropy = 0.0;
    foreach ($attrCols as $col) {
        if (!isset($valueCounts[$col])) { $attrWeights[$col] = 0.0; continue; }
        $counts = $valueCounts[$col];
        $colTotal = array_sum($counts);
        if ($colTotal == 0 || count($counts) <= 1) { $attrWeights[$col] = 0.0; continue; }
        $entropy = 0.0;
        foreach ($counts as $c) {
            $p = $c / $colTotal;
            if ($p > 0) $entropy -= $p * log($p);
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

// similarity: unweighted cosine/jaccard + weighted hybrid
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
    if ($n === 0 || $wTotal == 0.0) return [0.0, 0.0, 0.0, 0];
    // unweighted cosine/jaccard
    $cosine = $matches / $n;
    $union = 2 * $n - $matches;
    $jaccard = $union > 0 ? ($matches / $union) : 0.0;
    // weighted for hybrid
    $wCosine = $wMatch / $wTotal;
    $wUnion = 2 * $wTotal - $wMatch;
    $wJaccard = $wUnion > 0 ? ($wMatch / $wUnion) : 0.0;
    $hybrid = $alpha * $wCosine + (1 - $alpha) * $wJaccard;
    return [$hybrid, $cosine, $jaccard, $matches];
}

// load base cases
$baseCases = [];
$allBaseRes = $conn->query("SELECT * FROM $table_base");
if ($allBaseRes) {
    while ($row = $allBaseRes->fetch_assoc()) {
        $row['_normalized'] = normalize_case_row($row ?? [], $attrCols);
        $baseCases[] = $row;
    }
}

// iterate test cases
while ($test = $testRes->fetch_assoc()) {
    $case_id = $test['case_id'];
    $case_goal = $goalCol . '=' . (isset($test[$goalCol]) ? $test[$goalCol] : '');
    $normalizedTest = normalize_case_row($test, $attrCols);

    $bestScore = -1.0;
    $bestBase = null;
    foreach ($baseCases as $base) {
        $normBase = $base['_normalized'] ?? normalize_case_row($base, $attrCols);
        list($hybrid, $cosine, $jaccard, $matches) = compute_similarity($normalizedTest, $normBase, $attrCols, $attrWeights, $alpha);
        $score = $hybrid;
        if ($mode === 'jaccard') { $score = $jaccard; }
        elseif ($mode === 'cosine') { $score = $cosine; }
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestBase = $base;
        }
    }

    if ($bestBase !== null) {
        $rule_id = $bestBase['case_id'];
        $rule_goal = $goalCol . '=' . $bestBase[$goalCol];
        $testGoalNorm = normalize_value($test[$goalCol] ?? '');
        $ruleGoalNorm = normalize_value($bestBase[$goalCol] ?? '');
        $cocok = ($testGoalNorm === $ruleGoalNorm) ? '1' : '0';
        $akhir = microtime(true);
        $lama = $akhir - $awal;

        $sql = sprintf(
            "INSERT INTO %s (case_id, case_goal, rule_id, rule_goal, match_value, cocok, user_id, waktu) VALUES (%d, '%s', %d, '%s', %.5f, '%s', %d, %.14f)",
            $table_inf,
            (int)$case_id,
            $conn->real_escape_string($case_goal),
            (int)$rule_id,
            $conn->real_escape_string($rule_goal),
            $bestScore,
            $cocok,
            (int)$user_id,
            $lama
        );
        if (!$conn->query($sql)) {
            echo "Error insert: " . $conn->error . "\n";
        }
    }
}

$conn->close();
?>
