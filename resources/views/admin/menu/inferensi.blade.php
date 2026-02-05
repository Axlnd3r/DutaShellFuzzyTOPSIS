@extends('layouts.admin')

@section('content')

@php
    $user = Auth::user();

    // Ambil data dari inferensi_user_{userId}
    $inferensi = new \App\Models\Inferensi();
    $inferensi->setTableForUser($user->user_id);
    $tableExists1 = $inferensi->tableExists();
    $inference1 = $tableExists1 ? $inferensi->getRules()->map(function($item) {
        $item->source_algorithm = 'Matching Rule';
        return $item;
    }) : collect();

    // Ambil data dari inferensi_fc_user_{userId}
    $inferensiFC = new \App\Models\ForwardChaining();
    $inferensiFC->setTableForUser($user->user_id);
    $tableExists2 = $inferensiFC->tableExists();
    $inference2 = $tableExists2 ? $inferensiFC->getRules()->map(function($item) {
        $item->source_algorithm = 'Forward Chaining';
        return $item;
    }) : collect();

    // Ambil data dari inferensi_fc_user_{userId}
    $inferensiBC = new \App\Models\BackwardChaining();
    $inferensiBC->setTableForUser($user->user_id);
    $tableExists3 = $inferensiBC->tableExists();
    $inference3 = $tableExists3 ? $inferensiBC->getRules()->map(function($item) {
        $item->source_algorithm = 'Backward Chaining';
        return $item;
    }) : collect();

    // Ambil data dari inferensi_hs_user_{userId}
    $inferensiHS = new \App\Models\HybridSimilarity();
    $inferensiHS->setTableForUser($user->user_id);
    $tableExists4 = $inferensiHS->tableExists();
    $inference4 = $tableExists4 ? $inferensiHS->getRules()->map(function($item) {
        $item->source_algorithm = 'Hybrid Similarity';
        return $item;
    }) : collect();

    // Ambil data dari inferensi_jc_user_{userId}
    $inferensiJC = new \App\Models\JaccardSimilarity();
    $inferensiJC->setTableForUser($user->user_id);
    $tableExists5 = $inferensiJC->tableExists();
    $inference5 = $tableExists5 ? $inferensiJC->getRules()->map(function($item) {
        $item->source_algorithm = 'Jaccard Similarity';
        return $item;
    }) : collect();

    // Ambil data dari inferensi_cs_user_{userId}
    $inferensiCS = new \App\Models\CosineSimilarity();
    $inferensiCS->setTableForUser($user->user_id);
    $tableExists6 = $inferensiCS->tableExists();
    $inference6 = $tableExists6 ? $inferensiCS->getRules()->map(function($item) {
        $item->source_algorithm = 'Cosine Similarity';
        return $item;
    }) : collect();

    // Gabungkan seluruh hasil inferensi
    $allInference = $inference1
        ->merge($inference2)
        ->merge($inference3)
        ->merge($inference4)
        ->merge($inference5)
        ->merge($inference6)
        ->sortBy('case_id');

    $kasus = \App\Models\Kasus::where('case_num', $user->user_id)->first();

    // Ambil algoritma dari test_case_user_{userId}
    $generate = new \App\Models\Consultation();
    $generate->setTableForUser($user->user_id);
    $tableExistss = $generate->tableExists();
    
    $testCases = $tableExistss ? $generate->getRules() : collect();
    $algorithms = $testCases->pluck('algoritma', 'case_id')->toArray();

    // Build confusion matrix: prefer matrix from eval (on-the-fly), fallback to table-based for Hybrid/Jaccard/Cosine
    $matrices = [];
    $evalMatrix = session('eval_matrix');
    if ($evalMatrix && isset($evalMatrix['algo'], $evalMatrix['matrix'])) {
        $algo = $evalMatrix['algo'];
        $matrices[$algo] = $evalMatrix['matrix'];
    } elseif (session('eval_output')) {
        $allowedMatrixAlgo = ['Hybrid Similarity', 'Jaccard Similarity', 'Cosine Similarity'];
        $extractClass = function ($goal) {
            if (!$goal) return null;
            $parts = explode('=', $goal, 2);
            $cls = isset($parts[1]) ? trim($parts[1]) : trim($parts[0]);
            $cls = preg_replace('/^\\d+_/', '', $cls);
            $cls = str_replace(['_', '-'], ' ', $cls);
            return trim($cls);
        };

        foreach ($allInference as $item) {
            $algo = $item->source_algorithm ?? ($algorithms[$item->case_id] ?? 'Unknown');
            if (!in_array($algo, $allowedMatrixAlgo)) continue;
            $actual = $extractClass($item->case_goal ?? null);
            $pred = $extractClass($item->rule_goal ?? null);
            if (!$actual || !$pred) continue;
            if (!isset($matrices[$algo])) {
                $matrices[$algo] = [
                    'counts' => [],
                    'actuals' => [],
                    'preds' => [],
                    'total' => 0,
                    'correct' => 0,
                ];
            }
            $matrices[$algo]['counts'][$actual][$pred] = ($matrices[$algo]['counts'][$actual][$pred] ?? 0) + 1;
            $matrices[$algo]['actuals'][$actual] = true;
            $matrices[$algo]['preds'][$pred] = true;
            $matrices[$algo]['total']++;
            if ($actual === $pred) $matrices[$algo]['correct']++;
        }
    }
@endphp


    <h1 class="mt-4">Inferensi for User: {{ $user->username }}</h1>

    @if(isset($success))
        <div class="alert alert-success">
            {{ $success }}
        </div>
    @endif
    
    <form method="POST" action="{{ route('inference.evaluate') }}" class="mb-3 d-flex flex-wrap gap-2">
        @csrf
        <div>
            <label class="form-label mb-0 small">Algorithm</label>
            <select name="mode" class="form-select">
                <option value="hybrid">Hybrid Similarity</option>
                <option value="jaccard">Jaccard Similarity</option>
                <option value="cosine">Cosine Similarity</option>
            </select>
        </div>
        <div>
            <label class="form-label mb-0 small">Evaluation</label>
            <select name="eval" class="form-select">
                <option value="loocv">Leave-One-Out (CBR)</option>
                <option value="kfold">K-Fold</option>
                <option value="split">Train/Test Split</option>
            </select>
        </div>
        <div>
            <label class="form-label mb-0 small">Param (k or ratio)</label>
            <input type="text" name="param" class="form-control" placeholder="5 or 0.8">
        </div>
        <div class="align-self-end">
            <button type="submit" class="btn btn-success">Run Evaluation</button>
        </div>
    </form>

    @if (session('eval_output'))
        <div class="alert alert-info" style="white-space: pre-wrap;">{{ session('eval_output') }}</div>
    @endif

    @if (!empty($matrices))
        <div class="card my-3">
            <div class="card-body">
                <h5>Confusion Matrix</h5>
                @foreach ($matrices as $algo => $matrix)
                    @php
                        $actuals = $matrix['actuals'] ?? [];
                        $preds = $matrix['preds'] ?? [];
                        sort($actuals);
                        sort($preds);

                        // Jika hanya 2 kelas, tampilkan ringkasan TP/FP/FN/TN
                        $binarySummary = null;
                        if (count($actuals) === 2 && count($preds) === 2) {
                            $pos = $actuals[0];
                            $neg = $actuals[1];
                            $c = $matrix['counts'] ?? [];
                            $TP = $c[$pos][$pos] ?? 0;
                            $FN = $c[$pos][$neg] ?? 0;
                            $FP = $c[$neg][$pos] ?? 0;
                            $TN = $c[$neg][$neg] ?? 0;
                            $binarySummary = compact('pos','neg','TP','FN','FP','TN');
                        }
                    @endphp
                    <h6 class="mt-3">{{ $algo }} (acc: {{ $matrix['total'] ? number_format($matrix['correct']/$matrix['total'], 4) : '0' }}, total: {{ $matrix['total'] }})</h6>
                    @if ($binarySummary)
                        <div class="row mb-2">
                            <div class="col-md-6">
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered mb-0">
                                        <thead>
                                            <tr>
                                                <th></th>
                                                <th class="text-center">Pred: {{ $binarySummary['pos'] }}</th>
                                                <th class="text-center">Pred: {{ $binarySummary['neg'] }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <th>Actual: {{ $binarySummary['pos'] }}</th>
                                                <td class="text-center fw-bold text-success">TP<br>{{ $binarySummary['TP'] }}</td>
                                                <td class="text-center fw-bold text-danger">FN<br>{{ $binarySummary['FN'] }}</td>
                                            </tr>
                                            <tr>
                                                <th>Actual: {{ $binarySummary['neg'] }}</th>
                                                <td class="text-center fw-bold text-warning">FP<br>{{ $binarySummary['FP'] }}</td>
                                                <td class="text-center fw-bold text-primary">TN<br>{{ $binarySummary['TN'] }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    @endif
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered">
                            <thead>
                                <tr>
                                    <th>Actual \\ Pred</th>
                                    @foreach ($preds as $p)
                                        <th>{{ $p }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($actuals as $a)
                                    <tr>
                                        <th>{{ $a }}</th>
                                        @foreach ($preds as $p)
                                            <td>{{ $matrix['counts'][$a][$p] ?? 0 }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <br>

    @if (!$tableExists1 && !$tableExists2 && !$tableExists3 && !$tableExists4 && !$tableExists5 && !$tableExists6)
    <ol class="breadcrumb mb-4">
        <li class="breadcrumb-item active">There is no inference for this user.</li>
    </ol>
    @elseif ($allInference->isEmpty())
        <ol class="breadcrumb mb-4">
            <li class="breadcrumb-item active">There is no inference for this user.</li>
        </ol>
    @else
        <div class="card-body">
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>Id</th>
                        <th>Case Title</th>
                        {{-- <th>Case Goal</th> --}}
                        <th>Rule Id</th>
                        <th>Goal</th>
                        <th>Match Value</th>
                        <th>Algortihm</th>
                        <th>Execution Time</th>
                        {{-- <th>Cocok</th> --}}
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($allInference as $index => $inferensi)
                    <tr>
                        <td>{{ $inferensi->case_id }}</td>
                        <td>{{ $kasus->case_title }}</td>
                        <td>{{ $inferensi->rule_id }}</td>
                        <td>
                            @php
                                $cleanedIfPart = preg_replace('/\b\d+_/', ' ', $inferensi->rule_goal);
                                $cleanedIfPart = str_replace('_', ' ', $cleanedIfPart);
                                $cleanedIfPart = str_replace('-', ' ', $cleanedIfPart);
                                $cleanedIfPart = str_replace('=', ' =', $cleanedIfPart);
                            @endphp
                            {{ $cleanedIfPart }}
                        </td>
                        <td>{{ $inferensi->match_value }}</td>
                        <td>{{ $inferensi->source_algorithm ?? ($algorithms[$inferensi->case_id] ?? 'Unknown') }}</td>
                        <td>{{ $inferensi->waktu ?? '-' }}</td>
                        <td><a href="{{ url('/detail?case_id=' . $inferensi->case_id)}}" class="btn btn-primary">Detail</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

@endsection
