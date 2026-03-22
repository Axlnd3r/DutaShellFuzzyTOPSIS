# DutaShell - Sistem Pakar Multi-Algoritma dengan Fuzzy TOPSIS

Aplikasi berbasis Laravel untuk Case-Based Reasoning (CBR) dengan dukungan multi-algoritma inferensi, termasuk implementasi **Hybrid Fuzzy-TOPSIS** sebagai Case Retrieval Engine.

[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-blue)](https://www.php.net/)
[![Laravel Version](https://img.shields.io/badge/Laravel-11.9-red)](https://laravel.com)

## Fitur Utama

- **9 Algoritma Inferensi**: Matching Rule, Forward Chaining, Backward Chaining, Hybrid Similarity, Jaccard Similarity, Cosine Similarity, Fuzzy TOPSIS, SVM, Random Forest
- **Fuzzy TOPSIS**: Case Retrieval Engine berbasis Multi-Criteria Decision Making (MCDM)
- **Confusion Matrix & Evaluasi**: TP, FP, TN, FN, Accuracy, Precision, Recall, F1-Score
- **Ranking Kasus**: Closeness Coefficient (CC) dengan visualisasi ranking
- Multi-kernel SVM (Linear/SGD, RBF, Sigmoid)
- Antarmuka web untuk manajemen atribut, data, training, dan prediksi

---

## Blueprint Metodologi Penelitian (BAB 3)

### 3.1 Gambaran Umum Sistem

DutaShell adalah sistem pakar berbasis **Case-Based Reasoning (CBR)** yang menggunakan pendekatan **Hybrid Fuzzy-TOPSIS** sebagai Case Retrieval Engine. Sistem ini dirancang untuk melakukan **ranking kasus** dari database kasus yang ada, menghasilkan rekomendasi yang lebih akurat, objektif, dan transparan dibandingkan pendekatan similarity tradisional.

### 3.2 Arsitektur Sistem

```
┌─────────────────────────────────────────────────────────┐
│                     DutaShell System                     │
├─────────────┬───────────────────────┬───────────────────┤
│  Input      │  Processing Engine    │  Output           │
│  Layer      │                       │  Layer            │
│             │  ┌─────────────────┐  │                   │
│  Database   │  │ Forward/Backward│  │  Ranking Kasus    │
│  Kasus  ───►│  │ Chaining        │  │  (CC Score)       │
│             │  └─────────────────┘  │                   │
│  Test Case  │  ┌─────────────────┐  │  Rekomendasi      │
│  (User   ───►│  │ Hybrid Fuzzy-  │──►│  Kasus Terbaik   │
│  Input)     │  │ TOPSIS Engine   │  │                   │
│             │  └─────────────────┘  │  Confusion Matrix │
│  Atribut &  │  ┌─────────────────┐  │  (Evaluasi)       │
│  Bobot   ───►│  │ ML Algorithms  │  │                   │
│             │  │ (SVM, RF)       │  │  Accuracy,        │
│             │  └─────────────────┘  │  Precision,       │
│             │                       │  Recall, F1       │
└─────────────┴───────────────────────┴───────────────────┘
```

**Posisi Fuzzy TOPSIS dalam arsitektur:**
- Bertindak sebagai **modul inferensi tambahan** (Case Retrieval Engine)
- **Tidak menggantikan** Forward/Backward Chaining
- Input berasal dari database kasus
- Output berupa **Closeness Coefficient (CC)** dan **ranking kasus**

### 3.3 Struktur Data

#### A. Data Kasus (Case Base)
| Field | Tipe | Keterangan |
|-------|------|------------|
| `case_id` | INT | Primary key, auto-increment |
| `{atribut_id}_{atribut_name}` | VARCHAR | Kolom dinamis per atribut |
| `goal` | VARCHAR | Kolom target/goal (atribut dengan flag T) |
| `user_id` | INT | Foreign key ke tabel user |

#### B. Struktur Kriteria
Setiap kriteria memiliki:
- `nama_kriteria` - Nama atribut dari tabel `atribut`
- `tipe` - Benefit atau Cost (kolom `type` di tabel `atribut`)
- `bobot (weight)` - Bobot kepentingan (kolom `weight` di tabel `atribut`)
- `tipe_data` - Numeric (→ fuzzy) atau Categorical (→ langsung)

#### C. Triangular Fuzzy Number (TFN)
Setiap atribut numerik dikonversi menjadi:
```
TFN = (a, b, c)
dimana:
  a = max(0, x - spread)   → lower bound
  b = x                     → middle (nilai asli)
  c = min(1, x + spread)   → upper bound
  spread = 0.1 (default)
```

### 3.4 Pipeline Algoritma Fuzzy TOPSIS

Pipeline dieksekusi secara **berurutan** dengan 9 langkah:

#### STEP 1 — Ambil Data Kasus
```
Load semua case dari database → Bentuk matriks keputusan X (m x n)
  m = jumlah kasus (base cases)
  n = jumlah kriteria (non-goal attributes)
```
**Implementasi**: `FuzzyTopsisService::loadCases()`

#### STEP 2 — Bangun Matriks Keputusan
```
Untuk setiap base case vs test case:
  Numerik: similarity = 1.0 - (|base - test| / range)
  Kategorikal: similarity = 1.0 (exact match) / 0.0 (tidak match)
```
**Implementasi**: `DecisionMatrixService::build()`

#### STEP 3 — Fuzzifikasi (Triangular Membership Function)
```
Konversi nilai crisp → Triangular Fuzzy Number (TFN):
  TFN(x) = (max(0, x-0.1), x, min(1, x+0.1))

Tujuan: Menangani boundary values dan ketidakpastian data
```
**Implementasi**: `FuzzificationService::process()`

#### STEP 4 — Defuzzifikasi
```
Ubah TFN → nilai crisp:
  defuzz = (a + b + c) / 3
```
**Implementasi**: `DefuzzificationService::process()`

#### STEP 5 — Normalisasi Matriks (Vector Normalization)
```
rij = xij / sqrt(sum(xij^2))

Tujuan: Menghilangkan perbedaan skala antar atribut
```
**Implementasi**: `NormalizationService::calculate()`

#### STEP 6 — Matriks Terbobot
```
vij = wj * rij

dimana wj = bobot kriteria j (sudah dinormalisasi, sum = 1.0)
```
**Implementasi**: `NormalizationService::calculate()` (bagian weighted)

#### STEP 7 — Tentukan Solusi Ideal
```
Positive Ideal Solution (A+):
  Benefit: max(vij)
  Cost:    min(vij)

Negative Ideal Solution (A-):
  Benefit: min(vij)
  Cost:    max(vij)
```
**Implementasi**: `IdealSolutionService::calculate()`

#### STEP 8 — Hitung Jarak Euclidean
```
D+ = sqrt(sum((vij - Aj+)^2))  → jarak ke solusi ideal positif
D- = sqrt(sum((vij - Aj-)^2))  → jarak ke solusi ideal negatif
```
**Implementasi**: `DistanceService::calculate()`

#### STEP 9 — Closeness Coefficient (CC) & Ranking
```
CC = D- / (D+ + D-)

Rules:
  - Range: 0 - 1
  - Semakin dekat ke 1 → semakin baik
  - Sort descending → case teratas = rekomendasi utama
```
**Implementasi**: `RankingService::rank()`

### 3.5 Diagram Alir Pipeline

```
┌──────────────┐
│ Load Cases   │  Step 1: Ambil data dari DB
│ from DB      │
└──────┬───────┘
       ▼
┌──────────────┐
│ Build        │  Step 2: Matriks keputusan (similarity)
│ Decision     │
│ Matrix       │
└──────┬───────┘
       ▼
┌──────────────┐
│ Fuzzifikasi  │  Step 3: Crisp → TFN (a, b, c)
│ (TMF)        │
└──────┬───────┘
       ▼
┌──────────────┐
│ Defuzzifikasi│  Step 4: TFN → Crisp = (a+b+c)/3
└──────┬───────┘
       ▼
┌──────────────┐
│ Normalisasi  │  Step 5: Vector normalization
│ Vektor       │
└──────┬───────┘
       ▼
┌──────────────┐
│ Matriks      │  Step 6: vij = wj × rij
│ Terbobot     │
└──────┬───────┘
       ▼
┌──────────────┐
│ Solusi Ideal │  Step 7: A+ dan A-
│ (A+, A-)     │
└──────┬───────┘
       ▼
┌──────────────┐
│ Jarak        │  Step 8: D+ dan D-
│ Euclidean    │
└──────┬───────┘
       ▼
┌──────────────┐
│ CC & Ranking │  Step 9: CC = D-/(D++D-), sort desc
└──────┬───────┘
       ▼
┌──────────────┐
│ Evaluasi     │  Confusion Matrix, Accuracy, Precision,
│ (Opsional)   │  Recall, F1-Score
└──────────────┘
```

### 3.6 Evaluasi Model - Confusion Matrix

#### Definisi Label
| Label | Definisi |
|-------|----------|
| **True Positive (TP)** | Kasus rank-1 memiliki goal yang sama dengan test case |
| **False Positive (FP)** | Kasus rank-1 memiliki goal berbeda dengan test case |
| **True Negative (TN)** | Kasus non-rank-1 memang memiliki goal berbeda |
| **False Negative (FN)** | Kasus non-rank-1 seharusnya match tapi tidak di rank-1 |

#### Konversi Ranking → Klasifikasi
```
Strategy: Top-1
  - Kasus rank-1 = Predicted Positive
  - Kasus rank > 1 = Predicted Negative
  - Goal match dengan test case = Actual Positive
  - Goal tidak match = Actual Negative
```

#### Rumus Evaluasi
```
Accuracy  = (TP + TN) / (TP + TN + FP + FN)
Precision = TP / (TP + FP)
Recall    = TP / (TP + FN)
F1-Score  = 2 × TP / (2 × TP + FP + FN)
```

**Implementasi**: `ConfusionMatrixService::evaluate()`

### 3.7 Prinsip Desain Model

| Prinsip | Penjelasan |
|---------|------------|
| **Domain-independent** | Model tidak bergantung pada domain spesifik (stroke, diabetes, dll) |
| **Fuzzy = Preprocessing** | Fuzzy hanya untuk menangani ketidakpastian data, bukan ranking |
| **TOPSIS = Ranking Engine** | TOPSIS adalah engine utama untuk ranking kasus |
| **CC = Similarity Baru** | Closeness Coefficient menggantikan similarity CBR klasik |
| **Tanpa freshness/success rate** | Fokus pada atribut multi-kriteria dan uncertainty handling |
| **Modular & extensible** | Mudah ditambahkan ke berbagai domain DutaShell |

### 3.8 Teknologi Implementasi

| Komponen | Teknologi |
|----------|-----------|
| Backend Framework | Laravel 11.9 (PHP 8.2+) |
| Database | MySQL/MariaDB |
| Frontend | Blade Templates, Bootstrap 5, Vite |
| ML Libraries | Rubix ML (SVM, Random Forest) |
| Fuzzy TOPSIS | Implementasi custom PHP |

### 3.9 Pemetaan File → Pipeline

| Step | File Implementasi | Keterangan |
|------|-------------------|------------|
| Orchestrator | `app/Services/Inference/FuzzyTopsisService.php` | Mengatur keseluruhan pipeline |
| Step 1 | `FuzzyTopsisService::loadCases()` | Load data dari DB |
| Step 2 | `app/Services/Topsis/DecisionMatrixService.php` | Matriks keputusan |
| Step 3 | `app/Services/Fuzzy/FuzzificationService.php` | Fuzzifikasi TFN |
| Step 4 | `app/Services/Fuzzy/DefuzzificationService.php` | Defuzzifikasi |
| Step 5-6 | `app/Services/Topsis/NormalizationService.php` | Normalisasi & bobot |
| Step 7 | `app/Services/Topsis/IdealSolutionService.php` | Solusi ideal A+ A- |
| Step 8 | `app/Services/Topsis/DistanceService.php` | Jarak Euclidean |
| Step 9 | `app/Services/Topsis/RankingService.php` | CC & ranking |
| Evaluasi | `app/Services/Evaluation/ConfusionMatrixService.php` | Confusion matrix |
| Model | `app/Models/FuzzyTopsisInference.php` | Tabel `inferensi_ft_user_{id}` |
| Controller | `app/Http/Controllers/FuzzyTopsisController.php` | API endpoint |
| View | `resources/views/admin/menu/inferensi.blade.php` | Tampilan hasil |

---

## Persyaratan

- PHP 8.2+
- Composer
- MySQL atau MariaDB
- Node.js dan npm

## Instalasi Singkat

### Menggunakan Laravel Herd (Direkomendasikan)

1. Pastikan Laravel Herd sudah terinstal
2. Import folder proyek ke Herd
3. Beri nama situs (misalnya: dutashell.test)
4. Tambahkan situs baru dan jalankan melalui Herd

### Alternatif: Menggunakan Artisan Serve

```bash
# Clone repository
git clone <repository-url>
cd DutaShellNew

# Install dependencies
composer install
npm install

# Setup environment
cp .env.example .env
php artisan key:generate

# Konfigurasi database di .env
# DB_DATABASE=expertt
# DB_USERNAME=username
# DB_PASSWORD=password

# Import SQL schema
mysql -u username -p expertt < expertt.sql

# Build assets
npm run build

# Jalankan server
php artisan serve
```

## Cara Menggunakan Fuzzy TOPSIS

1. **Setup Atribut**: Menu Attribute Management. Tandai satu atribut sebagai goal (T). Set `weight` dan `type` (benefit/cost) untuk setiap kriteria.
2. **Input Data Kasus**: Menu Generate Case. Masukkan base cases (training data).
3. **Konsultasi**: Menu Consultation → Add New.
   - Isi nilai atribut test case.
   - Klik tombol **Fuzzy TOPSIS** di grup "Multi-Criteria Decision Making".
4. **Lihat Hasil**: Otomatis redirect ke halaman Inference.
   - Ranking kasus dengan CC score dan progress bar visual.
   - Confusion Matrix dengan metrik Accuracy, Precision, Recall, F1-Score.
   - Tabel history semua algoritma yang pernah dijalankan.

## Konfigurasi SVM (.env)

```env
SVM_SCRIPT=scripts/decision-tree/SVM.php
SVM_INFER_SCRIPT=scripts/decision-tree/SVMInfer.php
SVM_SAVE_MODEL=1
SVM_TEST_RATIO=0.3
SVM_THRESHOLD=0.0
SVM_SPLIT_SEED=42
```

## Struktur Project

```
DutaShellNew/
├── app/
│   ├── DTO/
│   │   ├── CaseDTO.php               # Data Transfer Object kasus
│   │   └── RankingResultDTO.php       # DTO hasil ranking
│   ├── Http/Controllers/
│   │   ├── ConsultationController.php # Handler konsultasi & algoritma
│   │   ├── FuzzyTopsisController.php  # API Fuzzy TOPSIS
│   │   ├── InferenceController.php    # Evaluasi inferensi
│   │   └── ...
│   ├── Models/
│   │   ├── FuzzyTopsisInference.php   # Model tabel inferensi_ft_user
│   │   ├── Atribut.php                # Atribut/kriteria
│   │   ├── CaseUser.php               # Base cases
│   │   ├── Consultation.php           # Test cases
│   │   └── ...
│   └── Services/
│       ├── Inference/
│       │   └── FuzzyTopsisService.php # Orchestrator pipeline
│       ├── Fuzzy/
│       │   ├── FuzzificationService.php        # Step 3: TFN
│       │   ├── DefuzzificationService.php      # Step 4: Defuzz
│       │   └── MembershipFunctionService.php   # Triangular MF
│       ├── Topsis/
│       │   ├── DecisionMatrixService.php       # Step 2: Matriks
│       │   ├── NormalizationService.php        # Step 5-6
│       │   ├── IdealSolutionService.php        # Step 7
│       │   ├── DistanceService.php             # Step 8
│       │   └── RankingService.php              # Step 9
│       └── Evaluation/
│           └── ConfusionMatrixService.php      # Evaluasi
├── resources/views/admin/menu/
│   ├── testCaseTambah.blade.php       # Form konsultasi
│   ├── testCase.blade.php             # Daftar test case
│   └── inferensi.blade.php            # Hasil & evaluasi
├── routes/web.php                     # Definisi route
├── storage/app/private/fuzzy_topsis/  # Debug JSON snapshots
└── scripts/decision-tree/             # CLI scripts SVM
```

## Dokumentasi Tambahan

- `docs/INDEX.md` - Peta dokumentasi
- `docs/README.md` - Ringkasan sistem & konfigurasi
- `docs/user-guide.md` - Panduan penggunaan
- `docs/api-endpoints.md` - Dokumentasi API

---

**Made by**: 71220853 - Matthew Alexander
