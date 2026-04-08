{{-- resources/views/admin/menu/inferensi.blade.php --}}
@extends('layouts.admin')

@section('content')

@php
    use Illuminate\Support\Facades\Auth;

    $user = Auth::user();

    $setCommon = function($r, string $src) {
        $r->_source = $src;
        $rankMap = [
            'user' => 1,
            'fc' => 2,
            'bc' => 3,
            'hs' => 4,
            'jc' => 5,
            'cs' => 6,
            'rf' => 7,
            'ft' => 8,
        ];
        $r->_algo_rank = $rankMap[$src] ?? 9;

        $r->_created = $r->created_at
            ?? ($r->createdAt ?? null)
            ?? ($r->created ?? null)
            ?? ($r->tanggal ?? null)
            ?? ($r->ts ?? null);

        $r->_ts = $r->_created ? @strtotime((string) $r->_created) : null;

        $lid = $r->inf_id ?? $r->id ?? $r->case_id ?? null;

        $ruleId   = strtoupper((string) ($r->rule_id ?? ''));
        $ruleGoal = (string) ($r->rule_goal ?? $r->goal ?? '');
        $isSvm = ($src === 'user' && ($ruleId === 'SVM' || stripos($ruleGoal, 'kernel=') !== false));

        if ($src === 'fc')      { $prefix = 'FC-'; }
        elseif ($src === 'bc')  { $prefix = 'BC-'; }
        elseif ($src === 'hs')  { $prefix = 'HS-'; }
        elseif ($src === 'jc')  { $prefix = 'JC-'; }
        elseif ($src === 'cs')  { $prefix = 'CS-'; }
        elseif ($src === 'rf')  { $prefix = 'RF-'; }
        elseif ($src === 'ft')  { $prefix = 'FT-'; }
        else                    { $prefix = $isSvm ? 'SVM-' : 'MR-'; }

        $r->_disp_id = $lid !== null ? ($prefix . $lid) : ($prefix . '?');

        return $r;
    };

    $mdlInf = new \App\Models\Inferensi();
    $mdlInf->setTableForUser($user->user_id);
    $t1Exists = $mdlInf->tableExists();
    $rows1    = $t1Exists
        ? $mdlInf->getRules()->map(function($r) use ($setCommon){ return $setCommon($r, 'user'); })
        : collect();

    $mdlFC = new \App\Models\ForwardChaining();
    $mdlFC->setTableForUser($user->user_id);
    $t2Exists = $mdlFC->tableExists();
    $rows2    = $t2Exists
        ? $mdlFC->getRules()->map(function($r) use ($setCommon){ return $setCommon($r, 'fc'); })
        : collect();

    $mdlBC = new \App\Models\BackwardChaining();
    $mdlBC->setTableForUser($user->user_id);
    $t3Exists = $mdlBC->tableExists();
    $rows3    = $t3Exists
        ? $mdlBC->getRules()->map(function($r) use ($setCommon){ return $setCommon($r, 'bc'); })
        : collect();

    $mdlHS = new \App\Models\HybridSimilarity();
    $mdlHS->setTableForUser($user->user_id);
    $t4Exists = $mdlHS->tableExists();
    $rows4    = $t4Exists
        ? $mdlHS->getRules()->map(function($r) use ($setCommon){ return $setCommon($r, 'hs'); })
        : collect();

    $mdlJC = new \App\Models\JaccardSimilarity();
    $mdlJC->setTableForUser($user->user_id);
    $t5Exists = $mdlJC->tableExists();
    $rows5    = $t5Exists
        ? $mdlJC->getRules()->map(function($r) use ($setCommon){ return $setCommon($r, 'jc'); })
        : collect();

    $mdlCS = new \App\Models\CosineSimilarity();
    $mdlCS->setTableForUser($user->user_id);
    $t6Exists = $mdlCS->tableExists();
    $rows6    = $t6Exists
        ? $mdlCS->getRules()->map(function($r) use ($setCommon){ return $setCommon($r, 'cs'); })
        : collect();

    $mdlRF = new \App\Models\RandomForestInference();
    $mdlRF->setTableForUser($user->user_id);
    $t7Exists = $mdlRF->tableExists();
    $rows7    = $t7Exists
        ? $mdlRF->getRules()->map(function($r) use ($setCommon){ return $setCommon($r, 'rf'); })
        : collect();

    // Fuzzy TOPSIS
    $mdlFT = new \App\Models\FuzzyTopsisInference();
    $mdlFT->setTableForUser($user->user_id);
    $t8Exists = $mdlFT->tableExists();
    $rows8    = $t8Exists
        ? $mdlFT->getRules()->map(function($r) use ($setCommon){ return $setCommon($r, 'ft'); })
        : collect();

    $all = $rows1->merge($rows2)->merge($rows3)->merge($rows4)->merge($rows5)->merge($rows6)->merge($rows7)->merge($rows8)
        ->sortByDesc(function($r) {
            $ts = is_int($r->_ts) ? $r->_ts : 0;
            $rid = (string) ($r->inf_id ?? $r->id ?? '');
            $ridKey = (ctype_digit($rid) && $rid !== '')
                ? str_pad($rid, 20, '0', STR_PAD_LEFT)
                : 'Z' . $rid;
            return sprintf('%013d-%s', $ts, $ridKey);
        })
        ->values();

    $search = trim((string) request()->input('search', ''));

    $kasus = \App\Models\Kasus::where('case_num', $user->user_id)->first();

    $stripNumPrefix = function(string $s){
        return preg_replace('/^\s*\d+_/', '', trim($s));
    };

    $formatRuleGoal = function($row) use ($stripNumPrefix) {
        $ruleId = strtoupper((string) ($row->rule_id ?? ''));
        $raw    = (string) ($row->rule_goal ?? $row->goal ?? '');

        $isSvm = ($ruleId === 'SVM') || (stripos($raw, 'kernel=') !== false);
        if ($isSvm) {
            $main = preg_replace('/\s*\|\s*kernel\s*=.*$/i', '', $raw);
            if (strpos($main, '=') !== false) {
                [$lhs, $rhs] = array_map('trim', explode('=', $main, 2));
                $lhs = $stripNumPrefix($lhs);
                $rhs = $stripNumPrefix($rhs);
                return $lhs . ' = ' . $rhs;
            }
            return $stripNumPrefix($main);
        }

        $text = preg_replace('/\b\d+_/', ' ', $raw);
        $text = str_replace(['_', '-'], ' ', $text);
        $text = str_replace('=', ' =', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    };

    $renderAlgo = function($row) {
        $src = $row->_source ?? 'user';
        if ($src === 'fc') return 'Forward Chaining';
        if ($src === 'bc') return 'Backward Chaining';
        if ($src === 'hs') return 'Hybrid Similarity';
        if ($src === 'jc') return 'Jaccard Similarity';
        if ($src === 'cs') return 'Cosine Similarity';
        if ($src === 'rf') return 'Random Forest';
        if ($src === 'ft') return 'Fuzzy TOPSIS';

        $ruleId   = strtoupper((string) ($row->rule_id ?? ''));
        $ruleGoal = (string) ($row->rule_goal ?? $row->goal ?? '');
        if ($ruleId === 'SVM' || stripos($ruleGoal, 'kernel=') !== false) {
            return 'Support Vector Machine';
        }
        return 'Matching Rule';
    };

    $cInfer = $rows1->count();
    $cFC    = $rows2->count();
    $cBC    = $rows3->count();
    $cHS    = $rows4->count();
    $cJC    = $rows5->count();
    $cCS    = $rows6->count();
    $cRF    = $rows7->count();
    $cFT    = $rows8->count();
    $cAll   = $all->count();

    if ($search !== '') {
        $needle = strtolower($search);
        $all = $all->filter(function($row) use ($needle, $formatRuleGoal, $renderAlgo, $kasus) {
            $fields = [
                $row->_disp_id ?? '',
                $row->case_title ?? ($kasus->case_title ?? ''),
                $row->rule_id ?? '',
                $row->rule_goal ?? $row->goal ?? '',
                $formatRuleGoal($row),
                $renderAlgo($row),
                $row->_source ?? '',
                $row->match_value ?? $row->score ?? '',
            ];

            foreach ($fields as $val) {
                if ($val === null) {
                    continue;
                }
                if (stripos((string) $val, $needle) !== false) {
                    return true;
                }
            }
            return false;
        })->values();
    }

    $cFiltered = $all->count();

    $perPageOptions = [10, 25, 50, 100];
    $perPage = (int) request()->input('per_page', 10);
    if (!in_array($perPage, $perPageOptions)) $perPage = 10;
    $page    = max((int) request()->input('page', 1), 1);
    $paged   = new \Illuminate\Pagination\LengthAwarePaginator(
        $all->slice(($page - 1) * $perPage, $perPage),
        $all->count(),
        $perPage,
        $page,
        ['path' => request()->url(), 'query' => request()->query()]
    );

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
            $cls = preg_replace('/^\d+_/', '', $cls);
            $cls = str_replace(['_', '-'], ' ', $cls);
            return trim($cls);
        };

        foreach ($all as $item) {
            $algo = $renderAlgo($item);
            if (!in_array($algo, $allowedMatrixAlgo, true)) continue;
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

    // === Fuzzy TOPSIS Confusion Matrix ===
    $ftConfusion = null;
    if ($t8Exists && $rows8->isNotEmpty()) {
        $ftGrouped = $rows8->groupBy('case_id');
        $ftTP2 = 0; $ftFP2 = 0; $ftTN2 = 0; $ftFN2 = 0;
        foreach ($ftGrouped as $caseId => $group) {
            $top1 = $group->sortBy('rank')->first();
            if (!$top1) continue;
            $topCocok = (string) ($top1->cocok ?? '0');
            $totalInGroup = $group->count();
            $cocokInGroup = $group->where('cocok', '1')->count();

            if ($topCocok === '1') {
                $ftTP2++;
                $ftTN2 += ($totalInGroup - $cocokInGroup);
            } else {
                $ftFP2++;
                $ftFN2 += $cocokInGroup;
            }
        }

        $ftTotalEval = $ftTP2 + $ftFP2 + $ftTN2 + $ftFN2;
        $ftConfusion = [
            'tp' => $ftTP2,
            'fp' => $ftFP2,
            'tn' => $ftTN2,
            'fn' => $ftFN2,
            'total' => $ftTotalEval,
            'accuracy' => $ftTotalEval > 0 ? ($ftTP2 + $ftTN2) / $ftTotalEval : 0,
            'precision' => ($ftTP2 + $ftFP2) > 0 ? $ftTP2 / ($ftTP2 + $ftFP2) : 0,
            'recall' => ($ftTP2 + $ftFN2) > 0 ? $ftTP2 / ($ftTP2 + $ftFN2) : 0,
            'f1' => (2 * $ftTP2 + $ftFP2 + $ftFN2) > 0 ? (2 * $ftTP2) / (2 * $ftTP2 + $ftFP2 + $ftFN2) : 0,
            'total_cases' => $ftGrouped->count(),
        ];
    }

    // === Fuzzy TOPSIS Ranking (latest test case) ===
    $ftRanking = collect();
    if ($t8Exists && $rows8->isNotEmpty()) {
        $latestCaseId = $rows8->max('case_id');
        $ftRanking = $rows8->where('case_id', $latestCaseId)->sortBy('rank')->values();
    }
@endphp

<h1 class="mt-4">History - {{ $user->username }}</h1>

@if(session('success'))
  <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if(session('error'))
  <div class="alert alert-danger" style="white-space:pre-wrap">{{ session('error') }}</div>
@endif

@if(session('svm_diag'))
  <div class="alert alert-secondary mt-2">
    <details open>
      <summary><strong>Diagnostics</strong> (klik untuk sembunyikan)</summary>
      <pre class="mt-2" style="white-space:pre-wrap">{{ session('svm_diag') }}</pre>
    </details>
  </div>
@endif

<div class="mb-3 d-flex flex-wrap gap-2 align-items-center">
  <span class="badge text-bg-primary">Ditampilkan: {{ $cFiltered }}</span>
  <span class="badge text-bg-dark">Total Data: {{ $cAll }}</span>
  <span class="badge text-bg-success">inferensi_user: {{ $cInfer }}</span>
  <span class="badge text-bg-info">inferensi_fc_user: {{ $cFC }}</span>
  <span class="badge text-bg-warning">inferensi_bc_user: {{ $cBC }}</span>
  <span class="badge text-bg-secondary">inferensi_hs_user: {{ $cHS }}</span>
  <span class="badge text-bg-secondary">inferensi_jc_user: {{ $cJC }}</span>
  <span class="badge text-bg-secondary">inferensi_cs_user: {{ $cCS }}</span>
  <span class="badge text-bg-secondary">inferensi_rf_user: {{ $cRF }}</span>
  <span class="badge text-bg-warning">inferensi_ft_user: {{ $cFT }}</span>
  @if($search !== '')
    <span class="badge text-bg-light">Filter: "{{ $search }}"</span>
  @endif
</div>

<div class="mb-3 d-flex flex-wrap gap-2">
  <a href="{{ url()->current() }}" class="btn btn-sm btn-outline-secondary">Refresh</a>
  <a href="{{ route('test.case.form') }}" class="btn btn-sm btn-outline-primary">Lihat Test Case</a>
</div>

<form method="GET" class="mb-3 d-flex flex-wrap gap-3 align-items-end" role="search">
  <div class="input-group" style="max-width: 400px;">
    <input
      type="text"
      name="search"
      class="form-control"
      placeholder="Cari case title, rule id, atau goal..."
      value="{{ $search }}"
    >
    <button class="btn btn-primary" type="submit">Cari</button>
    @if($search !== '')
      <a href="{{ url()->current() }}" class="btn btn-outline-secondary">Reset</a>
    @endif
  </div>
  <div class="d-flex align-items-center gap-2">
    <label for="per_page" class="form-label mb-0">Show:</label>
    <select name="per_page" id="per_page" class="form-select form-select-sm" style="width: auto;" onchange="this.form.submit()">
      @foreach($perPageOptions as $opt)
        <option value="{{ $opt }}" {{ $perPage == $opt ? 'selected' : '' }}>{{ $opt }}</option>
      @endforeach
    </select>
    <span class="text-muted">per page</span>
  </div>
</form>

@if (session('eval_output'))
    <div class="alert alert-info" style="white-space: pre-wrap;">{{ session('eval_output') }}</div>
@endif

{{-- ============================================================ --}}
{{-- FUZZY TOPSIS RANKING RESULTS                                  --}}
{{-- ============================================================ --}}
@if ($ftRanking->isNotEmpty())
    <div class="card my-3 border-warning">
        <div class="card-header bg-warning text-dark fw-semibold">
            Fuzzy TOPSIS - Ranking Kasus (Test Case #{{ $ftRanking->first()->case_id ?? '-' }})
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-bordered table-striped mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th style="width:60px;">Rank</th>
                            <th style="width:100px;">Base Case</th>
                            <th>Goal (Base)</th>
                            <th style="width:140px;">CC Score</th>
                            <th style="width:120px;">D+</th>
                            <th style="width:120px;">D-</th>
                            <th style="width:80px;">Match</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ftRanking->take(20) as $ftRow)
                            @php
                                $rankNum = (int) ($ftRow->rank ?? 0);
                                $ruleIdParts = explode('-', $ftRow->rule_id ?? '');
                                $baseCaseId = end($ruleIdParts);
                                $ccScore = (float) ($ftRow->score ?? $ftRow->match_value ?? 0);
                                $rowClass = $rankNum === 1 ? 'table-success fw-bold' : '';
                            @endphp
                            <tr class="{{ $rowClass }}">
                                <td class="text-center">
                                    @if($rankNum === 1)
                                        <span class="badge bg-success">{{ $rankNum }}</span>
                                    @elseif($rankNum <= 3)
                                        <span class="badge bg-primary">{{ $rankNum }}</span>
                                    @else
                                        {{ $rankNum }}
                                    @endif
                                </td>
                                <td>Case #{{ $baseCaseId }}</td>
                                <td>{{ preg_replace('/^\d+_/', '', str_replace(['_', '-'], ' ', $ftRow->rule_goal ?? '-')) }}</td>
                                <td>
                                    <div class="d-flex align-items-center gap-1">
                                        <div class="progress flex-grow-1" style="height: 16px;">
                                            <div class="progress-bar bg-{{ $ccScore >= 0.7 ? 'success' : ($ccScore >= 0.4 ? 'warning' : 'danger') }}"
                                                 style="width: {{ $ccScore * 100 }}%">
                                            </div>
                                        </div>
                                        <small>{{ number_format(ceil($ccScore * 10000) / 10000, 4) }}</small>
                                    </div>
                                </td>
                                <td>{{ number_format((float)($ftRow->s_plus ?? 0), 6) }}</td>
                                <td>{{ number_format((float)($ftRow->s_minus ?? 0), 6) }}</td>
                                <td class="text-center">
                                    @if(($ftRow->cocok ?? '0') === '1')
                                        <span class="badge bg-success">Yes</span>
                                    @else
                                        <span class="badge bg-secondary">No</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($ftRanking->count() > 20)
                <small class="text-muted mt-2 d-block">Menampilkan 20 dari {{ $ftRanking->count() }} kasus.</small>
            @endif
        </div>
    </div>
@endif

{{-- ============================================================ --}}
{{-- FUZZY TOPSIS CONFUSION MATRIX & EVALUATION METRICS            --}}
{{-- ============================================================ --}}
@if ($ftConfusion)
    <div class="card my-3 border-warning">
        <div class="card-header bg-warning text-dark fw-semibold">
            Fuzzy TOPSIS - Evaluasi (Confusion Matrix)
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-5">
                    <h6>Confusion Matrix (Top-1 Strategy)</h6>
                    <p class="text-muted small mb-2">Top-1 ranked case = predicted positive. Goal match = actual positive.</p>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0 text-center">
                            <thead>
                                <tr>
                                    <th></th>
                                    <th>Predicted: Positive</th>
                                    <th>Predicted: Negative</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <th>Actual: Positive</th>
                                    <td class="fw-bold text-success bg-success bg-opacity-10">TP<br>{{ $ftConfusion['tp'] }}</td>
                                    <td class="fw-bold text-danger bg-danger bg-opacity-10">FN<br>{{ $ftConfusion['fn'] }}</td>
                                </tr>
                                <tr>
                                    <th>Actual: Negative</th>
                                    <td class="fw-bold text-warning bg-warning bg-opacity-10">FP<br>{{ $ftConfusion['fp'] }}</td>
                                    <td class="fw-bold text-primary bg-primary bg-opacity-10">TN<br>{{ $ftConfusion['tn'] }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="col-md-7">
                    <h6>Evaluation Metrics</h6>
                    <div class="row g-2">
                        <div class="col-6 col-lg-3">
                            <div class="card text-center border-success">
                                <div class="card-body py-2">
                                    <small class="text-muted">Accuracy</small>
                                    <h4 class="mb-0 text-success">{{ number_format($ftConfusion['accuracy'] * 100, 2) }}%</h4>
                                    <small class="text-muted">(TP+TN)/Total</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="card text-center border-primary">
                                <div class="card-body py-2">
                                    <small class="text-muted">Precision</small>
                                    <h4 class="mb-0 text-primary">{{ number_format($ftConfusion['precision'] * 100, 2) }}%</h4>
                                    <small class="text-muted">TP/(TP+FP)</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="card text-center border-warning">
                                <div class="card-body py-2">
                                    <small class="text-muted">Recall</small>
                                    <h4 class="mb-0 text-warning">{{ number_format($ftConfusion['recall'] * 100, 2) }}%</h4>
                                    <small class="text-muted">TP/(TP+FN)</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="card text-center border-danger">
                                <div class="card-body py-2">
                                    <small class="text-muted">F1-Score</small>
                                    <h4 class="mb-0 text-danger">{{ number_format($ftConfusion['f1'] * 100, 2) }}%</h4>
                                    <small class="text-muted">2TP/(2TP+FP+FN)</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-2">
                        <small class="text-muted">
                            Total konsultasi Fuzzy TOPSIS: <strong>{{ $ftConfusion['total_cases'] }}</strong> test case |
                            Total ranking records: <strong>{{ $cFT }}</strong>
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endif

{{-- ============================================================ --}}
{{-- EXISTING CONFUSION MATRICES (Other Algorithms)                --}}
{{-- ============================================================ --}}
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

{{-- ============================================================ --}}
{{-- MAIN HISTORY TABLE (All Algorithms)                           --}}
{{-- ============================================================ --}}
@if (!$t1Exists && !$t2Exists && !$t3Exists && !$t4Exists && !$t5Exists && !$t6Exists && !$t7Exists && !$t8Exists)
  <ol class="breadcrumb mb-4"><li class="breadcrumb-item active">Belum ada tabel inferensi untuk user ini.</li></ol>
@elseif ($all->isEmpty())
  <ol class="breadcrumb mb-4"><li class="breadcrumb-item active">Belum ada data inferensi.</li></ol>
@else
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-bordered align-middle">
        <thead class="table-light">
          <tr>
            <th style="width:120px;">Id</th>
            <th style="min-width:220px;">Case Title</th>
            <th style="width:120px;">Rule Id</th>
            <th>Goal / Rule Goal</th>
            <th style="width:140px;">Match Value</th>
            <th style="min-width:180px;">Algorithm</th>
            <th style="width:170px;">Tanggal Eksekusi</th>
            <th style="width:150px;">Execution Time (s)</th>
            <th style="width:110px;">Action</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($paged as $row)
            @php
                $dispId = $row->_disp_id ?? '-';
                $caseTitle = $row->case_title ?? ($kasus->case_title ?? '-');
                $ruleId = (string)($row->rule_id ?? '');
                $goalText = $formatRuleGoal($row);
                $mv = isset($row->match_value) ? (float) $row->match_value : (isset($row->score) ? (float) $row->score : 0.0);
                $mvFmt = number_format($mv, 4);
                $algo = $renderAlgo($row);
                $sec = isset($row->waktu) ? (float) $row->waktu : (isset($row->exec_time) ? (float) $row->exec_time : 0.0);
                $secFmt = number_format($sec, 6);
                $isLegacyNoExecDate = ($algo === 'Matching Rule' && abs($sec) < 1e-12);
                $execDate = (!$isLegacyNoExecDate && $row->_created)
                    ? date('d M Y H:i:s', strtotime($row->_created))
                    : '-';
                $cidForLink = $row->case_id ?? '';
            @endphp
            <tr>
              <td>{{ $dispId }}</td>
              <td>{{ $caseTitle }}</td>
              <td>{{ $ruleId }}</td>
              <td>{{ $goalText }}</td>
              <td>{{ $mvFmt }}</td>
              <td>
                @if($algo === 'Fuzzy TOPSIS')
                    <span class="badge bg-warning text-dark">{{ $algo }}</span>
                @else
                    {{ $algo }}
                @endif
              </td>
              <td>{{ $execDate }}</td>
              <td>{{ $secFmt }}</td>
              <td>
                <a href="{{ url('/detail?case_id=' . urlencode((string) $cidForLink)) }}" class="btn btn-primary btn-sm">Detail</a>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    <div class="mt-3">
      {{ $paged->onEachSide(1)->links('pagination::bootstrap-5') }}
    </div>
  </div>
@endif

@endsection
