@extends('layouts.admin')

@section('content')
<style>
  .hs-conf {overflow-x: auto;}
  .hs-conf table {border-collapse: collapse; min-width: 280px;}
  .hs-conf th, .hs-conf td {border: 1px solid #e5e7eb; padding: 6px 8px; text-align: center; font-size: 12px;}
  .hs-conf th {background: #f8fafc; font-weight: 600;}
  .hs-conf .axis {background: #f1f5f9; font-weight: 600;}
</style>
<div class="container mt-4">
  <h2>Hybrid Similarity (Jaccard + Cosine)</h2>
  <p class="text-muted">Evaluasi akurasi algoritma similarity terhadap semua kasus yang ada.</p>
  <hr>

  {{-- Flash messages --}}
  @if(session('hs_err'))
    <div class="alert alert-danger"><pre class="mb-0">{{ session('hs_err') }}</pre></div>
  @endif
  @if(session('hs_ok'))
    <div class="alert alert-success"><pre class="mb-0">{{ session('hs_ok') }}</pre></div>
  @endif

  {{-- Confusion Matrix --}}
  @php $matrix = session('hs_matrix'); @endphp
  @if(is_array($matrix))
    @php
      $algo   = $matrix['algo'] ?? 'Similarity';
      $m      = $matrix['matrix'] ?? [];
      $counts = $m['counts'] ?? [];
      $labels = array_values(array_unique(array_merge($m['actuals'] ?? [], $m['preds'] ?? [])));
      $total  = $m['total'] ?? 0;
      $correct= $m['correct'] ?? 0;
      $accPct = $total > 0 ? number_format(($correct / $total) * 100, 2) : '0.00';
    @endphp
    <div class="card mb-4">
      <div class="card-header fw-semibold">Hasil Evaluasi: {{ $algo }}</div>
      <div class="card-body">
        <div class="row align-items-start g-4">
          {{-- Akurasi --}}
          <div class="col-md-4">
            <h6>Ringkasan</h6>
            <ul class="list-unstyled">
              <li>Total prediksi: <strong>{{ $total }}</strong></li>
              <li>Prediksi benar: <strong>{{ $correct }}</strong></li>
              <li>Akurasi: <strong>{{ $accPct }}%</strong></li>
            </ul>
            <div class="progress" style="height: 20px;">
              <div
                class="progress-bar {{ (float)$accPct >= 70 ? 'bg-success' : 'bg-warning' }}"
                role="progressbar"
                style="width: {{ $accPct }}%;"
              >{{ $accPct }}%</div>
            </div>
          </div>
          {{-- Confusion Matrix --}}
          <div class="col-md-8">
            @if(count($labels) > 0 && !empty($counts))
              <h6>Confusion Matrix</h6>
              <div class="hs-conf">
                <table>
                  <thead>
                    <tr>
                      <th class="axis">Actual \ Predicted</th>
                      @foreach($labels as $lbl)
                        <th>{{ $lbl }}</th>
                      @endforeach
                    </tr>
                  </thead>
                  <tbody>
                    @php
                      $maxVal = 0;
                      foreach ($counts as $aRow) {
                        foreach ($aRow as $v) { if ($v > $maxVal) $maxVal = $v; }
                      }
                    @endphp
                    @foreach($labels as $actual)
                      <tr>
                        <th class="axis">{{ $actual }}</th>
                        @foreach($labels as $pred)
                          @php
                            $v = $counts[$actual][$pred] ?? 0;
                            $ratio = $maxVal > 0 ? $v / $maxVal : 0;
                            $alpha = 0.15 + (0.55 * $ratio);
                            $bg = ($actual === $pred)
                              ? "rgba(16, 185, 129, {$alpha})"
                              : "rgba(239, 68, 68, {$alpha})";
                            $fg = $ratio > 0.5 ? '#fff' : '#111';
                          @endphp
                          <td style="background: {{ $bg }}; color: {{ $fg }};">{{ $v }}</td>
                        @endforeach
                      </tr>
                    @endforeach
                  </tbody>
                </table>
              </div>
            @else
              <div class="text-muted">Confusion matrix tidak tersedia.</div>
            @endif
          </div>
        </div>
      </div>
    </div>
  @endif

  {{-- Form Evaluasi --}}
  <div class="card mb-4">
    <div class="card-header fw-semibold">Generate Evaluasi Similarity</div>
    <div class="card-body">
      <form action="{{ route('HybridSim.generate') }}" method="POST" class="row g-3">
        @csrf
        <div class="col-md-3">
          <label class="form-label">Algoritma</label>
          <select name="mode" id="hsMode" class="form-select">
            <option value="hybrid">Hybrid Similarity</option>
            <option value="jaccard">Jaccard Similarity</option>
            <option value="cosine">Cosine Similarity</option>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Metode Evaluasi</label>
          <select name="eval" id="hsEval" class="form-select">
            <option value="loocv">Leave-One-Out CV</option>
            <option value="kfold">K-Fold CV</option>
            <option value="split">Train/Test Split</option>
          </select>
        </div>
        <div class="col-md-2" id="paramWrap" style="display:none;">
          <label class="form-label" id="paramLabel">K</label>
          <input type="text" name="param" id="paramInput" class="form-control" value="5">
        </div>
        <div class="col-md-2" id="alphaWrap">
          <label class="form-label">Alpha (0.0 - 1.0)</label>
          <input type="number" name="alpha" class="form-control" value="0.5" min="0" max="1" step="0.1">
          <small class="text-muted">Bobot Cosine vs Jaccard</small>
        </div>
        <div class="col-md-2 d-flex align-items-end">
          <button class="btn btn-primary w-100">Generate</button>
        </div>
      </form>
    </div>
  </div>

</div>

<script>
(function(){
  const mode = document.getElementById('hsMode');
  const evalSel = document.getElementById('hsEval');
  const alphaWrap = document.getElementById('alphaWrap');
  const paramWrap = document.getElementById('paramWrap');
  const paramLabel = document.getElementById('paramLabel');
  const paramInput = document.getElementById('paramInput');

  function toggle() {
    // Alpha hanya untuk hybrid
    alphaWrap.style.display = mode.value === 'hybrid' ? '' : 'none';

    // Param hanya untuk kfold & split
    const ev = evalSel.value;
    if (ev === 'kfold') {
      paramWrap.style.display = '';
      paramLabel.textContent = 'K (jumlah fold)';
      paramInput.value = paramInput.value || '5';
    } else if (ev === 'split') {
      paramWrap.style.display = '';
      paramLabel.textContent = 'Ratio train (0-1)';
      paramInput.value = paramInput.value || '0.8';
    } else {
      paramWrap.style.display = 'none';
    }
  }

  toggle();
  mode.addEventListener('change', toggle);
  evalSel.addEventListener('change', toggle);
})();
</script>
@endsection
