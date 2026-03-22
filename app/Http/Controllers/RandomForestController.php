<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Rubix\ML\Classifiers\RandomForest;
use Rubix\ML\Datasets\Labeled;
use Rubix\ML\Datasets\Unlabeled;
use Rubix\ML\CrossValidation\Metrics\Accuracy;
use Rubix\ML\Persisters\Filesystem;
use Rubix\ML\Serializers\Native;
use Rubix\ML\Exceptions\EmptyDataset;

class RandomForestController extends Controller
{
    public function index()
    {
        $userId = Auth::id();
        $modelPath = storage_path("app/model_rf_{$userId}.model");
        $modelExists = file_exists($modelPath);

        return view('admin.menu.randomforest', [
            'modelExists' => $modelExists,
            'featuresInfo' => session('features_info', []),
            'predictionResult' => session('prediction_result', null),
            'evaluation' => session('evaluation', null),
            'debugInfo' => session('debug_info', null),
        ]);
    }

    public function generate()
    {
        $startTime = microtime(true);
        $userId = Auth::id();
        $table = 'test_case_user_' . $userId;
        $testTable = 'test_case_user_' . $userId . '_a';

        if (!Schema::hasTable($table)) {
            return back()->withErrors("Tabel {$table} tidak ditemukan.");
        }

        $rows = DB::table($table)->get()->toArray();
        if (!$rows) {
            return back()->withErrors("Dataset kosong untuk user {$userId}.");
        }

        $rawColumns = Schema::getColumnListing($table);
        $ignore = ['id', 'no', 'case_id', 'user_id', 'case_num', 'title', 'case_title', 'created_at', 'updated_at', 'algoritma'];
        $columns = array_values(array_diff($rawColumns, $ignore));

        // Prioritas utama: kolom tepat sebelum user_id / case_num pada urutan tabel
        $labelByOrder = null;
        $cutoffIdx = null;
        foreach (['user_id', 'case_num'] as $stop) {
            $idx = array_search($stop, $rawColumns, true);
            if ($idx !== false) {
                $cutoffIdx = $cutoffIdx === null ? $idx : min($cutoffIdx, $idx);
            }
        }
        if ($cutoffIdx !== null && $cutoffIdx > 0) {
            for ($i = $cutoffIdx - 1; $i >= 0; $i--) {
                $cand = $rawColumns[$i];
                if (!in_array($cand, $ignore, true)) {
                    $labelByOrder = $cand;
                    break;
                }
            }
        }

        // Hitung statistik kolom sederhana agar label lebih tepat
        $maxUniqueCheck = 50;
        $columnStats = [];
        foreach ($columns as $col) {
            $columnStats[$col] = [
                'unique' => [],
                'unique_count' => 0,
                'non_empty' => 0,
            ];
        }
        foreach ($rows as $row) {
            $r = (array) $row;
            foreach ($columns as $col) {
                if (!array_key_exists($col, $r)) {
                    continue;
                }
                $val = $r[$col];
                if ($val === null || $val === '') {
                    continue;
                }
                $columnStats[$col]['non_empty']++;
                if ($columnStats[$col]['unique_count'] <= $maxUniqueCheck) {
                    $columnStats[$col]['unique'][(string) $val] = true;
                    $columnStats[$col]['unique_count'] = count($columnStats[$col]['unique']);
                }
                if ($columnStats[$col]['unique_count'] > $maxUniqueCheck) {
                    $columnStats[$col]['unique'] = [];
                }
            }
        }
        foreach ($columnStats as $col => $stat) {
            if (!empty($stat['unique'])) {
                $columnStats[$col]['unique_count'] = count($stat['unique']);
            } elseif ($stat['unique_count'] > $maxUniqueCheck) {
                $columnStats[$col]['unique_count'] = $maxUniqueCheck + 1;
            }
        }

        $chooseLabel = function (array $candidates) use ($columnStats) {
            $best = null;
            $bestUnique = PHP_INT_MAX;
            foreach ($candidates as $c) {
                if (!isset($columnStats[$c]) || $columnStats[$c]['non_empty'] === 0) {
                    continue;
                }
                $u = $columnStats[$c]['unique_count'];
                if ($u < $bestUnique) {
                    $bestUnique = $u;
                    $best = $c;
                }
            }
            return $best;
        };

        $diabetesCols = array_values(array_filter($columns, fn($c) => preg_match('/diabetes/i', $c)));
        $labelKey = $labelByOrder && in_array($labelByOrder, $columns, true) ? $labelByOrder : null;
        if (!$labelKey) {
            $labelKey = $chooseLabel($diabetesCols);
        }

        if (!$labelKey) {
            $patternCols = array_values(array_filter($columns, fn($c) =>
                preg_match('/action|label|hasil|target|output|diagnosa|penyakit|goal/i', $c)
            ));
            $labelKey = $chooseLabel($patternCols);
        }
        if (!$labelKey) {
            $labelKey = $chooseLabel($columns);
        }
        if (!$labelKey) {
            $labelKey = end($columns);
        }

        $labelDisplayName = str_replace(['_', '-'], ' ', $labelKey);
        $labelDisplayName = preg_replace('/^[^a-zA-Z]+/', '', $labelDisplayName);
        $labelDisplayName = $labelDisplayName ?: 'Goal';
        $labelDisplayName = ucwords(strtolower($labelDisplayName));
        $labelIsBinary = preg_match('/diabetes/i', $labelKey) || preg_match('/diabetes/i', $labelDisplayName);
        $stripIdPrefix = function (string $text) {
            $text = trim($text);
            if ($text === '') return '';
            $parts = explode('_', $text);
            if (count($parts) > 1) {
                $last = end($parts);
                if ($last !== '') {
                    return $last;
                }
            }
            return $text;
        };
        $normalizeBinaryLabel = function (string $text) {
            $t = strtolower(trim($text));
            $t = str_replace(['_', '-'], ' ', $t);
            $t = preg_replace('/\s+/', ' ', $t);
            if (preg_match('/(0|1)\s*$/', $t, $m)) {
                return $m[1];
            }
            if (is_numeric($t)) {
                $num = (float) $t;
                if (abs($num) < 1e-9) return '0';
                if (abs($num - 1.0) < 1e-9) return '1';
            }
            $tokens = array_values(array_filter(preg_split('/\s+/', $t)));
            foreach ($tokens as $tok) {
                if (is_numeric($tok)) {
                    $num = (float) $tok;
                    if (abs($num) < 1e-9) return '0';
                    if (abs($num - 1.0) < 1e-9) return '1';
                }
            }
            $falsey = ['0', 'no', 'tidak', 'negatif', 'false', 'f', 'n', 'non', 'bukan'];
            $truthy = ['1', 'yes', 'ya', 'positif', 'true', 't', 'y'];
            if (in_array($t, $falsey, true)) return '0';
            if (in_array($t, $truthy, true)) return '1';
            if (preg_match('/^0\b/', $t)) return '0';
            if (preg_match('/^1\b/', $t)) return '1';
            return null;
        };

        $featureKeys = array_values(array_filter($columns, fn($c) => $c !== $labelKey));

        $samples = [];
        $labels = [];

        foreach ($rows as $row) {
            $r = (array) $row;
            if (!array_key_exists($labelKey, $r) || $r[$labelKey] === null || $r[$labelKey] === '') {
                continue;
            }

            $rawLabel = trim((string) $r[$labelKey]);
            if (str_contains($rawLabel, '=')) {
                [, $rawValue] = array_pad(explode('=', $rawLabel, 2), 2, '');
            } else {
                $rawValue = $rawLabel;
            }
            $rawValue = $stripIdPrefix($rawValue);
            $rawValue = str_replace(['_', '-'], ' ', trim($rawValue));

            if ($labelIsBinary) {
                $bin = $normalizeBinaryLabel($rawValue);
                if ($bin === null) {
                    continue;
                }
                $labels[] = "{$labelDisplayName} = {$bin}";
                $encoded = [];
                foreach ($featureKeys as $key) {
                    $v = $r[$key];
                    if (is_null($v) || $v === '') $encoded[] = 0;
                    else $encoded[] = is_numeric($v)
                        ? (float) $v
                        : (float) ((crc32(strtolower(trim($v))) % 1000) / 1000);
                }
                $samples[] = $encoded;
                continue;
            }

            $value = trim(preg_replace('/\s+/', ' ', $rawValue));
            if ($value === '') {
                $value = 'Unknown';
            }

            $labels[] = "{$labelDisplayName} = {$value}";

            $encoded = [];
            foreach ($featureKeys as $key) {
                $v = $r[$key];
                if (is_null($v) || $v === '') $encoded[] = 0;
                else $encoded[] = is_numeric($v)
                    ? (float) $v
                    : (float) ((crc32(strtolower(trim($v))) % 1000) / 1000);
            }
            $samples[] = $encoded;
        }

        if (count($samples) === 0) {
            return back()->withErrors("Tidak ada data yang memiliki nilai pada kolom label '{$labelKey}'. Periksa lagi dataset test_case_user_{id} / test_case_user_{id}_a Anda.");
        }

        $labelIndices = [];
        foreach ($labels as $idx => $label) {
            $labelIndices[$label][] = $idx;
        }
        $classCounts = array_map('count', $labelIndices);
        $originalEncodedCount = count($samples);

        $enableUndersampling = filter_var(env('RF_ENABLE_UNDERSAMPLE', true), FILTER_VALIDATE_BOOLEAN);
        $undersampleSizeEnv = env('RF_UNDERSAMPLE_SIZE');
        $undersampleSize = is_numeric($undersampleSizeEnv) ? (int) $undersampleSizeEnv : null;
        $undersampleApplied = false;
        $targetPerClass = null;

        if ($enableUndersampling && count($classCounts) > 1) {
            $minCount = min($classCounts);
            $targetPerClass = $undersampleSize && $undersampleSize > 0
                ? min($undersampleSize, $minCount)
                : $minCount;

            if ($targetPerClass > 0) {
                $balancedSamples = [];
                $balancedLabels = [];
                foreach ($labelIndices as $label => $indices) {
                    shuffle($indices);
                    $selected = array_slice($indices, 0, $targetPerClass);
                    foreach ($selected as $i) {
                        $balancedSamples[] = $samples[$i];
                        $balancedLabels[] = $labels[$i];
                    }
                }
                $samples = $balancedSamples;
                $labels = $balancedLabels;
                $undersampleApplied = true;
            }
        }

        try {
            $dataset = Labeled::build($samples, $labels);
        } catch (EmptyDataset $e) {
            return back()->withErrors('Dataset kosong setelah pemrosesan, tidak bisa melatih model.');
        }

        $dataset = $dataset->randomize();
        $train = $dataset;

        $numTrees = 100;
        $sampling = 0.2;

        $model = new RandomForest();
        try {
            $model->train($train);
        } catch (EmptyDataset $e) {
            return back()->withErrors('Dataset training kosong atau tidak valid untuk Random Forest.');
        }

        $predictions = $model->predict($train);
        $metric = new Accuracy();
        $accuracy = round($metric->score($predictions, $train->labels()) * 100, 2);
        $trainTime = round(microtime(true) - $startTime, 3);

        $testAccuracy = null;
        $testSize = 0;
        $testLabelCounts = [];
        if (Schema::hasTable($testTable)) {
            $testRows = DB::table($testTable)->get()->toArray();
            if (!empty($testRows)) {
                $testSamples = [];
                $testLabels = [];

                foreach ($testRows as $row) {
                    $r = (array) $row;
                    if (!array_key_exists($labelKey, $r) || $r[$labelKey] === null || $r[$labelKey] === '') {
                        continue;
                    }

                    $rawLabel = trim((string) $r[$labelKey]);
                    if (str_contains($rawLabel, '=')) {
                        [, $rawValue] = array_pad(explode('=', $rawLabel, 2), 2, '');
                    } else {
                        $rawValue = $rawLabel;
                    }
                    $rawValue = $stripIdPrefix($rawValue);
                    $rawValue = str_replace(['_', '-'], ' ', trim($rawValue));

                    if ($labelIsBinary) {
                        $bin = $normalizeBinaryLabel($rawValue);
                        if ($bin === null) {
                            continue;
                        }
                        $testLabels[] = "{$labelDisplayName} = {$bin}";
                    } else {
                        $value = trim(preg_replace('/\s+/', ' ', $rawValue));
                        if ($value === '') {
                            $value = 'Unknown';
                        }
                        $testLabels[] = "{$labelDisplayName} = {$value}";
                    }

                    $encoded = [];
                    foreach ($featureKeys as $key) {
                        $v = $r[$key] ?? null;
                        if (is_null($v) || $v === '') $encoded[] = 0;
                        else $encoded[] = is_numeric($v)
                            ? (float) $v
                            : (float) ((crc32(strtolower(trim($v))) % 1000) / 1000);
                    }
                    $testSamples[] = $encoded;
                }

                if (count($testSamples) > 0) {
                    try {
                        $testDataset = Labeled::build($testSamples, $testLabels);
                        $testPredictions = $model->predict($testDataset);
                        $testAccuracy = round($metric->score($testPredictions, $testDataset->labels()) * 100, 2);
                        $testSize = count($testDataset);
                        $testLabelCounts = array_count_values($testLabels);
                    } catch (EmptyDataset $e) {
                        // keep defaults
                    }
                }
            }
        }

        $encoding = (new Native())->serialize($model, true);
        (new Filesystem(storage_path("app/model_rf_{$userId}.model")))->save($encoding);
        file_put_contents(storage_path("app/features_{$userId}.json"), json_encode($featureKeys, JSON_PRETTY_PRINT));

        $labelCounts = array_count_values($labels);
        $meta = [
            'accuracy' => $accuracy,
            'train_size' => count($train),
            'train_size_before_undersample' => $originalEncodedCount,
            'test_size' => $testSize,
            'test_accuracy' => $testAccuracy,
            'dataset_size' => $originalEncodedCount + $testSize,
            'label_distribution' => $labelCounts,
            'test_label_distribution' => $testLabelCounts,
            'trees' => $numTrees,
            'sampling' => $sampling,
            'training_time' => $trainTime,
            'label_key' => $labelKey,
            'label_name' => $labelDisplayName,
            'undersampling' => [
                'enabled' => $enableUndersampling,
                'applied' => $undersampleApplied,
                'target_per_class' => $targetPerClass,
                'before' => [
                    'samples' => $originalEncodedCount,
                    'class_counts' => $classCounts,
                ],
                'after' => [
                    'samples' => count($labels),
                    'class_counts' => $labelCounts,
                ],
            ],
            'full_dataset_used' => true,
            'updated_at' => now()->toDateTimeString(),
        ];
        file_put_contents(storage_path("app/meta_{$userId}.json"), json_encode($meta, JSON_PRETTY_PRINT));

        session([
            'features_info' => $featureKeys,
            'evaluation' => $meta,
            'debug_info' => [
                'label_key' => $labelKey,
                'feature_count' => count($featureKeys),
                'original_dataset_size' => count($rows),
                'encoded_samples_before_undersampling' => $originalEncodedCount,
                'encoded_samples_after_undersampling' => count($samples),
                'class_counts_before_undersampling' => $classCounts,
                'balanced_label_distribution' => array_count_values($labels),
                'undersampling_applied' => $undersampleApplied,
                'undersampling_target_per_class' => $targetPerClass,
                'full_dataset_for_training' => true,
                'test_size' => $testSize,
                'test_accuracy' => $testAccuracy,
                'test_label_distribution' => $testLabelCounts,
            ],
        ]);

        return back()->with('success', "Model berhasil dilatih (Akurasi: {$accuracy}%)");
    }

    public function predict(Request $request)
    {
        $userId = Auth::id();
        $modelPath = storage_path("app/model_rf_{$userId}.model");
        $featurePath = storage_path("app/features_{$userId}.json");
        $metaPath = storage_path("app/meta_{$userId}.json");

        if (!file_exists($modelPath)) {
            return back()->withErrors('Belum ada model, silakan latih dulu.');
        }
        if (!file_exists($featurePath)) {
            return back()->withErrors('Urutan fitur belum ditemukan.');
        }

        $featureKeys = json_decode(file_get_contents($featurePath), true);
        $features = json_decode($request->input('features'), true);

        if (!is_array($features)) {
            return back()->withErrors('Format input tidak valid, harus JSON array.');
        }

        if (count($features) !== count($featureKeys)) {
            return back()->withErrors("Jumlah fitur input (" . count($features) . ") tidak cocok dengan model (" . count($featureKeys) . ").");
        }

        $encoded = [];
        foreach ($features as $v) {
            $encoded[] = is_numeric($v)
                ? (float) $v
                : (float) ((crc32(strtolower(trim($v))) % 1000) / 1000);
        }

        $encoding = (new Filesystem($modelPath))->load();
        $model = (new Native())->deserialize($encoding);

        $dataset = Unlabeled::build([$encoded]);
        $prediction = $model->predict($dataset)[0];
        $probabilities = $model->proba($dataset)[0] ?? [];

        $labelName = 'Goal';
        if (file_exists($metaPath)) {
            $meta = json_decode(file_get_contents($metaPath), true);
            if (!empty($meta['label_name'])) {
                $labelName = $meta['label_name'];
            } elseif (!empty($meta['label_key'])) {
                $labelName = str_replace(['_', '-'], ' ', $meta['label_key']);
            }
        }
        if ($labelName === 'Goal') {
            $casesTable = 'test_case_user_' . $userId;
            if (Schema::hasTable($casesTable)) {
                $columns = Schema::getColumnListing($casesTable);
                $ignore = ['id', 'no', 'case_id', 'user_id', 'case_num', 'title', 'case_title', 'created_at', 'updated_at', 'algoritma'];
                $candidates = array_values(array_diff($columns, $ignore));
                $detected = collect($candidates)->filter(fn($c) =>
                    preg_match('/action|label|hasil|target|output|diagnosa|penyakit|goal/i', $c)
                )->last() ?? end($candidates);
                if ($detected) {
                    $labelName = str_replace(['_', '-'], ' ', $detected);
                    $labelName = preg_replace('/^[^a-zA-Z]+/', '', $labelName);
                    $labelName = ucwords(strtolower($labelName ?: 'Goal'));
                }
            }
        }

        $normalizePrediction = function ($rawPrediction) use ($labelName) {
            $text = str_replace(['_', '-'], ' ', trim((string) $rawPrediction));
            if ($text === '') {
                return "{$labelName} = Unknown";
            }

            $extractValue = function ($rhs) {
                $rhs = str_replace(['_', '-'], ' ', trim($rhs));
                $tokens = array_values(array_filter(preg_split('/\s+/', $rhs)));
                return $tokens ? end($tokens) : 'Unknown';
            };

            if (str_contains($text, '=')) {
                [, $right] = array_pad(explode('=', $text, 2), 2, '');
                $value = $extractValue($right);
                return "{$labelName} = {$value}";
            }

            $value = $extractValue($text);
            return "{$labelName} = {$value}";
        };

        $rawPrediction = is_string($prediction) ? $prediction : (string) $prediction;
        $displayPrediction = $normalizePrediction($rawPrediction);
        $confidence = round(max($probabilities) * 100, 2);

        session([
            'prediction_result' => "{$displayPrediction} ({$confidence}% yakin)",
            'debug_info' => [
                'input_count' => count($features),
                'expected_count' => count($featureKeys),
                'probabilities' => $probabilities,
            ]
        ]);

        return back()->with('success', 'Prediksi berhasil dilakukan!');
    }

    public function inferenceFromConsultation($user_id, $case_num)
    {
        $startTime = microtime(true);
        $userId = Auth::id();
        $case_num = $userId;

        $modelPath = storage_path("app/model_rf_{$userId}.model");
        $featurePath = storage_path("app/features_{$userId}.json");
        $metaPath = storage_path("app/meta_{$userId}.json");
        $labelName = 'Goal';
        if (file_exists($metaPath)) {
            $meta = json_decode(file_get_contents($metaPath), true);
            if (!empty($meta['label_name'])) {
                $labelName = $meta['label_name'];
            } elseif (!empty($meta['label_key'])) {
                $labelName = str_replace(['_', '-'], ' ', $meta['label_key']);
            }
        }
        if ($labelName === 'Goal') {
            $casesTable = 'test_case_user_' . $userId;
            if (Schema::hasTable($casesTable)) {
                $columns = Schema::getColumnListing($casesTable);
                $ignore = ['id', 'no', 'case_id', 'user_id', 'case_num', 'title', 'case_title', 'created_at', 'updated_at', 'algoritma'];
                $candidates = array_values(array_diff($columns, $ignore));
                $detected = collect($candidates)->filter(fn($c) =>
                    preg_match('/action|label|hasil|target|output|diagnosa|penyakit|goal/i', $c)
                )->last() ?? end($candidates);
                if ($detected) {
                    $labelName = str_replace(['_', '-'], ' ', $detected);
                    $labelName = preg_replace('/^[^a-zA-Z]+/', '', $labelName);
                    $labelName = ucwords(strtolower($labelName ?: 'Goal'));
                }
            }
        }

        $normalizePrediction = function ($rawPrediction) use ($labelName) {
            $text = str_replace(['_', '-'], ' ', trim((string) $rawPrediction));
            if ($text === '') {
                return "{$labelName} = Unknown";
            }

            $extractLabel = function ($lhs) use ($labelName) {
                $lhs = trim(preg_replace('/^[^a-zA-Z]+/', '', $lhs));
                $lhs = preg_replace('/\s+/', ' ', $lhs);
                return $lhs !== '' ? ucwords(strtolower($lhs)) : $labelName;
            };

            $extractValue = function ($rhs) {
                if (preg_match('/-?\d+(\.\d+)?/', $rhs, $m)) {
                    return $m[0];
                }
                $tokens = array_values(array_filter(preg_split('/\s+/', trim($rhs))));
                return $tokens ? end($tokens) : 'Unknown';
            };

            if (str_contains($text, '=')) {
                [$left, $right] = array_pad(explode('=', $text, 2), 2, '');
                $label = $extractLabel($left);
                $value = $extractValue($right);
                return "{$label} = {$value}";
            }

            $label = $extractLabel($text);
            $value = $extractValue($text);
            return "{$label} = {$value}";
        };

        if (!file_exists($modelPath) || !file_exists($featurePath)) {
            return redirect()
                ->route('test.case.form')
                ->withErrors('Model Random Forest belum tersedia. Silakan latih model terlebih dahulu.');
        }

        $featureKeys = json_decode(file_get_contents($featurePath), true);
        if (!is_array($featureKeys) || empty($featureKeys)) {
            return redirect()
                ->route('test.case.form')
                ->withErrors('Informasi fitur Random Forest tidak valid.');
        }

        $casesTable = 'test_case_user_' . $userId;
        if (!Schema::hasTable($casesTable)) {
            return redirect()
                ->route('test.case.form')
                ->withErrors("Tabel {$casesTable} tidak ditemukan.");
        }

        $caseRows = DB::table($casesTable)
            ->where('algoritma', 'Random Forest')
            ->get();

        if ($caseRows->isEmpty()) {
            return redirect()
                ->route('test.case.form')
                ->withErrors('Belum ada konsultasi dengan algoritma Random Forest.');
        }

        $inferTable = 'inferensi_rf_user_' . $userId;

        if (!Schema::hasTable($inferTable)) {
            Schema::create($inferTable, function (Blueprint $table) {
                $table->increments('inf_id');
                $table->string('case_id', 100);
                $table->string('case_goal', 200)->nullable();
                $table->string('rule_id', 100)->nullable();
                $table->string('rule_goal', 200);
                $table->decimal('match_value', 5, 4);
                $table->enum('cocok', ['1', '0'])->default('1');
                $table->integer('user_id');
                $table->decimal('waktu', 16, 14)->default(0);
                $table->timestamp('created_at')->useCurrent();
            });
        } else {
            // Tambah kolom created_at jika belum ada (untuk tabel lama)
            if (!Schema::hasColumn($inferTable, 'created_at')) {
                Schema::table($inferTable, function (Blueprint $table) {
                    $table->timestamp('created_at')->useCurrent();
                });
            }
        }

        // Ambil case_id yang sudah diproses untuk menghindari duplikasi
        $processedCaseIds = DB::table($inferTable)->pluck('case_id')->toArray();

        $encoding = (new Filesystem($modelPath))->load();
        $model = (new Native())->deserialize($encoding);

        foreach ($caseRows as $row) {
            // Skip jika case_id sudah pernah diproses
            if (in_array($row->case_id, $processedCaseIds)) {
                continue;
            }

            $encoded = [];
            foreach ($featureKeys as $key) {
                $value = $row->{$key} ?? null;
                if (is_null($value) || $value === '') {
                    $encoded[] = 0.0;
                } else {
                    $encoded[] = is_numeric($value)
                        ? (float) $value
                        : (float) ((crc32(strtolower(trim($value))) % 1000) / 1000);
                }
            }

            $dataset = Unlabeled::build([$encoded]);
            $predictions = $model->predict($dataset);
            $prediction = $predictions[0] ?? null;
            $probabilities = $model->proba($dataset)[0] ?? [];

            $rawPrediction = $prediction !== null ? (string) $prediction : '';
            $ruleGoal = $normalizePrediction($rawPrediction);

            $confidence = $probabilities ? max($probabilities) : 0;
            $elapsed = microtime(true) - $startTime;

            DB::table($inferTable)->insert([
                'case_id' => $row->case_id,
                'case_goal' => '',
                'rule_id' => 'RF',
                'rule_goal' => $ruleGoal,
                'match_value' => $confidence,
                'cocok' => '1',
                'user_id' => $userId,
                'waktu' => $elapsed,
                'created_at' => now(),
            ]);
        }

        return redirect('/history')
            ->with('success', 'Inference Random Forest updated successfully!');
    }
}
