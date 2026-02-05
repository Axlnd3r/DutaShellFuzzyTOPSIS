@extends('layouts.admin')

@section('content')

@php
    use Illuminate\Support\Facades\Schema;
    use Illuminate\Support\Facades\DB;

    $user = Auth::user();
    $selectedCaseId = request('case_id'); // Ambil case_id dari URL request

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

    // Ambil data dari inferensi_bc_user_{userId}
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

    // Gabungkan data inferensi dan filter berdasarkan case_id yang dipilih
    $allInference = $inference1
        ->merge($inference2)
        ->merge($inference3)
        ->merge($inference4)
        ->merge($inference5)
        ->merge($inference6);
    $selectedInference = $allInference->where('case_id', $selectedCaseId);

    $kasus = \App\Models\Kasus::where('case_num', $user->user_id)->first();

    // Ambil algoritma dari test_case_user_{userId}
    $generate = new \App\Models\Consultation();
    $generate->setTableForUser($user->user_id);
    $tableExistss = $generate->tableExists();
    $testCases = $tableExistss ? $generate->getRules()->where('case_id', $selectedCaseId) : collect();

    $algorithms = $testCases->pluck('algoritma', 'case_id')->toArray();

    // Mendapatkan kolom dari tabel yang sesuai
    $columns = $tableExistss ? Schema::getColumnListing($generate->getTable()) : [];

    // Mengambil atribut yang goal-nya bukan 'T'
    $validAtributs = DB::table('atribut')
            ->where('user_id', $user->user_id)
            ->where('goal', '!=', 'T')
            ->pluck('atribut_name', 'atribut_id'); 

    // Filter kolom hanya untuk atribut yang valid
    $filteredColumns = array_filter($columns, function ($column) use ($validAtributs, $columns) {
        return in_array($column, $columns) && collect($validAtributs)->keys()->contains(explode('_', $column)[0]);
    });

    // Kolom yang ingin disembunyikan
    $excludeColumns = ['case_id', 'user_id', 'case_num', 'algoritma'];
@endphp

<h1 class="mt-4">Detail Inferensi for Id {{ $selectedCaseId }}</h1>

@if ($selectedInference->isEmpty())
    <p class="alert alert-warning">You have no detail for Id {{ $selectedCaseId }}</p>
@else
    <table class="table table-bordered">
        @foreach ($selectedInference as $detail)
        <tr>
            <th>Id</th>
            <td>{{ $detail->case_id }}</td>
        </tr>
        <tr>
            <th>Rule Id</th>
            <td>{{ $detail->rule_id }}</td>
        </tr>
        <tr>
            <th>Match Value</th>
            <td>{{ $detail->match_value }}</td>
        </tr>
        @endforeach

        <tr>
            <td colspan="100%">Your Consultation </td>
        </tr>
        
        @foreach ($testCases as $index => $row)
        {{-- Tambahkan bagian ini untuk menampilkan kolom yang difilter --}}
            @foreach($filteredColumns as $column)
            @if(!in_array($column, $excludeColumns))
                    <th>{{ str_replace('_', ' ', preg_replace('/\b\d+_/', ' ', $column)) }}</th>
                    <td>
                        @php
                            $cleanedIfPart = preg_replace('/\b\d+_/', ' ', $row->$column,);
                            $cleanedIfPart = str_replace('_', ' ', $cleanedIfPart);
                            $cleanedIfPart = str_replace('-', ' ', $cleanedIfPart);
                        @endphp
                        {{ $cleanedIfPart }}
                    </td>
                </tr>
                @endif
            @endforeach
        @endforeach
        <br>
        <tr>
            <td colspan="100%">Your Consultation Goal</td>
        </tr>
        
        @foreach ($selectedInference as $detail)
        <tr>
            <th>Goal</th>
            <td>{{ str_replace(['_', '-', '='], [' ', ' ', ' ='], preg_replace('/\b\d+_/', ' ', $detail->rule_goal)) }}</td>
        </tr>
        <tr>
            <th>Algorithm</th>
            <td>{{ $detail->source_algorithm ?? ($algorithms[$detail->case_id] ?? 'Unknown') }}</td>
        </tr>
        <tr>
            <th>Execution Time</th>
            <td>{{ $detail->waktu ?? '-' }}</td>
        </tr>
        @endforeach
    </table>
@endif

<a href="{{ url('/inference') }}" class="btn btn-secondary">Back</a>

@endsection
