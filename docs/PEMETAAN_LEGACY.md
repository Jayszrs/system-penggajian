# Pemetaan aplikasi lama

Sumber: `gaji.exe` SHA-256 `c67af47aec7f89253c833a0cea0d0fa342c82608271017ed0111c2589222e926`, backup SHA-256 `ba5bfa52aeb2c0ce0a137b735773d6a60a8f2cff58e16e6f34b3d5d405b3b117`, dan PDF dokumentasi pengguna. Instruksi/usulan desain di dalam PDF tidak menjadi otoritas atas permintaan pengguna.

## Struktur dan form

PE32 x86, Visual Basic 6 native code, 24 form dan satu modul. Header `VB5!` berada pada offset `0x2e94`. Alamat virtual native menggunakan image base `0x400000`.

| Form/objek | Modul baru |
|---|---|
| menu, password | Sidebar, masuk/keluar |
| bagian, Cari_bagian | Master bagian dan pemilihan bagian |
| karyawan, Cari_Karyawan | Master dan pencarian karyawan |
| harian, konsumsi, lembur, infal, overtime | Lima master tarif tunjangan |
| kelebihan | Kelebihan mengajar |
| p_anak, p_bjbs, p_bank, p_lunak, p_lain | Lima master potongan |
| penghasilan | Transaksi bulanan |
| cetak_slip, slip_perbagian | Slip karyawan/bagian |
| cetak_rekap, cetak_rekap_periode, cetak_rekap_periode_sub | Rekap bagian, rentang, rentang per bagian |
| cetak_absen; menu rekap_gapok.rpt | Rekap absensi dan gaji pokok |

## Bukti handler dan binding

INSERT penghasilan pada offset BSTR `0x19f88`; susunan getter native pada `0x492d38–0x49360b` dipetakan terhadap urutan VALUES. Kolom dipetakan berdasarkan nama, bukan urutan katalog fisik.

| Getter vtable | Field tersimpan |
|---|---|
| 0x3a4 / 0x30c / 0x308 | nip / bulan / tahun |
| 0x37c / 0x378 / 0x374 / 0x370 | gapok / t_jabatan / t_harian / t_konsumsi |
| 0x360 / 0x36c / 0x368 | lembur / infal / overtime (jumlah) |
| 0x408 / 0x404 / 0x400 | ulembur / uinfal / uovertime (nominal) |
| 0x364 / 0x3ac / 0x440 | lain_lain / kelebihan / lain1 |
| 0x35c / 0x358 / 0x354 / 0x350 | makan / cash_bon / pinjaman_lunak / absensi |
| 0x34c / 0x348 / 0x344 / 0x340 / 0x33c | tabungan / arisan / pinjaman_bsm / p_anak / plain |
| 0x300 / 0x388 / 0x384 / 0x380 | hefektif / hmasuk / hijin / halpha |
| 0x3d4 / 0x3cc / 0x3c4 | tpenghasilan / tpotongan / tbersih |

Pemetaan penting tidak mengikuti dugaan dari nama kolom:

- `hijin`: Izin/Alpha pada layar; `halpha`: Sakit pada layar.
- `p_bjbs.jumlah` → `pinjaman_bsm` (BJBS/BPJSKET), handler `0x49cebf–0x49d0de`.
- `p_bank.jumlah` → `arisan` (Pinjaman Bank), handler `0x49d6c9–0x49d8e8`.
- `p_lunak.jumlah` → `pinjaman_lunak`; `p_anak.jumlah` → `p_anak`.
- `p_lain.jumlah` → **`lain1`, komponen penghasilan**, handler `0x49dace–0x49dced`. Ini adalah bukti perilaku **aplikasi lama**, bukan klasifikasi untuk transaksi baru. Kolom dan nominal transaksi historis dipertahankan; pada `accounting_v2`, master `p_lain` mengisi `plain` sebagai potongan lain-lain dan `lain1` tetap penghasilan manual.

## Kalkulasi yang dibuktikan pada aplikasi lama

| Komponen | Aturan | Bukti |
|---|---|---|
| Gaji pokok, jabatan | Nilai master karyawan | Handler pemilihan karyawan |
| Tunjangan harian | karyawan.t_harian × hefektif | `0x4a3130`, perkalian `0x4a3773` |
| Konsumsi | tunj_konsumsi.jumlah × hmasuk | `0x4a3814–0x4a3b3f` |
| Lembur | karyawan.t_lembur × lembur | `0x4a1740`, perkalian `0x4a1a1b` |
| Infal | tunj_infal.jumlah × infal | `0x4a1b30`, perkalian `0x4a1e6b` |
| Overtime | tunj_overtime.jumlah × overtime | `0x4a2510`, perkalian `0x4a284b` |
| Kelebihan mengajar | tunj_jam.jumlah × tunj_jam.tarip | pemilihan karyawan, perkalian `0x49c9af` |
| Absensi | tarif harian karyawan × (hijin + 0,5 × halpha) | `0x4a3e90`, `0x4a4780`; konstanta 0,5 pada `0x4a44b3` / `0x4a4e10` |
| Bruto | gapok + t_jabatan + t_harian + t_konsumsi + ulembur + uinfal + uovertime + lain_lain + kelebihan + lain1 | Penjumlahan native `0x4a1f80–0x4a2510` |
| Potongan | makan + cash_bon + pinjaman_lunak + absensi + tabungan + arisan + pinjaman_bsm + p_anak + plain | `0x4a50e0–0x4a5470` |
| Bersih | bruto − potongan | `0x4a5600–0x4a5606` |

Seluruh 332 transaksi sumber cocok pada bruto/potongan/bersih. Untuk semua 332 baris, absensi cocok dengan tarif historis yang diturunkan dari `t_harian / hefektif`, termasuk hari sakit berbobot 0,5. Tarif master sekarang tidak digunakan untuk mengubah transaksi historis.

Master tunjangan harian dan lembur tetap tersedia meskipun handler transaksi yang ditelusuri memakai tarif karyawan untuk dua komponen tersebut. Master tabungan yang tidak memiliki form EXE tersendiri dipertahankan dalam data dan arsip; nilai transaksi tabungan tetap bisa diisi.

## Pembulatan dan batas

Penyimpanan sumber menggunakan `numeric(18,0)`. Hasil pecahan dibulatkan setengah menjauh dari nol saat konversi SQL. Aplikasi lama menghitung total dari hasil antara sebelum pembulatan penyimpanan per kolom; kasus pecahan sintetis dapat menghasilkan selisih satu rupiah antara total tersimpan dan penjumlahan komponen yang ditampilkan. Bukti handler dan snapshot sumber tetap dipertahankan sebagai `legacy_v1`.

Pada `accounting_v2`, layanan server menggunakan BCMath, membulatkan **setiap komponen terlebih dahulu**, lalu menjumlahkan komponen tersimpan untuk bruto/potongan dan mengurangkannya untuk bersih. Batas nominal per kolom tetap 18 digit. Grafik menggunakan angka JavaScript hanya untuk visualisasi; angka tabel/rekonsiliasi tetap string desimal.

Kode native memakai operasi floating point/Variant VB6. Tidak ada klaim kesetaraan bit per bit pada nilai ekstrem di luar presisi integer Double; data sumber yang diuji berada di rentang payroll normal. Nilai impor selalu dipertahankan tepat, tanpa melewati floating point.

## Laporan dan perbedaan antarmuka

Enter pada textbox lama memicu handler bertahap. Web baru menghitung melalui layanan server yang sama untuk preview dan simpan, dengan tombol Simpan yang eksplisit. Validasi jumlah masuk + izin/alpha + sakit ≤ hari efektif dipertahankan. Nilai historis menjadi default saat edit; pemakaian master saat ini dipilih secara eksplisit.

Laporan rentang menggunakan pasangan tahun-bulan inklusif. Baris rincian adalah transaksi bulanan, subtotal per bagian dan total keseluruhan tetap disertakan. Definisi `.rpt` belum tersedia: grouping internal Crystal Reports lama belum disahkan. Rekap gapok menjumlahkan gaji pokok + tunjangan jabatan per bulan; tarif harian dan tarif per satuan lembur ditampilkan terpisah tanpa subtotal karena satuannya berbeda.

## Versi kalkulasi dan pelestarian histori

| Perilaku | `legacy_v1` / sumber | `accounting_v2` |
|---|---|---|
| Master `p_lain` | Binding native ke `lain1` sebagai penghasilan | Masuk ke `plain` satu kali sebagai potongan |
| Pembulatan | Total dihitung sebelum konversi SQL masing-masing kolom | Komponen dibulatkan dahulu, kemudian total dihitung |
| Impor dan buka histori | Nilai sumber tetap utuh | Tidak memicu hitung ulang otomatis |
| Simpan tanpa perubahan komponen | Nominal dan versi tersimpan dipertahankan | Nominal, versi, dan snapshot tersimpan dipertahankan |
| Perubahan komponen / pilih master sekarang | Perlu pemeriksaan eksplisit | Menggunakan `accounting_v2`; hasil dan peringatan diperiksa sebelum simpan |

`calculation_snapshot` menyimpan versi kebijakan, periode, kuantitas, sumber tarif, mode, dan pemetaan yang digunakan untuk perhitungan baru. Snapshot tarif historis yang tidak tersedia tidak dibuat-buat dari master sekarang. Audit log menyimpan perubahan dan konteks konfirmasinya. Detail temuan dan pengujian terdapat dalam [AUDIT_PENGGAJIAN.md](AUDIT_PENGGAJIAN.md).
