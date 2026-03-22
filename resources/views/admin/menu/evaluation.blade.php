@extends('layouts.admin')

@section('content')
<style>
  .cm-table { border-collapse: collapse; min-width: 200px; font-size: 12px; }
  .cm-table th, .cm-table td { border: 1px solid #dee2e6; padding: 4px 8px; text-align: center; }
  .cm-table th { background: #f8f9fa; font-weight: 600; }
  .cm-table .axis { background: #e9ecef; font-weight: 600; text-align: right; }
  .metric-card { border-radius: 8px; padding: 16px; text-align: center; }
  .metric-card h3 { font-size: 28px; margin: 0; font-weight: 700; }
  .metric-card small { font-size: 12px; color: #6c757d; }
  .scenario-tab { cursor: pointer; }
  .comparison-row td { font-weight: 600; }
  .winner { color: #198754; }
  .loser  { color: #dc3545; }
  .algo-header-ft { background: #0d6efd !important; color: #fff; }
  .algo-header-hs { background: #dc3545 !important; color: #fff; }
  .algo-header-jc { background: #6f42c1 !important; color: #fff; }
  .algo-header-cs { background: #fd7e14 !important; color: #fff; }
</style>

<div class="container-fluid mt-3">
  <h3><i class="fas fa-chart-bar me-2"></i>Evaluasi Perbandingan Algoritma</h3>
  <p class="text-muted mb-3">Fuzzy TOPSIS vs Hybrid Similarity vs Jaccard vs Cosine &mdash; 5 Skenario Train/Test Split dengan Confusion Matrix Multi-Class</p>
  <hr>

  {{-- Error / Status --}}
  @if(session('eval_err'))
    <div class="alert alert-danger">{{ session('eval_err') }}</div>
  @endif

  {{-- Info Sumber Data --}}
  <div class="alert alert-info mb-3">
    <strong><i class="fas fa-info-circle me-1"></i> Sumber Data (user: {{ Auth::user()->username ?? '?' }}, id: {{ Auth::user()->user_id ?? '?' }}):</strong>
    <code>case_user_{{ Auth::user()->user_id ?? '?' }}</code> (basis kasus)
    @if(\Illuminate\Support\Facades\Schema::hasTable('test_case_user_' . (Auth::user()->user_id ?? 0)))
      + <code>test_case_user_{{ Auth::user()->user_id ?? '?' }}</code> (test case)
    @endif
  </div>

  {{-- Form --}}
  <div class="card mb-4">
    <div class="card-header fw-semibold"><i class="fas fa-play me-1"></i> Jalankan Evaluasi 5 Skenario</div>
    <div class="card-body">
      <form action="{{ route('evaluation.run') }}" method="POST" class="row g-3 align-items-end">
        @csrf
        <div class="col-md-3">
          <label class="form-label">Seed (random split)</label>
          <input type="number" name="seed" class="form-control" value="{{ session('eval_seed', 42) }}" min="1">
          <small class="text-muted">Seed sama = split konsisten (reprodusibel)</small>
        </div>
        <div class="col-md-6">
          <label class="form-label">5 Skenario Train/Test (total = basis + test case)</label>
          <div class="d-flex gap-2 flex-wrap">
            <span class="badge bg-primary fs-6">80/20</span>
            <span class="badge bg-primary fs-6">70/30</span>
            <span class="badge bg-primary fs-6">60/40</span>
            <span class="badge bg-primary fs-6">50/50</span>
            <span class="badge bg-primary fs-6">40/60</span>
          </div>
          <small class="text-muted">Fixed test set selalu masuk ke test di semua skenario</small>
        </div>
        <div class="col-md-3 d-flex align-items-end">
          <button class="btn btn-primary w-100" id="btnRun">
            <i class="fas fa-play me-1"></i> Jalankan Semua Skenario
          </button>
        </div>
      </form>
    </div>
  </div>

  {{-- RESULTS --}}
  @if(session('eval_ok'))
  @php
    $ftResults = session('ft_results', []);
    $hsResults = session('hs_results', []);
    $jcResults = session('jc_results', []);
    $csResults = session('cs_results', []);
    $totalBase = session('total_base', 0);
    $totalTest      = session('total_test', 0);
    $totalAll       = session('total_all', 0);
    $removedOverlap = session('removed_overlap', 0);
    $evalMode       = session('eval_mode', 'B');

    // Define algorithms for easy iteration
    $algorithms = [
        'ft' => ['name' => 'Fuzzy TOPSIS', 'short' => 'FT', 'results' => $ftResults, 'color' => 'primary', 'headerClass' => 'algo-header-ft', 'icon' => 'fa-brain'],
        'hs' => ['name' => 'Hybrid Similarity', 'short' => 'HS', 'results' => $hsResults, 'color' => 'danger', 'headerClass' => 'algo-header-hs', 'icon' => 'fa-project-diagram'],
        'jc' => ['name' => 'Jaccard Similarity', 'short' => 'JC', 'results' => $jcResults, 'color' => 'purple', 'headerClass' => 'algo-header-jc', 'icon' => 'fa-th'],
        'cs' => ['name' => 'Cosine Similarity', 'short' => 'CS', 'results' => $csResults, 'color' => 'warning', 'headerClass' => 'algo-header-cs', 'icon' => 'fa-ruler-combined'],
    ];
  @endphp

  <div class="alert alert-success mb-3">
    <strong>Evaluasi selesai!</strong>
    @if($evalMode === 'B')
      Mode: <span class="badge bg-primary">B — Fixed Test Set</span>
      <br>Training pool: <strong>{{ $totalBase }}</strong> kasus (<code>case_user_{{ Auth::user()->user_id }}</code>)
      + Fixed test: <strong>{{ $totalTest }}</strong> kasus (<code>test_case_user_{{ Auth::user()->user_id }}</code>)
      = <strong>{{ $totalAll }}</strong> total.
      @if($removedOverlap > 0)
        <br><span class="text-warning"><i class="fas fa-exclamation-triangle me-1"></i>
          <strong>{{ $removedOverlap }} kasus</strong> dikeluarkan dari training pool karena overlap dengan test set (mencegah data leakage).
        </span>
      @endif
    @else
      Mode: <span class="badge bg-info">A — Self-Split</span>
      <br>Dataset: <strong>{{ $totalBase }}</strong> kasus dari <code>case_user_{{ Auth::user()->user_id }}</code>, di-split otomatis per skenario.
      @if($removedOverlap > 0)
        <br><small class="text-muted"><i class="fas fa-info-circle me-1"></i>Mode A digunakan karena <strong>{{ $removedOverlap }}</strong> dari {{ $removedOverlap + $totalBase }} kasus overlap dengan test_case_user (data sama). Self-split mencegah data leakage.</small>
      @else
        <br><small class="text-muted"><i class="fas fa-info-circle me-1"></i>Mode A digunakan karena tabel test_case_user tidak tersedia atau kosong.</small>
      @endif
    @endif
    <br>Seed: <strong>{{ session('eval_seed', 42) }}</strong>.
    Algoritma: <strong>4</strong> (Fuzzy TOPSIS, Hybrid Similarity, Jaccard, Cosine).
  </div>

  {{-- ======================== COMPARISON TABLE ======================== --}}
  <div class="card mb-4">
    <div class="card-header fw-semibold"><i class="fas fa-table me-1"></i> Tabel Perbandingan: 4 Algoritma</div>
    <div class="card-body table-responsive">
      <table class="table table-bordered table-hover text-center align-middle mb-0" style="font-size: 13px;">
        <thead class="table-dark">
          <tr>
            <th rowspan="2">Skenario</th>
            <th rowspan="2">Train</th>
            <th rowspan="2">Test</th>
            <th colspan="4" class="algo-header-ft">Fuzzy TOPSIS</th>
            <th colspan="4" class="algo-header-hs">Hybrid Similarity</th>
            <th colspan="4" class="algo-header-jc">Jaccard Similarity</th>
            <th colspan="4" class="algo-header-cs">Cosine Similarity</th>
            <th rowspan="2">Pemenang</th>
          </tr>
          <tr>
            @for($a = 0; $a < 4; $a++)
            <th>Acc</th><th>Prec</th><th>Rec</th><th>F1</th>
            @endfor
          </tr>
        </thead>
        <tbody>
          @foreach($ftResults as $i => $ft)
          @php
            $hs = $hsResults[$i] ?? [];
            $jc = $jcResults[$i] ?? [];
            $cs = $csResults[$i] ?? [];

            $accs = [
                'Fuzzy TOPSIS'      => $ft['accuracy'] ?? 0,
                'Hybrid Sim'        => $hs['accuracy'] ?? 0,
                'Jaccard Sim'       => $jc['accuracy'] ?? 0,
                'Cosine Sim'        => $cs['accuracy'] ?? 0,
            ];
            $bestAcc = max($accs);
            $winners = array_keys($accs, $bestAcc);
            $winnerLabel = count($winners) > 1 ? 'Seri' : $winners[0];
          @endphp
          <tr>
            <td><strong>{{ $ft['label'] }}</strong></td>
            <td>{{ $ft['train_count'] }}</td>
            <td>{{ $ft['test_count'] }}</td>
            {{-- FT --}}
            <td class="{{ ($ft['accuracy'] ?? 0) == $bestAcc ? 'fw-bold text-success' : '' }}">{{ number_format(($ft['accuracy'] ?? 0) * 100, 2) }}%</td>
            <td>{{ number_format(($ft['macro_precision'] ?? 0) * 100, 2) }}%</td>
            <td>{{ number_format(($ft['macro_recall'] ?? 0) * 100, 2) }}%</td>
            <td>{{ number_format(($ft['macro_f1'] ?? 0) * 100, 2) }}%</td>
            {{-- HS --}}
            <td class="{{ ($hs['accuracy'] ?? 0) == $bestAcc ? 'fw-bold text-success' : '' }}">{{ number_format(($hs['accuracy'] ?? 0) * 100, 2) }}%</td>
            <td>{{ number_format(($hs['macro_precision'] ?? 0) * 100, 2) }}%</td>
            <td>{{ number_format(($hs['macro_recall'] ?? 0) * 100, 2) }}%</td>
            <td>{{ number_format(($hs['macro_f1'] ?? 0) * 100, 2) }}%</td>
            {{-- JC --}}
            <td class="{{ ($jc['accuracy'] ?? 0) == $bestAcc ? 'fw-bold text-success' : '' }}">{{ number_format(($jc['accuracy'] ?? 0) * 100, 2) }}%</td>
            <td>{{ number_format(($jc['macro_precision'] ?? 0) * 100, 2) }}%</td>
            <td>{{ number_format(($jc['macro_recall'] ?? 0) * 100, 2) }}%</td>
            <td>{{ number_format(($jc['macro_f1'] ?? 0) * 100, 2) }}%</td>
            {{-- CS --}}
            <td class="{{ ($cs['accuracy'] ?? 0) == $bestAcc ? 'fw-bold text-success' : '' }}">{{ number_format(($cs['accuracy'] ?? 0) * 100, 2) }}%</td>
            <td>{{ number_format(($cs['macro_precision'] ?? 0) * 100, 2) }}%</td>
            <td>{{ number_format(($cs['macro_recall'] ?? 0) * 100, 2) }}%</td>
            <td>{{ number_format(($cs['macro_f1'] ?? 0) * 100, 2) }}%</td>
            {{-- Winner --}}
            <td class="fw-bold">{{ $winnerLabel }}</td>
          </tr>
          @endforeach
        </tbody>
        <tfoot class="table-light">
          @php
            $n = count($ftResults) ?: 1;
            $avgFtAcc = array_sum(array_column($ftResults, 'accuracy')) / $n;
            $avgHsAcc = array_sum(array_column($hsResults, 'accuracy')) / $n;
            $avgJcAcc = array_sum(array_column($jcResults, 'accuracy')) / $n;
            $avgCsAcc = array_sum(array_column($csResults, 'accuracy')) / $n;

            $avgFtP = array_sum(array_column($ftResults, 'macro_precision')) / $n;
            $avgHsP = array_sum(array_column($hsResults, 'macro_precision')) / $n;
            $avgJcP = array_sum(array_column($jcResults, 'macro_precision')) / $n;
            $avgCsP = array_sum(array_column($csResults, 'macro_precision')) / $n;

            $avgFtR = array_sum(array_column($ftResults, 'macro_recall')) / $n;
            $avgHsR = array_sum(array_column($hsResults, 'macro_recall')) / $n;
            $avgJcR = array_sum(array_column($jcResults, 'macro_recall')) / $n;
            $avgCsR = array_sum(array_column($csResults, 'macro_recall')) / $n;

            $avgFtF1 = array_sum(array_column($ftResults, 'macro_f1')) / $n;
            $avgHsF1 = array_sum(array_column($hsResults, 'macro_f1')) / $n;
            $avgJcF1 = array_sum(array_column($jcResults, 'macro_f1')) / $n;
            $avgCsF1 = array_sum(array_column($csResults, 'macro_f1')) / $n;

            $avgAccs = ['Fuzzy TOPSIS' => $avgFtAcc, 'Hybrid Sim' => $avgHsAcc, 'Jaccard Sim' => $avgJcAcc, 'Cosine Sim' => $avgCsAcc];
            $bestAvg = max($avgAccs);
            $avgWinners = array_keys($avgAccs, $bestAvg);
            $avgWinnerLabel = count($avgWinners) > 1 ? 'Seri' : $avgWinners[0];
          @endphp
          <tr class="comparison-row">
            <td colspan="3"><strong>Rata-rata</strong></td>
            <td class="{{ $avgFtAcc == $bestAvg ? 'winner' : '' }}">{{ number_format($avgFtAcc * 100, 2) }}%</td>
            <td>{{ number_format($avgFtP * 100, 2) }}%</td>
            <td>{{ number_format($avgFtR * 100, 2) }}%</td>
            <td>{{ number_format($avgFtF1 * 100, 2) }}%</td>
            <td class="{{ $avgHsAcc == $bestAvg ? 'winner' : '' }}">{{ number_format($avgHsAcc * 100, 2) }}%</td>
            <td>{{ number_format($avgHsP * 100, 2) }}%</td>
            <td>{{ number_format($avgHsR * 100, 2) }}%</td>
            <td>{{ number_format($avgHsF1 * 100, 2) }}%</td>
            <td class="{{ $avgJcAcc == $bestAvg ? 'winner' : '' }}">{{ number_format($avgJcAcc * 100, 2) }}%</td>
            <td>{{ number_format($avgJcP * 100, 2) }}%</td>
            <td>{{ number_format($avgJcR * 100, 2) }}%</td>
            <td>{{ number_format($avgJcF1 * 100, 2) }}%</td>
            <td class="{{ $avgCsAcc == $bestAvg ? 'winner' : '' }}">{{ number_format($avgCsAcc * 100, 2) }}%</td>
            <td>{{ number_format($avgCsP * 100, 2) }}%</td>
            <td>{{ number_format($avgCsR * 100, 2) }}%</td>
            <td>{{ number_format($avgCsF1 * 100, 2) }}%</td>
            <td class="fw-bold">{{ $avgWinnerLabel }}</td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>

  {{-- ======================== DETAIL PER SCENARIO ======================== --}}
  <ul class="nav nav-tabs mb-3" id="scenarioTabs" role="tablist">
    @foreach($ftResults as $i => $ft)
    <li class="nav-item">
      <button class="nav-link {{ $i === 0 ? 'active' : '' }}" data-bs-toggle="tab" data-bs-target="#scenario{{ $i }}" type="button">
        {{ $ft['label'] }}
      </button>
    </li>
    @endforeach
  </ul>

  <div class="tab-content" id="scenarioTabContent">
    @foreach($ftResults as $i => $ft)
    @php
      $hs = $hsResults[$i] ?? [];
      $jc = $jcResults[$i] ?? [];
      $cs = $csResults[$i] ?? [];
      $scenarioAlgos = [
          ['key' => 'ft', 'data' => $ft, 'name' => 'Fuzzy TOPSIS', 'headerClass' => 'algo-header-ft', 'icon' => 'fa-brain', 'maxVar' => 'maxFt'.$i],
          ['key' => 'hs', 'data' => $hs, 'name' => 'Hybrid Similarity', 'headerClass' => 'algo-header-hs', 'icon' => 'fa-project-diagram', 'maxVar' => 'maxHs'.$i],
          ['key' => 'jc', 'data' => $jc, 'name' => 'Jaccard Similarity', 'headerClass' => 'algo-header-jc', 'icon' => 'fa-th', 'maxVar' => 'maxJc'.$i],
          ['key' => 'cs', 'data' => $cs, 'name' => 'Cosine Similarity', 'headerClass' => 'algo-header-cs', 'icon' => 'fa-ruler-combined', 'maxVar' => 'maxCs'.$i],
      ];
    @endphp
    <div class="tab-pane fade {{ $i === 0 ? 'show active' : '' }}" id="scenario{{ $i }}">
      <div class="row g-4">
        @foreach($scenarioAlgos as $algo)
        @php $d = $algo['data']; @endphp
        <div class="col-lg-6">
          <div class="card h-100">
            <div class="card-header {{ $algo['headerClass'] }} fw-semibold">
              <i class="fas {{ $algo['icon'] }} me-1"></i> {{ $algo['name'] }} &mdash; {{ $d['label'] ?? '' }}
              <span class="badge bg-light text-dark float-end">{{ $d['time'] ?? 0 }}s</span>
            </div>
            <div class="card-body">
              {{-- Metric Cards --}}
              <div class="row g-2 mb-3">
                <div class="col-3">
                  <div class="metric-card bg-light">
                    <h3 class="text-primary">{{ number_format(($d['accuracy'] ?? 0) * 100, 1) }}%</h3>
                    <small>Accuracy</small>
                  </div>
                </div>
                <div class="col-3">
                  <div class="metric-card bg-light">
                    <h3 class="text-info">{{ number_format(($d['macro_precision'] ?? 0) * 100, 1) }}%</h3>
                    <small>Precision</small>
                  </div>
                </div>
                <div class="col-3">
                  <div class="metric-card bg-light">
                    <h3 class="text-warning">{{ number_format(($d['macro_recall'] ?? 0) * 100, 1) }}%</h3>
                    <small>Recall</small>
                  </div>
                </div>
                <div class="col-3">
                  <div class="metric-card bg-light">
                    <h3 class="text-success">{{ number_format(($d['macro_f1'] ?? 0) * 100, 1) }}%</h3>
                    <small>F1-Score</small>
                  </div>
                </div>
              </div>

              {{-- Confusion Matrix --}}
              @if(!empty($d['labels']))
              <h6>Confusion Matrix ({{ $d['correct'] ?? 0 }}/{{ $d['total'] ?? 0 }} benar)</h6>
              <div class="table-responsive">
                <table class="cm-table">
                  <thead>
                    <tr>
                      <th class="axis">Actual \ Pred</th>
                      @foreach($d['labels'] as $lbl)
                        <th>{{ $lbl }}</th>
                      @endforeach
                    </tr>
                  </thead>
                  <tbody>
                    @php $maxV = 0; foreach($d['matrix'] ?? [] as $r) { foreach($r as $v) { if($v > $maxV) $maxV = $v; } } @endphp
                    @foreach($d['labels'] as $actual)
                    <tr>
                      <td class="axis">{{ $actual }}</td>
                      @foreach($d['labels'] as $pred)
                      @php
                        $v = $d['matrix'][$actual][$pred] ?? 0;
                        $ratio = $maxV > 0 ? $v / $maxV : 0;
                        $alp = 0.15 + (0.6 * $ratio);
                        $bg = ($actual === $pred) ? "rgba(16,185,129,{$alp})" : ($v > 0 ? "rgba(239,68,68,{$alp})" : "rgba(0,0,0,0.03)");
                      @endphp
                      <td style="background:{{ $bg }};">{{ $v }}</td>
                      @endforeach
                    </tr>
                    @endforeach
                  </tbody>
                </table>
              </div>

              {{-- Per-class metrics --}}
              <h6 class="mt-3">Per-Class Metrics</h6>
              <table class="table table-sm table-bordered text-center" style="font-size:12px;">
                <thead><tr><th>Class</th><th>Precision</th><th>Recall</th><th>F1</th><th>Support</th></tr></thead>
                <tbody>
                  @foreach($d['per_class'] ?? [] as $cls => $m)
                  <tr>
                    <td class="fw-bold">{{ $cls }}</td>
                    <td>{{ number_format($m['precision'] * 100, 2) }}%</td>
                    <td>{{ number_format($m['recall'] * 100, 2) }}%</td>
                    <td>{{ number_format($m['f1'] * 100, 2) }}%</td>
                    <td>{{ $m['support'] }}</td>
                  </tr>
                  @endforeach
                </tbody>
              </table>
              @endif
            </div>
          </div>
        </div>
        @endforeach
      </div>
    </div>
    @endforeach
  </div>


  @endif
</div>

<script>
document.getElementById('btnRun')?.addEventListener('click', function() {
  this.disabled = true;
  this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Menjalankan evaluasi (4 algoritma × 5 skenario)...';
  this.closest('form').submit();
});
</script>
@endsection
