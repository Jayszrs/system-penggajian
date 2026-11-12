# Penggajian Mutiara Hikmah

Aplikasi penggajian lokal berbasis PHP MVC, PDO, MySQL 8, Bootstrap 5.3.8, dan Chart.js 4.5.1. Tampilan mengikuti referensi dashboard pengguna dengan sidebar putih dan aksen hijau bergradasi. Aset antarmuka tersedia lokal tanpa CDN saat aplikasi dijalankan.

## Kerja bersama melalui GitHub dan VS Code

Repo utama tim: **https://github.com/Jayszrs/system-penggajian**. Semua anggota perlu memakai repo ini agar **Sync Changes** mengambil dan mengirim perubahan ke tempat yang sama. Pemilik repo menambahkan anggota melalui **Settings > Collaborators**, kemudian anggota menerima undangannya.

Untuk anggota yang belum memiliki project:

```powershell
git clone https://github.com/Jayszrs/system-penggajian.git
cd system-penggajian
code .
```

Jika sudah memiliki project dari repo `lakchamana/system-penggajian`, periksa `git remote -v`. Bila `origin` masih menunjuk ke repo tersebut, arahkan ke repo utama lalu ambil informasi branch:

```powershell
git remote set-url origin https://github.com/Jayszrs/system-penggajian.git
git fetch origin
git branch --set-upstream-to=origin/main main
```

Gunakan branch **main**. Sebelum mulai bekerja, klik **Source Control > Sync Changes** untuk mengambil pembaruan teman. Setelah mengedit, tinjau perubahan, lakukan **Stage Changes**, isi pesan, klik **Commit**, lalu **Sync Changes** untuk mengirimnya. Commit yang belum di-push belum tersedia bagi anggota lain. Jika ada perubahan lokal yang belum siap di-commit, simpan dengan **Stash** sebelum sinkronisasi dan terapkan kembali setelahnya. Jika muncul konflik, selesaikan file yang ditandai, lalu commit dan sinkronkan kembali.

Pengaturan project mengaktifkan `git.autofetch` agar VS Code mengecek pembaruan remote secara berkala. Fetch memperbarui informasi commit; file kerja diperbarui saat **Sync Changes** atau **Pull** dijalankan.

Pada komputer pemilik project ini, repo lama `lakchamana/system-penggajian` tetap tersedia sebagai remote **upstream**. Jika teman masih mengirim perubahan ke repo lama, perubahan tersebut perlu digabungkan secara terpisah dari working tree yang bersih:

```powershell
git fetch upstream
git merge upstream/main
git push origin main
```

Jika merge menghasilkan konflik, selesaikan dan commit terlebih dahulu sebelum push. Untuk alur cukup **Sync Changes**, semua anggota harus push ke repo utama Jayszrs. Sinkronisasi Git hanya membagikan source code; `.env`, data gaji, dan database lokal tidak ikut disinkronkan.

## Membuka aplikasi

1. Klik **Start All** pada Laragon untuk mengaktifkan Apache dan MySQL.
2. Pada penggunaan pertama atau setelah folder proyek dipindahkan/diubah namanya, klik dua kali **`JALANKAN.bat`** di folder proyek. Launcher menghubungkan `www/system-penggajian` ke folder proyek saat ini dan membuka **http://localhost/system-penggajian/**. Pada penggunaan berikutnya cukup **Start All**, lalu buka alamat tersebut. Tidak perlu menyalin proyek ke folder `www` atau menjalankan server PHP terpisah.
3. Jika database belum memiliki akun, buat nama, ID pengguna, dan kata sandi minimal 12 karakter. Jika memakai backup SQL, masuk menggunakan akun yang ada dalam backup. Aplikasi tidak menyediakan kredensial bawaan.
4. Masuk. Periode awal mengikuti transaksi terbaru yang tersedia pada database; tanpa transaksi aplikasi menampilkan keadaan kosong.

Pada instalasi lokal awal, data aplikasi utama sudah dimigrasikan. Repositori publik hanya berisi kode, skema, dan dokumentasi: backup, ekspor database, nominal gaji asli, kredensial, serta hasil QA yang memuat data pribadi tetap disimpan lokal dan tidak disertakan. Instalasi baru perlu melakukan migrasi menggunakan backup milik pengguna. Database `penggajian_test` digunakan khusus pengujian dan tidak menjadi database aplikasi utama.

## Instalasi pada komputer lain

Persyaratan: PHP 8.1 atau lebih baru dengan `pdo_mysql`, `bcmath`, `mbstring`, `session`, dan `json`; MySQL 8; Apache dengan `mod_rewrite` dan `AllowOverride All`.

Salin `.env.example` menjadi `.env`, sesuaikan koneksi database, kemudian jalankan:

```powershell
php scripts/install.php
```

Gunakan nama database kosong yang khusus untuk aplikasi ini. Installer membuat tabel yang belum ada dan tidak menghapus tabel atau data yang sudah tersedia. Arahkan virtual host ke direktori proyek; `.htaccess` melindungi source, konfigurasi, arsip, skrip, dan dokumentasi dari HTTP. Untuk jaringan kantor, arahkan akses ke komputer Laragon tersebut sesuai pengaturan jaringan setempat.

### Memperbarui instalasi yang sudah berisi data

Cadangkan database terlebih dahulu ke lokasi privat di `storage/`. Setelah kode diperbarui, jalankan:

```powershell
php scripts/migrate-accounting.php
```

Migrasi ini idempoten: menambahkan `calculation_version` dan `calculation_snapshot` hanya jika belum ada. Nominal, 32 kolom sumber, identitas transaksi, dan arsip tidak dihitung ulang. Transaksi lama diberi versi `legacy_v1`; transaksi baru atau perubahan komponen yang dihitung ulang memakai `accounting_v2`. Installer baru sudah menjalankan migrasi metadata ini secara otomatis.

Baseline audit serta backup lokal berada di `storage/audit/` dan `storage/exports/`; keduanya diabaikan Git dan dilindungi dari akses HTTP. Jika baseline audit lokal sudah tersedia, jalankan `php scripts/verify-accounting-baseline.php` untuk membandingkan checksum kolom asli sebelum/sesudah pembaruan. Skrip tersebut membaca database dan menulis hasil verifikasi ke direktori audit privat.

### Launcher lokal Windows

Setelah `.env` dan database disiapkan, klik **Start All** di Laragon lalu klik dua kali `JALANKAN.bat`. Launcher memakai Apache Laragon di **http://localhost/system-penggajian/**. Folder proyek boleh tetap berada di Downloads dan boleh memiliki spasi pada namanya. Junction yang terputus akibat perubahan nama folder diperbaiki otomatis; folder biasa atau junction yang masih dipakai proyek lain tidak ditimpa. Launcher tidak mengimpor atau mereset database.

Laragon ditemukan dari aplikasi Laragon yang sedang berjalan, lokasi proyek di dalam `www`, atau `C:\laragon` / `D:\laragon`. Untuk instalasi di lokasi lain:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/setup-laragon.ps1 -LaragonPath "D:\lokasi\laragon" -OpenBrowser
```

Setelah komputer dihidupkan ulang, cukup **Start All** di Laragon lalu buka **http://localhost/system-penggajian/**. Alamat **http://system-penggajian.test** juga dapat digunakan setelah fitur Auto Virtual Hosts Laragon diaktifkan dan **Reload** dijalankan.

Jika memakai terminal Laragon (Cmder), jalankan pindah folder dan launcher sebagai **dua perintah terpisah**:

```bat
cd /d "C:\Users\jaela\Downloads\Project2 Web\penggajianmutiarahikmah"
JALANKAN.bat
```

Untuk server PHP terpisah tanpa Apache, skrip lama tetap tersedia. Aktifkan MySQL, lalu jalankan `powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/start-local.ps1 -PhpPath "C:\lokasi\php.exe" -OpenBrowser`. Alamat alternatif ini adalah **http://127.0.0.1:8018**; log server ada di `storage/php-local.error.log`.

### Memakai backup SQL MySQL

Jika memiliki `penggajian.sql`, buat database kosong melalui HeidiSQL/phpMyAdmin di Laragon, lalu impor berkas tersebut ke database itu. Sesuaikan `DB_DATABASE`, `DB_HOST`, `DB_PORT`, `DB_USERNAME`, dan `DB_PASSWORD` pada `.env`. Gunakan database terpisah jika database lama sudah berisi data yang masih diperlukan. Akun dalam backup tetap dipakai; halaman pendaftaran hanya muncul jika belum ada akun.

Jalankan `php scripts/install.php` dari terminal Laragon di folder proyek untuk melengkapi tabel dan metadata yang belum ada setelah impor. Installer tidak mengganti akun atau nominal yang sudah tersimpan. Simpan dump SQL, backup, dan `.env` secara lokal; berkas tersebut tidak perlu diunggah ke GitHub.

## Migrasi ulang dari backup SQL Server

Backup sumber adalah native SQL Server 2008 R2 (`10.50`), dengan nama database `gaji_bmt`. `gaji.exe` menyebut katalog `gaji`; aplikasi baru memakai data backup yang diberikan.

Migrasi dijalankan otomatis melalui satu perintah, termasuk persiapan Docker/SQL Server audit, restore salinan baru, ekspor seluruh sumber, backup MySQL yang diuji pemulihannya, rekonsiliasi, serta pemeriksaan hasil:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/migrate-legacy.ps1 -BackupPath "$env:USERPROFILE\Downloads\GAJI-5.dat"
```

Target MySQL mengikuti `.env`. Proses tidak meminta konfirmasi pengguna. Backup sumber dipasang hanya baca; container audit hanya membuka port loopback dan dihentikan setelah proses. Kredensial, backup, ekspor, serta laporan privat berada dalam `storage/legacy/<SHA-256>/<proses>/`, diabaikan Git, dan dilindungi dari HTTP.

Backup yang sama tetap direkonsiliasi meskipun pernah terverifikasi. Baris yang belum tersedia ditambahkan otomatis jika identitas dan relasinya valid. Perubahan/tambahan lokal dan penghapusan yang tercatat dipertahankan. Master kosong tidak diisi dari transaksi historis. Pengulangan identik tidak menulis ulang data atau metadata. Sumber berbeda pada database yang sudah memiliki arsip ditolak; gunakan database terpisah.

Halaman **Data & Migrasi** menyediakan inventaris tabel, status pemetaan, pencarian nama/NIP, serta pembandingan setiap master dan komponen historis dengan arsip. Panduan lengkap, aturan konflik, dan pilihan audit hanya baca tersedia pada [Migrasi otomatis](docs/MIGRASI_OTOMATIS.md).

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
- **Data & Migrasi:** riwayat rekonsiliasi, status setiap tabel, pencarian karyawan, serta pembandingan master dan komponen historis dengan arsip hanya baca.
- **Profil:** ubah nama atau kata sandi menggunakan kata sandi saat ini.

Setiap simpan melewati evaluasi server; nilai total yang dikirim browser tidak dipercaya. Komponen yang berubah dihitung ulang, sedangkan penyimpanan tanpa perubahan mempertahankan nominal sebelumnya. Transaksi NIP/periode sama ditolak dan pengguna perlu membuka transaksi yang sudah ada. Perubahan tarif master tidak mengubah transaksi historis secara otomatis.

Pada `accounting_v2`, nominal setiap komponen dibulatkan ke rupiah sebelum dijumlahkan: bruto = seluruh penghasilan, potongan = seluruh potongan, bersih = bruto − potongan. Master potongan lain-lain mengisi `plain`; `lain1` tetap penghasilan manual. Dashboard menghitung penerima berdasarkan NIP unik yang benar-benar memiliki transaksi pada periode/bagian terpilih. Rekap gaji pokok menjumlahkan gaji pokok + jabatan; tarif harian dan lembur hanya ditampilkan sebagai tarif, tanpa subtotal.

Kesalahan masukan memblokir simpan. Peringatan, seperti kehadiran belum lengkap, perubahan manual, atau hitung ulang histori, perlu diperiksa dan dikonfirmasi melalui dialog sebelum disimpan. Perubahan nominal, sumber tarif, atau revisi transaksi setelah pratinjau memerlukan pemeriksaan hasil terbaru. Aturan absensi lama dan nominal BJBS/BPJS tetap ditampilkan sebagai dasar perhitungan, tanpa menambahkan asumsi kebijakan atau persentase baru.

## Pengujian

### Format cetak

Slip karyawan dan slip per bagian menggunakan desain klasik dengan kertas **A5 landscape**, margin 8 mm, satu transaksi per lembar. Semua pintasan slip pada Penggajian menggunakan template yang sama. Nama Accounting mengikuti nama lengkap akun yang mencetak; lokasi default Tambun dapat disesuaikan melalui `App\Domain\PrintLayout::LOCATION`. Tanggal dan jam diperbarui saat mencetak dengan zona waktu **WIB**, termasuk melalui Ctrl+P.

Rekap tetap memakai **A4 landscape**. Judul, periode, nama petugas, jam cetak, dan kepala tabel diulang pada halaman lanjutan. Rincian lengkap tetap dipisah menjadi penghasilan, potongan, kehadiran, dan ringkasan. Pada dialog cetak Chrome, gunakan skala **100%**, ukuran kertas sesuai dokumen, dan nonaktifkan header/footer bawaan browser.

Jalankan `npm run test:print` dari terminal Laragon untuk memeriksa tujuh laporan dan kedua pintasan slip. Pengujian ini membuat database sementara dengan nama acak `penggajian_print_test_*`, hanya berisi fixture sintetis, lalu menghapusnya setelah selesai. PDF uji disimpan di `storage/qa/print/`. Pemeriksaan ukuran kertas, jumlah halaman, pengulangan judul, kelengkapan teks, serta gambar hasil render dapat dijalankan dengan `python tests/verify-print-pdfs.py` setelah paket Python `pymupdf` tersedia. Pengujian cetak tidak memerlukan backup SQL Server dan tidak mengubah database aplikasi utama.

### Pengujian perhitungan dan browser

```powershell
php tests/run.php
php tests/calculator-accounting.php
php tests/confirmation-accounting.php
php tests/report-accounting.php
php tests/dashboard-accounting.php
```

`tests/run.php` memerlukan ekspor SQL Server lokal (argumen path, ekspor lama, atau ekspor proses terbaru) dan database hasil migrasi sebagai pembanding hanya baca. Ekspektasi mengikuti manifest sumber; setiap kolom master/transaksi, relasi, checksum arsip, dan total baris sumber diperiksa tanpa menghitung tambahan lokal sebagai data sumber. Pengujian kalkulasi dan konfirmasi merupakan fixture murni tanpa koneksi database. Pengujian laporan/dashboard membaca transaksi dalam database yang dikonfigurasi melalui transaksi hanya baca; keluarannya tidak memuat nominal atau identitas karyawan.

Setelah database uji disiapkan oleh suite browser, jalankan `php tests/calculator-integration.php` untuk pemetaan tarif/master dan `php tests/workflow-accounting.php` untuk pratinjau, konfirmasi, simpan, serta benturan revisi. Keduanya membatasi penulisan ke `penggajian_test`; fixture integrasi di-rollback dan fixture alur dibersihkan setelah pemeriksaan. Jangan mengarahkannya ke database kerja.

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

- `docs/MIGRASI_OTOMATIS.md`: perintah otomatis, rekonsiliasi, pelestarian lokal, dan penelusuran karyawan.
- `docs/KAMUS_DATA.md`: seluruh tabel/kolom database hasil restore dan pemetaannya.
- `docs/PEMETAAN_LEGACY.md`: form, handler, sumber tarif, pemetaan field, dan rumus yang ditelusuri dari EXE.
- `docs/VERIFIKASI.md`: hasil pemeriksaan dan keterbatasan.
- `docs/AUDIT_PENGGAJIAN.md`: temuan audit angka, aturan versi baru, pelestarian histori, serta batas kebijakan.
- `docs/PERAPIHAN_TAMPILAN.md`: filter dan Reset, input tahun, tata letak slip, serta pengujian tampilan/PDF sintetis.
- `storage/legacy/reconciliation.json`: hasil rekonsiliasi terstruktur.

EXE tidak dieksekusi pada lingkungan Windows 7. Kesetaraan perhitungan dibuktikan melalui handler native dan data transaksi tersimpan; ini bukan klaim bahwa seluruh interaksi EXE telah dijalankan. Template `.rpt` tidak tersedia, sehingga laporan dibangun ulang dari data dan dokumentasi visual; kesamaan persis tata letak/pengelompokan Crystal Reports lama belum dapat dibuktikan.

Nilai historis tidak dihitung ulang ketika diimpor. Penyimpanan ulang tanpa perubahan komponen mempertahankan nilai dan versi sebelumnya. Konfirmasi peringatan adalah pemeriksaan oleh pengguna yang sedang memasukkan transaksi; aplikasi belum menyediakan persetujuan berjenjang, status pembayaran, mesin pajak/iuran otomatis, absensi sidik jari, atau transfer bank otomatis.
