# Hasil verifikasi

Pemeriksaan dilakukan pada 31 Oktober 2026, zona waktu Asia/Jakarta. Aplikasi berjalan lokal pada Laragon, PHP 8.1.10 dan MySQL 8.0.30. Browser QA menggunakan Chrome terpasang melalui Playwright, dengan database terpisah `penggajian_test`.

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

`php tests/calculator-integration.php` juga lolos **18 pemeriksaan integrasi** untuk tarif harian, konsumsi, lembur, infal, overtime, kelebihan mengajar, binding BJBS/bank/lain-lain, dan pembulatan. Fixture ditulis dalam transaksi khusus database uji lalu di-rollback.

Penyimpanan mengikuti konversi numeric sumber per kolom. Pada kasus sintetis yang menghasilkan setengah rupiah, pembulatan komponen/total secara terpisah dapat menghasilkan selisih satu rupiah ketika total tersimpan dikurangkan ulang; hasil sebelum pembulatan tetap menjadi dasar kalkulasi. Hal ini didokumentasikan dan tidak digunakan untuk menghitung ulang data historis.

## Pengujian browser

Enam skenario end-to-end mencakup:

1. Pengaturan akun pertama, login, dashboard, periode terbaru, dan grafik lokal.
2. Pembukaan seluruh modul master, riwayat migrasi, dan arsip tabel tambahan.
3. Tambah master, NIP bernol awal, escaping nama, dan larangan menghapus bagian yang masih digunakan.
4. Kalkulasi server, simpan transaksi, penolakan transaksi periode sama, perubahan tarif master yang tidak mengubah histori, dan pembatalan perubahan.
5. Ketujuh laporan, rentang lintas tahun, penolakan rentang terbalik, seluruh komponen, serta cetak PDF.
6. Layout ponsel, buka/tutup sidebar, pemilih periode, pencarian karyawan, periode kosong, penolakan CSRF, dan perlindungan berkas internal.

Hasil akhir suite: **6 skenario lolos**. Gambar desktop/ponsel disimpan di `storage/qa/`; data uji dipisahkan dari database aplikasi utama.

## Pemeriksaan PDF

Cetak seluruh komponen dibagi menjadi empat bagian: penghasilan, potongan, kehadiran/jumlah kegiatan, dan ringkasan. Setiap bagian mencantumkan identitas, periode, subtotal bagian, serta total. Tabel layar tetap menyediakan seluruh kolom dengan gulir horizontal.

PDF rincian September 2025 diperiksa menggunakan parser PDF: teks kolom terakhir, termasuk Gaji bersih, Pendidikan anak, dan Kelebihan mengajar, hadir dalam dokumen. Pembagian menghindari pemotongan kolom di tepi kertas. Header tabel diulang pada halaman berikutnya.

## Batas kesetaraan

- EXE VB6 dianalisis statis sampai handler native; EXE tidak dijalankan di Windows 7.
- Template Crystal Reports `.rpt` tidak tersedia. Kesamaan persis tata letak serta grouping laporan Crystal Reports lama belum disahkan. Laporan baru memakai dokumentasi visual dan rincian transaksi sumber yang tetap lengkap.
- Tidak ada klaim kesetaraan floating point VB6 pada nilai ekstrem di luar presisi integer Double; nominal sumber diimpor sebagai string desimal tanpa kehilangan presisi.
- Tidak ada pembuatan data contoh di database aplikasi utama. Akun pertama tetap dibuat oleh pengguna.
- SHA-256 sumber dicatat dalam dokumen pemetaan dan laporan rekonsiliasi; berkas sumber tidak dimodifikasi.
