# Penggajian Mutiara Hikmah

Aplikasi penggajian lokal berbasis PHP MVC, PDO, MySQL 8, Bootstrap 5.3.8, dan Chart.js 4.5.1. Tampilan mengikuti referensi dashboard pengguna dengan sidebar putih dan aksen hijau bergradasi. Aset antarmuka tersedia lokal tanpa CDN saat aplikasi dijalankan.

## Membuka aplikasi

1. Jalankan Apache dan MySQL pada Laragon.
2. Buka **http://system-penggajian.test**. Akses melalui **http://localhost/system-penggajian/** juga didukung; rute, aset, dan endpoint API mengikuti folder aplikasi secara otomatis.
3. Pada penggunaan pertama, buat nama, ID pengguna, dan kata sandi minimal 12 karakter. Aplikasi tidak menyediakan kredensial bawaan.
4. Masuk. Periode awal mengikuti transaksi terbaru yang tersedia pada database; tanpa transaksi aplikasi menampilkan keadaan kosong.

Pada instalasi lokal awal, data aplikasi utama sudah dimigrasikan. Repositori publik hanya berisi kode, skema, dan dokumentasi: backup, ekspor database, nominal gaji asli, kredensial, serta hasil QA yang memuat data pribadi tetap disimpan lokal dan tidak disertakan. Instalasi baru perlu melakukan migrasi menggunakan backup milik pengguna. Database `penggajian_test` digunakan khusus pengujian dan tidak menjadi database aplikasi utama.

## Instalasi pada komputer lain

Persyaratan: PHP 8.1 atau lebih baru dengan `pdo_mysql`, `bcmath`, `mbstring`, `session`, dan `json`; MySQL 8; Apache dengan `mod_rewrite` dan `AllowOverride All`.

Salin `.env.example` menjadi `.env`, sesuaikan koneksi database, kemudian jalankan:

```powershell
php scripts/install.php
```

Gunakan nama database kosong yang khusus untuk aplikasi ini. Installer membuat tabel yang belum ada dan tidak menghapus tabel atau data yang sudah tersedia. Arahkan virtual host ke direktori proyek; `.htaccess` melindungi source, konfigurasi, arsip, skrip, dan dokumentasi dari HTTP. Untuk jaringan kantor, arahkan akses ke komputer Laragon tersebut sesuai pengaturan jaringan setempat.

### Launcher lokal Windows

Setelah `.env` dan database disiapkan, aktifkan MySQL di Laragon lalu klik dua kali `JALANKAN.bat`. Launcher membuka **http://127.0.0.1:8018** dan menjalankan server PHP di latar belakang. Apache tidak diperlukan untuk alamat ini; akses hanya tersedia dari komputer yang menjalankan launcher.

PHP ditemukan dari PATH terminal atau folder `bin/php` Laragon relatif terhadap lokasi proyek. Untuk lokasi PHP lain, jalankan `powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/start-local.ps1 -PhpPath "C:\lokasi\php.exe" -OpenBrowser`. Launcher tidak mengimpor atau mereset database. Setelah komputer dihidupkan ulang, aktifkan MySQL dan klik launcher lagi. Log server tersedia di `storage/php-local.error.log`.

## Migrasi ulang dari backup SQL Server

Backup sumber adalah native SQL Server 2008 R2 (`10.50`), dengan nama database `gaji_bmt`. `gaji.exe` menyebut katalog `gaji`; aplikasi baru memakai data backup yang diberikan.

Pemulihan yang sudah dilaksanakan menggunakan image resmi `mcr.microsoft.com/mssql/server:2022-latest` pada container `penggajian-sql-audit`, hanya terikat ke loopback port `11433`. File backup dipasang hanya baca, dan database audit diubah menjadi hanya baca setelah pemeriksaan.

```powershell
powershell -NoProfile -File scripts/start-audit.ps1
powershell -NoProfile -File scripts/restore-legacy.ps1
php scripts/migrate-legacy.php
docker stop penggajian-sql-audit
```

Skrip start menyiapkan container audit dan kredensial acak lokal di `storage/legacy/sql-secret.json` bila belum tersedia. Tunggu SQL Server siap sebelum menjalankan restore; jangan menyalin kredensial ke repositori. Jangan gunakan database aktif sebagai target pemulihan. File sumber dapat ditentukan dengan parameter `-BackupPath` pada kedua skrip.

Skrip impor menolak menimpa tabel operasional yang sudah berisi data. Backup sama yang sudah terverifikasi tidak diimpor dua kali. Untuk mencoba backup lain, gunakan database MySQL baru. Arsip dan ekspor berada di `storage/legacy/`, dilindungi dari HTTP dan diabaikan Git. Jangan menghapusnya bila masih diperlukan untuk audit/pemulihan.

### Hasil rekonsiliasi backup yang diberikan

| Pemeriksaan | Hasil |
|---|---:|
| Tabel sumber, termasuk tabel kosong | 19 |
| Seluruh baris aktif sumber yang diarsipkan | 485 |
| Karyawan | 39 |
| Transaksi penghasilan | 332 |
| Baris operasional yang dipetakan | 396 |
| Perbandingan nilai operasional | 11.000, cocok |
| Jumlah penghasilan bruto historis | Cocok; nominal tersimpan lokal |
| Jumlah potongan historis | Cocok; nominal tersimpan lokal |
| Jumlah penghasilan bersih historis | Cocok; nominal tersimpan lokal |

`RESTORE VERIFYONLY` dan `DBCC CHECKDB` lolos. Checksum setiap baris dan tabel arsip telah dibandingkan setelah penulisan. Kolom sumber `id` dipertahankan sebagai `legacy_id` pada tabel operasional; `id` aplikasi adalah identitas terpisah. Spasi pengisi `char` dipangkas di kanan pada data operasional dan dipertahankan utuh pada arsip.

## Alur penggunaan

- **Master:** kelola bagian dan karyawan. NIP berupa teks, termasuk nol di depan. Kode/NIP tidak diganti saat mengubah data. Penghapusan master yang masih digunakan ditolak.
- **Tunjangan dan Potongan:** kelola komponen berbasis bagian atau NIP sesuai aplikasi lama.
- **Transaksi Penghasilan:** pilih periode, bagian, dan karyawan; masukkan hari efektif serta kehadiran. Mode **Hitung dari master saat ini** memakai tarif/master sekarang. Mode **Nilai komponen transaksi** mempertahankan komponen masukan; digunakan sebagai default saat membuka transaksi tersimpan.
- **Muat komponen master** mengaktifkan perhitungan dari master. Jangan gunakan untuk transaksi historis kecuali memang hendak menghitung ulang dengan master saat ini.
- **Slip & Laporan:** tujuh jenis keluaran, cakupan semua/tertentu, rentang lintas tahun, seluruh komponen, subtotal bagian, serta cetak atau simpan PDF melalui dialog browser. Cetak seluruh komponen dipisah menjadi tabel penghasilan, potongan, kehadiran, dan ringkasan agar tidak terpotong pada kertas landscape.
- **Data & Migrasi:** riwayat rekonsiliasi dan seluruh tabel arsip hanya baca.
- **Profil:** ubah nama atau kata sandi menggunakan kata sandi saat ini.

Setiap simpan dihitung ulang di server; nilai total yang dikirim browser tidak dipercaya. Transaksi NIP/periode sama ditolak dan pengguna perlu membuka transaksi yang sudah ada. Perubahan tarif master tidak mengubah transaksi historis secara otomatis.

## Pengujian

```powershell
php tests/run.php
```

Pengujian tersebut memerlukan ekspor SQL Server lokal di `storage/legacy/export.json` dan database hasil migrasi sebagai pembanding hanya baca: 332 fixture transaksi, 332 rumus absensi, dan 485 checksum arsip.

Setelah database uji disiapkan oleh suite browser, pemetaan tarif/master dapat diuji dengan `php tests/calculator-integration.php`. Seluruh penulisan fixture pada pengujian ini di-rollback.

Untuk pengujian browser, jalankan terminal terpisah:

```powershell
$env:DB_DATABASE = 'penggajian_test'
php -d variables_order=EGPCS -S 127.0.0.1:8018 -t . scripts/router.php
```

Kemudian:

```powershell
npm install
npm run test:browser
```

Suite browser memakai Chrome yang terpasang pada Windows. Suite membuat ulang **hanya** database `penggajian_test`; reset ditolak jika ditemukan akun selain akun QA. Jangan menggunakan nama ini untuk data kerja. Gambar hasil QA dan contoh PDF berada di `storage/qa/` dan tidak bisa diakses lewat HTTP.

Setelah suite browser selesai, jalankan `node tests/laragon-routing.js` untuk memeriksa akses dari akar domain dan subfolder seperti `localhost/system-penggajian/`. Pengujian ini menjalankan server PHP sementara dengan database `penggajian_test`, memeriksa login, aset, navigasi, formulir, API, CSRF, arsip terlindungi, serta logout, kemudian menghentikan server tersebut.

## Dokumen teknis dan batas verifikasi

- `docs/KAMUS_DATA.md`: seluruh tabel/kolom database hasil restore dan pemetaannya.
- `docs/PEMETAAN_LEGACY.md`: form, handler, sumber tarif, pemetaan field, dan rumus yang ditelusuri dari EXE.
- `docs/VERIFIKASI.md`: hasil pemeriksaan dan keterbatasan.
- `storage/legacy/reconciliation.json`: hasil rekonsiliasi terstruktur.

EXE tidak dieksekusi pada lingkungan Windows 7. Kesetaraan perhitungan dibuktikan melalui handler native dan data transaksi tersimpan; ini bukan klaim bahwa seluruh interaksi EXE telah dijalankan. Template `.rpt` tidak tersedia, sehingga laporan dibangun ulang dari data dan dokumentasi visual; kesamaan persis tata letak/pengelompokan Crystal Reports lama belum dapat dibuktikan.

Nilai historis tidak dihitung ulang ketika diimpor. Tidak ada status pembayaran, approval, pajak baru, fingerprint, atau transfer bank otomatis yang ditambahkan.
