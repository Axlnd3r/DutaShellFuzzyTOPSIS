# INSTRUKSI PATCH: Fix Cost/Benefit Logic — Pipeline Fuzzy TOPSIS DutaShell

## Konteks Masalah

Pipeline Fuzzy TOPSIS di DutaShell memiliki bug arsitektur pada penanganan kriteria cost/benefit:

1. `DecisionMatrixService.php` mengubah SEMUA atribut (termasuk cost) menjadi similarity score [0,1] via rumus `1 - |base-test|/range`
2. `IdealSolutionService.php` menerapkan `A⁺ = min(vij)` untuk cost — yang artinya kasus PALING TIDAK MIRIP dianggap ideal
3. Ini MEMBALIK logika TOPSIS: kasus mirip justru dihukum, kasus berbeda justru dianjurkan
4. `FuzzificationService.php` menerapkan spread=0.1 dan clamp [0,1] untuk semua atribut, padahal atribut cost bernilai selisih absolut yang bisa > 1

## Solusi

Kriteria **cost** harus masuk ke matriks keputusan sebagai **selisih absolut mentah** `|base - test|` (bukan similarity), sehingga:
- Nilai kecil = mirip = baik → cocok dengan TOPSIS `A⁺ = min(vij)`
- Fuzzifikasi cost menggunakan spread proporsional `0.1 × range` dengan clamp `[0, range]`

Kriteria **benefit** tetap menggunakan **similarity score** `1 - |base-test|/range` [0,1], tidak berubah.

---

## File yang Perlu Diubah (4 file)

### 1. `app/Services/Topsis/DecisionMatrixService.php` — PERUBAHAN UTAMA

**Method `build()`** — tambahkan `$type` saat memanggil `similarityScore()`:

```php
// CARI baris ini di dalam loop foreach ($criteriaColumns as $column):
$row[$column] = $this->similarityScore($baseValue, $testValue, $range);

// GANTI MENJADI:
$type = $types[$column] ?? 'benefit';
$row[$column] = $this->similarityScore($baseValue, $testValue, $range, $type);
```

**Method `similarityScore()`** — ubah signature dan tambahkan logika cost:

```php
// CARI:
private function similarityScore(mixed $baseValue, mixed $testValue, ?array $range): float
{
    $baseNumeric = $this->extractNumeric($baseValue);
    $testNumeric = $this->extractNumeric($testValue);

    if ($baseNumeric !== null && $testNumeric !== null) {
        $delta = 0.0;
        if ($range !== null) {
            $delta = (float) $range['max'] - (float) $range['min'];
        }

        if ($delta <= 0.0) {
            return abs($baseNumeric - $testNumeric) < 1.0e-12 ? 1.0 : 0.0;
        }

        $normalizedDifference = abs($baseNumeric - $testNumeric) / $delta;
        $score = 1.0 - $normalizedDifference;
        return $this->clamp($score);
    }

    $baseText = $this->normalizeText($baseValue);
    $testText = $this->normalizeText($testValue);

    if ($baseText === '' || $testText === '') {
        return 0.0;
    }

    return $baseText === $testText ? 1.0 : 0.0;
}

// GANTI SELURUHNYA MENJADI:
/**
 * Hitung skor per sel matriks keputusan berdasarkan tipe kriteria.
 *
 * COST:    Selisih absolut |base - test|. Semakin KECIL = semakin mirip.
 *          → Cocok dengan TOPSIS: A⁺ = min(vij), A⁻ = max(vij)
 *
 * BENEFIT: Similarity score 1 - |base - test| / range. Semakin BESAR = semakin mirip.
 *          → Cocok dengan TOPSIS: A⁺ = max(vij), A⁻ = min(vij)
 */
private function similarityScore(mixed $baseValue, mixed $testValue, ?array $range, string $type): float
{
    $baseNumeric = $this->extractNumeric($baseValue);
    $testNumeric = $this->extractNumeric($testValue);

    if ($baseNumeric !== null && $testNumeric !== null) {

        // --- COST: selisih absolut mentah ---
        if ($type === 'cost') {
            return abs($baseNumeric - $testNumeric);
        }

        // --- BENEFIT: similarity score [0,1] ---
        $delta = 0.0;
        if ($range !== null) {
            $delta = (float) $range['max'] - (float) $range['min'];
        }

        if ($delta <= 0.0) {
            return abs($baseNumeric - $testNumeric) < 1.0e-12 ? 1.0 : 0.0;
        }

        $normalizedDifference = abs($baseNumeric - $testNumeric) / $delta;
        $score = 1.0 - $normalizedDifference;
        return $this->clamp($score);
    }

    // Kategorikal: exact match
    $baseText = $this->normalizeText($baseValue);
    $testText = $this->normalizeText($testValue);

    if ($baseText === '' || $testText === '') {
        return 0.0;
    }

    if ($type === 'cost') {
        // Kategorikal cost: 0 jika match (tidak ada jarak), 1 jika berbeda
        return $baseText === $testText ? 0.0 : 1.0;
    }

    return $baseText === $testText ? 1.0 : 0.0;
}
```

### 2. `app/Services/Fuzzy/FuzzificationService.php` — PERUBAHAN SPREAD

**Ganti seluruh method `process()`:**

```php
// CARI:
public function process(array $matrix, float $spread = 0.1): array
{
    $fuzzyMatrix = [];

    foreach ($matrix as $caseId => $criteriaValues) {
        foreach ($criteriaValues as $criterion => $value) {
            $x = $this->clamp((float) $value, 0.0, 1.0);

            // Triangular Fuzzy Number (lower, middle, upper)
            $a = max(0.0, $x - $spread);  // lower bound
            $b = $x;                        // middle (nilai asli)
            $c = min(1.0, $x + $spread);   // upper bound

            $fuzzyMatrix[$caseId][$criterion] = [$a, $b, $c];
        }
    }

    return $fuzzyMatrix;
}

// GANTI SELURUHNYA MENJADI:
/**
 * Konversi matriks keputusan (crisp) → matriks fuzzy (TFN).
 *
 * Untuk kriteria BENEFIT (similarity [0,1]):
 *   spread = 0.1 (toleransi ±10% dari skala [0,1])
 *   TFN = (max(0, x-0.1), x, min(1, x+0.1))
 *
 * Untuk kriteria COST (selisih absolut [0, range]):
 *   spread = 0.1 × range (toleransi ±10% dari skala range)
 *   TFN = (max(0, x-spread), x, min(range, x+spread))
 *
 * @param array  $matrix       Matriks keputusan dari DecisionMatrixService
 * @param array  $types        Tipe per kriteria: ['column' => 'cost'|'benefit']
 * @param array  $ranges       Range per kriteria: ['column' => ['min'=>..,'max'=>..]]
 * @param float  $spreadFactor Faktor spread (default 0.1 = 10%)
 */
public function process(array $matrix, array $types = [], array $ranges = [], float $spreadFactor = 0.1): array
{
    $fuzzyMatrix = [];

    foreach ($matrix as $caseId => $criteriaValues) {
        foreach ($criteriaValues as $criterion => $value) {
            $type = strtolower((string) ($types[$criterion] ?? 'benefit'));
            $x = (float) $value;

            if ($type === 'cost') {
                // COST: selisih absolut, skala [0, range]
                $rangeValue = 0.0;
                if (isset($ranges[$criterion])) {
                    $rangeValue = (float) $ranges[$criterion]['max'] - (float) $ranges[$criterion]['min'];
                }
                $rangeValue = max($rangeValue, 0.0);

                $spread = $spreadFactor * $rangeValue;
                $x = max(0.0, $x);  // tidak boleh negatif

                $a = max(0.0, $x - $spread);
                $b = $x;
                $c = ($rangeValue > 0.0) ? min($rangeValue, $x + $spread) : ($x + $spread);
            } else {
                // BENEFIT: similarity score, skala [0, 1]
                $spread = $spreadFactor;
                $x = $this->clamp($x, 0.0, 1.0);

                $a = max(0.0, $x - $spread);
                $b = $x;
                $c = min(1.0, $x + $spread);
            }

            $fuzzyMatrix[$caseId][$criterion] = [$a, $b, $c];
        }
    }

    return $fuzzyMatrix;
}
```

### 3. `app/Services/Inference/FuzzyTopsisService.php` — 1 BARIS

Di method `infer()`, sekitar baris 45:

```php
// CARI:
$fuzzyMatrix = $this->fuzzification->process($decision['matrix']);

// GANTI MENJADI:
$fuzzyMatrix = $this->fuzzification->process(
    $decision['matrix'],
    $decision['types'],
    $decision['ranges']
);
```

### 4. `app/Http/Controllers/EvaluationController.php` — 1 BARIS

Di method `evaluateFuzzyTopsis()`, sekitar baris 273:

```php
// CARI:
$fuzzyMatrix = $this->fuzzification->process($decision['matrix']);

// GANTI MENJADI:
$fuzzyMatrix = $this->fuzzification->process(
    $decision['matrix'],
    $decision['types'],
    $decision['ranges']
);
```

---

## File yang TIDAK Perlu Diubah

File-file ini sudah benar dan backward compatible:

- `app/Services/Fuzzy/DefuzzificationService.php` — centroid (a+b+c)/3 tetap benar
- `app/Services/Fuzzy/MembershipFunctionService.php` — tidak terpengaruh
- `app/Services/Topsis/NormalizationService.php` — vector normalization tetap benar
- `app/Services/Topsis/IdealSolutionService.php` — sudah benar: cost→min, benefit→max
- `app/Services/Topsis/DistanceService.php` — Euclidean distance tetap benar
- `app/Services/Topsis/RankingService.php` — CC dan sorting tetap benar
- `app/Services/Evaluation/ConfusionMatrixService.php` — evaluasi tetap benar
- `scripts/decision-tree/hybrid_similarity.php` — algoritma berbeda, tidak terpengaruh
- Semua Model, DTO, View, Route — tidak terpengaruh

---

## Verifikasi Setelah Patch

1. Pastikan `heart_atribut.sql` sudah benar di database:
   - age: type = 'cost'
   - cp: type = 'benefit'
   - trestbps: type = 'cost'
   - chol: type = 'benefit'
   - thalach: type = 'benefit'
   - oldpeak: type = 'cost'
   - ca: type = 'benefit'
   - thal: type = 'benefit'

2. Jalankan evaluasi perbandingan 4 algoritma di halaman Evaluation

3. Cek bahwa Fuzzy TOPSIS sekarang menghasilkan akurasi yang berbeda dari sebelum patch (seharusnya ~75% pada skenario 80/20 dengan dataset heart)

4. Cek debug JSON di `storage/app/private/fuzzy_topsis/` — pastikan:
   - `decision_matrix` untuk atribut cost berisi selisih absolut (bisa > 1)
   - `decision_matrix` untuk atribut benefit berisi similarity [0,1]
   - `fuzzy_matrix` untuk cost punya upper bound > 1 (sesuai range)
   - `ideal_solution.positive` untuk cost = nilai terkecil
   - `ideal_solution.positive` untuk benefit = nilai terbesar
