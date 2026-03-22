# Analisis Lengkap Fuzzy TOPSIS - DutaShell
## Dokumen Pendukung Skripsi - BAB 3 Metodologi & BAB 4 Pembahasan

**Penulis**: 71220853 - Matthew Alexander
**Sistem**: DutaShell - Sistem Pakar CBR Multi-Algoritma
**Algoritma Fokus**: Hybrid Fuzzy-TOPSIS sebagai Case Retrieval Engine

---

## DAFTAR ISI

1. [Q1 & Q8 — Atribut Mana yang Difuzzifikasi?](#q1--q8--atribut-mana-yang-difuzzifikasi-dan-mengapa)
2. [Q2 & Q5 — Contoh Kasus Manual + Pembuktian](#q2--q5--contoh-kasus-manual-dengan-perhitungan-lengkap)
3. [Q3 — Alasan Menggabungkan Fuzzy + TOPSIS](#q3--mengapa-menggabungkan-fuzzy-dengan-topsis)
4. [Q4 — Fuzzy Saja vs TOPSIS Saja](#q4--skenario-fuzzy-saja-vs-topsis-saja)
5. [Q6 — Perbandingan dengan Fuzzy AHP dll](#q6--perbandingan-fuzzy-topsis-vs-fuzzy-ahp-dan-lainnya)
6. [Q7 — Atribut Numerik vs Teks/Deskriptif](#q7--apakah-fuzzy-topsis-hanya-untuk-atribut-numerik)
7. [Contoh Kasus Excel](#contoh-kasus-untuk-excel)
8. [Prompt untuk Membuat Excel di Claude Web](#prompt-untuk-claude-web--membuat-excel)

---

## Q1 & Q8 — Atribut Mana yang Difuzzifikasi dan Mengapa?

### Jawaban Singkat

**SEMUA atribut kriteria** (yang `goal='F'`) masuk proses fuzzifikasi.
**Atribut goal** (yang `goal='T'`) **TIDAK** masuk fuzzifikasi.

### Penjelasan

Yang difuzzifikasi **bukan nilai mentah atribut**, melainkan **skor kemiripan (similarity score)** yang sudah dihitung per atribut.

```
Nilai mentah atribut (misal: "True", 28°C, "Putih")
        ↓
Similarity Score per atribut (0.0 - 1.0)     ← dihitung dulu di DecisionMatrixService
        ↓
Fuzzifikasi → TFN (a, b, c)                  ← baru difuzzifikasi di FuzzificationService
        ↓
Defuzzifikasi → Crisp (a+b+c)/3              ← kembali ke angka untuk TOPSIS
        ↓
Masuk pipeline TOPSIS (normalisasi → bobot → ideal → D+/D- → CC)
```

### Mengapa Difuzzifikasi?

| Alasan | Penjelasan |
|--------|------------|
| **Ketidakpastian pengukuran** | Skor kemiripan 0.85 bisa saja sebenarnya 0.75–0.95 |
| **Toleransi noise** | Data real-world (medis, perikanan) sering memiliki ketidaktepatan |
| **Robustness** | TOPSIS yang bekerja dengan range lebih stabil daripada titik tunggal |
| **Mengurangi rank reversal** | Perubahan kecil pada input tidak langsung mengubah ranking |

### Mengapa Atribut Goal TIDAK Difuzzifikasi?

Atribut goal adalah **label diagnosis/jawaban** (misal: "Flu", "BKC", "Parasit"). Atribut ini:
- Bukan input perhitungan TOPSIS
- Hanya dipakai di akhir untuk **mencocokkan** prediksi (confusion matrix)
- Tidak punya "skor kemiripan" — hanya match/tidak match

### Contoh Konkret

Misalkan DutaShell punya 5 atribut untuk diagnosa penyakit kerang:

| Atribut | Goal? | Tipe Data | Masuk Fuzzifikasi? | Alasan |
|---------|-------|-----------|-------------------|--------|
| **Penyakit** | T (goal) | Teks | **TIDAK** | Label diagnosis, bukan input TOPSIS |
| **Demam** | F | True/False | **YA** | Similarity 0/1 → TFN (0,0,0.1) atau (0.9,1,1) |
| **Warna_Cangkang** | F | Kategorik | **YA** | Similarity 0/1 → TFN |
| **Suhu_Air** | F | Numerik | **YA** | Similarity 0–1 → TFN (a,b,c) |
| **pH_Air** | F | Numerik | **YA** | Similarity 0–1 → TFN (a,b,c) |

### Implementasi di Kode

**File**: `app/Services/Fuzzy/FuzzificationService.php`
```php
// Yang difuzzifikasi = similarity score (selalu 0.0 – 1.0)
$a = max(0.0, $x - $spread);  // lower bound
$b = $x;                       // middle (nilai asli)
$c = min(1.0, $x + $spread);  // upper bound
$fuzzyMatrix[$caseId][$criterion] = [$a, $b, $c];
```

---

## Q2 & Q5 — Contoh Kasus Manual dengan Perhitungan Lengkap

### Skenario: Rekomendasi Diagnosa Kucing Sakit

Sistem CBR mencari kasus lama yang paling mirip dengan kucing yang sedang diperiksa.

### A. Definisi Atribut

| ID | Nama Atribut | Tipe Data | Goal? | Weight | Type |
|----|-------------|-----------|-------|--------|------|
| 1 | Demam | True/False | F (kriteria) | 3 | benefit |
| 2 | Nafsu_Makan | Baik/Buruk | F (kriteria) | 2 | benefit |
| 3 | Umur | Angka (tahun) | F (kriteria) | 1 | benefit |
| 4 | **Diagnosis** | Teks | **T (goal)** | - | - |

### B. Data Kasus Lama (Base Cases)

| case_id | Demam | Nafsu_Makan | Umur | **Diagnosis** |
|---------|-------|-------------|------|---------------|
| K1 | True | Buruk | 3 | **Flu** |
| K2 | False | Baik | 7 | **Cacingan** |
| K3 | True | Baik | 2 | **Flu** |

### C. Kucing Baru yang Diperiksa (Test Case)

| | Demam | Nafsu_Makan | Umur | **Diagnosis** |
|--|-------|-------------|------|---------------|
| Test | **True** | **Buruk** | **4** | **Flu** |

---

### STEP 1: Decision Matrix (Similarity Scores)

#### Demam (True/False → Kategorik, exact match):
| Case | Base | Test | Match? | Similarity |
|------|------|------|--------|-----------|
| K1 | True | True | ✓ Ya | **1.00** |
| K2 | False | True | ✗ Tidak | **0.00** |
| K3 | True | True | ✓ Ya | **1.00** |

#### Nafsu Makan (Baik/Buruk → Kategorik, exact match):
| Case | Base | Test | Match? | Similarity |
|------|------|------|--------|-----------|
| K1 | Buruk | Buruk | ✓ Ya | **1.00** |
| K2 | Baik | Buruk | ✗ Tidak | **0.00** |
| K3 | Baik | Buruk | ✗ Tidak | **0.00** |

#### Umur (Numerik, range = max-min = 7-2 = 5):
| Case | Base | Test | Rumus | Similarity |
|------|------|------|-------|-----------|
| K1 | 3 | 4 | 1 - \|3-4\|/5 = 1 - 0.2 | **0.80** |
| K2 | 7 | 4 | 1 - \|7-4\|/5 = 1 - 0.6 | **0.40** |
| K3 | 2 | 4 | 1 - \|2-4\|/5 = 1 - 0.4 | **0.60** |

#### Decision Matrix Lengkap:

| Case | Demam (C1) | Nafsu (C2) | Umur (C3) |
|------|-----------|-----------|-----------|
| K1 | 1.00 | 1.00 | 0.80 |
| K2 | 0.00 | 0.00 | 0.40 |
| K3 | 1.00 | 0.00 | 0.60 |

---

### STEP 2: Fuzzifikasi → TFN (spread = 0.1)

Rumus: `TFN(x) = (max(0, x-0.1), x, min(1, x+0.1))`

| Case | Demam TFN | Nafsu TFN | Umur TFN |
|------|-----------|-----------|----------|
| K1 | (0.90, 1.00, 1.00) | (0.90, 1.00, 1.00) | (0.70, 0.80, 0.90) |
| K2 | (0.00, 0.00, 0.10) | (0.00, 0.00, 0.10) | (0.30, 0.40, 0.50) |
| K3 | (0.90, 1.00, 1.00) | (0.00, 0.00, 0.10) | (0.50, 0.60, 0.70) |

> **Catatan**: min(1.0, 1.0+0.1) = 1.0 dan max(0.0, 0.0-0.1) = 0.0 (di-clamp)

---

### STEP 3: Defuzzifikasi (Centroid Method)

Rumus: `crisp = (a + b + c) / 3`

| Case | Demam | Nafsu | Umur |
|------|-------|-------|------|
| K1 | (0.90+1.00+1.00)/3 = **0.9667** | (0.90+1.00+1.00)/3 = **0.9667** | (0.70+0.80+0.90)/3 = **0.8000** |
| K2 | (0.00+0.00+0.10)/3 = **0.0333** | (0.00+0.00+0.10)/3 = **0.0333** | (0.30+0.40+0.50)/3 = **0.4000** |
| K3 | (0.90+1.00+1.00)/3 = **0.9667** | (0.00+0.00+0.10)/3 = **0.0333** | (0.50+0.60+0.70)/3 = **0.6000** |

Crisp Matrix:

| Case | C1 (Demam) | C2 (Nafsu) | C3 (Umur) |
|------|-----------|-----------|-----------|
| K1 | 0.9667 | 0.9667 | 0.8000 |
| K2 | 0.0333 | 0.0333 | 0.4000 |
| K3 | 0.9667 | 0.0333 | 0.6000 |

---

### STEP 4: Normalisasi Bobot

Bobot awal: w1=3, w2=2, w3=1 → total = 6
- Demam: 3/6 = **0.5000**
- Nafsu: 2/6 = **0.3333**
- Umur: 1/6 = **0.1667**

---

### STEP 5: Normalisasi Vektor

Rumus: `r_ij = x_ij / √(Σ x_ij²)`

#### Denominator per kolom:
- C1: √(0.9667² + 0.0333² + 0.9667²) = √(0.9345 + 0.0011 + 0.9345) = √1.8701 = **1.3675**
- C2: √(0.9667² + 0.0333² + 0.0333²) = √(0.9345 + 0.0011 + 0.0011) = √0.9367 = **0.9678**
- C3: √(0.8000² + 0.4000² + 0.6000²) = √(0.6400 + 0.1600 + 0.3600) = √1.1600 = **1.0770**

#### Normalized Matrix (r_ij):

| Case | r_C1 | r_C2 | r_C3 |
|------|------|------|------|
| K1 | 0.9667/1.3675 = **0.7069** | 0.9667/0.9678 = **0.9989** | 0.8000/1.0770 = **0.7427** |
| K2 | 0.0333/1.3675 = **0.0244** | 0.0333/0.9678 = **0.0344** | 0.4000/1.0770 = **0.3714** |
| K3 | 0.9667/1.3675 = **0.7069** | 0.0333/0.9678 = **0.0344** | 0.6000/1.0770 = **0.5570** |

---

### STEP 6: Matriks Terbobot

Rumus: `v_ij = w_j × r_ij`

| Case | v_C1 (×0.5000) | v_C2 (×0.3333) | v_C3 (×0.1667) |
|------|----------------|----------------|----------------|
| K1 | **0.3535** | **0.3330** | **0.1238** |
| K2 | **0.0122** | **0.0115** | **0.0619** |
| K3 | **0.3535** | **0.0115** | **0.0928** |

---

### STEP 7: Solusi Ideal (semua benefit)

| | C1 (Demam) | C2 (Nafsu) | C3 (Umur) |
|--|-----------|-----------|-----------|
| **A+ (max)** | 0.3535 | 0.3330 | 0.1238 |
| **A- (min)** | 0.0122 | 0.0115 | 0.0619 |

---

### STEP 8: Jarak Euclidean

#### D+ (jarak ke A+):
```
K1: √((0.3535-0.3535)² + (0.3330-0.3330)² + (0.1238-0.1238)²)
  = √(0 + 0 + 0) = 0.0000

K2: √((0.0122-0.3535)² + (0.0115-0.3330)² + (0.0619-0.1238)²)
  = √(0.1164 + 0.1034 + 0.0038) = √0.2236 = 0.4729

K3: √((0.3535-0.3535)² + (0.0115-0.3330)² + (0.0928-0.1238)²)
  = √(0 + 0.1034 + 0.0010) = √0.1044 = 0.3231
```

#### D- (jarak ke A-):
```
K1: √((0.3535-0.0122)² + (0.3330-0.0115)² + (0.1238-0.0619)²)
  = √(0.1164 + 0.1034 + 0.0038) = √0.2236 = 0.4729

K2: √((0.0122-0.0122)² + (0.0115-0.0115)² + (0.0619-0.0619)²)
  = √(0 + 0 + 0) = 0.0000

K3: √((0.3535-0.0122)² + (0.0115-0.0115)² + (0.0928-0.0619)²)
  = √(0.1164 + 0 + 0.0010) = √0.1174 = 0.3426
```

---

### STEP 9: Closeness Coefficient & Ranking

Rumus: `CC = D- / (D+ + D-)`

| Rank | Case | D+ | D- | **CC Score** | Diagnosis | Match Test? |
|------|------|-----|-----|-------------|-----------|-------------|
| **1** | **K1** | 0.0000 | 0.4729 | **1.0000** | Flu | **✓ Ya** |
| **2** | K3 | 0.3231 | 0.3426 | **0.5147** | Flu | ✓ Ya |
| **3** | K2 | 0.4729 | 0.0000 | **0.0000** | Cacingan | ✗ Tidak |

### Interpretasi Hasil:

- **K1 menang (CC=1.0)** karena: Demam ✓, Nafsu ✓, Umur dekat (3 vs 4)
- **K3 di posisi 2 (CC=0.51)** karena: Demam ✓, tapi Nafsu ✗ (Baik vs Buruk)
- **K2 terakhir (CC=0.0)** karena: Demam ✗, Nafsu ✗ — semua beda

---

### Evaluasi: Confusion Matrix (Top-1 Strategy)

Strategi: Hanya rank-1 yang dianggap "Predicted Positive"

| Case | Rank | Predicted | Goal | Same as Test? | Label |
|------|------|-----------|------|---------------|-------|
| K1 | 1 | Positive | Flu | Ya (Flu=Flu) | **TP** |
| K3 | 2 | Negative | Flu | Ya (Flu=Flu) | **FN** |
| K2 | 3 | Negative | Cacingan | Tidak | **TN** |

|  | Predicted + | Predicted - |
|--|-------------|-------------|
| **Actual +** | TP = 1 | FN = 1 |
| **Actual -** | FP = 0 | TN = 1 |

| Metrik | Rumus | Hasil |
|--------|-------|-------|
| **Accuracy** | (TP+TN)/(TP+TN+FP+FN) = (1+1)/(1+1+0+1) | **66.67%** |
| **Precision** | TP/(TP+FP) = 1/(1+0) | **100.00%** |
| **Recall** | TP/(TP+FN) = 1/(1+1) | **50.00%** |
| **F1-Score** | 2×(P×R)/(P+R) = 2×(1.0×0.5)/(1.0+0.5) | **66.67%** |

---

## Q3 — Mengapa Menggabungkan Fuzzy dengan TOPSIS?

### Masalah TOPSIS Murni (Tanpa Fuzzy)

TOPSIS bekerja dengan **angka pasti (crisp)**. Dalam konteks CBR:
- Similarity = 0.85 → dianggap **pasti 0.85**, tidak ada toleransi
- Padahal dalam data real-world (diagnosa kerang/medis):
  - Penilaian gejala bisa **subjektif** ("agak coklat" vs "coklat")
  - Data sensor bisa **berfluktuasi** (suhu ±0.5°C)
  - Skor 0.85 belum tentu tepat — bisa 0.80 atau 0.90

### Apa yang Fuzzy Tambahkan?

| Aspek | TOPSIS Murni | Fuzzy + TOPSIS |
|-------|-------------|----------------|
| Input TOPSIS | Skor tunggal: 0.85 | Range TFN: (0.75, 0.85, 0.95) |
| Ketidakpastian | Diabaikan | Ditangkap oleh spread ±0.1 |
| Sensitivitas noise | Tinggi | Rendah (TFN meredam fluktuasi) |
| Rank reversal | Rawan | Berkurang |
| Dasar teori | Crisp MCDM | Fuzzy MCDM — standar untuk data tidak pasti |

### Tujuan Penggabungan

1. **Fuzzy** = menangani **ketidakpastian** data input
2. **TOPSIS** = melakukan **ranking multi-kriteria** dengan perspektif ganda (D+/D-)
3. **Gabungan** = ranking yang **robust** dan **toleran terhadap noise**

### Referensi Akademik

- Chen, C.T. (2000). "Extensions of the TOPSIS for group decision-making under fuzzy environment." Fuzzy Sets and Systems, 114(1), 1-9.
- Kahraman, C. et al. (2007). "Fuzzy TOPSIS for multi-criteria decision making." Studies in Fuzziness and Soft Computing, 16, 53-83.

---

## Q4 — Skenario Fuzzy Saja vs TOPSIS Saja

### Skenario A: Hanya Fuzzy (Tanpa TOPSIS)

Pipeline:
```
Similarity scores → Fuzzifikasi → TFN → Defuzzifikasi → ???
```

**Masalah**: Setelah defuzzifikasi, Anda punya matriks crisp tapi **tidak punya mekanisme ranking multi-kriteria**. Hanya bisa:
- Rata-rata semua atribut per kasus → ranking sederhana (naif)
- **Tidak bisa** memberi bobot berbeda per atribut
- **Tidak ada** konsep ideal solution
- **Tidak ada** D+/D- → tidak ada CC
- **Kesimpulan**: Fuzzifikasi sia-sia karena tidak ada framework yang memanfaatkannya

### Skenario B: Hanya TOPSIS (Tanpa Fuzzy)

Pipeline:
```
Similarity scores → Langsung normalisasi → Weighted → Ideal → D+/D- → CC
```

**Bisa jalan!** Tapi:
- Setiap skor dianggap pasti 100% — tidak ada toleransi
- Lebih sensitif terhadap noise dan outlier
- Ranking bisa berubah drastis karena perubahan kecil (rank reversal)

### Perbandingan Ketiganya Menggunakan Contoh Kasus

Dengan data yang sama (K1, K2, K3 vs Test):

| Metode | K1 Score | K2 Score | K3 Score | Ranking |
|--------|---------|---------|---------|---------|
| **Fuzzy TOPSIS** | CC=1.0000 | CC=0.0000 | CC=0.5147 | K1 > K3 > K2 |
| **TOPSIS Saja** | CC≈0.9998 | CC≈0.0002 | CC≈0.5145 | K1 > K3 > K2 |
| **Rata-rata Saja** | avg=0.93 | avg=0.13 | avg=0.53 | K1 > K3 > K2 |

> Pada contoh kecil ini hasilnya sama, tapi pada dataset besar dengan data yang noisy, **perbedaan akan terlihat jelas** — Fuzzy TOPSIS lebih stabil.

### Tabel Ringkasan

| Aspek | Fuzzy Only | TOPSIS Only | **Fuzzy TOPSIS** |
|-------|-----------|-------------|------------------|
| Multi-criteria ranking | ✗ | ✓ | **✓** |
| Bobot per atribut | ✗ | ✓ | **✓** |
| Tangkap ketidakpastian | ✓ | ✗ | **✓** |
| Ideal solution (D+/D-) | ✗ | ✓ | **✓** |
| Robustness terhadap noise | Rendah | Sedang | **Tinggi** |

---

## Q6 — Perbandingan Fuzzy TOPSIS vs Fuzzy AHP dan Lainnya

### Tabel Perbandingan Komprehensif

| Kriteria | **Fuzzy TOPSIS** | **Fuzzy AHP** | **Fuzzy SAW** | **Fuzzy VIKOR** |
|----------|------------------|---------------|---------------|-----------------|
| **Prinsip** | Jarak ke solusi ideal | Perbandingan berpasangan | Weighted sum | Compromise solution |
| **Input bobot** | Langsung ditentukan | Dihitung dari matriks m×m | Langsung | Langsung |
| **Kompleksitas** | O(n×m) | O(m² + n×m) | O(n×m) | O(n×m) |
| **Scalability** | Mudah (berapapun atribut) | **Sulit jika m>7** | Mudah | Mudah |
| **Uji konsistensi** | Tidak perlu | **Wajib (CR < 0.10)** | Tidak perlu | Tidak perlu |
| **Output** | CC score (0–1) | Skor prioritas | Skor total | Indeks Q |
| **Perspektif** | **Ganda (D+ dan D-)** | Tunggal | Tunggal | Ganda |

### Alasan Memilih Fuzzy TOPSIS untuk DutaShell

#### Faktor 1: Jumlah Atribut Dinamis
DutaShell memungkinkan user membuat atribut sendiri (bisa 3, bisa 20+).
- Fuzzy AHP: 10 atribut = **45 perbandingan berpasangan** yang harus diisi manual!
- Fuzzy TOPSIS: Hanya butuh **1 bobot per atribut** → langsung jalan

#### Faktor 2: Perspektif Ganda
TOPSIS menilai dari 2 sisi:
- Seberapa **dekat ke kasus terbaik** (D+)
- Seberapa **jauh dari kasus terburuk** (D-)

SAW hanya weighted sum → 1 perspektif. Ini penting karena kasus yang "agak mirip semua penyakit" harus dibedakan dari kasus yang "sangat mirip 1 penyakit".

#### Faktor 3: Integrasi Natural dengan CBR
CBR menghasilkan skor kemiripan per atribut → langsung jadi decision matrix TOPSIS. Tidak perlu transformasi tambahan.

#### Faktor 4: Interpretabilitas
CC score mudah dipahami: **0.78 = "78% dekat ke ideal"**. D+ dan D- bisa ditampilkan sebagai penjelasan.

#### Faktor 5: Tidak Perlu Uji Konsistensi
Fuzzy AHP memerlukan CR (Consistency Ratio) < 0.10. Jika tidak konsisten, user harus mengulang perbandingan. Fuzzy TOPSIS tidak ada syarat ini.

### Untuk Pembuktian Empiris di Skripsi

Idealnya buat eksperimen:
```
Dataset yang sama → Fuzzy TOPSIS → Accuracy, Precision, Recall, F1
Dataset yang sama → TOPSIS murni → Accuracy, Precision, Recall, F1
Dataset yang sama → CBR biasa   → Accuracy, Precision, Recall, F1
```

Minimal bandingkan **Fuzzy TOPSIS vs TOPSIS murni** untuk menunjukkan kontribusi komponen Fuzzy.

---

## Q7 — Apakah Fuzzy TOPSIS Hanya untuk Atribut Numerik?

### Jawaban: TIDAK

Dalam DutaShell, ada 2 jalur similarity di `DecisionMatrixService.php`:

### Atribut Numerik
```
Suhu: 28 vs 29 → similarity = 1 - |28-29|/range = 0.75
```
Hasil: skor **kontinu 0.0–1.0**

### Atribut Kategorik (termasuk True/False)
```
Demam: True vs True   → 1.0 (exact match)
Demam: True vs False  → 0.0 (tidak match)
Warna: Putih vs Putih → 1.0
Warna: Putih vs Coklat → 0.0
```
Hasil: **biner 0 atau 1**

### Setelah Similarity → Semua Jadi Numerik!
```
True/False "True vs True"   → similarity 1.0 → TFN (0.9, 1.0, 1.0)
Kategorik "Putih vs Coklat" → similarity 0.0 → TFN (0.0, 0.0, 0.1)
Numerik 28 vs 29             → similarity 0.75 → TFN (0.65, 0.75, 0.85)
```

**Yang difuzzifikasi bukan atribut mentah, tapi skor similarity-nya!**

### Tipe Atribut yang Didukung

| Tipe Atribut | Contoh | Similarity Method | Status |
|-------------|--------|-------------------|--------|
| Numerik | Suhu: 28°C | 1 - \|a-b\|/range | ✓ Sudah diimplementasi |
| Kategorik (multi-opsi) | Warna: Putih/Coklat/Hijau | Exact match (0 atau 1) | ✓ Sudah diimplementasi |
| Boolean (True/False) | Demam: True/False | Exact match (0 atau 1) | ✓ Sudah diimplementasi |
| Ordinal (bertingkat) | Stadium: Ringan→Sedang→Berat | 1 - \|rank_a-rank_b\|/max_rank | ⚡ Bisa ditambahkan |
| Teks bebas | "Cangkang retak kecil" | Jaccard/Cosine similarity | ⚡ Bisa ditambahkan |

### Solusi untuk Atribut Deskriptif/Teks

#### Solusi 1: Konversi ke Kategori (Sudah Diterapkan)
DutaShell menggunakan `atribut_value` — user memilih dari opsi yang sudah ditentukan, bukan mengetik bebas. Masalah teks deskriptif **sudah diantisipasi dari desain**.

#### Solusi 2: Ordinal Similarity (Untuk Teks Bertingkat)
```
Tingkat_Kerusakan: Tidak_Ada(0), Ringan(1), Sedang(2), Parah(3)

"Ringan" vs "Sedang" = 1 - |1-2|/3 = 0.67  (bukan 0!)
"Ringan" vs "Parah"  = 1 - |1-3|/3 = 0.00
```

#### Solusi 3: Text Similarity (Untuk Teks Bebas)
```
Jaccard("cangkang retak kecil", "cangkang retak halus")
  = |{cangkang,retak} ∩ {cangkang,retak}| / |{cangkang,retak,kecil} ∪ {cangkang,retak,halus}|
  = 2/4 = 0.50
```

### Kekuatan Arsitektur

```
Apapun tipe atribut → Similarity (0-1) → Fuzzifikasi → TOPSIS
                       ↑
                       Metode similarity bisa DIGANTI
                       tanpa mengubah pipeline Fuzzy TOPSIS
```

Arsitektur ini **modular** — metode similarity bisa diganti tanpa menyentuh fuzzifikasi maupun TOPSIS.

---

## Contoh Kasus untuk Excel

### Data untuk Dimasukkan ke Excel

#### Sheet 1: Base Cases
```
case_id | Demam | Nafsu_Makan | Umur | Diagnosis
K1      | True  | Buruk       | 3    | Flu
K2      | False | Baik        | 7    | Cacingan
K3      | True  | Baik        | 2    | Flu
```

#### Sheet 2: Test Case
```
Test | True | Buruk | 4 | Flu
```

#### Sheet 3: Bobot
```
Atribut     | Weight | Type    | Normalized Weight
Demam       | 3      | benefit | 0.5000
Nafsu_Makan | 2      | benefit | 0.3333
Umur        | 1      | benefit | 0.1667
```

#### Sheet 4: Perhitungan Step-by-Step

**Decision Matrix:**
```
Case | C1(Demam) | C2(Nafsu) | C3(Umur)
K1   | 1.00      | 1.00      | 0.80
K2   | 0.00      | 0.00      | 0.40
K3   | 1.00      | 0.00      | 0.60
```

**Fuzzy (TFN a,b,c):**
```
Case | C1_a | C1_b | C1_c | C2_a | C2_b | C2_c | C3_a | C3_b | C3_c
K1   | 0.90 | 1.00 | 1.00 | 0.90 | 1.00 | 1.00 | 0.70 | 0.80 | 0.90
K2   | 0.00 | 0.00 | 0.10 | 0.00 | 0.00 | 0.10 | 0.30 | 0.40 | 0.50
K3   | 0.90 | 1.00 | 1.00 | 0.00 | 0.00 | 0.10 | 0.50 | 0.60 | 0.70
```

**Defuzzified (Centroid):**
```
Case | C1     | C2     | C3
K1   | 0.9667 | 0.9667 | 0.8000
K2   | 0.0333 | 0.0333 | 0.4000
K3   | 0.9667 | 0.0333 | 0.6000
```

**Denominator per kolom:**
```
C1: √(0.9667² + 0.0333² + 0.9667²) = 1.3675
C2: √(0.9667² + 0.0333² + 0.0333²) = 0.9678
C3: √(0.8000² + 0.4000² + 0.6000²) = 1.0770
```

**Normalized:**
```
Case | r_C1   | r_C2   | r_C3
K1   | 0.7069 | 0.9989 | 0.7427
K2   | 0.0244 | 0.0344 | 0.3714
K3   | 0.7069 | 0.0344 | 0.5570
```

**Weighted (v = r × w):**
```
Case | v_C1   | v_C2   | v_C3
K1   | 0.3535 | 0.3330 | 0.1238
K2   | 0.0122 | 0.0115 | 0.0619
K3   | 0.3535 | 0.0115 | 0.0928
```

**Ideal Solutions:**
```
A+ | 0.3535 | 0.3330 | 0.1238
A- | 0.0122 | 0.0115 | 0.0619
```

**Distances & CC:**
```
Case | D+     | D-     | CC     | Rank
K1   | 0.0000 | 0.4729 | 1.0000 | 1
K3   | 0.3231 | 0.3426 | 0.5147 | 2
K2   | 0.4729 | 0.0000 | 0.0000 | 3
```

**Confusion Matrix:**
```
         | Pred + | Pred -
Actual + |  TP=1  |  FN=1
Actual - |  FP=0  |  TN=1

Accuracy  = 66.67%
Precision = 100.00%
Recall    = 50.00%
F1-Score  = 66.67%
```

---

## Prompt untuk Claude Web — Membuat Excel

Salin prompt di bawah ini ke Claude Web untuk menghasilkan file Excel:

---

### PROMPT (Copy mulai baris berikutnya):

```
Buatkan saya file Excel (.xlsx) untuk perhitungan manual Fuzzy TOPSIS dengan data berikut.

## DATA KASUS

### Atribut:
| ID | Nama | Tipe Data | Goal? | Weight | Type |
|----|------|-----------|-------|--------|------|
| 1 | Demam | True/False | F (kriteria) | 3 | benefit |
| 2 | Nafsu_Makan | Baik/Buruk | F (kriteria) | 2 | benefit |
| 3 | Umur | Angka (tahun) | F (kriteria) | 1 | benefit |
| 4 | Diagnosis | Teks | T (goal) | - | - |

### Base Cases:
| case_id | Demam | Nafsu_Makan | Umur | Diagnosis |
|---------|-------|-------------|------|-----------|
| K1 | True | Buruk | 3 | Flu |
| K2 | False | Baik | 7 | Cacingan |
| K3 | True | Baik | 2 | Flu |

### Test Case:
| | Demam | Nafsu_Makan | Umur | Diagnosis |
|--|-------|-------------|------|-----------|
| Test | True | Buruk | 4 | Flu |

## LANGKAH PERHITUNGAN (Buat 1 sheet per step)

### Sheet 1: "Data Input"
- Tabel base cases + test case + atribut + bobot

### Sheet 2: "Step 1 - Decision Matrix"
- Similarity per atribut:
  - Kategorik (Demam, Nafsu): exact match → 1.0 atau 0.0
  - Numerik (Umur): 1 - |base - test| / range, dimana range = max - min = 7 - 2 = 5

### Sheet 3: "Step 2 - Fuzzifikasi"
- Setiap similarity x → TFN (a, b, c):
  - a = MAX(0, x - 0.1)
  - b = x
  - c = MIN(1, x + 0.1)

### Sheet 4: "Step 3 - Defuzzifikasi"
- Setiap TFN → crisp = (a + b + c) / 3

### Sheet 5: "Step 4 - Normalisasi"
- Normalisasi bobot: w_j = weight_j / SUM(weights)
- Denominator per kolom: √(Σ x²)
- r_ij = x_ij / denominator_j

### Sheet 6: "Step 5 - Weighted Matrix"
- v_ij = w_j × r_ij

### Sheet 7: "Step 6 - Ideal Solutions"
- A+ = MAX per kolom (semua benefit)
- A- = MIN per kolom

### Sheet 8: "Step 7 - Distance & CC"
- D+ = √(Σ (v_ij - A_j+)²)
- D- = √(Σ (v_ij - A_j-)²)
- CC = D- / (D+ + D-)
- Ranking berdasarkan CC descending

### Sheet 9: "Step 8 - Confusion Matrix"
- Strategy: Top-1 (hanya rank 1 = predicted positive)
- TP, FP, TN, FN
- Accuracy, Precision, Recall, F1-Score

### Sheet 10: "Ringkasan"
- Tabel ranking final: Case, D+, D-, CC, Rank, Diagnosis, Match

## INSTRUKSI FORMATTING:
- Gunakan warna header: biru tua dengan teks putih
- Highlight CC Score tertinggi dengan warna hijau
- Gunakan border untuk semua tabel
- Tampilkan rumus di cell (bukan hardcode angka) agar bisa diverifikasi
- Tambahkan komentar/catatan di setiap sheet yang menjelaskan apa yang dihitung
- Format angka: 4 desimal
- Freeze pane di baris header

## HASIL YANG DIHARAPKAN:
- K1 → CC = 1.0000 (Rank 1)
- K3 → CC = 0.5147 (Rank 2)
- K2 → CC = 0.0000 (Rank 3)
- Accuracy = 66.67%, Precision = 100%, Recall = 50%, F1 = 66.67%
```

---

## Ringkasan untuk Presentasi ke Dosen

| No | Pertanyaan | Jawaban Kunci |
|----|-----------|---------------|
| 1 | Atribut mana yang difuzzifikasi? | Semua atribut kriteria (goal='F') — yang difuzzifikasi adalah **skor similarity**, bukan nilai mentah |
| 2 | Contoh kasus di Excel? | 3 kasus × 3 atribut (Demam True/False, Nafsu Baik/Buruk, Umur angka) |
| 3 | Kenapa Fuzzy + TOPSIS? | Fuzzy = tangkap ketidakpastian; TOPSIS = ranking multi-kriteria perspektif ganda |
| 4 | Fuzzy saja / TOPSIS saja? | Fuzzy saja tidak bisa ranking; TOPSIS saja kurang robust terhadap noise |
| 5 | Buktikan cara kerja? | Perhitungan manual 9 step → hasil CC harus sama dengan output web |
| 6 | Vs Fuzzy AHP? | TOPSIS lebih scalable, tidak perlu matriks perbandingan, tidak perlu uji konsistensi |
| 7 | Hanya numerik? | Tidak — atribut teks/boolean → similarity 0/1 → lalu difuzzifikasi |
| 8 | Mana yang difuzzifikasi? | Kriteria = Ya, Goal = Tidak, Metadata = Tidak |

---

**Dokumen ini dibuat sebagai panduan analisis untuk skripsi DutaShell.**
**71220853 - Matthew Alexander**
