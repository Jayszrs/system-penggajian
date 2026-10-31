# Filter, input tahun, dan tata letak slip

Revisi tampilan mempertahankan palet hijau, perhitungan, sumber data, autentikasi, nama parameter formulir, dan endpoint. Tidak memerlukan migrasi database.

## Perilaku antarmuka

- Kolom Karyawan pada Rincian per Bagian di dashboard rata tengah; nominal tetap rata kanan.
- Konteks Transaksi memakai pembagian 2–2–4–4 dan 5–7 pada desktop, dua kolom pada tablet, serta satu kolom pada ponsel. Tinggi kontrol 48 px. Tahun tetap dapat dipilih dari saran atau diketik, tanpa pemisah ribuan; transaksi tersimpan tetap mengunci periode.
- Filter Penggajian memberikan ruang fleksibel untuk pencarian nama/NIP. Reset dan Tampilkan berada di kanan; Tampilkan selebar 260 px pada desktop mulai 1440 px. Pada tablet tindakan menempati baris terakhir; pada ponsel tombol tersusun vertikal.
- Reset Penggajian membuka halaman tanpa parameter: periode transaksi terbaru, semua bagian, pencarian kosong, dan halaman pertama. Daftar transaksi dan ringkasan langsung menampilkan hasil default. Tautan Reset tetap bekerja ketika input belum valid dan tidak menghapus data.
- Ketujuh laporan memiliki Reset, yang mempertahankan jenis laporan dan mengembalikan filter ke default sekaligus menutup pratinjau. Rekap rentang mengelompokkan bulan/tahun dalam Periode Awal dan Periode Akhir.
- Tahun pada Slip & Laporan menggunakan input angka dengan langkah satu tahun, dapat diketik pada rentang 1900–9999, termasuk tahun mendatang tanpa transaksi. Rekap Gaji Pokok tetap tanpa filter tahun. Mengubah tahun tidak otomatis mengirim formulir; gunakan Tampilkan.
- Slip memakai satu template bersama untuk seluruh pintasan, dengan A5 landscape, satu transaksi per halaman, Times New Roman, identitas dan kehadiran sejajar, dua kelompok komponen dengan pemisah tengah, jumlah kegiatan, total, tanda tangan dinamis, serta tanggal/jam/WIB. Rekap tetap A4 landscape. Cetak pada skala 100% tanpa header/footer bawaan browser.

## Verifikasi

Pengujian terakhir meliputi 17 skenario browser, tujuh laporan beserta Reset, filter Penggajian, input tahun melalui mouse/keyboard, tahun tanpa transaksi, rentang lintas tahun, batas input, hasil kosong, serta lebar layar 1600/1440/1024/768/390 px.

Sepuluh PDF sintetis diperiksa untuk ukuran kertas, beberapa halaman, judul berulang, nama panjang, nominal 18 digit, waktu, dan kesejajaran identitas dengan toleransi maksimum 0,25 pt. Regresi perhitungan, pratinjau/simpan, dashboard, dan laporan juga lolos pada database uji terpisah. Verifikasi terhadap baseline lokal memastikan seluruh kolom data historis tetap identik.

Untuk mengulang pemeriksaan tampilan dan cetak sintetis:

```powershell
node tests/print-layout.js
python tests/verify-print-pdfs.py
```

Pengujian ini memerlukan PHP/MySQL lokal, Chrome pada Windows, dependensi npm, serta PyMuPDF untuk pemeriksaan PDF. Database sementara bernama `penggajian_print_test_<acak>` dibuat khusus untuk fixture sintetis dan dibersihkan setelah selesai.

Server browser QA dapat memakai port terpisah dari aplikasi kerja. Setelah menyiapkan database uji sesuai README, jalankan server dengan `DB_DATABASE=penggajian_test`, lalu arahkan Playwright melalui `PAYROLL_TEST_URL`, misalnya `http://127.0.0.1:8019`. Jangan arahkan pengujian tulis ke database kerja.

Tangkapan layar, PDF, baseline, backup, dan rincian rekonsiliasi tetap berada dalam `storage/` yang diabaikan Git dan dilindungi dari akses HTTP. Dokumen ini tidak menyertakan identitas atau rincian gaji pegawai.
