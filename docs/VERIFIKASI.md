# Hasil verifikasi

Pemeriksaan dilakukan pada 31 Oktober–1 November 2026, zona waktu Asia/Jakarta. Aplikasi berjalan lokal pada Laragon, PHP 8.1.10 dan MySQL 8.0.30. Browser QA menggunakan Chrome terpasang melalui Playwright, dengan database terpisah `penggajian_test`.

## Database sumber

- Backup asli dipasang hanya baca pada SQL Server 2022 dalam Docker.
- Metadata: database `gaji_bmt`, engine pembuat backup SQL Server 2008 R2 (`10.50`).
- `RESTORE VERIFYONLY` dan `DBCC CHECKDB` lolos.
- 19 tabel sumber dan 99 kolom diinventarisasi dari katalog setelah restore, termasuk `dbo.dtproperties` yang ditandai sistem oleh SQL Server tetapi tetap termasuk inventaris yang perlu dipertahankan.
- 485 baris aktif diarsipkan; 396 baris dipetakan ke tabel operasional.
- Seluruh 11.000 perbandingan nilai operasional cocok setelah penulisan MySQL.
- Seluruh checksum baris dan tabel arsip cocok setelah pembacaan ulang.
- Tidak ditemukan duplikasi NIP/periode atau transaksi tanpa master karyawan pada snapshot ini.
- Data transaksi mencakup Mei 2024 dan Juli 2024 sampai September 2025. Bulan tanpa transaksi tidak dibuat-buat.

| Total historis | Nominal |
|---|---:|
| Bruto | Cocok; nominal tersimpan lokal |
| Potongan | Cocok; nominal tersimpan lokal |
| Bersih | Cocok; nominal tersimpan lokal |

Nominal rekonsiliasi lengkap tersedia hanya pada laporan lokal `storage/legacy/reconciliation.json` dan salinan dokumentasi lokal di `storage/private-docs/`; berkas tersebut tidak masuk repositori publik.

## Kalkulasi dan data

`php tests/run.php` lolos **1.835 pemeriksaan**:

- Jumlah 32 field dan pemetaan tanpa field ganda.
- Format nominal 18 digit tanpa floating point.
- Pembulatan setengah positif/negatif sesuai konversi numeric SQL.
- Penolakan kehadiran berlebih dan masukan notasi ilmiah.
- Bruto, potongan, dan bersih cocok pada seluruh 332 transaksi sumber.
- Rumus absensi cocok pada seluruh 332 transaksi menggunakan tarif historis yang tersirat pada nilai sumber.
- 485 checksum arsip, seluruh tabel kosong, jumlah migrasi, total historis, dan periode terbaru cocok.

Migrasi memeriksa nilai setiap kolom yang dipetakan; pengujian finansial tidak menghitung ulang tarif historis menggunakan master sekarang.

Audit lanjutan membedakan bukti sumber `legacy_v1` dari koreksi transaksi baru `accounting_v2`. Pembulatan baru dilakukan per komponen sebelum penjumlahan, sehingga komponen yang ditampilkan, bruto, potongan, dan bersih saling cocok. Master `p_lain` menjadi potongan `plain` pada versi baru; binding native lama ke penghasilan `lain1` tetap didokumentasikan dan nominal historis tidak direklasifikasi massal.

| Pengujian audit | Pemeriksaan lolos | Cara kerja |
|---|---:|---|
| `php tests/calculator-accounting.php` | 91 | Fixture aritmetika/validasi murni, tanpa database |
| `php tests/calculator-integration.php` | 29 | Pemetaan master/tarif, pembulatan, snapshot; fixture database uji di-rollback |
| `php tests/confirmation-accounting.php` | 126 | Token sesi, perubahan hasil/sumber/identitas, masa berlaku, dan parser periode; tanpa database |
| `php tests/workflow-accounting.php` | 82 | Pratinjau, konfirmasi, simpan, perlindungan revisi, histori; khusus database uji |
| `php tests/report-accounting.php` | 42.760 | Render slip/rekap dibandingkan baris sumber, subtotal, total, unit, rentang, dan filter; hanya baca |
| `php tests/dashboard-accounting.php` | 2.521 | Kartu, grafik, kelompok bagian, dan tautan dibandingkan dengan komponen transaksi; hanya baca |

Audit hanya baca pada 332 transaksi aktif menemukan nol perbedaan antara jumlah komponen dan bruto, jumlah potongan dan total potongan, serta bruto dikurangi potongan dan bersih. Tidak ditemukan NULL komponen/total, periode invalid, duplikasi NIP/periode, snapshot identitas kosong, atau referensi hilang. Koreksi pembulatan mencegah kesalahan pada kasus pecahan sintetis dan transaksi mendatang; bukan alasan mengganti nominal histori yang telah cocok.

Migrasi metadata `scripts/migrate-accounting.php` tidak mengubah nominal. Baseline dan ekspor sebelum perubahan berada lokal di `storage/audit/`; hasil pemeriksaan sesudah penerapan ditulis oleh `scripts/verify-accounting-baseline.php`. Penerapan pada database utama selesai pada 1 November 2026 setelah pengujian terpisah dan backup tambahan sebelum DDL. Migrasi dijalankan dua kali untuk memeriksa idempotensi. Verifikasi sesudahnya menyatakan **17 tabel tetap identik pada kolom asli, 332 transaksi tetap `legacy_v1`, dan 332 snapshot histori tetap NULL**. Bukti lokal: `after.json`, `metadata-after.json`, serta `backup-metadata.json` pada direktori audit yang dirujuk `storage/audit/current-accounting-audit.txt`.

## Pengujian browser

**17 skenario end-to-end lolos**: sepuluh skenario aplikasi utama dan tujuh skenario audit. Cakupannya meliputi:

1. Pengaturan akun pertama, login, dashboard, periode terbaru, dan grafik lokal.
2. Pembukaan seluruh modul master, riwayat migrasi, dan arsip tabel tambahan.
3. Tambah master, NIP bernol awal, escaping nama, dan larangan menghapus bagian yang masih digunakan.
4. Kalkulasi server, simpan transaksi, penolakan transaksi periode sama, perubahan tarif master yang tidak mengubah histori, dan pembatalan perubahan.
5. Ketujuh laporan, rentang lintas tahun, penolakan rentang terbalik, seluruh komponen, serta cetak PDF.
6. Layout ponsel, buka/tutup sidebar, pemilih periode, pencarian karyawan, periode kosong, penolakan CSRF, dan perlindungan berkas internal.

Tujuh skenario audit memeriksa total browser yang dimanipulasi, nominal 18 digit, angka negatif, potongan berlebih, hari kalender/kehadiran, tarif kosong dibanding nol eksplisit, token tanpa pengakuan atau berubah, pembatalan sebelum POST, keberhasilan setelah commit, penghapusan dengan konfirmasi, fokus keyboard pada modal ponsel, simulasi server 500 tanpa kehilangan isian, serta master `p_lain` yang mengurangi bersih tepat satu kali. Gambar desktop/ponsel disimpan di `storage/qa/`; data uji dipisahkan dari database aplikasi utama. Skenario terakhir memastikan snapshot tarif tersimpan terlihat; hitung ulang pada desktop/ponsel harus dikonfirmasi, pembatalan mempertahankan nilai, dan konfirmasi pratinjau belum mengubah database sampai tindakan simpan dilakukan.

## Akses Laragon melalui domain dan subfolder

Pengalihan login, tautan halaman, aset lokal, formulir, dan endpoint JSON mengikuti lokasi aplikasi secara otomatis. Akses Apache pada `http://system-penggajian.test/` serta `http://localhost/system-penggajian/` telah diperiksa: keduanya mengarah ke halaman login yang benar dengan HTTP 200. Endpoint tanpa sesi mengembalikan JSON 401 dan ekspor database terlindungi dengan HTTP 403.

`node tests/laragon-routing.js` versi audit lolos pada dua konfigurasi server terisolasi: akar domain dan subfolder. Pengujian mencakup masuk, aset tanpa 404, navigasi, identitas/menu aktif, pencarian karyawan, pratinjau perhitungan, formulir master dan pembatalan, laporan, penolakan CSRF dalam JSON, perlindungan arsip, dan keluar akun. Pemeriksaan mencakup pencarian nama/NIP, ukuran kontrol dan sidebar pada beberapa lebar layar, penyimpanan/penghapusan transaksi uji, serta navigasi semua modul/laporan. Kedua alamat Apache kembali diperiksa setelah migrasi dan berakhir pada login HTTP 200. Server QA dihentikan setelah pengujian; launcher lokal kembali dijalankan dengan konfigurasi database utama dan `http://127.0.0.1:8018/` juga berakhir pada login HTTP 200. Konfigurasi `.env` lokal tidak disertakan dalam Git.

## Pemeriksaan PDF

Cetak seluruh komponen dibagi menjadi empat bagian: penghasilan, potongan, kehadiran/jumlah kegiatan, dan ringkasan. Setiap bagian mencantumkan identitas, periode, subtotal bagian, serta total. Tabel layar tetap menyediakan seluruh kolom dengan gulir horizontal.

`node tests/print-layout.js` dan `python tests/verify-print-pdfs.py` lolos pada sembilan PDF sintetis. Slip memakai A5 landscape satu transaksi per halaman; rekap A4 landscape. Parser memeriksa judul/petugas/jam cetak yang berulang, batas teks pada kertas, nama panjang, nominal 18 digit dan tanda negatif, serta dokumen rincian 49 halaman dan rekap 7 halaman. Database cetak sementara dibersihkan otomatis.

PDF rincian September 2025 juga diperiksa menggunakan parser PDF: teks kolom terakhir, termasuk Gaji bersih, Pendidikan anak, dan Kelebihan mengajar, hadir dalam dokumen. Pembagian menghindari pemotongan kolom di tepi kertas. Header tabel diulang pada halaman berikutnya.

## Batas kesetaraan

- EXE VB6 dianalisis statis sampai handler native; EXE tidak dijalankan di Windows 7.
- Template Crystal Reports `.rpt` tidak tersedia. Kesamaan persis tata letak serta grouping laporan Crystal Reports lama belum disahkan. Laporan baru memakai dokumentasi visual dan rincian transaksi sumber yang tetap lengkap.
- Tidak ada klaim kesetaraan floating point VB6 pada nilai ekstrem di luar presisi integer Double; nominal sumber diimpor sebagai string desimal tanpa kehilangan presisi.
- Tidak ada pembuatan data contoh di database aplikasi utama. Akun pertama tetap dibuat oleh pengguna.
- SHA-256 sumber dicatat dalam dokumen pemetaan dan laporan rekonsiliasi; berkas sumber tidak dimodifikasi.
- Bobot potongan absensi mengikuti kebijakan lama; pengujian kecocokan rumus tidak membuktikan kepatuhan terhadap kebijakan ketenagakerjaan tertentu.
- BJBS/BPJS adalah nominal master. Aplikasi belum menghitung persentase, dasar iuran, batas upah, pembagian pemberi kerja/karyawan, atau pajak otomatis.
