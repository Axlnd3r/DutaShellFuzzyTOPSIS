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

    $all = $rows1->merge($rows2)->merge($rows3)->merge($rows4)->merge($rows5)->merge($rows6)->merge($rows7)
        ->sortBy(function($r) {
            $ts = is_int($r->_ts) ? $r->_ts : 9_999_999_999_999;
            $rid = (string) ($r->inf_id ?? $r->id ?? '');
            $ridKey = (ctype_digit($rid) && $rid !== '')
                ? str_pad($rid, 20, '0', STR_PAD_LEFT)
                : 'Z' . $rid;
            return sprintf('%013d-%02d-%s', $ts, (int) ($r->_algo_rank ?? 9), $ridKey);
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
    $cAll   = $all->count();

    if ($search !== '') {
        $needle = strtolower($search);
        $all = $all->filter(function($row) use ($needle, $formatRuleGoal, $kasus) {
            $fields = [
                $row->_disp_id ?? '',
                $row->case_title ?? ($kasus->case_title ?? ''),
                $row->rule_id ?? '',
                $row->rule_goal ?? $row->goal ?? '',
                $formatRuleGoal($row),
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

    $perPage = 10;
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
@endphp

<h1 class="mt-4">Inferensi - {{ $user->username }}</h1>

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
  @if($search !== '')
    <span class="badge text-bg-light">Filter: "{{ $search }}"</span>
  @endif
</div>

<div class="mb-3 d-flex flex-wrap gap-2">
  <a href="{{ url()->current() }}" class="btn btn-sm btn-outline-secondary">Refresh</a>
  <a href="{{ route('test.case.form') }}" class="btn btn-sm btn-outline-primary">Lihat Test Case</a>
</div>

<form method="GET" class="mb-3" role="search">
  <div class="input-group" style="max-width: 520px;">
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
</form>

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

@if (!$t1Exists && !$t2Exists && !$t3Exists && !$t4Exists && !$t5Exists && !$t6Exists && !$t7Exists)
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
            <th style="width:180px;">Execution Time (s)</th>
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
                $cidForLink = $row->case_id ?? '';
            @endphp
            <tr>
              <td>{{ $dispId }}</td>
              <td>{{ $caseTitle }}</td>
              <td>{{ $ruleId }}</td>
              <td>{{ $goalText }}</td>
              <td>{{ $mvFmt }}</td>
              <td>{{ $algo }}</td>
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
