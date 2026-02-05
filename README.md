<<<<<<< HEAD
<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

You may also try the [Laravel Bootcamp](https://bootcamp.laravel.com), where you will be guided through building a modern Laravel application from scratch.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com/)**
- **[Tighten Co.](https://tighten.co)**
- **[WebReinvent](https://webreinvent.com/)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel/)**
- **[Cyber-Duck](https://cyber-duck.co.uk)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Jump24](https://jump24.co.uk)**
- **[Redberry](https://redberry.international/laravel/)**
- **[Active Logic](https://activelogic.com)**
- **[byte5](https://byte5.de)**
- **[OP.GG](https://op.gg)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
=======
# Sistem Klasifikasi SVM - DutaShell

Aplikasi berbasis Laravel untuk klasifikasi data menggunakan Support Vector Machine (SVM) dengan dukungan multi-kernel, multi-class, serta antarmuka web dan CLI.

[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-blue)](https://www.php.net/)
[![Laravel Version](https://img.shields.io/badge/Laravel-11.9-red)](https://laravel.com)

## Fitur Utama

- Multi-kernel SVM: Linear/SGD, RBF, dan Sigmoid dengan parameter yang dapat dikonfigurasi
- Multi-class classification dengan riwayat training dan inferensi
- Antarmuka web untuk manajemen atribut, data, training, dan prediksi
- Confidence score pada setiap prediksi dan penyimpanan model dalam format JSON
- Dukungan CLI untuk training dan inferensi

## Persyaratan

- PHP 8.2+
- Composer
- MySQL atau MariaDB
- Node.js dan npm

## Instalasi Singkat

### Menggunakan Laravel Herd (Direkomendasikan)

Laravel Herd adalah alat resmi Laravel untuk pengembangan lokal. Untuk menjalankan aplikasi ini dengan Herd:

1. Pastikan Laravel Herd sudah terinstal di sistem Anda
2. Import folder proyek ini ke Herd:
   - Buka aplikasi Herd
   - Klik "Add Site"
   - Pilih folder sebagai root folder
   - Beri nama situs (misalnya: dutashell.test)
   - Tambahkan situs baru dan jalankan melalui Herd

### Alternatif: Menggunakan Artisan Serve

Jika tidak menggunakan Herd, jalankan perintah berikut:

```bash
# Clone repository
git clone <repository-url>
cd KerjaPraktik

# Install dependencies
composer install
npm install

# Setup environment
cp .env.example .env
php artisan key:generate

# Konfigurasi database di .env
# DB_DATABASE=nama_database
# DB_USERNAME=username
# DB_PASSWORD=password

# Jalankan migrasi
php artisan migrate

# Build assets
npm run build

# Jalankan server
php artisan serve
```

Akses aplikasi di `http://localhost:8000` (untuk artisan serve) atau di `http://dutashell.test` (untuk Herd).

## Konfigurasi SVM (.env)

```env
# Path ke script training (opsional)
SVM_SCRIPT=scripts/decision-tree/SVM.php

# Path ke script inferensi (opsional)
SVM_INFER_SCRIPT=scripts/decision-tree/SVMInfer.php

# Simpan model setelah training (1=yes, 0=no)
SVM_SAVE_MODEL=1

# Proporsi data testing (0.0-0.9)
SVM_TEST_RATIO=0.3

# Decision threshold untuk klasifikasi binary
SVM_THRESHOLD=0.0

# Seed untuk train/test split (opsional)
SVM_SPLIT_SEED=42
```

## Cara Menggunakan (Ringkas)

1. Setup atribut di menu Attribute Management dan tandai satu sebagai goal/target.
2. Masukkan data training melalui Generate Case (manual atau generate otomatis).
3. Training model di menu Support Vector Machine, pilih kernel (sgd, rbf, sigmoid), lalu klik *Train Model*.
4. Prediksi menggunakan kernel yang sama, isi nilai atribut, lalu klik *Predict* untuk melihat hasil dan confidence score.

## Dokumentasi

- `docs/INDEX.md` - Peta dokumentasi dan tautan cepat.
- `docs/README.md` - Ringkasan sistem, instalasi, dan konfigurasi.
- `docs/user-guide.md` - Panduan penggunaan aplikasi.

## Teknologi

- Backend: PHP 8.2, Laravel 11.9
- Database: MySQL/MariaDB
- ML: Rubix ML dan implementasi SVM kustom
- Frontend: Blade Templates, Vite, JavaScript

## Struktur Project

```
KerjaPraktik/
├── .editorconfig                 # Konfigurasi format kode
├── .env.example                  # Template file environment
├── .gitignore                    # File dan folder yang diabaikan oleh Git
├── README.md                     # Dokumentasi utama proyek
├── artisan                       # Command-line interface Laravel
├── composer.json                 # Dependency dan konfigurasi proyek PHP
├── composer.lock                 # Versi dependency yang terinstall
├── composer.phar                 # Composer executable
├── package.json                  # Dependency frontend
├── phpunit.xml                   # Konfigurasi testing PHP
├── vite.config.js                # Konfigurasi build asset frontend
├── app/                          # Kode sumber utama aplikasi
│   ├── Console/                  # Command-line commands
│   ├── Exceptions/               # Handler exception
│   ├── Http/                     # Controllers, middleware, request
│   │   ├── Controllers/          # File controller aplikasi
│   │   │   ├── AuthController.php     # Authentication
│   │   │   ├── AtributController.php  # Management atribut
│   │   │   ├── CaseController.php     # Management kasus
│   │   │   ├── InferenceController.php # Inference system
│   │   │   ├── SVMController.php      # SVM functionality
│   │   │   └── ...                    # Other controllers
│   │   ├── Middleware/           # HTTP middleware
│   │   └── Requests/             # Form request validation
│   ├── Models/                   # Model Eloquent
│   ├── Support/                  # Helper dan support classes
│   └── ...                       # Other app directories
├── bootstrap/                    # File bootstrap aplikasi
├── config/                       # File konfigurasi Laravel
├── database/                     # Migration, seeders, factories
├── dataset/                      # Dataset untuk training dan testing
├── docs/                         # Dokumentasi proyek
│   ├── README.md                 # Dokumentasi utama
│   ├── INDEX.md                  # Indeks dokumentasi
│   ├── user-guide.md             # Panduan pengguna
│   └── api-endpoints.md          # Dokumentasi API endpoints
├── public/                       # File publik (CSS, JS, gambar)
│   ├── index.php                 # Entry point aplikasi
│   ├── css/                      # Asset CSS
│   ├── js/                       # Asset JS
│   ├── vendor/                   # Asset vendor
│   └── ...                       # File publik lainnya
├── scripts/                      # Skrip eksternal (ML, DLL)
│   └── decision-tree/            # Skrip algoritma decision tree
│       ├── SVM.php               # Script training SVM
│       └── SVMInfer.php          # Script inferensi SVM
├── storage/                      # File disimpan (models, logs, dll.)
│   └── app/
│       └── svm/                  # Model SVM disimpan di sini
├── svm_models/                   # Alternatif lokasi penyimpanan model SVM
└── tests/                        # File testing
    └── Feature/                  # Feature tests
```

**Made by**: 71220924-Giovanka.S.H.P.
>>>>>>> 1caa14645c69b47910ab957c1380a891efae9714
