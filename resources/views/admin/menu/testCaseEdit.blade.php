@extends('layouts.admin')

@section('content')
<h1 class="mt-4">Edit Consultation</h1>

@if ($errors->any())
    <div class="alert alert-danger">
            @foreach ($errors->all() as $error)
                {{ $error }}
            @endforeach
    </div>
@endif


<form action="{{ route('test.case.update', $case->case_id) }}" method="POST">
    @csrf
    @method('PUT')

    <div class="row">
        @foreach ($atributs as $atribut)
            @php
                $kolom_name = "{$atribut->atribut_id}_{$atribut->atribut_name}";
                $current_value = $case->$kolom_name ?? null;
                $values = DB::table('atribut_value')
                    ->where('atribut_id', $atribut->atribut_id)
                    ->where('user_id', Auth::id())
                    ->get();
            @endphp

            <div class="form-group col-md-6 mb-3">
                <label for="{{ $kolom_name }}"><strong>{{ ucfirst($atribut->atribut_name) }}</strong></label>
                @if($values->count() > 0)
                    <select name="{{ $kolom_name }}" id="{{ $kolom_name }}" class="form-control" required>
                        <option value="">-- Pilih --</option>
                        @foreach ($values as $value)
                            <option value="{{ $value->value_id . '_' . $value->value_name }}"
                                {{ $current_value == ($value->value_id . '_' . $value->value_name) ? 'selected' : '' }}>
                                {{ explode('_', $value->value_name, 2)[1] ?? $value->value_name }}
                            </option>
                        @endforeach
                    </select>
                @else
                    <input type="number"
                           step="any"
                           name="{{ $kolom_name }}"
                           id="{{ $kolom_name }}"
                           class="form-control"
                           value="{{ $current_value }}"
                           placeholder="Masukkan nilai numerik"
                           required>
                @endif
            </div>
        @endforeach
    </div>

    <button type="submit" class="btn btn-primary mt-4">Update</button>
</form>
@endsection
