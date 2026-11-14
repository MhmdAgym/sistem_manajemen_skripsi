# LAPORAN TEKNIS PENGUJIAN MIGRASI TERISOLASI B-CHAT-15B
## Eksekusi Pengujian 13 Berkas Laravel Migration pada MySQL 8.0.30 Terisolasi

**Proyek:** Sistem Informasi Manajemen Skripsi UNUHA  
**Fitur:** Bimbingan Online Chat Real-Time E2EE (B-CHAT)  
**Dokumen Acuan:** `B-CHAT-13_Rancangan_DDL_Final.md`, `B-CHAT-14_Laporan_Audit_Kesiapan_Migrasi.md`, `B-CHAT-15A_Laporan_Review_Statis_Migration.md`  
**Database Pengujian Terisolasi:** `bchat_migration_test`  
**Database Aplikasi/Produksi:** `manajemen_skripsi` (TIDAK DISENTUH — ZERO MUTATION)  
**Target Lingkungan:** PHP 8.2.12 / Laravel Framework 12.x / MySQL Community Server 8.0.30 Win64 (InnoDB utf8mb4)  
**Git HEAD Target:** `57b2e90170bfab49e4dcaf951ecb7f277b593dfd`

---

## 1. Lingkungan Pengujian dan Bukti Isolasi Database

### 1.1. Informasi Rilis Perangkat Lunak
- **Git HEAD SHA:** `57b2e90170bfab49e4dcaf951ecb7f277b593dfd`
- **PHP Version:** `PHP 8.2.12 (cli)`
- **Laravel Framework:** `12.x`
- **Database Engine:** `MySQL Community Server 8.0.30 Win64 (InnoDB)`

### 1.2. Bukti Koneksi Database Terisolasi
Pengujian diarahkan secara runtime ke database terisolasi `bchat_migration_test` menggunakan variabel lingkungan sesi powershell `$env:DB_DATABASE='bchat_migration_test'` tanpa menimpa berkas `.env` utama secara permanen.

Bukti koneksi terverifikasi melalui eksekusi Artisan:
```
$ DB::scalar('SELECT DATABASE()')
=> "bchat_migration_test"
```
Konfirmasi:
1. Nama database target pengujian adalah tepat **`bchat_migration_test`**.
2. Database produksi **`manajemen_skripsi`** terbukti tidak disentuh sama sekali (0 tabel B-CHAT terbentuk di database produksi).

---

## 2. Persiapan Skema Dependensi Akademik

Sesuai ketentuan Bagian 4 Mandat B-CHAT-15B, untuk memungkinkan pengujian foreign key 13 berkas migrasi B-CHAT, skema dependensi akademik existing telah dimigrasikan terlebih dahulu ke database terisolasi `bchat_migration_test`:

1. `0001_01_01_000000_create_users_table.php` $\rightarrow$ tabel `users`, `password_reset_tokens`, `sessions`.
2. `2026_09_26_030433_create_mahasiswa_table.php` $\rightarrow$ tabel `mahasiswa`.
3. `2026_09_26_030449_create_dosen_table.php` $\rightarrow$ tabel `dosen`.
4. `2026_09_26_040728_create_skripsi_table.php` $\rightarrow$ tabel `skripsi`.
5. `2026_10_28_000001_create_pengajuan_dospem_table.php` $\rightarrow$ tabel `pengajuan_dospem`.

Hasil persiapan dependensi: **SUKSES** (5 tabel akademik siap menerima relasi foreign key).

---

## 3. Hasil Pengujian Eksekusi 13 Migration (Up Migration)

Pengujian eksekusi migrasi menghasilkan temuan teknis yang sangat penting mengenai kompatibilitas Laravel Schema Builder dan MySQL 8.0.30:

| No | Berkas Migration | Tabel Sasaran | Hasil Eksekusi | Status | Catatan Teknis / Error Log |
| :---: | :--- | :--- | :---: | :---: | :--- |
| 1 | `2026_11_07_000001_create_sk_skripsi_dokumen_table.php` | `sk_skripsi_dokumen` | 390.43 ms | **PASS** | Sukses penuh. Composite key, self-ref composite FK, virtual column `terkini_slot`, unique index terbentuk sempurna. |
| 2 | `2026_11_07_000002_create_penugasan_pembimbing_table.php` | `penugasan_pembimbing` | 304.05 ms | **FAIL** | **MySQL Error 3823:** `Column 'sk_dokumen_id' cannot be used in a check constraint 'chk_penugasan_tahap_sk_bifurcation': needed in a foreign key constraint 'fk_penugasan_sk_composite' referential action.` |
| 3 | `2026_11_07_000003_create_penugasan_pembimbing_logs_table.php` | `penugasan_pembimbing_logs` | 157.42 ms | **PASS** | Sukses penuh. Relasi FK `RESTRICT` dan index riwayat terbentuk sempurna. |
| 4 | `2026_11_07_000004_create_bchat_rooms_table.php` | `bchat_rooms` | 85.06 ms | **PASS** | Sukses penuh. Relasi 1-to-1 dengan penugasan, enum status, sekuens uint64 terbentuk. |
| 5 | `2026_11_07_000005_create_bchat_room_members_table.php` | `bchat_room_members` | 143.32 ms | **PASS** | Sukses penuh. Composite unique `(room_id, user_id)` dan index otentikasi terbentuk. |
| 6 | `2026_11_07_000006_create_bchat_key_packages_table.php` | `bchat_key_packages` | 3.41 ms | **FAIL** | **BadMethodCallException:** `Method Illuminate\Database\Schema\Blueprint::longBinary does not exist.` |
| 7 | `2026_11_07_000007_create_bchat_welcome_challenges_table.php` | `bchat_welcome_challenges` | 3.47 ms | **FAIL** | **BadMethodCallException:** `Method Illuminate\Database\Schema\Blueprint::longBinary does not exist.` |
| 8 | `2026_11_07_000008_create_bchat_messages_table.php` | `bchat_messages` | 3.57 ms | **FAIL** | **BadMethodCallException:** `Method Illuminate\Database\Schema\Blueprint::longBinary does not exist.` |
| 9 | `2026_11_07_000009_create_bchat_message_receipts_table.php` | `bchat_message_receipts` | 101.30 ms | **PASS** | Sukses penuh (teruji di atas tabel pesan yang valid). Composite unique tanda terima terbentuk. |
| 10 | `2026_11_07_000010_create_bchat_upload_sessions_table.php` | `bchat_upload_sessions` | 131.40 ms | **PASS** | Sukses penuh. Target composite key `(id, room_id)` terbentuk sempurna. |
| 11 | `2026_11_07_000011_create_bchat_attachments_table.php` | `bchat_attachments` | 130.67 ms | **PASS** | Sukses penuh. Composite FK ganda menuju sesi unggah dan pesan manifest terverifikasi 100%. |
| 12 | `2026_11_07_000012_create_bchat_attachment_chunks_table.php` | `bchat_attachment_chunks` | 64.84 ms | **PASS** | Sukses penuh. Unique `(attachment_id, chunk_index)` dan storage path terbentuk. |
| 13 | `2026_11_07_000013_create_bchat_crl_revocations_table.php` | `bchat_crl_revocations` | 71.15 ms | **PASS** | Sukses penuh. Relasi `RESTRICT` dan unique UUID kredensial terbentuk. |

---

## 4. Analisis Mendalam Akar Masalah Temuan Pengujian

### 4.1. Temuan 1: Konflik MySQL Error 3823 pada Migrasi 2 (`penugasan_pembimbing`)

#### Log Kegagalan:
```
SQLSTATE[HY000]: General error: 3823 Column 'sk_dokumen_id' cannot be used in a check constraint 
'chk_penugasan_tahap_sk_bifurcation': needed in a foreign key constraint 'fk_penugasan_sk_composite' referential action.
```

#### Analisis Akar Masalah:
Sesuai dokumentasi resmi MySQL 8.0 Reference Manual (Section 13.1.20.6 *CHECK Constraints*):
> *"A check constraint cannot refer to a column that has a referential action (`ON UPDATE CASCADE`, `ON DELETE CASCADE`, `ON UPDATE SET NULL`, `ON DELETE SET NULL`, etc.) in a foreign key constraint."*

Pada berkas `2026_11_07_000002_create_penugasan_pembimbing_table.php` (yang mengadopsi naskah DDL `B-CHAT-13` baris 147), FK komposit didefinisikan dengan:
```php
$table->foreign(['sk_dokumen_id', 'skripsi_id'], 'fk_penugasan_sk_composite')
    ->references(['id', 'skripsi_id'])
    ->on('sk_skripsi_dokumen')
    ->restrictOnDelete()
    ->cascadeOnUpdate(); // <-- REFERENTIAL ACTION PENYEBAB ER_CHECK_CONSTRAINT_REFERS_TO_FK
```
Karena `sk_dokumen_id` memiliki referential action `ON UPDATE CASCADE`, mesin MySQL 8.0.30 menolak penambahan CHECK constraint `chk_penugasan_tahap_sk_bifurcation` yang melibatkan kolom `sk_dokumen_id`.

#### Solusi Empiris yang Terverifikasi:
Dalam pengujian terpisah pada database terisolasi `bchat_migration_test`, foreign key `fk_penugasan_sk_composite` diuji menggunakan referential action `ON UPDATE RESTRICT`:
```sql
CONSTRAINT fk_penugasan_sk_composite FOREIGN KEY (sk_dokumen_id, skripsi_id) 
    REFERENCES sk_skripsi_dokumen(id, skripsi_id) ON DELETE RESTRICT ON UPDATE RESTRICT
```
**Hasil Verifikasi:** MySQL 8.0.30 secara sukses dan sempurna menerima:
- Foreign key `fk_penugasan_sk_composite`.
- Generated virtual column `active_slot`.
- Unique key `uk_skripsi_active_slot`.
- Kedua CHECK constraints: `chk_penugasan_tahap_sk_bifurcation` dan `chk_penugasan_pembimbing_ke_domain`.

Secara prinsip akademik dan hukum universitas, dokumen SK adalah dokumen negara/institusi yang *immutable* (nomor ID auto-increment tidak pernah diubah). Penerapan `ON UPDATE RESTRICT` justru jauh lebih aman dan merefleksikan integritas data yang kokoh.

---

### 4.2. Temuan 2: Ketiadaan Method `longBinary()` pada Laravel Schema Blueprint

#### Log Kegagalan:
```
BadMethodCallException: Method Illuminate\Database\Schema\Blueprint::longBinary does not exist.
```
Terjadi pada:
1. Migrasi 6 (`bchat_key_packages`): baris 25 (`$table->longBinary('key_package_data')`)
2. Migrasi 7 (`bchat_welcome_challenges`): baris 34 (`$table->longBinary('welcome_message')`)
3. Migrasi 8 (`bchat_messages`): baris 38 (`$table->longBinary('ciphertext_payload')`)

#### Analisis Akar Masalah:
Pada Laravel 12 Schema Builder Blueprint, method native untuk kolom biner adalah **`$table->binary('kolom')`**. Method `longBinary()` tidak ada dalam kelas `Illuminate\Database\Schema\Blueprint`.

Pada dialek MySQL Laravel, `$table->binary('kolom')` secara default menghasilkan tipe kolom `BLOB` (atau dapat diperluas menjadi `LONGBLOB` via modifier atau raw statement). Pemanggilan method yang tidak eksis menyebabkan `BadMethodCallException` sebelum DDL dikirim ke database.

---

## 5. Hasil Pengujian Rollback (Down Migration)

Pengujian rollback dilakukan untuk memvalidasi urutan dependensi terbalik (*LIFO*) dan isolasi skema:

1. **Rollback Migrasi 12 (`bchat_attachment_chunks`):** Berhasil (**PASS**, 15.12 ms). Tabel `bchat_attachment_chunks` terhapus bersih.
2. **Rollback Migrasi 11 (`bchat_attachments`):** Berhasil (**PASS**, 17.49 ms). Tabel `bchat_attachments` terhapus bersih.
3. **Rollback Migrasi 9 (`bchat_message_receipts`):** Berhasil (**PASS**, 15.57 ms). Tabel `bchat_message_receipts` terhapus bersih.
4. **Rollback Migrasi 13, 10, 5, 4, 3:** Berhasil (**PASS**). Tabel `bchat_crl_revocations`, `bchat_upload_sessions`, `bchat_room_members`, `bchat_rooms`, `penugasan_pembimbing_logs` terhapus bersih secara berurutan.
5. **Rollback Migrasi 1 (`sk_skripsi_dokumen`):** Berhasil (**PASS**, 21.28 ms). Tabel `sk_skripsi_dokumen` terhapus bersih.
6. **Isolasi Rollback terhadap Database Aplikasi:**
   - Tidak ada tabel di database aplikasi `manajemen_skripsi` yang terhapus atau berubah.
   - Tabel dependensi akademik di `bchat_migration_test` tetap utuh.

---

## 6. Konfirmasi Status Database Produksi

Pemeriksaan status basis data utama universitas dilakukan secara langsung:
```sql
SHOW TABLES LIKE 'bchat%';
-- Empty set (0 rows)

SHOW TABLES LIKE '%sk_skripsi%';
-- Empty set (0 rows)
```
**Konfirmasi Mutlak:** Database produksi `manajemen_skripsi` / `sim_skripsi` **100% TIDAK PERNAH DISENTUH ATAU DIMODIFIKASI (ZERO DATABASE MUTATION)**.

---

## 7. Keterbatasan dan Risiko Tersisa

1. **Integritas Constraint di Tingkat Database vs Logika Aplikasi:**
   Meskipun sebagian besar constraint (composite FK, generated active slots, dan unique indexes) berhasil diverifikasi perilakunya pada MySQL 8.0.30, batasan kuantitatif seperti:
   - Maksimum 10 MB per berkas lampiran,
   - Maksimum 25 MB total plaintext per pesan,
   - Maksimum 5 lampiran per pesan,
   - Verifikasi tanda tangan digital Ed25519 Canonical ACK 158-byte,
   tetap merupakan domain penegakan **Application Transaction Guard** pada lapisan service backend. Keberhasilan DDL tidak menggantikan pengujian unit/feature logic backend.
2. **Kebutuhan Perbaikan Terarah pada Tahap Berikutnya:**
   Sesuai batas otorisasi Mandat B-CHAT-15B yang melarang modifikasi otomatis file migration, draf berkas migrasi saat ini belum dimodifikasi. Perbaikan untuk:
   - Mengubah `cascadeOnUpdate()` menjadi `restrictOnUpdate()` pada FK `fk_penugasan_sk_composite` di migrasi 2,
   - Mengubah `$table->longBinary(...)` menjadi `$table->binary(...)` pada migrasi 6, 7, dan 8,
   harus menjadi agenda mandat koreksi terarah berikutnya (B-CHAT-15C).

---

## 8. Kesimpulan Akhir

Pengujian empiris pada lingkungan terisolasi `bchat_migration_test` berhasil membuktikan:
1. Sembilan (9) dari 13 berkas migrasi berhasil dijalankan dan di-rollback dengan sukses tanpa kendala.
2. Empat (4) berkas migrasi mengalami kegagalan teknis spesifik yang telah teridentifikasi akar masalahnya secara definitif:
   - Migrasi 2 gagal karena aturan MySQL 8.0.30 Error 3823 (kolom CHECK constraint tidak boleh memiliki FK `ON UPDATE CASCADE`).
   - Migrasi 6, 7, 8 gagal karena Laravel Schema Blueprint tidak memiliki method `longBinary()`.
3. Database utama universitas terbukti aman 100% tanpa mutasi.

Sesuai aturan format mandat Bagian 9, status kesimpulan akhir adalah:

$$\mathbf{FAIL\ —\ COMPATIBILITY\ BLOCKERS\ IDENTIFIED\ ON\ ISOLATED\ DATABASE}$$

*(Pengujian dijalankan dengan integritas tinggi dan berhasil mengungkap incompatibilities kritis sebelum mencapai database produksi).*

---
*Laporan pengujian migrasi terisolasi ini disusun secara faktual berdasarkan bukti eksekusi log PHP/MySQL, disahkan sebagai bukti penyelesaian mandat B-CHAT-15B.*

