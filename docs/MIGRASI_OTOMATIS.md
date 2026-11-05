# Migrasi otomatis SQL Server

Jalankan dari folder proyek:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/migrate-legacy.ps1 -BackupPath "$env:USERPROFILE\Downloads\GAJI-5.dat"
```

Target MySQL mengikuti `.env`. PHP ditemukan dari terminal atau folder Laragon. Bila MySQL tidak ada pada PATH, aplikasi mencari folder MySQL Laragon; `MYSQL_BIN` dapat menunjuk folder yang berisi `mysql.exe` dan `mysqldump.exe`.

Perintah menyiapkan Docker Desktop jika diperlukan, membuat container SQL Server dengan port loopback dinamis, memasang backup hanya baca, memilih backup penuh terbaru yang selesai, serta menjalankan `RESTORE VERIFYONLY`, restore salinan baru, dan `DBCC CHECKDB`. Database audit yang sudah ada tidak dipakai ulang. Container milik proses dihentikan setelah selesai; container lain tidak diubah.

Ekspor lengkap mencakup seluruh tabel, kolom, indeks, foreign key, definisi objek, dan nilai asli. SHA-256 file diperiksa sebelum/sesudah ekspor. Nominal SQL Server dikirim sebagai string; identitas teks tidak dikonversi menjadi angka. Checksum rekonsiliasi tabel memakai multiset hash baris sehingga urutan pembacaan tidak berpengaruh dan duplikasi tetap terhitung.

Sebelum mengubah MySQL, sistem membuat dump lengkap dan memulihkannya ke database verifikasi sementara. Semua baris dan definisi tabel dibandingkan, kemudian database verifikasi dihapus. Bila backup tidak cocok atau database berubah saat pencadangan, impor belum dijalankan. Penyiapan skema hanya menambahkan kolom identitas `legacy_id` yang telah didokumentasikan; tidak ada DDL yang dibentuk dari kolom backup tak dikenal.

## Rekonsiliasi dan pelestarian data

- Pemetaan memakai schema, tabel, dan kolom eksplisit pada `LegacyMapping`; nama tabel dari schema lain tidak otomatis disatukan.
- Baris tujuan yang identik dipertahankan, termasuk ID aplikasi. Baris sumber yang belum tersedia dimasukkan bila identitas unik dan relasinya valid.
- Nilai lokal yang berbeda, tambahan lokal, serta penghapusan yang sudah tercatat pada ledger/audit dipertahankan. Nol dan NULL bukan bukti bahwa data lokal perlu diganti.
- Master sumber kosong tetap kosong. Tarif atau kewajiban berulang tidak dibuat dari transaksi bulanan. Semua nominal historis, versi perhitungan, dan snapshot yang sudah ada tetap dipertahankan.
- `PGW$` dan `Sheet1$` merupakan referensi terpisah; `orang` adalah arsip identitas pengguna lama; `dtproperties` adalah metadata SQL Server. Tidak ada akun login baru atau penggabungan identitas otomatis dari tabel tersebut.
- Duplikasi, relasi putus, kolom/tabel belum dipetakan, atau nilai yang tidak bisa diwakili tepat pada tujuan dicatat sebagai masalah. Nilai mentah tetap tersimpan pada arsip.

Metadata `app_settings` dengan kunci `legacy_migration_state:<SHA-256>` menyimpan versi pemetaan, identitas sumber, ID tujuan, dan hash pembanding. `migration_runs` mencatat hasil baru ketika keadaan berubah. Pengulangan dengan sumber/tujuan identik tidak menulis ulang data maupun metadata. Database yang telah memiliki arsip dari backup berbeda ditolak sebelum penulisan; gunakan database terpisah untuk sumber lain.

Kalkulator tetap mengikuti pemetaan aplikasi: harian/lembur berasal dari tarif karyawan, konsumsi/infal/overtime dari bagian, dan potongan pribadi dari NIP. Tabel `tunj_harian`, `tunj_lembur`, serta `tabungan` tetap diimpor dan ditelusuri walaupun tidak dimuat sebagai default kalkulator. Binding `p_bjbs → pinjaman_bsm`, `p_bank → arisan`, dan `p_lain → plain` pada perhitungan baru tetap berlaku; histori tidak direklasifikasi.

## Hasil dan penelusuran

Halaman **Data & Migrasi** membaca hasil dan membandingkan data aktif dengan arsip. Cari nama/NIP untuk melihat tarif karyawan, komponen menurut bagian, potongan pribadi, seluruh komponen transaksi historis, dan tautan sumber/tujuan. Halaman tidak menjalankan impor atau mengubah data.

Status `verified` berarti cakupan operasional yang dipetakan cocok. Status `needs_attention` berarti ada masalah atau perubahan/penghapusan lokal yang dipertahankan. Proses tidak meminta konfirmasi pengguna; rinciannya disimpan otomatis. Kode keluar: `0` lengkap, `2` hasil dengan masalah, `1` kegagalan teknis. Kegagalan penulisan data membatalkan transaksi arsip, data operasional, dan ledger bersama-sama; DDL penyiapan skema berada pada tahap terpisah setelah backup terverifikasi.

Backup, kredensial container, ekspor, provenance, checksum, dan rincian rekonsiliasi berada di `storage/legacy/<SHA-256>/<proses>/`. Semua berkas tetap lokal, diabaikan Git, dan dilindungi dari HTTP. Laporan konsol hanya menampilkan jumlah, status, serta lokasi laporan privat.

Untuk audit tanpa perubahan database:

```powershell
php scripts/migrate-legacy.php "storage\legacy\<SHA-256>\<proses>\export.json" --check
```

`--database=<nama>` memilih target lain; `--run-dir=<folder-dalam-storage>` memilih folder laporan. Tanpa path ekspor, CLI menggunakan ekspor lama atau ekspor proses terbaru yang tersedia; sumber yang tidak cocok dengan arsip target tetap ditolak. Jalur utama yang dianjurkan tetap perintah PowerShell dengan file backup eksplisit.

## Pengujian

```powershell
php tests/legacy-migration.php
node tests/legacy-migration-browser.js
php tests/run.php "storage\legacy\<SHA-256>\<proses>\export.json"
```

Dua pengujian pertama membuat database acak khusus pengujian dan membersihkannya setelah selesai. Fixture sintetis mencakup semua master, sumber kosong, histori, konflik lokal, penghapusan, duplikasi, nilai besar/negatif/NULL, rollback, backup yang dapat dipulihkan, serta pengulangan tanpa perubahan. Pengujian browser mencakup penelusuran karyawan, tautan bukti, keyboard, desktop/ponsel, root domain dan subfolder. Pengujian `tests/run.php` membandingkan sumber nyata secara hanya baca dan mengecualikan tambahan lokal dari total pembanding sumber.
