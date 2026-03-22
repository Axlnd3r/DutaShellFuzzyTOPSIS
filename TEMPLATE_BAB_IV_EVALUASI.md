# Template BAB IV - Implementasi dan Pengujian Sistem

> Template ini membantu menyusun BAB IV skripsi untuk bagian evaluasi perbandingan 4 algoritma: Fuzzy TOPSIS, Hybrid Similarity, Jaccard Similarity, dan Cosine Similarity.

---

## 4.X Pengujian Akurasi Sistem

### 4.X.1 Skenario Pengujian

Pengujian dilakukan menggunakan dataset Drug200 yang terdiri dari 200 data kasus rekomendasi obat dengan 4 atribut (Age, Na_to_K, BP, Cholesterol) dan 1 atribut goal (Drug) yang memiliki 5 kelas (drugA, drugB, drugC, drugX, DrugY).

Data dibagi menjadi dua tabel: **basis kasus** (`case_user_15`, 160 data) sebagai pool training dan **fixed test case** (`test_case_user_15`, 40 data) yang selalu digunakan sebagai test set. Pembagian menggunakan **seed=42** untuk menjamin reprodusibilitas hasil. Lima skenario pengujian digunakan:

| Skenario | Data Training | Data Testing | Keterangan |
|----------|--------------|-------------|------------|
| 1 (80/20) | 160 kasus   | 40 kasus    | Semua basis kasus sebagai training, hanya fixed test |
| 2 (70/30) | 140 kasus   | 60 kasus    | 20 basis kasus dipindah ke test + 40 fixed test |
| 3 (60/40) | 120 kasus   | 80 kasus    | 40 basis kasus dipindah ke test + 40 fixed test |
| 4 (50/50) | 100 kasus   | 100 kasus   | 60 basis kasus dipindah ke test + 40 fixed test |
| 5 (40/60) | 80 kasus    | 120 kasus   | 80 basis kasus dipindah ke test + 40 fixed test |

### 4.X.2 Algoritma yang Diuji

Pengujian membandingkan **empat** algoritma:

**1. Fuzzy TOPSIS (Technique for Order Preference by Similarity to Ideal Solution)**
Pipeline inferensi: Decision Matrix (similarity score) → Fuzzification (TFN) → Defuzzification → Normalization → Weighted Normalization → Ideal Solution (A+/A-) → Distance (D+/D-) → Closeness Coefficient → Ranking.

Prediksi ditentukan berdasarkan **Top-1 strategy**: kelas goal dari basis kasus dengan CC tertinggi diambil sebagai prediksi.

**2. Hybrid Similarity**
Kombinasi Cosine Similarity dan Jaccard Similarity dengan pembobotan berbasis Entropy:
- Formula: `score = α × weighted_cosine + (1-α) × weighted_jaccard`
- Parameter α = 0.5 (bobot seimbang)
- Bobot atribut dihitung otomatis menggunakan metode Entropy

**3. Jaccard Similarity**
Jaccard murni tanpa pembobotan atribut:
- Formula: `jaccard = matches / (2n - matches)`
- Di mana `matches` = jumlah kecocokan atribut, `n` = jumlah atribut
- Tidak menggunakan Entropy weighting — setiap atribut memiliki bobot yang sama

**4. Cosine Similarity**
Cosine murni tanpa pembobotan atribut:
- Formula: `cosine = matches / n`
- Di mana `matches` = jumlah kecocokan atribut, `n` = jumlah atribut
- Tidak menggunakan Entropy weighting — setiap atribut memiliki bobot yang sama

> **Catatan:** Formula Jaccard dan Cosine diambil dari implementasi yang sama (`HybridSimController::computeScore()`) untuk menjamin konsistensi.

### 4.X.3 Metrik Evaluasi

Evaluasi menggunakan **Confusion Matrix Multi-Class** (5×5) dengan metrik:

| Metrik | Formula | Keterangan |
|--------|---------|------------|
| Accuracy | (TP_total) / N | Proporsi prediksi benar dari total |
| Precision (macro) | Rata-rata Precision per kelas | TP_i / (TP_i + FP_i) |
| Recall (macro) | Rata-rata Recall per kelas | TP_i / (TP_i + FN_i) |
| F1-Score (macro) | Rata-rata F1 per kelas | 2×P×R / (P+R) |

### 4.X.4 Hasil Pengujian

> **[ISI DENGAN SCREENSHOT DARI HALAMAN EVALUASI DutaShell]**

#### Tabel 4.X: Perbandingan Akurasi 4 Algoritma

| Skenario | Train | Test | FT Acc | FT F1 | HS Acc | HS F1 | JC Acc | JC F1 | CS Acc | CS F1 | Pemenang |
|----------|-------|------|--------|--------|--------|--------|--------|--------|--------|--------|----------|
| 80/20    | 160   | 40   | __%    | __%    | __%    | __%    | __%    | __%    | __%    | __%    |          |
| 70/30    | 140   | 60   | __%    | __%    | __%    | __%    | __%    | __%    | __%    | __%    |          |
| 60/40    | 120   | 80   | __%    | __%    | __%    | __%    | __%    | __%    | __%    | __%    |          |
| 50/50    | 100   | 100  | __%    | __%    | __%    | __%    | __%    | __%    | __%    | __%    |          |
| 40/60    | 80    | 120  | __%    | __%    | __%    | __%    | __%    | __%    | __%    | __%    |          |
| **Rata-rata** | | | **__%** | **__%** | **__%** | **__%** | **__%** | **__%** | **__%** | **__%** | |

> _Isi tabel di atas dengan hasil aktual dari DutaShell._

#### Confusion Matrix per Skenario

> **[SCREENSHOT confusion matrix 5×5 dari DutaShell untuk setiap skenario, masing-masing 4 algoritma]**

Contoh penjelasan untuk skenario 80/20:

> "Pada skenario 80/20, Fuzzy TOPSIS menghasilkan akurasi sebesar __% dengan confusion matrix menunjukkan bahwa kelas DrugY memiliki recall tertinggi (__%), sedangkan kelas drugC memiliki recall terendah (__%). Hal ini menunjukkan bahwa..."

### 4.X.5 Analisis Hasil

#### A. Pengaruh Rasio Data terhadap Akurasi

> _Jelaskan tren: apakah akurasi menurun seiring berkurangnya data training? Berapa threshold minimum data training yang masih menghasilkan akurasi baik?_

Contoh:
> "Dari Tabel 4.X terlihat bahwa akurasi Fuzzy TOPSIS relatif stabil dari skenario 80/20 hingga 60/40, namun mulai menurun signifikan pada skenario 40/60. Hal ini menunjukkan bahwa algoritma Fuzzy TOPSIS membutuhkan minimal 60% data training untuk mempertahankan akurasi di atas ___%."

#### B. Perbandingan 4 Algoritma dan Analisis Perbedaan Akurasi

> _Jelaskan mengapa Fuzzy TOPSIS jauh lebih unggul dan mengapa Hybrid/Jaccard/Cosine memiliki akurasi rendah._

**Perbedaan fundamental dalam penanganan atribut numerik:**

Dataset Drug200 memiliki 4 atribut kriteria: 2 atribut **numerik kontinu** (Age, Na_to_K) dan 2 atribut **kategoris** (BP, Cholesterol).

| Aspek | Fuzzy TOPSIS | Hybrid / Jaccard / Cosine |
|-------|-------------|--------------------------|
| Penanganan numerik | Range-based normalized difference: `1 - \|a-b\|/range` | Exact string matching: `"47" === "51"` → FALSE |
| Contoh Age (47 vs 51) | `1 - 4/59 = 0.932` (mirip) | `match = 0` (tidak cocok) |
| Contoh Na_to_K (14.5 vs 14.8) | `1 - 0.3/32 = 0.991` (sangat mirip) | `match = 0` (tidak cocok) |
| Variasi skor | 0.0 - 1.0 (sangat diskriminatif) | Hanya 0 atau 1 per atribut |

**Dampak pada akurasi:**

Karena 2 dari 4 atribut (Age, Na_to_K) **hampir tidak pernah exact match** (nilainya kontinu dengan ratusan nilai unik), maka skor maksimal Hybrid/Jaccard/Cosine terbatas pada kecocokan BP dan Cholesterol saja:

- Cosine terbaik = 2/4 = **0.50** (jika BP dan Cholesterol cocok)
- Jaccard terbaik = 2/(8-2) = **0.33**
- Semua basis kasus mendapat skor rendah dan mirip-mirip → prediksi Top-1 menjadi hampir **acak**

Sementara Fuzzy TOPSIS dapat membedakan: Age=47 vs 51 → 0.932, vs 74 → 0.542, vs 15 → 0.458, sehingga ranking sangat akurat.

Contoh penulisan:
> "Fuzzy TOPSIS menghasilkan akurasi rata-rata __% yang jauh mengungguli Hybrid Similarity (__%), Jaccard (__%), dan Cosine (__%). Perbedaan signifikan ini disebabkan oleh **perbedaan fundamental dalam penanganan atribut numerik kontinu**. Fuzzy TOPSIS menggunakan range-based normalized difference pada tahap Decision Matrix, di mana Age=47 dibandingkan dengan Age=51 menghasilkan similarity 0.932 — hampir mirip. Sebaliknya, ketiga algoritma similarity menggunakan exact string matching, di mana `'47' !== '51'` dianggap **tidak cocok sama sekali** (match=0). Dengan 2 dari 4 atribut berupa numerik kontinu (Age dan Na_to_K), skor similarity terbaik yang bisa dicapai hanya ~0.50 (Cosine) atau ~0.33 (Jaccard), menyebabkan semua basis kasus mendapat skor rendah dan mirip sehingga prediksi Top-1 menjadi hampir acak."

#### C. Pengaruh Pembobotan Atribut (Entropy Weighting)

> _Bandingkan Hybrid Similarity (dengan Entropy weighting) vs Jaccard/Cosine murni (tanpa weighting):_

Contoh:
> "Hybrid Similarity yang menggunakan pembobotan Entropy mencapai akurasi rata-rata __% dibanding Jaccard murni __% dan Cosine murni __%. Meskipun pembobotan Entropy memberikan sedikit peningkatan, perbedaannya tidak signifikan karena masalah utama bukan pada bobot atribut, melainkan pada **mekanisme exact matching** yang tidak mampu menangkap kedekatan nilai numerik kontinu. Pembobotan Entropy hanya mengubah proporsi kontribusi antar atribut, tetapi tidak mengubah fakta bahwa Age dan Na_to_K hampir selalu menghasilkan match=0."

#### D. Implikasi Temuan terhadap Pemilihan Algoritma

> _Jelaskan kapan masing-masing algoritma cocok digunakan:_

| Karakteristik Dataset | Algoritma yang Disarankan | Alasan |
|-----------------------|--------------------------|--------|
| Dominan numerik kontinu (Age, BMI, tekanan darah) | **Fuzzy TOPSIS** | Range-based similarity menangani gradasi kedekatan numerik |
| Dominan kategoris (gender, golongan darah, status) | **Hybrid Similarity** | Exact matching cocok untuk data diskrit, Entropy weighting mengoptimalkan bobot |
| Campuran numerik + kategoris | **Fuzzy TOPSIS** | Keunggulan pada atribut numerik lebih berdampak daripada kesetaraan pada kategoris |
| Semua kategoris, tanpa bobot khusus | **Cosine / Jaccard** | Sederhana, tidak butuh kalkulasi bobot |

Contoh penulisan:
> "Berdasarkan hasil evaluasi, dapat disimpulkan bahwa pemilihan algoritma sangat bergantung pada **tipe data atribut** dalam dataset. Untuk dataset seperti Drug200 yang memiliki 50% atribut numerik kontinu, Fuzzy TOPSIS memiliki **keunggulan struktural** yang signifikan. Algoritma berbasis exact matching (Hybrid, Jaccard, Cosine) lebih cocok digunakan pada dataset dengan atribut dominan kategoris, di mana perbandingan exact match memang sesuai dengan nature data."

#### E. Per-Class Performance

> _Analisis kelas mana yang paling mudah dan paling sulit diprediksi. Contoh:_

> "Kelas DrugY memiliki F1-Score tertinggi (__%) karena memiliki jumlah sample terbanyak (__ kasus). Sebaliknya, kelas drugC memiliki F1-Score terendah (__%) yang disebabkan oleh jumlah sample yang sedikit dan kemiripan profil pasien dengan kelas DrugY."

### 4.X.6 Kesimpulan Pengujian

> _Rangkum temuan utama:_
> 1. Algoritma mana yang paling unggul secara keseluruhan dari 4 algoritma?
> 2. Pada skenario mana algoritma tersebut paling optimal?
> 3. **Mengapa akurasi Hybrid/Jaccard/Cosine rendah?** — Karena exact string matching tidak cocok untuk atribut numerik kontinu (Age, Na_to_K). Nilai `"47" !== "51"` walaupun secara numerik sangat dekat.
> 4. **Mengapa Fuzzy TOPSIS jauh lebih unggul?** — Karena range-based normalized difference pada Decision Matrix mampu menangkap gradasi kedekatan numerik (`1 - |47-51|/59 = 0.932`).
> 5. Apakah pembobotan Entropy pada Hybrid Similarity memberikan peningkatan signifikan? — Sedikit, karena masalah utama bukan bobot tapi mekanisme matching.
> 6. Apa implikasi praktis? — Untuk dataset dengan atribut numerik kontinu, Fuzzy TOPSIS adalah pilihan yang tepat.

---

## 4.Y Implementasi Halaman Evaluasi

### 4.Y.1 Tampilan Halaman Evaluasi

> **[SCREENSHOT halaman Evaluasi Perbandingan dari DutaShell]**

Halaman evaluasi diakses melalui menu sidebar **"Evaluasi Perbandingan"**. Pengguna dapat mengatur parameter seed dan menjalankan semua 5 skenario sekaligus dengan satu klik tombol. Evaluasi membandingkan **4 algoritma** secara simultan.

### 4.Y.2 Komponen Halaman

1. **Form Parameter**: Input seed untuk reprodusibilitas hasil
2. **Tabel Perbandingan**: Menampilkan 5 skenario dengan metrik 4 algoritma side-by-side (color-coded header per algoritma)
3. **Tab Detail Skenario**: 4 kartu per skenario, masing-masing berisi confusion matrix 5×5 dan per-class metrics
4. **Panel Kesimpulan**: Skor kemenangan per algoritma dan kesimpulan otomatis berdasarkan data kuantitatif

### 4.Y.3 Implementasi Kode

**Controller**: `EvaluationController.php`
- Method `run()` menjalankan 5 skenario evaluasi × 4 algoritma = 20 evaluasi
- `evaluateFuzzyTopsis()` — menggunakan service class pipeline yang sama dengan fitur inferensi utama:
  - `DecisionMatrixService` untuk membangun matriks keputusan
  - `FuzzificationService` untuk mengubah nilai crisp ke Triangular Fuzzy Number
  - `DefuzzificationService` untuk defuzzifikasi
  - `NormalizationService` untuk normalisasi dan pembobotan
  - `IdealSolutionService` untuk menentukan solusi ideal positif dan negatif
  - `DistanceService` untuk menghitung jarak Euclidean
  - `RankingService` untuk menghitung Closeness Coefficient
- `evaluateSimilarity($mode)` — unified method yang mendukung 3 mode:
  - `'hybrid'` — weighted cosine + jaccard dengan Entropy weighting
  - `'jaccard'` — Jaccard murni tanpa bobot
  - `'cosine'` — Cosine murni tanpa bobot
- `computeSimilarityScore()` — formula dari `HybridSimController::computeScore()`
- `buildMultiClassMetrics()` — confusion matrix multi-class dan per-class metrics

**View**: `evaluation.blade.php`
- Responsive layout menggunakan Bootstrap 5
- Color-coded algorithm headers (biru=FT, merah=HS, ungu=JC, oranye=CS)
- Confusion matrix dengan color-coding (hijau=benar, merah=salah)
- Metric cards untuk visualisasi ringkasan per algoritma

**Route**: `/evaluation` (GET) dan `/evaluation/run` (POST)
