@extends('layouts.admin')

@section('content')
<div class="container py-3">
    <h1 class="mb-4">Random Forest Model</h1>

    @if(session('success'))
        <div class="alert alert-primary">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <div class="mb-4">
        <a href="{{ route('randomforest.generate') }}" class="btn btn-primary">
            Generate / Retrain Model
        </a>
    </div>

    @if($modelExists)
        <div class="alert alert-info">
            Model ditemukan dan siap digunakan untuk evaluasi performa.
            Prediksi kasus tetap dilakukan melalui modul inferensi (Forward / Backward Chaining).
        </div>

        @if($evaluation)
        <div class="card mb-4 shadow-sm">
            <div class="card-header bg-primary text-white">
                Evaluasi Model
            </div>
            <div class="card-body">
                @php
                    $usesFull = !empty($evaluation['full_dataset_used']);
                    $datasetSize = $evaluation['dataset_size'] ?? ($evaluation['train_size'] + ($evaluation['test_size'] ?? 0));
                    $undersampling = $evaluation['undersampling'] ?? null;
                @endphp
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Akurasi{{ $usesFull ? ' (data latih)' : '' }}:</strong> {{ $evaluation['accuracy'] }}%</p>
                        <p><strong>Data Latih:</strong> {{ $evaluation['train_size'] }}</p>
                        @if(!empty($evaluation['train_size_before_undersample']))
                        <p><strong>Data Latih (sebelum undersampling):</strong> {{ $evaluation['train_size_before_undersample'] }}</p>
                        @endif
                        @if(!empty($evaluation['test_size']))
                        <p><strong>Akurasi (data uji):</strong> {{ $evaluation['test_accuracy'] ?? 'n/a' }}%</p>
                        <p><strong>Data Uji:</strong> {{ $evaluation['test_size'] }}</p>
                        @endif
                        <p><strong>Total Data:</strong> {{ $datasetSize }}</p>
                        <p><strong>Jumlah Pohon:</strong> {{ $evaluation['trees'] }}</p>
                        <p><strong>Sampling Ratio:</strong> {{ $evaluation['sampling'] }}</p>
                        <p><strong>Waktu Training:</strong> {{ $evaluation['training_time'] }} detik</p>
                        @if($usesFull)
                        <p class="text-muted mb-0">
                            Semua data dipakai untuk training dan evaluasi (tanpa pembatasan atau hold-out).
                        </p>
                        @endif
                        @if(!empty($undersampling))
                        <hr>
                        <p class="mb-1"><strong>Random Undersampling:</strong> {{ $undersampling['applied'] ? 'Aktif' : 'Tidak diterapkan' }}</p>
                        <p class="mb-1"><strong>Target per kelas:</strong> {{ $undersampling['target_per_class'] ?? 'n/a' }}</p>
                        <p class="mb-1"><strong>Distribusi sebelum:</strong> {{ json_encode($undersampling['before']['class_counts'] ?? []) }}</p>
                        <p class="mb-0"><strong>Distribusi sesudah:</strong> {{ json_encode($undersampling['after']['class_counts'] ?? []) }}</p>
                        @endif
                    </div>
                    <div class="col-md-6">
                        <canvas id="labelChart" width="400" height="300"></canvas>
                    </div>
                </div>
            </div>
        </div>
        @endif

        @if($debugInfo)
        <div class="card mb-4 shadow-sm">
            <div class="card-header bg-secondary text-white">Debug Info</div>
            <div class="card-body">
                <pre class="mb-0">{{ print_r($debugInfo, true) }}</pre>
            </div>
        </div>
        @endif

        @if(session('features_info'))
        <div class="card mb-4 shadow-sm">
            <div class="card-header bg-primary text-white">Fitur yang Digunakan (Berurutan)</div>
            <div class="card-body">
                <pre class="mb-0">{{ print_r(session('features_info'), true) }}</pre>
            </div>
        </div>
        @endif

        <div class="card mb-4 shadow-sm">
            <div class="card-header bg-primary text-white">Prediksi & Inferensi</div>
            <div class="card-body">
                <p class="mb-1">
                    Random Forest digunakan untuk melatih dan mengevaluasi model secara statistik.
                </p>
                <p class="mb-0">
                    Untuk prediksi kasus pasien, silakan gunakan menu <strong>Consultation</strong> dan lihat hasilnya di menu <strong>History (Inference)</strong>
                    yang sudah terintegrasi dengan Forward dan Backward Chaining.
                </p>
            </div>
        </div>

    @else
        <div class="alert alert-warning">
            Belum ada model. Klik tombol "Generate / Retrain Model" di atas untuk melatih model terlebih dahulu.
        </div>
    @endif
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
document.addEventListener("DOMContentLoaded", function () {
    @if(isset($evaluation['label_distribution']))
    const labelData = @json(array_keys($evaluation['label_distribution']));
    const labelCount = @json(array_values($evaluation['label_distribution']));
    const ctx = document.getElementById("labelChart").getContext("2d");

    new Chart(ctx, {
        type: "bar",
        data: {
            labels: labelData,
            datasets: [{
                label: "Jumlah Data per Label",
                data: labelCount,
                backgroundColor: "rgba(54, 162, 235, 0.6)",
                borderColor: "rgba(37, 99, 235, 1)",
                borderWidth: 1
            }]
        },
        options: {
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { stepSize: 1 }
                }
            },
            plugins: {
                legend: { display: false },
                title: {
                    display: true,
                    text: "Distribusi Label Dataset"
                }
            }
        }
    });
    @endif
});
</script>
@endsection
