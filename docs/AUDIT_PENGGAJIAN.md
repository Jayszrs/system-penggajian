# Audit perhitungan penggajian

Audit 31 Oktober 2026 mencakup kalkulasi transaksi, sumber tarif, klasifikasi komponen, ringkasan dashboard, tujuh keluaran laporan, serta validasi dan konfirmasi penyimpanan. Tampilan hijau dan struktur navigasi tetap dipertahankan. Dokumen publik ini tidak memuat identitas karyawan, NIP, nominal individu, atau total gaji riil.

## Temuan dan perbaikan

| Area | Temuan sebelum koreksi | Perilaku setelah koreksi |
|---|---|---|
| Pembulatan | Total dihitung sebelum masing-masing komponen dikonversi ke rupiah; kasus pecahan dapat berbeda satu rupiah | `accounting_v2` membulatkan komponen dahulu, lalu menghitung bruto, potongan, dan bersih dengan BCMath |
| Potongan lain-lain | Handler lama mengisi master `p_lain` ke penghasilan `lain1` | Perhitungan baru mengisi `plain` sebagai potongan satu kali; `lain1` tetap penghasilan manual |
| Penerima dashboard | Jumlah seluruh master karyawan dapat berbeda dari penerima pada periode terpilih | Menghitung NIP unik yang memiliki transaksi pada periode dan bagian terpilih |
| Distribusi bagian | Nama snapshot berbeda dapat memecah kode bagian yang sama menjadi beberapa kelompok | Agregasi berdasarkan kode bagian; label grafik memakai nama master, lalu snapshot yang tersedia |
| Kelengkapan komponen | Panel dashboard menampilkan sebagian komponen tanpa penjelasan | Menyebutkan jumlah komponen yang ditampilkan dan menyediakan tautan rincian dengan filter yang sama |
| Rekap gaji pokok | Gaji bulanan dijumlahkan dengan tarif harian dan tarif lembur | Total tetap hanya gaji pokok + jabatan per bulan; tarif Rp/hari dan Rp/satuan tanpa subtotal/grand total |
| Kehadiran laporan | Jumlah hari lintas karyawan berpotensi dibaca sebagai hari kalender | Menjelaskan subtotal sebagai orang-hari; lembur, infal, overtime merupakan jumlah kegiatan |
| Identitas histori | Fallback nama bagian dapat mengikuti bagian karyawan sekarang | Resolver kode/nama yang konsisten mengutamakan snapshot transaksi dan tidak memindahkan histori otomatis |
| Periode | Input invalid dapat terpotong melalui cast atau diam-diam berubah menjadi bulan sekarang | Digit/rentang divalidasi ketat; default transaksi terbaru hanya untuk parameter yang tidak diberikan |
| Simpan dan pratinjau | Konfirmasi lama belum terikat ke hasil, sumber tarif, dan revisi transaksi terbaru | Satu layanan mengatur evaluasi, konfirmasi, dan penyimpanan; hasil berubah harus diperiksa ulang |
| Kesalahan/peringatan | Pengguna memerlukan penjelasan yang jelas sebelum hasil disimpan | Masalah terstruktur menurut kode, field, dan tingkat; kesalahan memblokir, peringatan perlu konfirmasi |

Contoh pembulatan **sintetis, bukan data pengguna**: bruto Rp100.000 dan potongan mentah Rp1,5. Komponen potongan menjadi Rp2; bersih yang seimbang adalah Rp99.998. Menghitung bersih sebelum pembulatan komponen menghasilkan Rp99.999 dan selisih Rp1 terhadap nominal yang ditampilkan. Versi baru menggunakan Rp2 dan Rp99.998.

## Aturan hitung dan validasi

- Bruto adalah jumlah sepuluh komponen penghasilan; potongan adalah jumlah sembilan komponen potongan; bersih adalah bruto dikurangi potongan. Kehadiran/jumlah kegiatan tidak dijumlahkan sebagai rupiah.
- Pembulatan ke rupiah memakai setengah menjauh dari nol. Nominal masukan berupa bilangan bulat maksimal 18 digit, tanpa notasi ilmiah. Komponen negatif dan hasil bersih negatif ditolak untuk perhitungan baru/perubahan komponen.
- Hari efektif harus positif dan tidak melebihi jumlah hari kalender periode. Masuk + izin/alpha + sakit tidak boleh melebihi hari efektif; kuantitas negatif ditolak.
- Selisih hari efektif yang belum diklasifikasikan menjadi peringatan. Sistem tidak menganggap selisih tersebut sebagai alpha atau membuat potongan tambahan otomatis.
- Gaji pokok, jabatan, dan tarif harian karyawan wajib tersedia saat menghitung dari master. Tarif kegiatan yang diperlukan tidak boleh hilang ketika kuantitasnya positif. Nilai nol yang diatur eksplisit tetap berbeda dari tarif yang belum tersedia.
- Input tidak valid menandai field dan menampilkan alasan. Kegagalan validasi atau database tidak menyimpan sebagian transaksi; isian tetap tersedia untuk diperbaiki.

Pratinjau dan simpan melewati layanan evaluasi yang sama. Browser tidak menentukan total akhir. Konfirmasi terikat ke nominal, snapshot tarif/kuantitas, versi kalkulasi, peringatan, identitas, dan revisi record sebelumnya. Token berlaku maksimal 15 menit, hanya pada sesi penerbitnya, dan dikonsumsi setelah simpan berhasil. Perubahan sumber atau hasil membatalkan konfirmasi lama. Audit log mencatat perubahan, snapshot, dan konteks pengakuan peringatan.

Dialog memakai komponen antarmuka lokal yang sudah tersedia. Informasi menjelaskan dasar hitung; peringatan meminta pengguna memeriksa dan mengonfirmasi; kesalahan memblokir simpan dan mengarahkan pemeriksaan field terkait. Konfirmasi ini merupakan pemeriksaan oleh pengguna yang sedang mengisi transaksi, bukan persetujuan berjenjang atau tanda pembayaran.

## Histori dan penerapan

Audit hanya baca menemukan **332 transaksi pada 16 periode**, dengan **nol selisih** pada tiga identitas bruto/potongan/bersih. Tidak ditemukan duplikasi NIP/periode, referensi hilang, periode invalid, atau NULL komponen/total pada snapshot yang diperiksa. Nominal histori yang sudah cocok tidak direklasifikasi atau dihitung ulang massal.

Migrasi metadata menambahkan `calculation_version VARCHAR(24) NOT NULL DEFAULT 'legacy_v1'` dan `calculation_snapshot JSON NULL`. Seluruh 32 kolom sumber, arsip, dan nilai aslinya tetap dipertahankan. `scripts/migrate-accounting.php` aman dijalankan ulang dan tidak mengganti nominal.

Transaksi baru atau perubahan komponen menggunakan `accounting_v2`. Membuka histori, mengubah tarif master, atau menyimpan ulang tanpa perubahan komponen tidak menghitung ulang nilai lama. Memilih perhitungan master sekarang pada record historis merupakan tindakan eksplisit dan menimbulkan peringatan. Dasar tarif historis yang tidak tersedia tidak direkonstruksi dari master sekarang.

Backup, baseline, dan hasil rekonsiliasi rinci hanya berada di `storage/audit/`, `storage/exports/`, atau `storage/legacy/`. Berkas tersebut dilindungi dari HTTP dan tidak masuk Git. Pemeriksaan pascapenerapan memakai `php scripts/verify-accounting-baseline.php` untuk memastikan checksum kolom asli tetap sama; metadata tambahan diverifikasi terpisah.

## Bukti pengujian dan batas kebijakan

Pengujian mencakup fixture aritmetika independen, integrasi tarif, token konfirmasi dan periode, siklus pratinjau/simpan, rekonsiliasi hasil render laporan/dashboard, serta uji browser. Hasil terukur dan status pemeriksaan terakhir dicatat pada [VERIFIKASI.md](VERIFIKASI.md). Bukti handler EXE tetap tersedia di [PEMETAAN_LEGACY.md](PEMETAAN_LEGACY.md); perubahan versi baru tidak mengganti bukti perilaku aplikasi sumber.

Bobot absensi masih mengikuti aturan lama: tarif harian × (izin/alpha + 0,5 × sakit). Label `hijin` sebagai izin/alpha dan `halpha` sebagai sakit merupakan hasil penelusuran aplikasi lama. Audit ini memeriksa konsistensi aritmetika dan keterlacakan kebijakan tersebut; belum menetapkan kebijakan cuti, izin berbayar, sakit, prorata, hari kerja, atau lembur berdasarkan kontrak/kategori pekerja tertentu.

BJBS/BPJS tetap berupa nominal master yang masuk ke `pinjaman_bsm`. Aplikasi belum menjadi mesin perhitungan iuran atau pajak: tidak menambahkan persentase, batas dasar upah, pembagian pemberi kerja/karyawan, maupun aturan PPh berdasarkan dugaan. Perubahan kebijakan tersebut memerlukan dasar kebijakan yang disahkan pemilik sistem sebelum dimasukkan sebagai rumus baru.
