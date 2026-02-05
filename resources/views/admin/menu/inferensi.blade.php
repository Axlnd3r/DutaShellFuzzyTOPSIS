{{-- resources/views/admin/menu/inferensi.blade.php --}}
@extends('layouts.admin')

@section('content')

@php
    use Illuminate\Support\Facades\Auth;

    $user = Auth::user();

    /**
     * Set metadata umum per-baris: source, rank algoritma, timestamp dibuat, dan display id unik.
     */
    $setCommon = function($r, string $src) {
        // sumber
        $r->_source = $src; // 'user' | 'fc' | 'bc'

        // rank algoritma untuk tie-breaker
        $algoRank = 1;
        if ($src === 'fc') $algoRank = 2;
        elseif ($src === 'bc') $algoRank = 3;
        $r->_algo_rank = $algoRank;

        // normalisasi timestamp "dibuat" (fallback beberapa nama kolom)
        $r->_created = $r->created_at
            ?? ($r->createdAt ?? null)
            ?? ($r->created ?? null)
            ?? ($r->tanggal ?? null)
            ?? ($r->ts ?? null);

        $r->_ts = $r->_created ? @strtotime((string)$r->_created) : null;

        // display id unik untuk UI (hindari tabrakan antar tabel)
        $lid = $r->inf_id ?? $r->id ?? $r->case_id ?? null;

        // deteksi SVM di sumber "user"
        $isSvm = false;
        $ruleId   = strtoupper((string)($r->rule_id ?? ''));
        $ruleGoal = (string)($r->rule_goal ?? $r->goal ?? '');
        if ($src === 'user' && ($ruleId === 'SVM' || stripos($ruleGoal, 'kernel=') !== false)) {
            $isSvm = true;
        }

        if ($src === 'fc')      { $prefix = 'FC-'; }
        elseif ($src === 'bc')  { $prefix = 'BC-'; }
        else                    { $prefix = $isSvm ? 'SVM-' : 'MR-'; }

        $r->_disp_id = $lid !== null ? ($prefix . $lid) : ($prefix . '?');

        return $r;
    };

    // === Ambil data dari 3 sumber: inferensi_user, inferensi_fc_user, inferensi_bc_user ===
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

    // === Gabung & urutkan: paling dulu dibuat (created_at), lalu tie-breaker algo_rank, lalu id lokal (stabil)
    $all = $rows1->merge($rows2)->merge($rows3)
        ->sortBy(function($r) {
            // ts: kalau null, dorong ke belakang dengan angka besar (biar yg ada timestamp muncul duluan)
            $ts = is_int($r->_ts) ? $r->_ts : 9_999_999_999_999;

            // stabilkan id lokal untuk tie-breaker terakhir
            $rid = (string)($r->inf_id ?? $r->id ?? '');
            $ridKey = (ctype_digit($rid) && $rid !== '')
                ? str_pad($rid, 20, '0', STR_PAD_LEFT)
                : 'Z' . $rid;

            // format string biar sort leksikografis vs numerik
            return sprintf('%013d-%02d-%s', $ts, (int)($r->_algo_rank ?? 9), $ridKey);
        })
        ->values();

    $search = trim((string) request()->input('search', ''));

    // Case title (opsional global; fallback ke row jika ada per-baris)
    $kasus = \App\Models\Kasus::where('case_num', $user->user_id)->first();

    // Util: buang prefix angka_ (e.g., "202_Olahraga" -> "Olahraga")
    $stripNumPrefix = function(string $s){
        return preg_replace('/^\s*\d+_/', '', trim($s));
    };

    // Formatter rule_goal / goal
    $formatRuleGoal = function($row) use ($stripNumPrefix) {
        $ruleId = strtoupper((string)($row->rule_id ?? ''));
        $raw    = (string)($row->rule_goal ?? $row->goal ?? '');

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

    // === Label algoritma untuk tampilan
    $renderAlgo = function($row) {
        $src = $row->_source ?? 'user';
        if ($src === 'fc') return 'Forward Chaining';
        if ($src === 'bc') return 'Backward Chaining';

        $ruleId   = strtoupper((string)($row->rule_id ?? ''));
        $ruleGoal = (string)($row->rule_goal ?? $row->goal ?? '');
        if ($ruleId === 'SVM' || stripos($ruleGoal, 'kernel=') !== false) {
            return 'Support Vector Machine';
        }
        return 'Matching Rule';
    };

    // Ringkasan jumlah per sumber (untuk badge)
    $cInfer = $rows1->count();
    $cFC    = $rows2->count();
    $cBC    = $rows3->count();
    $cAll   = $all->count();

    // Filter pencarian (sebelum pagination)
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

    // Pagination (client-side on the merged collection)
    $perPage = 10;
    $page    = max((int) request()->input('page', 1), 1);
    $paged   = new \Illuminate\Pagination\LengthAwarePaginator(
        $all->slice(($page - 1) * $perPage, $perPage),
        $all->count(),
        $perPage,
        $page,
        ['path' => request()->url(), 'query' => request()->query()]
    );
@endphp

<h1 class="mt-4">Inferensi - {{ $user->username }}</h1>

{{-- Alert hasil aksi --}}
@if(session('success'))
  <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if(session('error'))
  <div class="alert alert-danger" style="white-space:pre-wrap">{{ session('error') }}</div>
@endif

{{-- Panel diagnostik SVM (jika ada) --}}
@if(session('svm_diag'))
  <div class="alert alert-secondary mt-2">
    <details open>
      <summary><strong>Diagnostics</strong> (klik untuk sembunyikan)</summary>
      <pre class="mt-2" style="white-space:pre-wrap">{{ session('svm_diag') }}</pre>
    </details>
  </div>
@endif

{{-- Ringkasan jumlah --}}
<div class="mb-3 d-flex flex-wrap gap-2 align-items-center">
  <span class="badge text-bg-primary">Ditampilkan: {{ $cFiltered }}</span>
  <span class="badge text-bg-dark">Total Data: {{ $cAll }}</span>
  <span class="badge text-bg-success">inferensi_user: {{ $cInfer }}</span>
  <span class="badge text-bg-info">inferensi_fc_user: {{ $cFC }}</span>
  <span class="badge text-bg-warning">inferensi_bc_user: {{ $cBC }}</span>
  @if($search !== '')
    <span class="badge text-bg-secondary">Filter: "{{ $search }}"</span>
  @endif
</div>

{{-- Tombol kecil utilitas --}}
<div class="mb-3 d-flex flex-wrap gap-2">
  <a href="{{ url()->current() }}" class="btn btn-sm btn-outline-secondary">Refresh</a>
  <a href="{{ route('test.case.form') }}" class="btn btn-sm btn-outline-primary">Lihat Test Case</a>
</div>

{{-- Search bar --}}
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

@if (!$t1Exists && !$t2Exists && !$t3Exists)
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
                // Id tampil unik dengan prefix MR-/SVM-/FC-/BC-
                $dispId = $row->_disp_id ?? '-';

                // Case title (fallback ke global jika tak ada per-baris)
                $caseTitle = $row->case_title ?? ($kasus->case_title ?? '-');

                // Rule Id (apa adanya)
                $ruleId = (string)($row->rule_id ?? '');

                // Goal/Rule Goal (dirapikan)
                $goalText = $formatRuleGoal($row);

                // Match value (fallback ke 'score' untuk SVM jika ada)
                $mv = isset($row->match_value) ? (float)$row->match_value : (isset($row->score) ? (float)$row->score : 0.0);
                $mvFmt = number_format($mv, 4);

                // Algoritma (label)
                $algo = $renderAlgo($row);

                // Waktu eksekusi (fallback beberapa kolom)
                $sec = isset($row->waktu) ? (float)$row->waktu : (isset($row->exec_time) ? (float)$row->exec_time : 0.0);
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
                <a href="{{ url('/detail?case_id=' . urlencode((string)$cidForLink)) }}" class="btn btn-primary btn-sm">Detail</a>
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
