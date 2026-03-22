@extends('layouts.admin')

@section('content')
@php
    $userId = Auth::id();
    $latestConsultationId = DB::table("test_case_user_{$userId}")->max('case_id') ?? 0;
@endphp

<h1 class="mt-4">Add New Consultation for Id {{ $latestConsultationId + 1 }}</h1>
    @if ($errors->any())
        <div class="alert alert-danger">
                @foreach ($errors->all() as $error)
                    {{ $error }}
                @endforeach
        </div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger" style="white-space:pre-wrap">{{ session('error') }}</div>
    @endif
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('svm_diag'))
        <div class="alert alert-secondary">
            <details open>
                <summary><strong>SVM Diagnostics</strong></summary>
                <pre class="mt-2 mb-0" style="white-space:pre-wrap">{{ session('svm_diag') }}</pre>
            </details>
        </div>
    @endif

    <form action="{{ route('test.case.store') }}" method="POST">
        @csrf
        <div class="row">
            @php
                $atributCount = count($atributs);
                $perColumn = ceil($atributCount / 3);
            @endphp

            @for ($col = 0; $col < 3; $col++)
                <div class="col-md-4">
                    @for ($row = $col * $perColumn; $row < min(($col + 1) * $perColumn, $atributCount); $row++)
                        @php
                            $atribut = $atributs[$row];
                            $values = DB::table('atribut_value')
                                ->where('atribut_id', $atribut->atribut_id)
                                ->where('user_id', Auth::id())
                                ->get();
                        @endphp
                        <div class="form-group mb-4">
                            <label for="{{ $atribut->atribut_name }}">{{ ucfirst($atribut->atribut_name) }}</label>
                            <br>
                            <label for="{{ $atribut->atribut_desc }}">{{ ucfirst($atribut->atribut_desc) }}</label>
                            <select name="{{ $atribut->atribut_id }}_{{ $atribut->atribut_name }}" class="form-control" required>
                                <option value="">Select an option</option>
                                @foreach($values as $value)
                                    <option value="{{ $value->value_id . '_' . $value->value_name }}">
                                        {{ explode('_', $value->value_name, 2)[1] ?? $value->value_name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    @endfor
                </div>
            @endfor
        </div>

        {{-- SVM Kernel --}}
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <label class="form-label fw-semibold">SVM Kernel (optional)</label>
                <select name="svm_kernel" class="form-select">
                    <option value="sgd">SGD (Linear)</option>
                    <option value="rbf:D=128:gamma=0.25">RBF (D=128, gamma=0.25)</option>
                    <option value="sigmoid:D=128:scale=1.0:coef0=0.0">Sigmoid (D=128, scale=1.0, coef0=0.0)</option>
                </select>
                <small class="text-muted">Dipakai hanya jika memilih Support Vector Machine.</small>
            </div>
        </div>

        {{-- Algorithm Buttons - Grouped --}}
        <div class="card mb-4">
            <div class="card-header fw-semibold">Pilih Algoritma</div>
            <div class="card-body">
                {{-- Row 1: Rule-Based --}}
                <div class="mb-3">
                    <small class="text-muted d-block mb-2">Rule-Based Inference</small>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="submit" name="action_type" value="Matching Rule" class="btn btn-outline-primary">
                            Matching Rule
                        </button>
                        <button type="submit" name="action_type" value="Forward Chaining" class="btn btn-outline-primary">
                            Forward Chaining
                        </button>
                        <button type="submit" name="action_type" value="Backward Chaining" class="btn btn-outline-primary">
                            Backward Chaining
                        </button>
                    </div>
                </div>

                {{-- Row 2: Similarity-Based --}}
                <div class="mb-3">
                    <small class="text-muted d-block mb-2">Similarity-Based (CBR)</small>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="submit" name="action_type" value="Hybrid Similarity" class="btn btn-outline-success">
                            Hybrid Similarity
                        </button>
                        <button type="submit" name="action_type" value="Jaccard Similarity" class="btn btn-outline-success">
                            Jaccard Similarity
                        </button>
                        <button type="submit" name="action_type" value="Cosine Similarity" class="btn btn-outline-success">
                            Cosine Similarity
                        </button>
                    </div>
                </div>

                {{-- Row 3: MCDM / Fuzzy --}}
                <div class="mb-3">
                    <small class="text-muted d-block mb-2">Multi-Criteria Decision Making</small>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="submit" name="action_type" value="Fuzzy TOPSIS" class="btn btn-warning text-dark fw-semibold">
                            Fuzzy TOPSIS
                        </button>
                    </div>
                </div>

                {{-- Row 4: Machine Learning --}}
                <div>
                    <small class="text-muted d-block mb-2">Machine Learning</small>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="submit" name="action_type" value="Support Vector Machine" class="btn btn-outline-danger">
                            Support Vector Machine
                        </button>
                        <button type="submit" name="action_type" value="Random Forest" class="btn btn-outline-danger">
                            Random Forest
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>
@endsection
