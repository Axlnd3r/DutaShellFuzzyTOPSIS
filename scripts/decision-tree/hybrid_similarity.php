<?php
// Hybrid Similarity (Cosine+Jaccard) with entropy-based attribute weights and configurable alpha

// Load database config from Laravel .env
$envPath = __DIR__ . '/../../.env';
$dbConfig = [
    'host' => '127.0.0.1',
    'port' => 3306,
    'database' => 'expertt',
    'username' => 'root',
    'password' => ''
];

if (file_exists($envPath)) {
    $envContent = file_get_contents($envPath);
    if (preg_match('/^DB_HOST=(.*)$/m', $envContent, $m)) $dbConfig['host'] = trim($m[1]);
    if (preg_match('/^DB_PORT=(\d+)/m', $envContent, $m)) $dbConfig['port'] = (int)trim($m[1]);
    if (preg_match('/^DB_DATABASE=(.*)$/m', $envContent, $m)) $dbConfig['database'] = trim($m[1]);
    if (preg_match('/^DB_USERNAME=(.*)$/m', $envContent, $m)) $dbConfig['username'] = trim($m[1]);
    if (preg_match('/^DB_PASSWORD=(.*)$/m', $envContent, $m)) $dbConfig['password'] = trim($m[1]);
}

$conn = new mysqli($dbConfig['host'], $dbConfig['username'], $dbConfig['password'], $dbConfig['database'], $dbConfig['port']);
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

// ensure output table exists (tanpa TRUNCATE agar data lama tidak hilang)
$result = $conn->query("SHOW TABLES LIKE '$table_inf'");
if (!$result || $result->num_rows == 0) {
    $sql = "CREATE TABLE $table_inf (
        `inf_id` INT(11) PRIMARY KEY NOT NULL AUTO_INCREMENT,
        `case_id` VARCHAR(100) NOT NULL,
        `case_goal` VARCHAR(200) NOT NULL,
        `rule_id` VARCHAR(100) NOT NULL,
        `rule_goal` VARCHAR(200) NOT NULL,
        `match_value` DECIMAL(6,5) NOT NULL,
        `cocok` ENUM('1','0') NOT NULL,
        `user_id` INT(11) NOT NULL,
        `waktu` DECIMAL(16,14) NOT NULL DEFAULT 0,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
    )";
    if (!$conn->query($sql)) {
        die("Error creating table: " . $conn->error);
    }
} else {
    // Tambah kolom created_at jika belum ada (untuk tabel lama)
    $colCheck = $conn->query("SHOW COLUMNS FROM $table_inf LIKE 'created_at'");
    if ($colCheck && $colCheck->num_rows == 0) {
        $conn->query("ALTER TABLE $table_inf ADD COLUMN `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP");
    }
}

// Ambil case_id yang sudah pernah diproses untuk menghindari duplikasi
// Tapi izinkan reprocess jika goal kosong (fix data lama)
$processedCaseIds = [];
$existingRes = $conn->query("SELECT case_id, case_goal, rule_goal FROM $table_inf");
if ($existingRes) {
    while ($row = $existingRes->fetch_assoc()) {
        $cg = $row['case_goal'] ?? '';
        $rg = $row['rule_goal'] ?? '';
        // Cek apakah goal kosong (format: "xxx=" tanpa nilai setelah =)
        $cgEmpty = ($cg === '' || preg_match('/=\s*$/', $cg));
        $rgEmpty = ($rg === '' || preg_match('/=\s*$/', $rg));
        if ($cgEmpty || $rgEmpty) {
            // Hapus record lama dengan goal kosong agar bisa diproses ulang
            $conn->query("DELETE FROM $table_inf WHERE case_id = " . (int)$row['case_id']);
        } else {
            $processedCaseIds[$row['case_id']] = true;
        }
    }
}

// Ambil semua kolom dari tabel base case untuk verifikasi
$baseColNames = [];
$baseColRes = $conn->query("SELECT * FROM $table_base LIMIT 1");
if ($baseColRes) {
    $fields = $baseColRes->fetch_fields();
    foreach ($fields as $f) {
        $baseColNames[] = $f->name;
    }
}

// goal column - cari dari atribut table, lalu verifikasi terhadap kolom tabel yang sebenarnya
$goalCol = null;
$goalAttrName = null;
$goalQuery = $conn->query("SELECT atribut_id, atribut_name FROM atribut WHERE user_id = $user_id AND goal = 'T' LIMIT 1");
if ($goalQuery && $goalQuery->num_rows > 0) {
    $g = $goalQuery->fetch_assoc();
    $candidateGoalCol = $g['atribut_id'] . '_' . $g['atribut_name'];
    $goalAttrName = $g['atribut_name'];

    // Verifikasi: apakah kolom ini benar-benar ada di tabel base case?
    if (in_array($candidateGoalCol, $baseColNames)) {
        $goalCol = $candidateGoalCol;
    } else {
        // Kolom tidak ditemukan (mungkin atribut_id berubah), cari berdasarkan nama atribut
        foreach ($baseColNames as $colName) {
            if (in_array($colName, ['case_id', 'user_id', 'case_num'])) continue;
            // Cocokkan berdasarkan bagian nama setelah prefix id (misal "14_play" → "play")
            $parts = explode('_', $colName, 2);
            if (count($parts) === 2 && strtolower($parts[1]) === strtolower($g['atribut_name'])) {
                $goalCol = $colName;
                break;
            }
        }
    }
}

// Fallback: jika masih belum ketemu, cari kolom goal berdasarkan tabel atribut (match by name)
if ($goalCol === null && $goalAttrName === null) {
    // Ambil semua atribut goal
    $goalQuery2 = $conn->query("SELECT atribut_id, atribut_name FROM atribut WHERE user_id = $user_id AND goal = 'T'");
    if ($goalQuery2) {
        while ($g2 = $goalQuery2->fetch_assoc()) {
            foreach ($baseColNames as $colName) {
                if (in_array($colName, ['case_id', 'user_id', 'case_num'])) continue;
                $parts = explode('_', $colName, 2);
                if (count($parts) === 2 && strtolower($parts[1]) === strtolower($g2['atribut_name'])) {
                    $goalCol = $colName;
                    $goalAttrName = $g2['atribut_name'];
                    break 2;
                }
            }
        }
    }
}

// Fallback terakhir: heuristik posisi (kolom pertama setelah case_id, karena ORDER BY goal DESC saat CREATE)
if ($goalCol === null) {
    $skipCols = ['case_id', 'user_id', 'case_num', 'algoritma'];
    foreach ($baseColNames as $colName) {
        if (!in_array($colName, $skipCols)) {
            $goalCol = $colName;
            break; // Kolom pertama setelah case_id = goal (karena ORDER BY goal DESC)
        }
    }
}

if ($goalCol === null) {
    die("Cannot determine goal column");
}

echo "Goal column detected: $goalCol\n";

// attribute columns (non-goal, non-id)
$attrCols = [];
$skipCols = ['case_id', 'user_id', 'case_num', 'algoritma'];
foreach ($baseColNames as $colName) {
    if (in_array($colName, $skipCols)) continue;
    if ($colName === $goalCol) continue;
    $attrCols[] = $colName;
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
echo "Base cases loaded: " . count($baseCases) . "\n";
if (count($baseCases) > 0) {
    $firstBase = $baseCases[0];
    $goalValSample = $firstBase[$goalCol] ?? '(NULL/MISSING)';
    echo "Goal column '$goalCol' sample value: $goalValSample\n";
    echo "Base case columns: " . implode(', ', array_keys($firstBase)) . "\n";
}

// iterate test cases
while ($test = $testRes->fetch_assoc()) {
    $case_id = $test['case_id'];

    // Skip jika case_id sudah pernah diproses
    if (isset($processedCaseIds[$case_id])) {
        continue;
    }

    $testGoalRaw = isset($test[$goalCol]) ? $test[$goalCol] : '';
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
        $bestGoalVal = $bestBase[$goalCol] ?? '';
        $rule_goal = $goalCol . '=' . $bestGoalVal;

        // Jika test case tidak punya nilai goal (consultation), gunakan goal dari best match
        if ($testGoalRaw === '' || $testGoalRaw === null) {
            $case_goal = $rule_goal;
            $cocok = '1'; // consultation: goal = predicted goal
        } else {
            $case_goal = $goalCol . '=' . $testGoalRaw;
            $testGoalNorm = normalize_value($testGoalRaw);
            $ruleGoalNorm = normalize_value($bestGoalVal);
            $cocok = ($testGoalNorm === $ruleGoalNorm) ? '1' : '0';
        }
        $akhir = microtime(true);
        $lama = $akhir - $awal;

        $sql = sprintf(
            "INSERT INTO %s (case_id, case_goal, rule_id, rule_goal, match_value, cocok, user_id, waktu, created_at) VALUES (%d, '%s', %d, '%s', %.5f, '%s', %d, %.14f, NOW())",
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
