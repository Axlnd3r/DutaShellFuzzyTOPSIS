<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class SvmTrainerService
{
    public function train(
        int $userId,
        int $caseNum,
        string $kernelSpec = 'sgd',
        ?string $tableOverride = null
    ): array {
        $epochs = 20;
        $lambda = 1e-4;
        $eta0 = 0.1;
        $testRatio = $this->envf('SVM_TEST_RATIO', 0.3);
        if ($testRatio < 0.0) $testRatio = 0.0;
        if ($testRatio > 0.9) $testRatio = 0.9;

        $sourceTable = $this->resolveSourceTable($userId, $tableOverride);
        $goalKey = $this->resolveGoalKey($userId, $sourceTable);

        $rows = DB::table($sourceTable)->get()->map(fn ($r) => (array) $r)->all();
        if (!$rows) {
            throw new RuntimeException("Dataset kosong pada {$sourceTable}.");
        }

        $skipCols = ['case_id', 'user_id', 'case_num', 'algoritma', $goalKey];
        $cats = [];
        $nums = [];
        $labelsRaw = [];

        foreach ($rows as $row) {
            $lab = $row[$goalKey] ?? null;
            if ($lab === null || $lab === '' || preg_match('/^(unknown|tidak diketahui)$/i', (string) $lab)) {
                continue;
            }
            $labelsRaw[] = (string) $lab;
            foreach ($row as $c => $v) {
                if (in_array($c, $skipCols, true)) continue;
                if (is_numeric($v)) {
                    $f = (float) $v;
                    if (!isset($nums[$c])) {
                        $nums[$c] = ['min' => $f, 'max' => $f];
                    } else {
                        if ($f < $nums[$c]['min']) $nums[$c]['min'] = $f;
                        if ($f > $nums[$c]['max']) $nums[$c]['max'] = $f;
                    }
                } else {
                    $s = trim((string) $v);
                    if ($s === '') continue;
                    if (!isset($cats[$c])) $cats[$c] = [];
                    $cats[$c][$s] = true;
                }
            }
        }

        if (!$labelsRaw) {
            throw new RuntimeException("Semua label kosong/Unknown pada {$goalKey}.");
        }

        $freq = array_count_values($labelsRaw);
        arsort($freq);
        $classLabels = array_values(array_keys($freq));
        $numClasses = count($classLabels);
        if ($numClasses < 2) {
            throw new RuntimeException('Butuh >=2 kelas untuk SVM.');
        }

        $labelToIndex = [];
        foreach ($classLabels as $idx => $lab) {
            $labelToIndex[(string) $lab] = $idx;
        }

        $baseIndex = [];
        $bi = 0;
        foreach ($nums as $c => $_) $baseIndex["NUM::{$c}"] = $bi++;
        foreach ($cats as $c => $vals) {
            foreach (array_keys($vals) as $v) {
                $baseIndex["CAT::{$c}::{$v}"] = $bi++;
            }
        }
        $baseDim = $bi;

        $kcfg = $this->parseKernel($kernelSpec);
        [$featureMapper, $kernelMeta, $mappedDim] = $this->buildFeatureMapper($baseIndex, $kcfg);
        $biasIndex = $mappedDim;
        $dim = $mappedDim + 1;

        $X = [];
        $y = [];
        foreach ($rows as $row) {
            $lab = $row[$goalKey] ?? null;
            if ($lab === null || $lab === '' || preg_match('/^(unknown|tidak diketahui)$/i', (string) $lab)) {
                continue;
            }
            $lab = (string) $lab;
            if (!array_key_exists($lab, $labelToIndex)) continue;
            $yi = $labelToIndex[$lab];

            $xBase = array_fill(0, $baseDim, 0.0);
            foreach ($row as $c => $v) {
                if (in_array($c, $skipCols, true)) continue;
                if (isset($nums[$c])) {
                    $min = $nums[$c]['min'];
                    $max = $nums[$c]['max'];
                    $f = is_numeric($v) ? (float) $v : 0.0;
                    $z = ($max > $min) ? (($f - $min) / ($max - $min)) : 0.0;
                    $xBase[$baseIndex["NUM::{$c}"]] = $z;
                } elseif (isset($cats[$c])) {
                    $s = trim((string) $v);
                    $key = "CAT::{$c}::{$s}";
                    if ($s !== '' && isset($baseIndex[$key])) {
                        $xBase[$baseIndex[$key]] = 1.0;
                    }
                }
            }

            $z = $featureMapper($xBase);
            $xi = array_merge($z, [1.0]);
            $sum = 0.0;
            foreach ($xi as $vv) $sum += abs((float) $vv);
            if ($sum == 0.0) continue;

            $X[] = $xi;
            $y[] = $yi;
        }

        if (!$X) {
            throw new RuntimeException('Tidak ada sampel valid untuk training.');
        }

        [$trainIdx, $testIdx] = $this->stratifiedSplit($y, $testRatio);
        $nTrain = count($trainIdx);
        $nTest = count($testIdx);
        if ($nTrain === 0) {
            throw new RuntimeException('Data train kosong setelah split.');
        }

        $W = [];
        for ($c = 0; $c < $numClasses; $c++) {
            $W[$c] = array_fill(0, $dim, 0.0);
        }

        $start = microtime(true);
        $t = 0;
        $epochTimes = [];
        for ($ep = 0; $ep < $epochs; $ep++) {
            $eStart = microtime(true);
            $order = $trainIdx;
            shuffle($order);
            foreach ($order as $idx) {
                $t++;
                $eta = $eta0 / (1.0 + $lambda * $eta0 * $t);
                $xi = $X[$idx];
                $yi = $y[$idx];
                $L = count($xi);
                for ($c = 0; $c < $numClasses; $c++) {
                    $yc = ($c === $yi) ? 1.0 : -1.0;
                    $dot = 0.0;
                    for ($k = 0; $k < $L; $k++) $dot += $W[$c][$k] * $xi[$k];
                    if ($yc * $dot < 1.0) {
                        for ($k = 0; $k < $L; $k++) {
                            $W[$c][$k] -= $eta * ($lambda * $W[$c][$k] - $yc * $xi[$k]);
                        }
                    } else {
                        for ($k = 0; $k < $L; $k++) {
                            $W[$c][$k] -= $eta * ($lambda * $W[$c][$k]);
                        }
                    }
                }
            }
            $epochTimes[] = microtime(true) - $eStart;
        }
        $duration = microtime(true) - $start;
        $avgEpoch = array_sum($epochTimes) / max(count($epochTimes), 1);
        $throughput = ($nTrain * $epochs) / max($duration, 1e-9);

        $confTrain = $this->emptyConfusion($numClasses);
        $trainCorrect = 0;
        foreach ($trainIdx as $idx) {
            [$pred] = $this->predictClass($W, $X[$idx]);
            $actual = $y[$idx];
            $confTrain[$actual][$pred]++;
            if ($pred === $actual) $trainCorrect++;
        }
        $trainAcc = $trainCorrect / max($nTrain, 1);

        $confTest = null;
        $testAcc = null;
        if ($nTest > 0) {
            $confTest = $this->emptyConfusion($numClasses);
            $testCorrect = 0;
            foreach ($testIdx as $idx) {
                [$pred] = $this->predictClass($W, $X[$idx]);
                $actual = $y[$idx];
                $confTest[$actual][$pred]++;
                if ($pred === $actual) $testCorrect++;
            }
            $testAcc = $testCorrect / $nTest;
        }

        $modelPath = $this->saveModel(
            $userId,
            $kcfg['type'],
            [
                'type' => 'svm_sgd',
                'dim' => $dim,
                'weights' => $W,
                'bias_index' => $biasIndex,
                'lambda' => $lambda,
                'epochs' => $epochs,
                'eta0' => $eta0,
                'goal_column' => $goalKey,
                'classes' => $classLabels,
                'num_classes' => $numClasses,
                'label_map' => [
                    '+1' => $classLabels[0] ?? null,
                    '-1' => $classLabels[1] ?? null,
                ],
                'feature_index' => $baseIndex,
                'numeric_minmax' => $nums,
                'kernel' => $kcfg['type'],
                'kernel_meta' => $kernelMeta,
            ]
        );

        $json = [
            'status' => 'success',
            'kernel' => $kcfg['type'],
            'kernel_meta' => $kernelMeta,
            'samples' => [
                'total' => count($X),
                'train' => $nTrain,
                'test' => $nTest,
            ],
            'train_accuracy' => $trainAcc,
            'test_accuracy' => $testAcc,
            'threshold' => 0,
            'execution_time' => [
                'total_sec' => $duration,
                'avg_epoch' => $avgEpoch,
                'throughput' => $throughput,
            ],
            'hyperparams' => [
                'epochs' => $epochs,
                'lambda' => $lambda,
                'eta0' => $eta0,
                'test_ratio' => $testRatio,
                'oversample_minor' => 0,
                'class_weight' => 'none',
            ],
            'model_path' => $modelPath,
            'source_table' => $sourceTable,
            'goal_column' => $goalKey,
            'confusion' => [
                'labels' => $classLabels,
                'train' => $confTrain,
                'test' => $confTest,
            ],
        ];

        $stdout = $this->buildStdout($json);
        $this->logTraining($userId, 'success', $duration, $modelPath, $this->compactOutput($json));

        return ['stdout' => $stdout, 'json' => $json];
    }

    private function resolveSourceTable(int $userId, ?string $tableOverride): string
    {
        if ($tableOverride) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $tableOverride)) {
                throw new RuntimeException('Nama tabel override tidak valid.');
            }
            if (!$this->tableExists($tableOverride)) {
                throw new RuntimeException("Tabel override '{$tableOverride}' tidak ditemukan.");
            }
            return $tableOverride;
        }

        $case = "case_user_{$userId}";
        $testCase = "test_case_user_{$userId}";
        if ($this->tableExists($case)) return $case;
        if ($this->tableExists($testCase)) return $testCase;

        throw new RuntimeException("Tidak ditemukan tabel {$case} maupun {$testCase}.");
    }

    private function resolveGoalKey(int $userId, string $sourceTable): string
    {
        $goal = DB::table('atribut')
            ->where('user_id', $userId)
            ->where(function ($q) {
                $q->where('goal', 1)->orWhere('goal', 'T');
            })
            ->select('atribut_id', 'atribut_name')
            ->first();

        if (!$goal) throw new RuntimeException('Atribut goal belum ditentukan.');
        $wanted = $goal->atribut_id . '_' . $goal->atribut_name;

        $first = DB::table($sourceTable)->first();
        if (!$first) throw new RuntimeException("Dataset kosong pada {$sourceTable}.");
        $columns = array_keys((array) $first);
        $goalKey = $this->findGoalKey($columns, $wanted);
        if ($goalKey === null) {
            throw new RuntimeException("Kolom goal '{$wanted}' tidak ditemukan.");
        }
        return $goalKey;
    }

    private function tableExists(string $table): bool
    {
        $db = DB::getDatabaseName();
        $r = DB::selectOne(
            "SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema=? AND table_name=? LIMIT 1",
            [$db, $table]
        );
        return $r && (int) $r->c > 0;
    }

    private function norm(string $s): string
    {
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/i', '_', $s) ?? $s;
        $s = preg_replace('/_+/', '_', $s) ?? $s;
        return trim($s, '_');
    }

    private function findGoalKey(array $cols, string $wanted): ?string
    {
        if (in_array($wanted, $cols, true)) return $wanted;
        $wn = $this->norm($wanted);
        $m = [];
        foreach ($cols as $c) $m[$this->norm((string) $c)] = (string) $c;
        return $m[$wn] ?? null;
    }

    private function parseKernel(string $spec): array
    {
        $parts = explode(':', strtolower(trim($spec)));
        $type = $parts[0] ?? 'sgd';
        $cfg = ['type' => $type];
        foreach (array_slice($parts, 1) as $kv) {
            if (!str_contains($kv, '=')) continue;
            [$k, $v] = explode('=', $kv, 2);
            $cfg[trim($k)] = is_numeric(trim($v)) ? (float) trim($v) : trim($v);
        }
        if ($type === 'rbf') {
            $cfg['D'] = (int) ($cfg['D'] ?? 128);
            $cfg['gamma'] = (float) ($cfg['gamma'] ?? 0.25);
        }
        if ($type === 'sigmoid') {
            $cfg['D'] = (int) ($cfg['D'] ?? 128);
            $cfg['scale'] = (float) ($cfg['scale'] ?? 1.0);
            $cfg['coef0'] = (float) ($cfg['coef0'] ?? 0.0);
        }
        return $cfg;
    }

    private function buildFeatureMapper(array $baseIndex, array $kcfg): array
    {
        $B = count($baseIndex);
        if (($kcfg['type'] ?? 'sgd') === 'sgd') {
            return [fn (array $x) => $x, ['type' => 'sgd'], $B];
        }

        if ($kcfg['type'] === 'rbf') {
            $D = (int) $kcfg['D'];
            $gamma = (float) $kcfg['gamma'];
            $seed = crc32(json_encode(array_keys($baseIndex)));
            $scale = sqrt(2.0 / max($D, 1));
            $randMax = mt_getrandmax() ?: 1;
            $f = function (array $x) use ($seed, $gamma, $D, $B, $scale, $randMax) {
                $z = array_fill(0, $D, 0.0);
                for ($j = 0; $j < $D; $j++) {
                    mt_srand($seed + $j, MT_RAND_MT19937);
                    $dot = 0.0;
                    for ($k = 0; $k < $B; $k++) {
                        $u1 = max(mt_rand() / $randMax, 1e-12);
                        $u2 = mt_rand() / $randMax;
                        $n = sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);
                        $dot += sqrt(2.0 * $gamma) * $n * $x[$k];
                    }
                    $b = (mt_rand() / $randMax) * 2.0 * M_PI;
                    $z[$j] = $scale * cos($dot + $b);
                }
                return $z;
            };
            return [$f, ['type' => 'rbf', 'D' => $D, 'gamma' => $gamma, 'seed' => $seed], $D];
        }

        $D = (int) ($kcfg['D'] ?? 128);
        $scale = (float) ($kcfg['scale'] ?? 1.0);
        $coef0 = (float) ($kcfg['coef0'] ?? 0.0);
        $seed = 14641 ^ crc32(json_encode(array_keys($baseIndex)));
        $norm = sqrt(1.0 / max($D, 1));
        $randMax = mt_getrandmax() ?: 1;
        $f = function (array $x) use ($seed, $scale, $coef0, $D, $B, $norm, $randMax) {
            $z = array_fill(0, $D, 0.0);
            for ($j = 0; $j < $D; $j++) {
                mt_srand($seed + $j, MT_RAND_MT19937);
                $dot = 0.0;
                for ($k = 0; $k < $B; $k++) {
                    $u1 = max(mt_rand() / $randMax, 1e-12);
                    $u2 = mt_rand() / $randMax;
                    $n = sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);
                    $dot += $scale * $n * $x[$k];
                }
                $z[$j] = $norm * tanh($dot + $coef0);
            }
            return $z;
        };
        return [$f, ['type' => 'sigmoid', 'D' => $D, 'scale' => $scale, 'coef0' => $coef0, 'seed' => $seed], $D];
    }

    private function stratifiedSplit(array $y, float $testRatio): array
    {
        $buckets = [];
        foreach ($y as $idx => $c) $buckets[$c][] = $idx;

        $seed = getenv('SVM_SPLIT_SEED');
        if ($seed !== false && $seed !== '') {
            mt_srand((int) $seed, MT_RAND_MT19937);
        }

        $train = [];
        $test = [];
        foreach ($buckets as $idxList) {
            shuffle($idxList);
            $n = count($idxList);
            $tCount = (int) round($testRatio * $n);
            if ($tCount >= $n && $n > 1) $tCount = $n - 1;
            if ($tCount === 0 && $n > 1 && $testRatio > 0.0) $tCount = 1;
            $test = array_merge($test, array_slice($idxList, 0, $tCount));
            $train = array_merge($train, array_slice($idxList, $tCount));
        }

        if (!$train && $test) {
            $train[] = array_shift($test);
        }
        return [$train, $test];
    }

    private function predictClass(array $W, array $x): array
    {
        $bestIdx = 0;
        $bestScore = null;
        foreach ($W as $c => $wc) {
            $dot = 0.0;
            $L = count($x);
            for ($k = 0; $k < $L; $k++) $dot += $wc[$k] * $x[$k];
            if ($bestScore === null || $dot > $bestScore) {
                $bestScore = $dot;
                $bestIdx = (int) $c;
            }
        }
        return [$bestIdx, $bestScore];
    }

    private function emptyConfusion(int $n): array
    {
        $m = [];
        for ($i = 0; $i < $n; $i++) $m[] = array_fill(0, $n, 0);
        return $m;
    }

    private function saveModel(int $userId, string $kernelType, array $model): string
    {
        $dir = base_path('svm_models');
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . "svm_user_{$userId}_{$kernelType}.json";
        $ok = @file_put_contents($path, json_encode($model, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        if ($ok === false) {
            throw new RuntimeException("Gagal menyimpan model di {$path}");
        }
        return $path;
    }

    private function logTraining(int $userId, string $status, float $exec, ?string $modelPath, string $output): void
    {
        $table = "svm_user_{$userId}";
        DB::statement("
            CREATE TABLE IF NOT EXISTS `{$table}` (
                id INT AUTO_INCREMENT PRIMARY KEY,
                status VARCHAR(50),
                execution_time DECIMAL(12,6) NULL,
                model_path VARCHAR(1024) NULL,
                output LONGTEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        DB::table($table)->insert([
            'status' => $status,
            'execution_time' => $exec,
            'model_path' => $modelPath,
            'output' => $output,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function compactOutput(array $json): string
    {
        $accTrain = number_format(((float) ($json['train_accuracy'] ?? 0)) * 100, 2);
        $accTest = $json['test_accuracy'] === null ? 'NA' : number_format(((float) $json['test_accuracy']) * 100, 2);
        $t = $json['execution_time']['total_sec'] ?? 0;
        return "SVM {$json['kernel']}. source={$json['source_table']}; goal={$json['goal_column']}; acc_train={$accTrain}%; acc_test={$accTest}%; execution_time={$t}s";
    }

    private function buildStdout(array $json): string
    {
        $lines = [];
        $lines[] = "✅ SVM ({$json['kernel']})";
        $lines[] = "🔧 Hyper: epochs={$json['hyperparams']['epochs']}, lambda={$json['hyperparams']['lambda']}, eta0={$json['hyperparams']['eta0']}";
        $lines[] = "🔧 Kernel meta: " . json_encode($json['kernel_meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $lines[] = "📊 Sampel: {$json['samples']['train']} | Akurasi(train): " . number_format($json['train_accuracy'] * 100, 2) . "%";
        $lines[] = "⏱️ Total: " . number_format((float) $json['execution_time']['total_sec'], 6) . " s";
        $lines[] = "📦 Model: {$json['model_path']}";
        $lines[] = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return implode("\n", $lines);
    }

    private function envf(string $key, float $default): float
    {
        $v = getenv($key);
        if ($v === false || $v === '') return $default;
        return is_numeric($v) ? (float) $v : $default;
    }
}

