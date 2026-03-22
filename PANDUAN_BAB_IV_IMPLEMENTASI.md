# Panduan Menyusun BAB IV — Implementasi dan Pembahasan
## Fuzzy TOPSIS untuk DutaShell CBR System

**Untuk**: Skripsi/Tugas Akhir
**Penulis**: 71220853 - Matthew Alexander
**Sistem**: DutaShell - Sistem Pakar CBR Multi-Algoritma
**Fokus Algoritma**: Hybrid Fuzzy-TOPSIS sebagai Case Retrieval Engine

---

## PANDUAN STRUKTUR BAB IV

Berikut adalah **pemetaan lengkap** di mana bagian-bagian Fuzzy TOPSIS harus diletakkan dalam struktur BAB IV:

```
BAB IV — IMPLEMENTASI DAN PEMBAHASAN
│
├─ 4.1 IMPLEMENTASI AWAL
│   └─ 4.1.1 Analisis Kebutuhan Sistem
│   └─ 4.1.2 Desain Arsitektur CBR + Fuzzy TOPSIS
│   └─ 4.1.3 Pemilihan Teknologi & Framework
│
├─ 4.2 IMPLEMENTASI SISTEM
│   └─ 4.2.1 Persiapan Data & Atribut
│   │   └─ Penjelasan atribut mana yang masuk Fuzzifikasi (Q1/Q8)
│   │
│   └─ 4.2.2 Pipeline Fuzzy TOPSIS (9 Step)
│   │   ├─ Step 1: Load Cases dari Database
│   │   ├─ Step 2: Decision Matrix (Similarity Calculation)
│   │   ├─ Step 3: Fuzzifikasi (Triangular Membership Function)
│   │   ├─ Step 4: Defuzzifikasi (Centroid Method)
│   │   ├─ Step 5-6: Normalisasi & Pembobotan
│   │   ├─ Step 7: Solusi Ideal (A+/A-)
│   │   ├─ Step 8: Jarak Euclidean (D+/D-)
│   │   └─ Step 9: Closeness Coefficient & Ranking
│   │
│   └─ 4.2.3 Implementasi Kode
│   │   ├─ Service Architecture (file-file yang terlibat)
│   │   ├─ Controller & API Integration
│   │   └─ Database Schema & Dynamic Tables
│   │
│   └─ 4.2.4 Evaluasi Model
│       └─ Confusion Matrix & Metrik (TP/FP/TN/FN, Accuracy, Precision, Recall, F1)
│
├─ 4.3 PENGUJIAN DAN ANALISIS
│   └─ 4.3.1 Contoh Kasus Manual (Q2/Q5)
│   │   ├─ Dataset Kecil: 3 Kasus × 3 Atribut
│   │   ├─ Perhitungan Step-by-Step dengan Angka Konkret
│   │   └─ Verifikasi CC Score Manual vs Hasil Program
│   │
│   └─ 4.3.2 Pengujian Sistem pada DutaShell Web
│   │   ├─ Setup Data di Database
│   │   ├─ Eksekusi Fuzzy TOPSIS via UI
│   │   └─ Validasi Output Ranking & Confusion Matrix
│   │
│   └─ 4.3.3 Sensitivitas & Robustness Testing (Opsional)
│       └─ Menguji performa saat ada noise, nilai ekstrim, dll
│
├─ 4.4 PEMBAHASAN
│   └─ 4.4.1 Menjawab Pertanyaan Penelitian (Q3-Q7)
│   │   ├─ Mengapa Fuzzy + TOPSIS? (Q3)
│   │   ├─ Fuzzy saja vs TOPSIS saja? (Q4)
│   │   ├─ Perbandingan dengan Fuzzy AHP? (Q6)
│   │   ├─ Atribut teks/deskriptif? (Q7)
│   │   └─ Kesimpulan & Implikasi
│   │
│   └─ 4.4.2 Kelebihan & Keterbatasan
│   │   ├─ Keunggulan Fuzzy TOPSIS dalam CBR
│   │   ├─ Limitasi & Saran Perbaikan
│   │   └─ Scalability & Generalisasi
│   │
│   └─ 4.4.3 Kontribusi Penelitian
│       └─ Novel aspects dari implementasi ini
```

---

## PENEMPATAN SETIAP BAGIAN FUZZY TOPSIS

### 4.1 — IMPLEMENTASI AWAL

#### Apa yang Dibahas:
- **Konteks masalah**: Kenapa CBR perlu Case Retrieval Engine yang robust?
- **Tujuan**: Menggunakan Fuzzy TOPSIS untuk ranking kasus multi-kriteria
- **Ruang lingkup**: Fitur apa saja yang diimplementasikan

#### Konten yang Dimuat:
```markdown
4.1.1 Analisis Kebutuhan Sistem

DutaShell adalah sistem pakar berbasis CBR yang perlu:
1. Merangking kasus dari database berdasarkan kemiripan multi-kriteria
2. Menangani ketidakpastian dalam data input (gejala pasien, kondisi ikan)
3. Memberikan scoring yang transparan (Closeness Coefficient)
4. Evaluasi akurasi prediksi via confusion matrix

Solusi: Implementasi Fuzzy TOPSIS sebagai Case Retrieval Engine
- Fuzzy: Menangkap ketidakpastian skor kemiripan
- TOPSIS: Ranking dengan perspektif ganda (D+/D-)

4.1.2 Desain Arsitektur

[Gambar arsitektur dari README.md BAB 3]

Hubungan CBR ↔ Fuzzy TOPSIS:
- CBR: Framework keseluruhan (problem → retrieve → reuse → revise → retain)
- Fuzzy TOPSIS: Spesifik untuk "retrieve phase" (case retrieval engine)
- Output: Ranking kasus dengan CC score 0–1

4.1.3 Pemilihan Teknologi

Teknologi Implementasi:
- Framework: Laravel 11.9 (PHP 8.2+)
- Database: MySQL/MariaDB
- Library Fuzzy/TOPSIS: Custom PHP implementation
- Alasan:
  * Laravel: Web framework yang mature, mudah integration
  * Tidak ada library Fuzzy TOPSIS siap pakai di PHP → custom implement
  * Custom implementation memberikan kontrol penuh & transparency
```

---

### 4.2 — IMPLEMENTASI SISTEM

Ini adalah **bagian terbesar & paling penting**. Bagi menjadi 4 sub-bagian:

#### 4.2.1 — Persiapan Data & Atribut

```markdown
4.2.1 Persiapan Data dan Atribut

Sebelum eksekusi Fuzzy TOPSIS, sistem harus:

A. MENDEFINISIKAN ATRIBUT (Tabel: atribut)
   Setiap atribut memiliki:
   - atribut_id: Primary key
   - atribut_name: Nama atribut (unik per user)
   - goal: ENUM('T', 'F')
     * T = Goal/Target attribute (label yang ingin diprediksi)
     * F = Criterion attribute (masuk TOPSIS)
   - weight: Float (default 1.0) — bobot kepentingan
   - type: VARCHAR('benefit'|'cost') — directionality
     * benefit: Lebih tinggi lebih baik (maximize)
     * cost: Lebih rendah lebih baik (minimize)

   Contoh:
   | atribut_id | atribut_name   | goal | weight | type    |
   |------------|----------------|------|--------|---------|
   | 1          | Demam          | F    | 3      | benefit |
   | 2          | Nafsu_Makan    | F    | 2      | benefit |
   | 3          | Umur           | F    | 1      | benefit |
   | 4          | Diagnosis      | T    | NULL   | NULL    |

B. MEMILIH ATRIBUT YANG MASUK FUZZIFIKASI

   [Ini menjawab Q1 & Q8]

   Aturan:
   - Masuk Fuzzifikasi: Semua atribut dengan goal='F' (kriteria)
   - TIDAK Masuk: Atribut dengan goal='T' (hanya label)

   Alasan:
   Fuzzifikasi bekerja pada SKOR SIMILARITY (0–1), bukan nilai mentah.
   Atribut goal tidak punya skor similarity, hanya match/tidak match.

   Flow:
   Nilai atribut (True, Buruk, 28°C)
       ↓
   Similarity Score (1.0, 0.0, 0.75)
       ↓
   TFN Fuzzifikasi (0.9,1.0,1.0), (0.0,0.0,0.1), (0.65,0.75,0.85)
       ↓
   Crisp Defuzzifikasi (0.9667, 0.0333, 0.75)
       ↓
   Masuk TOPSIS untuk Ranking

C. MEMPERSIAPKAN DATA KASUS

   Dynamic table creation: case_user_{userId}
   - Setiap user memiliki tabel kasus sendiri
   - Column naming: {atribut_id}_{atribut_name}
   - Contoh: 1_Demam, 2_Nafsu_Makan, 3_Umur, 4_Diagnosis

   Insert test case ke: test_case_user_{userId}
   - Struktur sama dengan case table
   - Berisi 1 row (kasus yang sedang didiagnosa)
```

#### 4.2.2 — Pipeline Fuzzy TOPSIS (9 Step)

```markdown
4.2.2 Pipeline Algoritma Fuzzy TOPSIS

Algoritma berjalan dalam 9 step berurutan:

═══════════════════════════════════════════════════════════════
STEP 1: LOAD CASES FROM DATABASE
═══════════════════════════════════════════════════════════════

Fungsi: FuzzyTopsisService::loadCases($input)

Input:
- user_id: Untuk mengakses dynamic table user
- test_case_id (opsional): Case ID dari test_case_user_{userId}
- Jika null: ambil case terakhir

Output: Dataset containing:
{
  'base_cases': [CaseDTO, CaseDTO, ...],  // m kasus
  'test_case': CaseDTO,                    // 1 kasus uji
  'criteria': [
    {'column': '1_Demam', 'weight': 3, 'type': 'benefit'},
    {'column': '2_Nafsu_Makan', 'weight': 2, 'type': 'benefit'},
    {'column': '3_Umur', 'weight': 1, 'type': 'benefit'}
  ],
  'goal_map': {1: 'Flu', 2: 'Cacingan', 3: 'Flu'},
  'goal_column': '4_Diagnosis'
}

Implementasi File: app/Services/Inference/FuzzyTopsisService.php (line ~XX)

═══════════════════════════════════════════════════════════════
STEP 2: BUILD DECISION MATRIX (SIMILARITY SCORES)
═══════════════════════════════════════════════════════════════

Fungsi: DecisionMatrixService::build($baseCases, $testCase, $criteria)

Tujuan: Hitung kemiripan setiap base case vs test case per atribut

Formula:
- Atribut Numerik:
  similarity = 1.0 - (|base_value - test_value| / range)
  dimana range = MAX(all_values) - MIN(all_values)

- Atribut Kategorik (termasuk True/False):
  similarity = 1.0  (jika base_value == test_value)
  similarity = 0.0  (jika base_value != test_value)

Output: Decision Matrix m × n
[
  base_case_1 => [criteria_1 => 0.85, criteria_2 => 1.0, criteria_3 => 0.75],
  base_case_2 => [criteria_1 => 0.25, criteria_2 => 0.0, criteria_3 => 0.40],
  ...
]

Range dibangun dari: MIN(base_values + test_value) dan MAX(base_values + test_value)

Implementasi File: app/Services/Topsis/DecisionMatrixService.php (method: build)

═══════════════════════════════════════════════════════════════
STEP 3: FUZZIFIKASI (TRIANGULAR MEMBERSHIP FUNCTION)
═══════════════════════════════════════════════════════════════

[Ini menjawab Q2/Q5 secara teknis]

Fungsi: FuzzificationService::process($decisionMatrix, $spread = 0.1)

Tujuan: Konversi skor similarity (crisp) → Triangular Fuzzy Number (TFN)

Formula TFN:
TFN(x) = (a, b, c)
  a = MAX(0.0, x - spread)   // Lower bound
  b = x                        // Middle (original value)
  c = MIN(1.0, x + spread)   // Upper bound

Contoh (spread = 0.1):
- x = 0.85 → TFN = (0.75, 0.85, 0.95)
- x = 1.00 → TFN = (0.90, 1.00, 1.00)  // c di-clamp ke 1.0
- x = 0.00 → TFN = (0.00, 0.00, 0.10)  // a di-clamp ke 0.0

Mengapa spread = 0.1?
- Representasi ketidakpastian dalam skor similarity
- 0.1 = 10% tolerance dari nilai sebenarnya
- Configurable sesuai kebutuhan domain

Output: Fuzzy Matrix m × n
[
  base_case_1 => [criteria_1 => [0.75,0.85,0.95], ...],
  base_case_2 => [criteria_1 => [0.15,0.25,0.35], ...],
  ...
]

Implementasi File: app/Services/Fuzzy/FuzzificationService.php

═══════════════════════════════════════════════════════════════
STEP 4: DEFUZZIFIKASI (CENTROID METHOD)
═══════════════════════════════════════════════════════════════

Fungsi: DefuzzificationService::process($fuzzyMatrix)

Tujuan: Konversi kembali TFN → crisp values untuk TOPSIS

Formula Centroid:
crisp_value = (a + b + c) / 3

Contoh:
- TFN = (0.75, 0.85, 0.95) → crisp = (0.75+0.85+0.95)/3 = 0.8500
- TFN = (0.90, 1.00, 1.00) → crisp = (0.90+1.00+1.00)/3 = 0.9667
- TFN = (0.00, 0.00, 0.10) → crisp = (0.00+0.00+0.10)/3 = 0.0333

Output: Crisp Matrix m × n (sama struktur dengan input, tapi nilai sudah di-defuzzify)

Catatan: Mengapa fuzzify lalu defuzzify kembali?
Selama proses fuzzifikasi, sistem:
1. Menangkap ketidakpastian (range a–c)
2. Di langkah 5–8 (TOPSIS), bekerja dengan nilai yang lebih robust
3. Di akhir, hasil CC score sendiri sudah crisp

Implementasi File: app/Services/Fuzzy/DefuzzificationService.php

═══════════════════════════════════════════════════════════════
STEP 5-6: NORMALISASI & MATRIKS TERBOBOT
═══════════════════════════════════════════════════════════════

Fungsi: NormalizationService::calculate($crispMatrix, $weights)

Sub-Step 5A: Normalisasi Bobot
- Bobot input: w = [3, 2, 1] (dari atribut table)
- Normalized: w_normalized = [3/6, 2/6, 1/6] = [0.5, 0.3333, 0.1667]
- Tujuan: Semua bobot sum = 1.0

Sub-Step 5B: Vector Normalization (per kolom)
Rumus: r_ij = x_ij / √(Σ(x_ij²))

Denominator calculation:
- Kolom C1: √(0.9667² + 0.0333² + 0.9667²) = √1.8701 = 1.3675
- Kolom C2: √(0.9667² + 0.0333² + 0.0333²) = √0.9367 = 0.9678
- Kolom C3: √(0.8000² + 0.4000² + 0.6000²) = √1.1600 = 1.0770

Normalized matrix: r_ij = x_ij / denominator_j

Sub-Step 6: Weighted Matrix
Rumus: v_ij = w_j × r_ij

Contoh:
- v_11 = r_11 × w_1 = 0.7069 × 0.5000 = 0.3535
- v_12 = r_12 × w_2 = 0.9989 × 0.3333 = 0.3330
- v_13 = r_13 × w_3 = 0.7427 × 0.1667 = 0.1238

Output: Weighted Matrix v_ij

Implementasi File: app/Services/Topsis/NormalizationService.php (method: calculate)

═══════════════════════════════════════════════════════════════
STEP 7: SOLUSI IDEAL (A+ DAN A-)
═══════════════════════════════════════════════════════════════

Fungsi: IdealSolutionService::calculate($weightedMatrix, $types)

Untuk setiap kriteria j:
- Jika type[j] = 'benefit' (lebih tinggi lebih baik):
  * A+_j = MAX(v_ij)   // Positive ideal
  * A-_j = MIN(v_ij)   // Negative ideal

- Jika type[j] = 'cost' (lebih rendah lebih baik):
  * A+_j = MIN(v_ij)   // Positive ideal (flip!)
  * A-_j = MAX(v_ij)   // Negative ideal

Contoh dengan weighted matrix:
| Case | v_C1  | v_C2  | v_C3  |
|------|-------|-------|-------|
| K1   | 0.353 | 0.333 | 0.124 |
| K2   | 0.012 | 0.011 | 0.062 |
| K3   | 0.353 | 0.011 | 0.093 |

Semua benefit:
- A+ = [0.353, 0.333, 0.124]   // Maksimal per kolom
- A- = [0.012, 0.011, 0.062]   // Minimal per kolom

Output: Ideal solution (A+, A-)

Implementasi File: app/Services/Topsis/IdealSolutionService.php

═══════════════════════════════════════════════════════════════
STEP 8: EUCLIDEAN DISTANCE (D+ DAN D-)
═══════════════════════════════════════════════════════════════

Fungsi: DistanceService::calculate($weightedMatrix, $ideal)

Untuk setiap case i:
D+_i = √(Σ(v_ij - A+_j)²)    // Jarak ke solusi ideal positif
D-_i = √(Σ(v_ij - A-_j)²)    // Jarak ke solusi ideal negatif

Contoh:
Case K1:
D+ = √((0.353-0.353)² + (0.333-0.333)² + (0.124-0.124)²)
   = √(0 + 0 + 0) = 0.0000   // Sempurna! K1 = ideal

D- = √((0.353-0.012)² + (0.333-0.011)² + (0.124-0.062)²)
   = √(0.1164 + 0.1034 + 0.0038) = 0.4729

Interpretasi:
- D+ = 0 berarti case K1 sempurna (cocok dengan kriteria ideal)
- D- = 0.4729 berarti K1 jauh dari solusi terburuk (bagus!)

Output: D+ dan D- untuk setiap case

Implementasi File: app/Services/Topsis/DistanceService.php

═══════════════════════════════════════════════════════════════
STEP 9: CLOSENESS COEFFICIENT & RANKING
═══════════════════════════════════════════════════════════════

Fungsi: RankingService::rank($distances)

Rumus Closeness Coefficient (CC):
CC_i = D-_i / (D+_i + D-_i)

Range: 0 ≤ CC ≤ 1
- CC = 1.0: Case sempurna (D+ = 0, jadi D-/(0+D-) = 1)
- CC = 0.5: Seimbang antara ideal dan worst
- CC = 0.0: Case terburuk (D- = 0, jadi 0/(D++0) = 0)

Contoh:
- K1: CC = 0.4729 / (0.0000 + 0.4729) = 1.0000 ← Rank 1
- K3: CC = 0.3426 / (0.3231 + 0.3426) = 0.5147 ← Rank 2
- K2: CC = 0.0000 / (0.4729 + 0.0000) = 0.0000 ← Rank 3

Sorting:
1. Primary sort: CC descending (tertinggi di atas)
2. Secondary: D+ ascending (jika CC sama, lebih dekat ke ideal)
3. Tertiary: case_id ascending (untuk tie-breaking)

Output: Ranking list sorted by CC

Implementasi File: app/Services/Topsis/RankingService.php

═══════════════════════════════════════════════════════════════
STEP 10 (BONUS): EVALUASI CONFUSION MATRIX
═══════════════════════════════════════════════════════════════

[Menjawab bagian evaluasi model]

Fungsi: ConfusionMatrixService::evaluate($actual, $predicted)

Strategy: Top-1
- Rank 1 = Predicted Positive
- Rank > 1 = Predicted Negative
- Goal match test case = Actual Positive
- Goal tidak match = Actual Negative

Contoh:
| Rank | Case | CC    | Goal     | Test Goal | Match? | Pred | Label |
|------|------|-------|----------|-----------|--------|------|-------|
| 1    | K1   | 1.000 | Flu      | Flu       | Ya     | +    | TP    |
| 2    | K3   | 0.515 | Flu      | Flu       | Ya     | -    | FN    |
| 3    | K2   | 0.000 | Cacingan | Flu       | Tidak  | -    | TN    |

Confusion Matrix:
|              | Pred + | Pred - |
|--------------|--------|--------|
| Actual +     | TP=1   | FN=1   |
| Actual -     | FP=0   | TN=1   |

Metrics:
- Accuracy  = (TP+TN)/(TP+TN+FP+FN) = 2/3 = 66.67%
- Precision = TP/(TP+FP) = 1/1 = 100%
- Recall    = TP/(TP+FN) = 1/2 = 50%
- F1-Score  = 2×(P×R)/(P+R) = 2×(1.0×0.5)/1.5 = 66.67%

Implementasi File: app/Services/Evaluation/ConfusionMatrixService.php
```

#### 4.2.3 — Implementasi Kode

```markdown
4.2.3 Implementasi Kode dan Integrasi Sistem

A. SERVICE ARCHITECTURE

Kode Fuzzy TOPSIS tersebar di beberapa service file:

app/Services/Inference/
├─ FuzzyTopsisService.php          [ORCHESTRATOR - mengatur semua step]
│  └─ public function infer($input)  // Menjalankan 9-step pipeline

app/Services/Fuzzy/
├─ FuzzificationService.php         [STEP 3 - Fuzzifikasi]
├─ DefuzzificationService.php        [STEP 4 - Defuzzifikasi]
└─ MembershipFunctionService.php     [Helper - membership functions]

app/Services/Topsis/
├─ DecisionMatrixService.php         [STEP 2 - Similarity & Decision Matrix]
├─ NormalizationService.php          [STEP 5-6 - Normalisasi & Weighted]
├─ IdealSolutionService.php          [STEP 7 - A+ dan A-]
├─ DistanceService.php               [STEP 8 - D+ dan D-]
└─ RankingService.php                [STEP 9 - CC & Ranking]

app/Services/Evaluation/
└─ ConfusionMatrixService.php         [Evaluasi - TP/FP/TN/FN]

Dependency Flow (order eksekusi):
FuzzyTopsisService
  ├─→ DecisionMatrixService (STEP 2)
  ├─→ FuzzificationService (STEP 3)
  ├─→ DefuzzificationService (STEP 4)
  ├─→ NormalizationService (STEP 5-6)
  ├─→ IdealSolutionService (STEP 7)
  ├─→ DistanceService (STEP 8)
  ├─→ RankingService (STEP 9)
  └─→ ConfusionMatrixService (Evaluasi)

B. CONTROLLER & API

Endpoint: POST /fuzzy-topsis/infer
File: app/Http/Controllers/FuzzyTopsisController.php

Input JSON:
{
  "user_id": 1,
  "case_id": 10,           // optional
  "algorithm": "Fuzzy TOPSIS",
  "top_k": null,           // null = all results
  "include_intermediate": true,  // termasuk matriks intermediate
  "save_debug": true       // simpan JSON snapshot
}

Output JSON:
{
  "success": true,
  "user_id": 1,
  "test_case_id": 10,
  "ranking": [
    {
      "case_id": 5,
      "score": 0.8891,
      "rank": 1,
      "s_plus": 0.1020,
      "s_minus": 0.7870
    },
    ...
  ],
  "evaluation": {
    "tp": 1, "tn": 1, "fp": 0, "fn": 1,
    "accuracy": 0.6667,
    "precision": 1.0,
    "recall": 0.5,
    "f1": 0.6667
  },
  "intermediate": { ... },  // jika requested
  "debug_file": "storage/app/private/fuzzy_topsis/..."
}

Alternatif: Form submission di UI
File: resources/views/admin/menu/testCaseTambah.blade.php
- User isi atribut
- Klik tombol "Fuzzy TOPSIS"
- Auto-submit ke FuzzyTopsisService via ConsultationController
- Redirect ke /history dengan hasil

C. DATABASE SCHEMA

Tabel Dinamis per User:

case_user_{userId}
├─ case_id (INT, PK)
├─ user_id (INT)
├─ {atribut_id}_{atribut_name} (VARCHAR/DECIMAL) — columns dinamis
└─ ...

test_case_user_{userId}
├─ case_id (INT, PK)
├─ user_id (INT)
├─ algoritma (VARCHAR) — identifier algoritma mana yang digunakan
└─ {atribut_id}_{atribut_name} (VARCHAR/DECIMAL) — columns dinamis

inferensi_ft_user_{userId}  [Dibuat otomatis oleh FuzzyTopsisInference model]
├─ inf_id (INT, PK)
├─ case_id (VARCHAR)
├─ case_goal (VARCHAR)
├─ rule_id (VARCHAR)
├─ rule_goal (VARCHAR)
├─ match_value (DECIMAL) — similarity score
├─ score (DECIMAL) — CC score
├─ rank (INT)
├─ s_plus (DECIMAL)
├─ s_minus (DECIMAL)
├─ cocok (ENUM '0'/'1') — match dengan test goal
├─ user_id (INT)
└─ waktu (DECIMAL) — execution time
```

#### 4.2.4 — Evaluasi Model

Sudah dibahas di atas dalam STEP 10, letakkan bagian confusion matrix di sub-bab ini.

---

### 4.3 — PENGUJIAN DAN ANALISIS

#### 4.3.1 — Contoh Kasus Manual (Q2/Q5)

```markdown
4.3.1 Contoh Kasus Manual dan Verifikasi

[Ambil data dari file excel_fuzzy_topsis/]

Sistem Case: Diagnosa Penyakit Kucing Sakit

Dataset:
- 3 atribut kriteria: Demam (T/F), Nafsu_Makan (Baik/Buruk), Umur (tahun)
- 3 base cases: K1, K2, K3
- 1 test case
- Goal: Diagnosa penyakit

[Insert tabel lengkap dari 01_data_input.csv]

Perhitungan Manual Step-by-Step:
[Insert semua 7 CSV step]

Hasil Akhir:
- K1: CC = 1.0000 (Rank 1) — Match dengan Flu ✓
- K3: CC = 0.5147 (Rank 2) — Match dengan Flu ✓
- K2: CC = 0.0000 (Rank 3) — Beda (Cacingan) ✗

Evaluasi Confusion Matrix:
- TP = 1, FN = 1, TN = 1, FP = 0
- Accuracy = 66.67%, Precision = 100%, Recall = 50%, F1 = 66.67%

Verifikasi:
Hitung manual di Excel → Bandingkan dengan output DutaShell web
Jika CC score sama → Algoritma bekerja dengan benar!
```

#### 4.3.2 — Testing pada DutaShell Web

```markdown
4.3.2 Pengujian Sistem pada DutaShell Web

Prosedur Testing:

1. Setup Data di Database
   - CREATE TABLE case_user_1, test_case_user_1, inferensi_ft_user_1
   - INSERT base cases (K1, K2, K3)
   - INSERT test case (Test)

2. Eksekusi via UI
   - Navigate ke /consultation
   - Buat test case baru dengan nilai:
     * Demam: True
     * Nafsu_Makan: Buruk
     * Umur: 4
   - Pilih tombol "Fuzzy TOPSIS"
   - Submit

3. Verifikasi Output
   - Check /history page
   - Tabel ranking harus menampilkan:
     * Case K1: CC = 1.0000, Rank 1
     * Case K3: CC = 0.5147, Rank 2
     * Case K2: CC = 0.0000, Rank 3
   - Confusion Matrix harus menunjukkan:
     * Accuracy = 66.67%
     * Precision = 100%
     * Recall = 50%
     * F1 = 66.67%

4. Validasi Database
   - Check tabel inferensi_ft_user_1
   - Verify semua row terinsert dengan benar
   - CC score match dengan hasil di UI

Test Result: ✓ PASS (jika hasil cocok dengan manual calculation)
```

---

### 4.4 — PEMBAHASAN

#### 4.4.1 — Menjawab Pertanyaan Penelitian

```markdown
4.4.1 Menjawab Pertanyaan Penelitian

[Ini adalah bagian di mana Anda menjawab Q3-Q7 dari Dosen dengan perspektif
pembahasan yang lebih mendalam, bukan hanya FAQ]

Pertanyaan 1: Mengapa Menggabungkan Fuzzy dengan TOPSIS?

Jawaban Analitis:
- TOPSIS murni mengasumsikan skor similarity PASTI (crisp)
- Dalam praktik CBR, terutama di domain medis/diagnosa:
  * Gejala subjektif ("agak demam" vs "demam tinggi")
  * Data sensor berfluktuasi (±0.5°C)
  * Skor kemiripan 0.85 belum tentu 100% akurat
- Fuzzy meng-capture ketidakpastian ini via TFN (range, bukan titik)
- Hasil: Ranking lebih ROBUST dan STABLE

Studi Kasus Contoh:
Jika ada noise di input, misalkan similarity K1 turun dari 0.85 → 0.80:
- TOPSIS murni: Ranking bisa berubah drastis (rank reversal)
- Fuzzy TOPSIS: TFN (0.75,0.85,0.95) vs (0.70,0.80,0.90) → relatif stabil

Kesimpulan: Penggabungan diperlukan untuk robustness & menangani uncertainty.

Pertanyaan 2: Fuzzy Saja atau TOPSIS Saja?

Jawaban Teknis:
a) Fuzzy Saja:
   - Bisa fuzzify skor, tapi kemudian apa?
   - Tidak ada mekanisme ranking multi-kriteria
   - Tidak bisa memberi bobot berbeda per atribut
   - Kesimpulan: Tidak praktis

b) TOPSIS Saja:
   - Bisa jalan dan menghasilkan ranking
   - Tapi sensitif terhadap noise & outlier
   - Lebih rawan terhadap rank reversal
   - Kesimpulan: Bekerja tapi kurang optimal

c) Fuzzy TOPSIS:
   - Gabungan optimal untuk permasalahan MCDM dengan uncertainty
   - Perspektif ganda (D+/D-) memberikan insight lebih
   - Kesimpulan: Solusi terbaik untuk kasus ini

Tabel Perbandingan Performansi (Opsional—jika ada eksperimen):
| Metrik | Fuzzy TOPSIS | TOPSIS | CBR Biasa |
|--------|-------------|--------|-----------|
| Robustness | ★★★★★ | ★★★☆☆ | ★★☆☆☆ |
| Scalability | ★★★★☆ | ★★★★☆ | ★★★☆☆ |
| Interpretability | ★★★★☆ | ★★★★☆ | ★★★☆☆ |
| Handling Uncertainty | ★★★★★ | ★★☆☆☆ | ★★☆☆☆ |

Pertanyaan 3: Perbandingan dengan Fuzzy AHP & Alternatif Lain

Jawaban Komparatif:
[Lihat tabel Q6 dari ANALISIS_FUZZY_TOPSIS.md]

Alasan Memilih Fuzzy TOPSIS:
1. Scalability: DutaShell support berapapun atribut
   - AHP: 10 atribut = 45 perbandingan berpasangan (beban user)
   - TOPSIS: Hanya 1 bobot per atribut (ringan)

2. Perspective: TOPSIS perspektif ganda (D+/D-)
   - Lebih komprehensif dibanding SAW (weighted sum saja)

3. Transparency: CC score mudah dipahami (0–1)
   - Lebih intuitif daripada indeks AHP kompleks

4. Integration: Natural fit dengan CBR similarity calculation
   - Output similarity langsung jadi input TOPSIS

Pertanyaan 4: Atribut Teks/Deskriptif?

Jawaban Teknis:
[Lihat Q7 dari ANALISIS_FUZZY_TOPSIS.md]

Implementasi Saat Ini:
- DutaShell menggunakan atribut_value sebagai pilihan terbatas
- User tidak bisa input teks bebas
- Sehingga exact match (0/1) sudah memadai

Solusi untuk Teks Bebas (Future Enhancement):
1. Ordinal Similarity (untuk kategori bertingkat)
   Formula: sim = 1 - |rank_a - rank_b| / max_rank

2. Jaccard Similarity (untuk teks bebas)
   Formula: sim = |A ∩ B| / |A ∪ B|

Implementasi bisa dilakukan di DecisionMatrixService::similarityScore()
tanpa mengubah rest of pipeline Fuzzy TOPSIS.

Kesimpulan Pertanyaan Penelitian:
Fuzzy TOPSIS dipilih karena:
✓ Menangani ketidakpastian (Fuzzy)
✓ Ranking multi-kriteria (TOPSIS)
✓ Scalable untuk atribut dinamis
✓ Perspektif ganda (D+/D-)
✓ Transparent & interpretable
✓ Modular & extensible
```

#### 4.4.2 — Kelebihan & Keterbatasan

```markdown
4.4.2 Kelebihan dan Keterbatasan

KELEBIHAN FUZZY TOPSIS:

1. Robustness terhadap Noise
   - TFN range mengurangi dampak outlier & fluktuasi data
   - Rank reversal terbatas dibanding TOPSIS murni

2. Multi-Kriteria Handling
   - Support multiple attributes dengan bobot berbeda
   - Flexible (benefit/cost per atribut)

3. Transparency
   - CC score mudah dijelaskan ke user
   - D+ dan D- memberikan insight kenapa ranked begitu

4. Flexibility Bobot
   - User bisa set weight per atribut
   - Auto normalization ke 1.0

5. Scalability
   - Tidak ada matriks perbandingan (seperti AHP)
   - Works dengan berapapun jumlah atribut

KETERBATASAN:

1. Asumsi Triangular Membership Function
   - TFN dengan spread ±0.1 mungkin tidak universal
   - Perlu tuning per domain

2. Linear Similarity Calculation
   - Numerik: hanya range-based normalization
   - Tidak capture non-linear relationship

3. Top-1 Strategy untuk Evaluasi
   - Hanya rank-1 yang dievaluasi
   - Multiple correct answers diabaikan (jika ada K1 dan K3 sama-sama Flu)

4. Categorical Handling
   - Exact match (0 atau 1) mungkin terlalu kaku
   - Misal: "Putih" vs "Putih keabuan" = 0.0 (padahal mirip)
   - Solusi: Implementasi Jaccard/Cosine untuk teks

5. No Temporal Factor
   - Tidak mempertimbangkan freshness kasus atau recency
   - Sama-sama menganggap semua case dalam database

6. Single Threshold untuk Fuzzification
   - spread = 0.1 fixed untuk semua atribut
   - Ideal ada spread berbeda per atribut

SARAN PERBAIKAN:

Jangka Pendek:
1. Implement ordinal similarity untuk atribut bertingkat
2. Per-attribute spread configuration
3. Alternative defuzzification methods (bisection, maximum)

Jangka Menengah:
1. Add Jaccard/Cosine similarity untuk teks panjang
2. Implement case recency/freshness weighting
3. Adaptive weights based on historical performance

Jangka Panjang:
1. Hybrid dengan ML models (SVM, Random Forest)
2. Learning weights dari training data (optimization)
3. Multi-label classification (case bisa multi-goal)
```

#### 4.4.3 — Kontribusi Penelitian

```markdown
4.4.3 Kontribusi Penelitian

KONTRIBUSI ILMIAH:

1. Implementasi Fuzzy TOPSIS di Konteks CBR
   - Mayoritas paper fokus pada manufacturing, supply chain, medical diagnosis
   - Implementasi CBR sistem pakar dengan Fuzzy TOPSIS relatif novel
   - Terutama di konteks domain-independent (bisa ganti domain)

2. Custom PHP Implementation
   - Tidak ada library Fuzzy TOPSIS siap pakai di PHP
   - Implementasi custom dari scratch menunjukkan deep understanding
   - Bisa digunakan untuk educational & practical purposes

3. Dynamic Schema Architecture
   - Dynamic table creation per user (case_user_{id}, test_case_user_{id})
   - Memungkinkan multi-tenant SaaS dengan isolated data
   - Innovative untuk sistem pakar berbasis cloud

4. Integration dengan Laravel Framework
   - Fuzzy TOPSIS di Laravel ecosystem masih jarang
   - Complete implementation dari UI → Service → Database
   - Reusable untuk future projects

5. Evaluation Framework
   - Confusion matrix integration untuk evaluasi akurasi
   - Metrik Accuracy, Precision, Recall, F1 untuk sistem pakar
   - Quantitative evidence untuk performance

NOVELTY ASPECTS:

✓ Full-stack Fuzzy TOPSIS implementation dalam Laravel
✓ Support untuk arbitrary domain (kucing, ikan, penyakit, dll)
✓ Dynamic multi-criteria definition per user
✓ Web-based interface untuk non-technical users
✓ Combination of Fuzzy + TOPSIS + CBR dalam satu framework
✓ Comprehensive evaluation dengan confusion matrix

POTENSI PUBLIKASI:

- Jurnal: Soft Computing, Fuzzy Sets and Systems, atau Applied Intelligence
- Conference: IEEE Fuzzy, ICMCDM, atau AI/ML track conference
- Workshop: CBR systems, Expert Systems, MCDM applications
```

---

## RINGKASAN MAPPING 8 PERTANYAAN DOSEN KE BAB IV

| No | Pertanyaan | Sub-Bab | Konten |
|----|-----------|---------|--------|
| 1,8 | Atribut mana yang difuzzifikasi? | 4.2.1 | Penjelasan atribut goal vs kriteria, alasan diff |
| 2,5 | Contoh kasus manual? | 4.3.1 | Full 9-step calculation dengan angka konkret |
| 3 | Kenapa Fuzzy + TOPSIS? | 4.4.1 | Comparative analysis & justification |
| 4 | Fuzzy saja vs TOPSIS saja? | 4.4.1 | Skenario & tradeoff analysis |
| 6 | Vs Fuzzy AHP dll? | 4.4.1 | Tabel perbandingan & alasan selection |
| 7 | Atribut teks/deskriptif? | 4.4.1 | Solusi current & future enhancement |
| (impl) | Step-by-step teknis | 4.2.2 | Detailed 9-step algorithm dengan formula |
| (testing) | Pembuktian hasil | 4.3.2 | Testing procedure & validation |

---

## STRUKTUR FILE UNTUK README CLAUDE WEB

Ketika Anda copy-paste prompt ini ke Claude Web, berikan instruksi ini:

```markdown
# PROMPT UNTUK CLAUDE WEB

Buatkan saya BAB IV - IMPLEMENTASI DAN PEMBAHASAN untuk skripsi
tentang "Fuzzy TOPSIS sebagai Case Retrieval Engine di Sistem Pakar CBR DutaShell".

## DATA YANG SUDAH SAYA MILIKI:

1. **Algoritma Detail**: [ANALISIS_FUZZY_TOPSIS.md](reference)
2. **Contoh Kasus Manual**: [CSV files dari excel_fuzzy_topsis/](reference)
3. **Kode Implementasi**: Laravel + PHP custom Fuzzy TOPSIS
4. **Struktur Panduan**: [PANDUAN_BAB_IV_IMPLEMENTASI.md](reference)

## YANG SAYA BUTUHKAN:

Buatkan struktur BAB IV dengan sub-bab:

### 4.1 Implementasi Awal
- 4.1.1 Analisis Kebutuhan Sistem
- 4.1.2 Desain Arsitektur CBR + Fuzzy TOPSIS
- 4.1.3 Pemilihan Teknologi

### 4.2 Implementasi Sistem
- 4.2.1 Persiapan Data & Atribut (jawab Q1/Q8)
- 4.2.2 Pipeline Fuzzy TOPSIS (9 step lengkap dengan formula)
- 4.2.3 Implementasi Kode (architecture, controller, database)
- 4.2.4 Evaluasi Model (confusion matrix)

### 4.3 Pengujian dan Analisis
- 4.3.1 Contoh Kasus Manual (gunakan data dari CSV + verifikasi)
- 4.3.2 Testing pada DutaShell Web (prosedur & validasi)

### 4.4 Pembahasan
- 4.4.1 Menjawab Pertanyaan Penelitian (Q3-Q7 dengan analisa mendalam)
- 4.4.2 Kelebihan & Keterbatasan (plus saran improvement)
- 4.4.3 Kontribusi Penelitian (novelty aspects)

## FORMAT YANG DIINGINKAN:

- Profesional & academic tone
- Gunakan terminology teknis yang tepat
- Include formula/pseudocode dimana relevan
- Reference ke file kode yang beneran ada
- Tambahkan tabel & diagram dimana perlu
- Minimal 15-20 halaman (bukan template kosong!)

## HINDARI:

- Template generik "Lorem ipsum"
- Penjelasan Fuzzy TOPSIS dari textbook (sudah ada di BAB 3)
- Repetisi dengan BAB 3

## OUTPUT:

Markdown format siap di-convert ke Word/PDF untuk skripsi.
```

Atau langsung saja minimal kasih saya outline lengkap BAB IV
yang bisa saya expand sendiri nanti.
```

---

**Panduan ini siap digunakan untuk presentasi ke dosen & guidance untuk BAB IV!**

Gunakan sebagai referensi ketika menulis bab implementasi.
