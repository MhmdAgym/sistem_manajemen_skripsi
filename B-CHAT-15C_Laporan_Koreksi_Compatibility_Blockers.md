# LAPORAN TEKNIS KOREKSI TERARAH B-CHAT-15C
## Koreksi Compatibility Blockers pada Draf Berkas Laravel Migration

**Proyek:** Sistem Informasi Manajemen Skripsi UNUHA  
**Fitur:** Bimbingan Online Chat Real-Time E2EE (B-CHAT)  
**Dokumen Acuan:** `B-CHAT-13_Rancangan_DDL_Final.md`, `B-CHAT-15B_Laporan_Pengujian_Migrasi_Terisolasi.md`  
**Target Lingkungan:** PHP 8.2.12 / Laravel 12.x / MySQL Community Server 8.0.30 Win64 (InnoDB utf8mb4)  
**Git HEAD Target:** `57b2e90170bfab49e4dcaf951ecb7f277b593dfd`  
**Mode Operasi:** **SOURCE FILE EDIT ONLY — ZERO DATABASE MUTATION — ZERO MIGRATION EXECUTION**

---

## 1. Ringkasan Eksekutif & Status Mandat

Sesuai dengan mandat **B-CHAT-15C**, telah diselesaikan koreksi terarah atas dua kendala kompatibilitas (*compatibility blockers*) yang teridentifikasi pada tahap pengujian database terisolasi B-CHAT-15B:

1. **Koreksi Blocker A (MySQL Error 3823):**  
   Menyelaraskan referential action foreign key komposit `fk_penugasan_sk_composite` pada berkas `2026_11_07_000002_create_penugasan_pembimbing_table.php` dari `cascadeOnUpdate()` menjadi `restrictOnUpdate()`. Koreksi ini mengeliminasi penolakan MySQL 8.0.30 atas kolom CHECK constraint `chk_penugasan_tahap_sk_bifurcation` yang melibatkan `sk_dokumen_id`.
2. **Koreksi Blocker B (Ketiadaan Method `longBinary` pada Laravel 12):**  
   Mengganti pemanggilan method fiktif `$table->longBinary()` menjadi method native resmi Laravel 12 Schema Builder **`$table->rawColumn('kolom', 'longblob')`** pada tiga berkas migrasi:
   - `2026_11_07_000006_create_bchat_key_packages_table.php` (`key_package_data`)
   - `2026_11_07_000007_create_bchat_welcome_challenges_table.php` (`welcome_message`)
   - `2026_11_07_000008_create_bchat_messages_table.php` (`ciphertext_payload`)

**Kepatuhan Batasan Mutlak:**
- **Zero Database Mutation:** Tidak ada perintah migrasi, rollback, atau query SQL yang dieksekusi ke database apa pun (termasuk `bchat_migration_test` maupun `manajemen_skripsi`).
- **Strict Scope Isolation:** Tepat 4 berkas migrasi yang diubah; tidak ada berkas lain di luar ruang lingkup yang tersentuh.
- **Integritas Constraint:** Seluruh kolom, foreign key, composite index, generated virtual columns, dan CHECK constraints tetap dipertahankan 100%.

---

## 2. Inventaris Berkas yang Diperiksa dan Diubah

| No | Berkas Migration | Komponen yang Dikoreksi | Sebelum Koreksi | Setelah Koreksi |
| :---: | :--- | :--- | :--- | :--- |
| 1 | [`2026_11_07_000002_create_penugasan_pembimbing_table.php`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/manajemen_skripsi/database/migrations/2026_11_07_000002_create_penugasan_pembimbing_table.php) | FK `fk_penugasan_sk_composite` | `->cascadeOnUpdate()` | `->restrictOnUpdate()` |
| 2 | [`2026_11_07_000006_create_bchat_key_packages_table.php`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/manajemen_skripsi/database/migrations/2026_11_07_000006_create_bchat_key_packages_table.php) | Kolom `key_package_data` | `$table->longBinary(...)` | `$table->rawColumn('key_package_data', 'longblob')` |
| 3 | [`2026_11_07_000007_create_bchat_welcome_challenges_table.php`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/manajemen_skripsi/database/migrations/2026_11_07_000007_create_bchat_welcome_challenges_table.php) | Kolom `welcome_message` | `$table->longBinary(...)` | `$table->rawColumn('welcome_message', 'longblob')` |
| 4 | [`2026_11_07_000008_create_bchat_messages_table.php`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/manajemen_skripsi/database/migrations/2026_11_07_000008_create_bchat_messages_table.php) | Kolom `ciphertext_payload` | `$table->longBinary(...)` | `$table->rawColumn('ciphertext_payload', 'longblob')` |

---

## 3. Diagnosis & Penjelasan Teknis Koreksi Blocker

### 3.1. Koreksi A — Migrasi 000002 (`penugasan_pembimbing`)

#### Diagnosis Masalah:
Pada pengujian B-CHAT-15B, eksekusi statement CHECK constraint:
```sql
ALTER TABLE `penugasan_pembimbing` ADD CONSTRAINT `chk_penugasan_tahap_sk_bifurcation` CHECK (
    (`tahap` = 'proposal' AND `sk_dokumen_id` IS NULL AND `pembimbing_ke` = 1)
    OR
    (`tahap` = 'skripsi' AND `sk_dokumen_id` IS NOT NULL AND `pembimbing_ke` IN (1, 2))
)
```
ditolak oleh mesin MySQL 8.0.30 dengan kode galat:
```
SQLSTATE[HY000]: General error: 3823 Column 'sk_dokumen_id' cannot be used in a check constraint 
'chk_penugasan_tahap_sk_bifurcation': needed in a foreign key constraint 'fk_penugasan_sk_composite' referential action.
```
Berdasarkan dokumentasi resmi MySQL 8.0 Reference Manual (Section 13.1.20.6 *CHECK Constraints*), kolom yang dievaluasi dalam CHECK constraint dilarang keras memiliki *mutative referential action* (`ON UPDATE CASCADE`, `ON DELETE CASCADE`, `ON UPDATE SET NULL`, dsb.) pada foreign key yang mengikat kolom tersebut.

#### Tindakan Koreksi:
Pada baris 54-60 berkas `2026_11_07_000002_create_penugasan_pembimbing_table.php`, aksi referensial diubah:
```php
// SEBELUM:
$table->foreign(['sk_dokumen_id', 'skripsi_id'], 'fk_penugasan_sk_composite')
    ->references(['id', 'skripsi_id'])
    ->on('sk_skripsi_dokumen')
    ->restrictOnDelete()
    ->cascadeOnUpdate();

// SESUDAH:
$table->foreign(['sk_dokumen_id', 'skripsi_id'], 'fk_penugasan_sk_composite')
    ->references(['id', 'skripsi_id'])
    ->on('sk_skripsi_dokumen')
    ->restrictOnDelete()
    ->restrictOnUpdate();
```

#### Alasan Arsitektural & Integritas Bisnis:
1. **Kepatuhan MySQL 8.0.30:** Dengan `ON UPDATE RESTRICT`, kolom `sk_dokumen_id` tidak memiliki aksi referensial mutatif liar, sehingga MySQL mengizinkan keberadaan CHECK constraint `chk_penugasan_tahap_sk_bifurcation`.
2. **Immutability Dokumen SK:** Secara hukum tata kelola universitas, nomor ID arsip SK dan `skripsi_id` bersifat abadi (*immutable*). Primary key auto-increment pada `sk_skripsi_dokumen` tidak pernah dimutasi/diperbarui nilainya, sehingga kebijakan `RESTRICT` justru merefleksikan prinsip integritas data institusi yang lebih ketat dibanding `CASCADE`.
3. **Ketahanan Invarian:** Tidak ada pelemahan atau penghapusan CHECK constraint; bifurkasi proposal vs skripsi dan domain `pembimbing_ke` tetap ditegakkan seutuhnya.

---

### 3.2. Koreksi B — Migrasi 000006, 000007, dan 000008 (Kolom Binary)

#### Diagnosis Masalah:
Laravel 12 Schema Builder Blueprint tidak memiliki method `longBinary()`. Pemanggilan `$table->longBinary(...)` memicu pengecualian fatal:
```
BadMethodCallException: Method Illuminate\Database\Schema\Blueprint::longBinary does not exist.
```

#### Verifikasi Implementasi Native Laravel 12 & MySqlGrammar:
Inspeksi langsung terhadap kode sumber framework yang terpasang di `vendor/laravel/framework/` membuktikan fakta-fakta berikut:

1. **Perilaku Method `$table->binary()`:**  
   Pada `vendor/laravel/framework/src/Illuminate/Database/Schema/Grammars/MySqlGrammar.php` baris 1098-1105:
   ```php
   protected function typeBinary(Fluent $column)
   {
       if ($column->length) {
           return $column->fixed ? "binary({$column->length})" : "varbinary({$column->length})";
       }

       return 'blob';
   }
   ```
   Pemanggilan `$table->binary('kolom')` tanpa panjang akan menghasilkan tipe SQL **`blob`** (MySQL Standard BLOB, kapasitas maksimal $65.535\text{ byte} = 64\text{ KiB}$).
2. **Kebutuhan Tipe Kolom:**  
   Rancangan DDL Induk `B-CHAT-13_Rancangan_DDL_Final.md` menetapkan tipe **`LONGBLOB`** untuk:
   - `bchat_key_packages.key_package_data` (LONGBLOB)
   - `bchat_welcome_challenges.welcome_message` (LONGBLOB)
   - `bchat_messages.ciphertext_payload` (LONGBLOB)
   Meskipun ukuran riil KeyPackage (~1-2 KB), Welcome message (~3-8 KB), dan Chat payload (~1-10 KB) berada di bawah 64 KB, tipe `LONGBLOB` dibutuhkan untuk mencegah *overflow boundary risk* saat transaksi lampiran masif atau ekstensi TreeKEM di masa mendatang.
3. **Ketersediaan Method Resmi `$table->rawColumn()` di Laravel 12:**  
   Pada `vendor/laravel/framework/src/Illuminate/Database/Schema/Blueprint.php` baris 1676-1679:
   ```php
   public function rawColumn($column, $definition)
   {
       return $this->addColumn('raw', $column, compact('definition'));
   }
   ```
   Dan pada `vendor/laravel/framework/src/Illuminate/Database/Schema/Grammars/Grammar.php` baris 356-359:
   ```php
   protected function typeRaw(Fluent $column)
   {
       return $column->offsetGet('definition');
   }
   ```
   Method `rawColumn($column, $definition)` adalah method bawaan Laravel Blueprint yang dirancang khusus untuk mendeklarasikan tipe data spesifik mesin database (seperti `longblob`) secara elegan dan deklaratif di dalam closure `Schema::create`.

#### Bukti Hasil Kompilasi Blueprint ke Dialek MySQL 8:
Pengujian kompilasi statis melalui Laravel Schema Grammar membuktikan SQL yang dihasilkan:
- **`bchat_key_packages`:**
  ```sql
  create table `bchat_key_packages` (
      `id` bigint unsigned not null auto_increment primary key, 
      `user_id` bigint unsigned not null, 
      `key_package_hash` char(64) not null, 
      `key_package_data` longblob not null comment 'Paket biner KeyPackage MLS bertanda tangan digital CA Ed25519', 
      `is_consumed` tinyint(1) not null default '0', 
      ...
  ) default character set utf8mb4 collate 'utf8mb4_unicode_ci'
  ```
- **`bchat_welcome_challenges`:**
  ```sql
  create table `bchat_welcome_challenges` (
      ...
      `welcome_message` longblob not null comment 'Paket Welcome biner terenkripsi HPKE (immutable per operasi)',
      ...
  ) default character set utf8mb4 collate 'utf8mb4_unicode_ci'
  ```
- **`bchat_messages`:**
  ```sql
  create table `bchat_messages` (
      ...
      `ciphertext_payload` longblob not null comment 'Paket MLS PrivateMessage biner (TreeKEM ratcheted)',
      ...
  ) default character set utf8mb4 collate 'utf8mb4_unicode_ci'
  ```

---

## 4. Rincian Patch / Diff Keempat Berkas Migration

### 4.1. Berkas 000002 (`penugasan_pembimbing`)
```diff
--- a/manajemen_skripsi/database/migrations/2026_11_07_000002_create_penugasan_pembimbing_table.php
+++ b/manajemen_skripsi/database/migrations/2026_11_07_000002_create_penugasan_pembimbing_table.php
@@ -58,3 +58,3 @@
                 ->on('sk_skripsi_dokumen')
                 ->restrictOnDelete()
-                ->cascadeOnUpdate();
+                ->restrictOnUpdate();
```

### 4.2. Berkas 000006 (`bchat_key_packages`)
```diff
--- a/manajemen_skripsi/database/migrations/2026_11_07_000006_create_bchat_key_packages_table.php
+++ b/manajemen_skripsi/database/migrations/2026_11_07_000006_create_bchat_key_packages_table.php
@@ -25,1 +25,1 @@
-            $table->longBinary('key_package_data')->comment('Paket biner KeyPackage MLS bertanda tangan digital CA Ed25519');
+            $table->rawColumn('key_package_data', 'longblob')->comment('Paket biner KeyPackage MLS bertanda tangan digital CA Ed25519');
```

### 4.3. Berkas 000007 (`bchat_welcome_challenges`)
```diff
--- a/manajemen_skripsi/database/migrations/2026_11_07_000007_create_bchat_welcome_challenges_table.php
+++ b/manajemen_skripsi/database/migrations/2026_11_07_000007_create_bchat_welcome_challenges_table.php
@@ -34,1 +34,1 @@
-            $table->longBinary('welcome_message')->comment('Paket Welcome biner terenkripsi HPKE (immutable per operasi)');
+            $table->rawColumn('welcome_message', 'longblob')->comment('Paket Welcome biner terenkripsi HPKE (immutable per operasi)');
```

### 4.4. Berkas 000008 (`bchat_messages`)
```diff
--- a/manajemen_skripsi/database/migrations/2026_11_07_000008_create_bchat_messages_table.php
+++ b/manajemen_skripsi/database/migrations/2026_11_07_000008_create_bchat_messages_table.php
@@ -38,1 +38,1 @@
-            $table->longBinary('ciphertext_payload')->comment('Paket MLS PrivateMessage biner (TreeKEM ratcheted)');
+            $table->rawColumn('ciphertext_payload', 'longblob')->comment('Paket MLS PrivateMessage biner (TreeKEM ratcheted)');
```

---

## 5. Hasil Validasi Pasca-Koreksi

1. **Linting Sintaksis PHP 8.2 (`php -l`):**
   - `2026_11_07_000002_create_penugasan_pembimbing_table.php`: **PASS** (*No syntax errors detected*)
   - `2026_11_07_000006_create_bchat_key_packages_table.php`: **PASS** (*No syntax errors detected*)
   - `2026_11_07_000007_create_bchat_welcome_challenges_table.php`: **PASS** (*No syntax errors detected*)
   - `2026_11_07_000008_create_bchat_messages_table.php`: **PASS** (*No syntax errors detected*)
2. **Kompilasi DDL Schema Builder:**  
   Keempat berkas lolos kompilasi ke string SQL MySQL tanpa pengecualian `BadMethodCallException` atau sintaksis error.
3. **Verifikasi Lingkup Perubahan (Git Status):**  
   Pemeriksaan `git status --short` mengonfirmasi bahwa **hanya 4 berkas migrasi di atas** yang mengalami perubahan draf; seluruh kode aplikasi, model, controller, route, file konfigurasi, dan file `.env` tetap perawan.
4. **Verifikasi Zero Database Mutation:**  
   Tidak ada perintah DDL atau migrasi yang dijalankan ke database pada mandat ini.

---

## 6. Rencana Verifikasi Tipe Data Aktual pada B-CHAT-15D

Pada tahap pengujian integrasi database terisolasi berikutnya (**B-CHAT-15D**), keabsahan tipe data `LONGBLOB` aktual dan relasi foreign key `RESTRICT` akan diverifikasi secara fisik melalui query `information_schema.COLUMNS`:
```sql
SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE 
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = 'bchat_migration_test' 
  AND COLUMN_NAME IN ('key_package_data', 'welcome_message', 'ciphertext_payload');
```
Kriteria keberhasilan pada B-CHAT-15D adalah `DATA_TYPE = 'longblob'` dan `COLUMN_TYPE = 'longblob'` dengan `IS_NULLABLE = 'NO'`.

---

## 7. Kesimpulan Akhir Mandat B-CHAT-15C

Seluruh kriteria penyelesaian mandat B-CHAT-15C telah terpenuhi secara paripurna:
1. Dua blocker yang terungkap pada B-CHAT-15B telah dikoreksi tuntas.
2. Keempat berkas migrasi lolos verifikasi sintaksis PHP 8.2 dan kompilasi SQL Schema Builder.
3. Tidak ada pelemahan constraint bisnis atau penghapusan CHECK constraint.
4. Tipe `LONGBLOB` ditetapkan berdasarkan implementasi resmi `rawColumn` pada Laravel 12 Blueprint.
5. Zero database mutation dipatuhi secara ketat.

Rekomendasi Status Penutupan:
$$\mathbf{PASS\ —\ TARGETED\ MIGRATION\ CORRECTIONS\ COMPLETE}$$

> [!NOTE]
> Tahap perbaikan draf migrasi telah selesai. Berkas migrasi siap diuji ulang secara penuh (Up, Verifikasi Struktur Aktual, dan Rollback) pada database pengujian terisolasi melalui Mandat **B-CHAT-15D**.

---
*Laporan koreksi terarah ini disahkan sebagai bukti resmi penyelesaian mandat B-CHAT-15C.*

