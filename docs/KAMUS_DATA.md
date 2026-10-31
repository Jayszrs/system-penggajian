# Kamus Data Hasil Restore SQL Server

Sumber: `gaji_bmt`, native backup SQL Server 2008 R2. Inventaris diambil dari katalog database setelah RESTORE VERIFYONLY dan DBCC CHECKDB.

Data asli setiap tabel, termasuk tabel kosong dan kolom yang tidak digunakan UI, disimpan pada arsip hanya baca. Identitas sumber `id` dipetakan ke `legacy_id` pada tabel operasional. Nilai asli tetap tersedia dalam payload arsip.

| Tabel | Baris aktif | Kolom | Penempatan |
|---|---:|---:|---|
| `dbo.bagian` | 11 | 2 | Operasional dan arsip |
| `dbo.dtproperties` | 0 | 7 | Arsip lengkap |
| `dbo.karyawan` | 39 | 8 | Operasional dan arsip |
| `dbo.orang` | 5 | 2 | Arsip lengkap |
| `dbo.p_anak` | 1 | 3 | Operasional dan arsip |
| `dbo.p_bank` | 0 | 3 | Operasional dan arsip |
| `dbo.p_bjbs` | 1 | 3 | Operasional dan arsip |
| `dbo.P_LAIN` | 0 | 3 | Operasional dan arsip |
| `dbo.p_lunak` | 3 | 3 | Operasional dan arsip |
| `dbo.penghasilan` | 332 | 32 | Operasional dan arsip |
| `dbo.PGW$` | 68 | 7 | Arsip lengkap |
| `dbo.Sheet1$` | 16 | 4 | Arsip lengkap |
| `dbo.tabungan` | 0 | 3 | Operasional dan arsip |
| `dbo.tunj_harian` | 0 | 3 | Operasional dan arsip |
| `dbo.tunj_infal` | 1 | 3 | Operasional dan arsip |
| `dbo.tunj_jam` | 0 | 4 | Operasional dan arsip |
| `dbo.tunj_konsumsi` | 7 | 3 | Operasional dan arsip |
| `dbo.tunj_lembur` | 0 | 3 | Operasional dan arsip |
| `dbo.tunj_overtime` | 1 | 3 | Operasional dan arsip |

## dbo.bagian

| Kolom | Tipe sumber | NULL | Identity | Default |
|---|---|---|---|---|
| `kd_bagian` | `char(5)` | Ya | Tidak | ? |
| `nama_bagian` | `char(20)` | Ya | Tidak | ? |

Indeks sumber: 0. Relasi foreign key: 0.

## dbo.dtproperties

| Kolom | Tipe sumber | NULL | Identity | Default |
|---|---|---|---|---|
| `id` | `int` | Tidak | Ya | ? |
| `objectid` | `int` | Ya | Tidak | ? |
| `property` | `varchar(64)` | Tidak | Tidak | ? |
| `value` | `varchar(255)` | Ya | Tidak | ? |
| `uvalue` | `nvarchar(255)` | Ya | Tidak | ? |
| `lvalue` | `image` | Ya | Tidak | ? |
| `version` | `int` | Tidak | Tidak | (0) |

Indeks sumber: 1. Relasi foreign key: 0.

## dbo.karyawan

| Kolom | Tipe sumber | NULL | Identity | Default |
|---|---|---|---|---|
| `nip` | `char(15)` | Ya | Tidak | ? |
| `nama` | `char(30)` | Ya | Tidak | ? |
| `kd_bagian` | `char(5)` | Ya | Tidak | ? |
| `gapok` | `numeric(18,0)` | Ya | Tidak | ? |
| `t_jabatan` | `numeric(18,0)` | Ya | Tidak | ? |
| `t_harian` | `numeric(18,0)` | Ya | Tidak | ? |
| `t_lembur` | `numeric(18,0)` | Ya | Tidak | ? |
| `id` | `numeric(18,0)` | Tidak | Ya | ? |

Indeks sumber: 0. Relasi foreign key: 0.

## dbo.orang

| Kolom | Tipe sumber | NULL | Identity | Default |
|---|---|---|---|---|
| `id_user` | `char(10)` | Ya | Tidak | ? |
| `nama_user` | `char(30)` | Ya | Tidak | ? |

Indeks sumber: 0. Relasi foreign key: 0.

## dbo.p_anak

| Kolom | Tipe sumber | NULL | Identity | Default |
|---|---|---|---|---|
| `id` | `numeric(18,0)` | Tidak | Ya | ? |
| `nip` | `char(15)` | Ya | Tidak | ? |
| `jumlah` | `numeric(18,0)` | Ya | Tidak | ? |

Indeks sumber: 0. Relasi foreign key: 0.

## dbo.p_bank

| Kolom | Tipe sumber | NULL | Identity | Default |
|---|---|---|---|---|
| `id` | `numeric(18,0)` | Tidak | Ya | ? |
| `nip` | `char(15)` | Ya | Tidak | ? |
| `jumlah` | `numeric(18,0)` | Ya | Tidak | ? |

Indeks sumber: 0. Relasi foreign key: 0.

## dbo.p_bjbs

| Kolom | Tipe sumber | NULL | Identity | Default |
|---|---|---|---|---|
| `id` | `numeric(18,0)` | Tidak | Ya | ? |
| `nip` | `char(15)` | Ya | Tidak | ? |
| `jumlah` | `numeric(18,0)` | Ya | Tidak | ? |

Indeks sumber: 0. Relasi foreign key: 0.

## dbo.P_LAIN

| Kolom | Tipe sumber | NULL | Identity | Default |
|---|---|---|---|---|
| `id` | `numeric(18,0)` | Tidak | Ya | ? |
| `nip` | `char(15)` | Ya | Tidak | ? |
| `jumlah` | `numeric(18,0)` | Ya | Tidak | ? |

Indeks sumber: 0. Relasi foreign key: 0.

## dbo.p_lunak

| Kolom | Tipe sumber | NULL | Identity | Default |
|---|---|---|---|---|
| `id` | `numeric(18,0)` | Tidak | Ya | ? |
| `nip` | `char(15)` | Ya | Tidak | ? |
| `jumlah` | `numeric(18,0)` | Ya | Tidak | ? |

Indeks sumber: 0. Relasi foreign key: 0.

## dbo.penghasilan

| Kolom | Tipe sumber | NULL | Identity | Default |
|---|---|---|---|---|
| `nip` | `char(15)` | Ya | Tidak | ? |
| `bulan` | `char(2)` | Ya | Tidak | ? |
| `tahun` | `char(4)` | Ya | Tidak | ? |
| `gapok` | `numeric(18,0)` | Ya | Tidak | ? |
| `t_jabatan` | `numeric(18,0)` | Ya | Tidak | ? |
| `t_harian` | `numeric(18,0)` | Ya | Tidak | ? |
| `t_konsumsi` | `numeric(18,0)` | Ya | Tidak | ? |
| `lembur` | `numeric(18,0)` | Ya | Tidak | ? |
| `infal` | `numeric(18,0)` | Ya | Tidak | ? |
| `overtime` | `numeric(18,0)` | Ya | Tidak | ? |
| `lain_lain` | `numeric(18,0)` | Ya | Tidak | ? |
| `lain1` | `numeric(18,0)` | Ya | Tidak | ((0)) |
| `makan` | `numeric(18,0)` | Ya | Tidak | ? |
| `cash_bon` | `numeric(18,0)` | Ya | Tidak | ? |
| `pinjaman_lunak` | `numeric(18,0)` | Ya | Tidak | ? |
| `absensi` | `numeric(18,0)` | Ya | Tidak | ? |
| `tabungan` | `numeric(18,0)` | Ya | Tidak | ? |
| `arisan` | `numeric(18,0)` | Ya | Tidak | ? |
| `pinjaman_bsm` | `numeric(18,0)` | Ya | Tidak | ? |
| `p_anak` | `numeric(18,0)` | Ya | Tidak | ? |
| `hefektif` | `numeric(18,0)` | Ya | Tidak | ? |
| `hmasuk` | `numeric(18,0)` | Ya | Tidak | ? |
| `hijin` | `numeric(18,0)` | Ya | Tidak | ? |
| `halpha` | `numeric(18,0)` | Ya | Tidak | ? |
| `plain` | `numeric(18,0)` | Ya | Tidak | ? |
| `ulembur` | `numeric(18,0)` | Ya | Tidak | ? |
| `uinfal` | `numeric(18,0)` | Ya | Tidak | ? |
| `uovertime` | `numeric(18,0)` | Ya | Tidak | ? |
| `tpenghasilan` | `numeric(18,0)` | Ya | Tidak | ? |
| `tpotongan` | `numeric(18,0)` | Ya | Tidak | ? |
| `tbersih` | `numeric(18,0)` | Ya | Tidak | ? |
| `kelebihan` | `numeric(18,0)` | Ya | Tidak | ? |

Indeks sumber: 0. Relasi foreign key: 0.

## dbo.PGW$

| Kolom | Tipe sumber | NULL | Identity | Default |
|---|---|---|---|---|
| `NIP` | `char(15)` | Ya | Tidak | ? |
| `NAMA` | `char(30)` | Ya | Tidak | ? |
| `KD_BAGIAN` | `char(5)` | Ya | Tidak | ? |
| `GAPOK` | `numeric(18,0)` | Ya | Tidak | ? |
| `T_JABATAN` | `numeric(18,0)` | Ya | Tidak | ? |
| `T_HARIAN` | `numeric(18,0)` | Ya | Tidak | ? |
| `T_LEMBUR` | `numeric(18,0)` | Ya | Tidak | ? |

Indeks sumber: 0. Relasi foreign key: 0.

## dbo.Sheet1$

| Kolom | Tipe sumber | NULL | Identity | Default |
|---|---|---|---|---|
| `nama` | `nvarchar(255)` | Ya | Tidak | ? |
| `nip` | `nvarchar(255)` | Ya | Tidak | ? |
| `F3` | `nvarchar(255)` | Ya | Tidak | ? |
| `KD_BAGIAN` | `nvarchar(255)` | Ya | Tidak | ? |

Indeks sumber: 0. Relasi foreign key: 0.

## dbo.tabungan

| Kolom | Tipe sumber | NULL | Identity | Default |
|---|---|---|---|---|
| `id` | `numeric(18,0)` | Tidak | Ya | ? |
| `nip` | `char(15)` | Ya | Tidak | ? |
| `jumlah` | `numeric(18,0)` | Ya | Tidak | ? |

Indeks sumber: 0. Relasi foreign key: 0.

## dbo.tunj_harian

| Kolom | Tipe sumber | NULL | Identity | Default |
|---|---|---|---|---|
| `id` | `numeric(18,0)` | Tidak | Ya | ? |
| `kd_bagian` | `char(5)` | Ya | Tidak | ? |
| `jumlah` | `numeric(18,0)` | Ya | Tidak | ? |

Indeks sumber: 0. Relasi foreign key: 0.

## dbo.tunj_infal

| Kolom | Tipe sumber | NULL | Identity | Default |
|---|---|---|---|---|
| `id` | `numeric(18,0)` | Tidak | Ya | ? |
| `kd_bagian` | `char(5)` | Ya | Tidak | ? |
| `jumlah` | `numeric(18,0)` | Ya | Tidak | ? |

Indeks sumber: 0. Relasi foreign key: 0.

## dbo.tunj_jam

| Kolom | Tipe sumber | NULL | Identity | Default |
|---|---|---|---|---|
| `id` | `numeric(18,0)` | Tidak | Ya | ? |
| `nip` | `char(15)` | Ya | Tidak | ? |
| `jumlah` | `numeric(18,0)` | Ya | Tidak | ? |
| `tarip` | `numeric(18,0)` | Ya | Tidak | ? |

Indeks sumber: 0. Relasi foreign key: 0.

## dbo.tunj_konsumsi

| Kolom | Tipe sumber | NULL | Identity | Default |
|---|---|---|---|---|
| `id` | `numeric(18,0)` | Tidak | Ya | ? |
| `kd_bagian` | `char(5)` | Ya | Tidak | ? |
| `jumlah` | `numeric(18,0)` | Ya | Tidak | ? |

Indeks sumber: 0. Relasi foreign key: 0.

## dbo.tunj_lembur

| Kolom | Tipe sumber | NULL | Identity | Default |
|---|---|---|---|---|
| `id` | `numeric(18,0)` | Tidak | Ya | ? |
| `kd_bagian` | `char(5)` | Ya | Tidak | ? |
| `jumlah` | `numeric(18,0)` | Ya | Tidak | ? |

Indeks sumber: 0. Relasi foreign key: 0.

## dbo.tunj_overtime

| Kolom | Tipe sumber | NULL | Identity | Default |
|---|---|---|---|---|
| `id` | `numeric(18,0)` | Tidak | Ya | ? |
| `kd_bagian` | `char(5)` | Ya | Tidak | ? |
| `jumlah` | `numeric(18,0)` | Ya | Tidak | ? |

Indeks sumber: 0. Relasi foreign key: 0.

## Definisi dan objek lain

32 definisi/properti objek SQL (prosedur, default, dan objek lain yang ditemukan) dipertahankan dalam ekspor serta `app_settings.legacy_definitions`. Katalog kolom, indeks, dan foreign key tersimpan pada `legacy_tables.schema_data`.

## Perbedaan tipe operasional

Semua nominal dan jumlah sumber menggunakan `numeric(18,0)`; operasional memakai `DECIMAL(18,0)`. Kode/NIP tetap teks. Kolom nama operasional diperlebar untuk input baru tanpa memotong data impor. Metadata presisi, panjang, collation, nullable, identity, default, serta nilai NULL sumber tetap dipertahankan pada arsip.

Tabel aplikasi tambahan: `app_users`, `login_attempts`, `audit_log`, `migration_runs`, `legacy_tables`, `legacy_records`, dan `app_settings`. Akun `orang` diarsipkan; autentikasi baru memakai `app_users` dengan password hash.
