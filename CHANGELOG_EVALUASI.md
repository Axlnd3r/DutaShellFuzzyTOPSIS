# CHANGELOG - Fitur Evaluasi Perbandingan Algoritma

## Tanggal: Maret 2026

### File yang Dibuat (Baru)

| File | Deskripsi |
|------|-----------|
| `app/Http/Controllers/EvaluationController.php` | Controller utama untuk evaluasi batch 5 skenario. Menjalankan 4 algoritma (Fuzzy TOPSIS, Hybrid Similarity, Jaccard, Cosine) secara bersamaan dengan train/test split yang deterministic (seed-based). |
| `resources/views/admin/menu/evaluation.blade.php` | Halaman UI evaluasi perbandingan 4 algoritma. Menampilkan tabel perbandingan side-by-side, confusion matrix multi-class per skenario per algoritma, per-class metrics (precision/recall/F1), dan kesimpulan otomatis. |

### File yang Dimodifikasi

| File | Perubahan |
|------|-----------|
| `routes/web.php` | Ditambahkan 2 route: `GET /evaluation` dan `POST /evaluation/run`. Ditambahkan import `EvaluationController`. |
| `resources/views/layouts/partials/sidebar.blade.php` | Ditambahkan menu "Evaluasi Perbandingan" dengan icon `fa-chart-bar` di sidebar navigasi. |

---

## Fitur yang Diimplementasikan

### 1. Evaluasi Batch 5 Skenario Train/Test Split
- **80/20** (160 train / 40 test untuk Drug200)
- **70/30** (140 train / 60 test)
- **60/40** (120 train / 80 test)
- **50/50** (100 train / 100 test)
- **40/60** (80 train / 120 test)
- Split menggunakan **seed** yang konsisten sehingga hasil dapat direproduksi.
- Menggunakan **Option B**: `case_user_{id}` sebagai pool training, `test_case_user_{id}` sebagai fixed test set yang selalu masuk ke test.

### 2. Algoritma yang Dievaluasi (4 Algoritma)
1. **Fuzzy TOPSIS** - Pipeline lengkap: Decision Matrix -> Fuzzification (TFN) -> Defuzzification -> Normalization -> Weighted -> Ideal Solution -> Distance (D+/D-) -> CC Ranking
2. **Hybrid Similarity** - Kombinasi Cosine + Jaccard dengan Entropy-based attribute weighting, alpha=0.5
3. **Jaccard Similarity** - Jaccard murni tanpa bobot: `matches / (2n - matches)`, diambil dari `HybridSimController::computeScore()`
4. **Cosine Similarity** - Cosine murni tanpa bobot: `matches / n`, diambil dari `HybridSimController::computeScore()`

### 3. Confusion Matrix Multi-Class
- Mendukung **5 kelas** (drugA, drugB, drugC, drugX, DrugY) untuk Drug200
- Per-class metrics: **Precision, Recall, F1-Score, Support**
- Macro-average untuk ringkasan keseluruhan
- Visualisasi warna: hijau (benar), merah (salah), intensitas berdasarkan frekuensi

### 4. Tabel Perbandingan
- Side-by-side comparison 4 algoritma untuk setiap skenario
- Color-coded header per algoritma (biru=FT, merah=HS, ungu=JC, oranye=CS)
- Highlight pemenang per skenario (accuracy tertinggi)
- Rata-rata keseluruhan dengan penentuan pemenang otomatis
- Waktu eksekusi per skenario

### 5. Dashboard Kesimpulan
- Skor kemenangan: berapa kali masing-masing dari 4 algoritma menang
- Deteksi seri (jika beberapa algoritma memiliki accuracy sama)
- Kesimpulan teks otomatis berdasarkan data kuantitatif
- Ringkasan rata-rata akurasi semua algoritma

---

## Cara Penggunaan

1. Login ke DutaShell (pastikan sudah ada dataset di `case_user_{user_id}` dan `test_case_user_{user_id}`)
2. Klik menu **Evaluasi Perbandingan** di sidebar
3. Atur seed (default: 42) untuk reprodusibilitas
4. Klik **Jalankan Semua Skenario**
5. Tunggu proses selesai (~10-60 detik tergantung ukuran dataset)
6. Review hasil di:
   - **Tabel perbandingan** (ringkasan 4 algoritma × 5 skenario)
   - **Tab per skenario** (detail confusion matrix + per-class metrics untuk setiap algoritma)
   - **Kesimpulan** (pemenang keseluruhan)

---

## Arsitektur

```
EvaluationController
├── show()              → Tampilkan halaman evaluasi
└── run()               → Jalankan evaluasi batch
    ├── Loop 5 skenario (80/20 s/d 40/60)
    │   ├── Deterministic shuffle (seed-based)
    │   ├── Split train/test sesuai rasio (Option B: fixed test set)
    │   ├── evaluateFuzzyTopsis()
    │   │   ├── Build CaseDTO dari train set
    │   │   ├── Untuk setiap test case:
    │   │   │   ├── DecisionMatrixService.build()
    │   │   │   ├── FuzzificationService.process()
    │   │   │   ├── DefuzzificationService.process()
    │   │   │   ├── NormalizationService.calculate()
    │   │   │   ├── IdealSolutionService.calculate()
    │   │   │   ├── DistanceService.calculate()
    │   │   │   └── RankingService.rank() → Top-1 prediction
    │   │   └── buildMultiClassMetrics()
    │   ├── evaluateSimilarity('hybrid')
    │   │   ├── Build entropy-based weights
    │   │   ├── Untuk setiap test case:
    │   │   │   ├── computeSimilarityScore(mode='hybrid')
    │   │   │   └── Top-1 prediction
    │   │   └── buildMultiClassMetrics()
    │   ├── evaluateSimilarity('jaccard')
    │   │   ├── Untuk setiap test case:
    │   │   │   ├── computeSimilarityScore(mode='jaccard')
    │   │   │   └── Top-1 prediction
    │   │   └── buildMultiClassMetrics()
    │   └── evaluateSimilarity('cosine')
    │       ├── Untuk setiap test case:
    │       │   ├── computeSimilarityScore(mode='cosine')
    │       │   └── Top-1 prediction
    │       └── buildMultiClassMetrics()
    └── Return ft_results, hs_results, jc_results, cs_results ke Blade view
```

---

## Temuan: Mengapa Akurasi Hybrid/Jaccard/Cosine Rendah pada Drug200?

### Akar Masalah: Exact String Matching pada Atribut Numerik Kontinu

Dataset Drug200 memiliki 4 atribut kriteria dengan tipe data berbeda:

| Atribut | Tipe Data | Contoh Nilai | Jumlah Nilai Unik |
|---------|-----------|-------------|-------------------|
| Age | Numerik kontinu | 23, 47, 51, 68 | ~40+ nilai unik |
| Na_to_K | Numerik kontinu | 7.298, 14.526, 25.355 | ~180+ nilai unik |
| BP | Kategoris | LOW, NORMAL, HIGH | 3 nilai |
| Cholesterol | Kategoris | NORMAL, HIGH | 2 nilai |

### Perbedaan Cara Hitung Similarity

**Fuzzy TOPSIS** (`DecisionMatrixService.similarityScore()`) menggunakan **range-based normalized difference** untuk atribut numerik:

```
Contoh Age: test=47, basis=51
  delta = max(74) - min(15) = 59
  similarity = 1 - |47-51|/59 = 1 - 0.068 = 0.932  ← TINGGI (hampir mirip)

Contoh Na_to_K: test=14.5, basis=14.8
  delta = max(38) - min(6) = 32
  similarity = 1 - |14.5-14.8|/32 = 1 - 0.009 = 0.991  ← SANGAT TINGGI
```

Fuzzy TOPSIS bisa **membedakan gradasi kedekatan** antar nilai numerik, sehingga ranking Top-1 akurat.

**Hybrid/Jaccard/Cosine** (`computeSimilarityScore()`) menggunakan **exact string matching**:

```
Contoh Age: "47" === "51" → FALSE → match = 0  ← TIDAK COCOK SAMA SEKALI
Contoh Na_to_K: "14.526" === "14.828" → FALSE → match = 0  ← TIDAK COCOK
```

Walaupun nilai numerik sangat dekat (47 vs 51), exact matching menganggapnya **100% berbeda**.

### Dampak pada Skor Similarity

Dari 4 atribut, **2 atribut numerik (Age, Na_to_K) hampir tidak pernah cocok** karena nilainya kontinu dengan sangat banyak nilai unik. Skor maksimal yang bisa dicapai:

| Algoritma | Skenario Terbaik (BP + Cholesterol cocok) | Skor Maksimal |
|-----------|------------------------------------------|---------------|
| Cosine | matches=2, n=4 → 2/4 | **0.50** |
| Jaccard | matches=2, n=4 → 2/(8-2) | **0.33** |
| Hybrid | Rata-rata weighted cosine + jaccard | **~0.40** |

Karena **semua basis kasus mendapat skor rendah dan mirip-mirip** (hanya dibedakan oleh 2 atribut kategoris), prediksi Top-1 menjadi **hampir acak** di antara kasus-kasus yang memiliki BP dan Cholesterol sama tapi Drug berbeda → akurasi rendah.

### Mengapa Fuzzy TOPSIS Jauh Lebih Unggul

Fuzzy TOPSIS bisa memberikan skor yang sangat bervariasi (0.0 - 1.0) untuk setiap atribut numerik:
- Age=47 vs Age=51 → 0.932 (mirip)
- Age=47 vs Age=74 → 0.542 (agak jauh)
- Age=47 vs Age=15 → 0.458 (jauh)

Variasi skor ini membuat **ranking Top-1 Fuzzy TOPSIS sangat diskriminatif**, mampu memilih basis kasus yang benar-benar paling mirip secara keseluruhan.

### Implikasi untuk Skripsi

Temuan ini menunjukkan bahwa:

1. **Fuzzy TOPSIS lebih cocok untuk dataset campuran** (numerik + kategoris) karena fuzzification dengan TFN menangani kedekatan nilai numerik kontinu.
2. **Hybrid/Jaccard/Cosine lebih cocok untuk dataset kategoris murni** (semua atribut bernilai diskrit/ENUM) di mana exact matching masuk akal.
3. **Pembobotan Entropy pada Hybrid** sedikit membantu dibanding Jaccard/Cosine murni karena memberikan bobot lebih besar pada atribut yang informatif, tapi tidak bisa mengatasi masalah fundamental exact matching pada numerik.
4. Dataset Drug200 dengan **50% atribut numerik kontinu** merupakan kasus di mana Fuzzy TOPSIS memiliki **keunggulan struktural** yang signifikan.
