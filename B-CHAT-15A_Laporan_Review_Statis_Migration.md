# LAPORAN TEKNIS AUDIT STATIS B-CHAT-15A
## Verifikasi Kepatuhan 13 Draf Berkas Laravel Migration terhadap DDL Induk B-CHAT

**Proyek:** Sistem Informasi Manajemen Skripsi UNUHA  
**Fitur:** Bimbingan Online Chat Real-Time E2EE (B-CHAT)  
**Dokumen Acuan Utama:**  
1. `B-CHAT-13_Rancangan_DDL_Final.md` (Konsolidasi B-CHAT-13A s.d. 13E)  
2. `B-CHAT-14_Laporan_Audit_Kesiapan_Migrasi.md`  
3. `B-CHAT-13D_Finalisasi_Model_Welcome_Operation_Challenge.md`  
4. Dokumen Arsitektur B-CHAT-09 s.d. B-CHAT-12H  
**Berkas yang Diaudit:** 13 Berkas Migration pada `manajemen_skripsi/database/migrations/2026_11_07_*`  
**Git HEAD Target:** `57b2e90170bfab49e4dcaf951ecb7f277b593dfd`  
**Mode Audit:** **STRICTLY READ-ONLY — STATIC VERIFICATION ONLY — ZERO MUTATION**

---

## 1. Ringkasan Eksekutif & Status Audit

Sesuai dengan **MANDAT B-CHAT-15A**, telah dilaksanakan audit statis independen dan menyeluruh (*deep line-by-line static inspection*) terhadap **13 berkas draf Laravel Migration** yang dihasilkan pada mandat B-CHAT-15.

Audit ini memverifikasi tingkat kepatuhan teknis draf migrasi terhadap perancangan basis data induk `B-CHAT-13_Rancangan_DDL_Final.md`, kontrak kriptografis EXP-02/Welcome ACK, skema foreign key tabel akademik eksisting, serta batasan native MySQL 8.0.30 InnoDB.

### Ringkasan Hasil Pemeriksaan:
1. **Tingkat Kepatuhan DDL Induk:** **100% PARIPURNA.** Seluruh 13 tabel, nama kolom, signedness (`BIGINT UNSIGNED`), tipe presisi (`CHAR(36)`, `CHAR(64)`, `LONGBLOB`), virtual generated columns, unique constraints, dan foreign keys pada berkas migrasi fisik terbukti bersesuaian penuh dengan DDL Induk B-CHAT-13E.
2. **Klarifikasi Temuan Awal `active_operation_slot` vs `active_op_slot`:** Dikonfirmasi bahwa perbedaan nama tersebut **hanya terjadi pada teks ringkasan laporan B-CHAT-15**, sedangkan pada berkas migrasi fisik (`2026_11_07_000007_create_bchat_welcome_challenges_table.php`), nama yang diimplementasikan adalah **`active_operation_slot`** dan constraint-nya **`uk_challenge_active_slot`**, yang mana **100% IDENTIK** dengan DDL Induk B-CHAT-13E dan keputusan B-CHAT-13D.
3. **Kesesuaian Skema Akademik Eksisting:** Foreign key menuju tabel `users`, `mahasiswa`, `dosen`, `skripsi`, dan `pengajuan_dospem` telah diverifikasi langsung ke berkas migrasi sumbernya dan terbukti cocok tanpa deviasi.
4. **Pemisahan Batas Sistem (Database vs Aplikasi):** Batasan numerik agregat (maks 10 MB per berkas, 25 MB per pesan, 5 lampiran) dipetakan secara tegas sebagai tanggung jawab *Application Transaction Guard*, sementara integritas struktural, anti-replay, dan idempotensi dijamin mutlak oleh database engine.
5. **Kepatuhan Zero Mutation:** Selama pelaksanaan audit ini, **tidak ada perubahan kode, tidak ada eksekusi SQL, tidak ada `php artisan migrate`, dan tidak ada mutasi Git.**

---

## 2. Inventaris 13 Berkas Migration yang Diaudit

Pemeriksaan statis dilakukan langsung pada berkas fisik di direktori:  
`d:\Aplikasi\laragon\www\sistem_manajamen_skripsi\manajemen_skripsi\database\migrations\`

| No | Berkas Migration | Tabel Target | Domain | Status Linting PHP 8.2 | Status Audit Statis |
| :---: | :--- | :--- | :--- | :---: | :---: |
| 1 | [`2026_11_07_000001_create_sk_skripsi_dokumen_table.php`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/manajemen_skripsi/database/migrations/2026_11_07_000001_create_sk_skripsi_dokumen_table.php) | `sk_skripsi_dokumen` | Akademik & Dokumen | PASS | **PASS** |
| 2 | [`2026_11_07_000002_create_penugasan_pembimbing_table.php`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/manajemen_skripsi/database/migrations/2026_11_07_000002_create_penugasan_pembimbing_table.php) | `penugasan_pembimbing` | Akademik & Penugasan | PASS | **PASS** |
| 3 | [`2026_11_07_000003_create_penugasan_pembimbing_logs_table.php`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/manajemen_skripsi/database/migrations/2026_11_07_000003_create_penugasan_pembimbing_logs_table.php) | `penugasan_pembimbing_logs` | Audit Trail Akademik | PASS | **PASS** |
| 4 | [`2026_11_07_000004_create_bchat_rooms_table.php`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/manajemen_skripsi/database/migrations/2026_11_07_000004_create_bchat_rooms_table.php) | `bchat_rooms` | Inti B-CHAT | PASS | **PASS** |
| 5 | [`2026_11_07_000005_create_bchat_room_members_table.php`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/manajemen_skripsi/database/migrations/2026_11_07_000005_create_bchat_room_members_table.php) | `bchat_room_members` | Inti B-CHAT | PASS | **PASS** |
| 6 | [`2026_11_07_000006_create_bchat_key_packages_table.php`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/manajemen_skripsi/database/migrations/2026_11_07_000006_create_bchat_key_packages_table.php) | `bchat_key_packages` | Kriptografi Pre-Key | PASS | **PASS** |
| 7 | [`2026_11_07_000007_create_bchat_welcome_challenges_table.php`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/manajemen_skripsi/database/migrations/2026_11_07_000007_create_bchat_welcome_challenges_table.php) | `bchat_welcome_challenges` | Protokol Welcome ACK | PASS | **PASS** |
| 8 | [`2026_11_07_000008_create_bchat_messages_table.php`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/manajemen_skripsi/database/migrations/2026_11_07_000008_create_bchat_messages_table.php) | `bchat_messages` | Pesan & Idempotensi | PASS | **PASS** |
| 9 | [`2026_11_07_000009_create_bchat_message_receipts_table.php`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/manajemen_skripsi/database/migrations/2026_11_07_000009_create_bchat_message_receipts_table.php) | `bchat_message_receipts` | Tanda Terima Pesan | PASS | **PASS** |
| 10 | [`2026_11_07_000010_create_bchat_upload_sessions_table.php`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/manajemen_skripsi/database/migrations/2026_11_07_000010_create_bchat_upload_sessions_table.php) | `bchat_upload_sessions` | Sesi Unggah Lampiran | PASS | **PASS** |
| 11 | [`2026_11_07_000011_create_bchat_attachments_table.php`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/manajemen_skripsi/database/migrations/2026_11_07_000011_create_bchat_attachments_table.php) | `bchat_attachments` | Manifest Lampiran | PASS | **PASS** |
| 12 | [`2026_11_07_000012_create_bchat_attachment_chunks_table.php`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/manajemen_skripsi/database/migrations/2026_11_07_000012_create_bchat_attachment_chunks_table.php) | `bchat_attachment_chunks` | Potongan Chunk Biner | PASS | **PASS** |
| 13 | [`2026_11_07_000013_create_bchat_crl_revocations_table.php`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/manajemen_skripsi/database/migrations/2026_11_07_000013_create_bchat_crl_revocations_table.php) | `bchat_crl_revocations` | CRL Pencabutan Identitas | PASS | **PASS** |

---

## 3. Matriks Rekonsiliasi Rinci DDL Induk vs Laravel Migration

Berikut adalah perbandingan mendalam kolom-demi-kolom antara naskah `B-CHAT-13_Rancangan_DDL_Final.md` dengan berkas draf migrasi fisik:

### 3.1. Tabel `sk_skripsi_dokumen` (Migrasi 000001)
* **Kesesuaian Kolom:**
  - `id`: `BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY` $\leftrightarrow$ `$table->id()` (PASS)
  - `skripsi_id`: `BIGINT UNSIGNED NOT NULL` $\leftrightarrow$ `$table->unsignedBigInteger('skripsi_id')` (PASS)
  - `mahasiswa_id`: `BIGINT UNSIGNED NOT NULL` $\leftrightarrow$ `$table->unsignedBigInteger('mahasiswa_id')` (PASS)
  - `versi_ke`: `INT UNSIGNED NOT NULL DEFAULT 1` $\leftrightarrow$ `$table->unsignedInteger('versi_ke')->default(1)` (PASS)
  - `is_terkini`: `BOOLEAN NOT NULL DEFAULT TRUE` $\leftrightarrow$ `$table->boolean('is_terkini')->default(true)` (PASS)
  - `induk_dokumen_id`: `BIGINT UNSIGNED NULL` $\leftrightarrow$ `$table->unsignedBigInteger('induk_dokumen_id')->nullable()` (PASS)
  - `nomor_sk`: `VARCHAR(100) NOT NULL` $\leftrightarrow$ `$table->string('nomor_sk', 100)` (PASS)
  - `tanggal_sk`: `DATE NOT NULL` $\leftrightarrow$ `$table->date('tanggal_sk')` (PASS)
  - `file_path`: `VARCHAR(255) NOT NULL` $\leftrightarrow$ `$table->string('file_path', 255)` (PASS)
  - `file_hash_sha256`: `CHAR(64) NOT NULL` $\leftrightarrow$ `$table->char('file_hash_sha256', 64)` (PASS)
  - `file_size_bytes`: `BIGINT UNSIGNED NOT NULL` $\leftrightarrow$ `$table->unsignedBigInteger('file_size_bytes')` (PASS)
  - `status_dokumen`: `ENUM('MENUNGGU_PEMERIKSAAN', 'PERLU_PERBAIKAN', 'DITOLAK', 'TERVERIFIKASI', 'DIBATALKAN') DEFAULT 'MENUNGGU_PEMERIKSAAN'` $\leftrightarrow$ `$table->enum(...)` (PASS)
  - `admin_id`: `BIGINT UNSIGNED NULL` $\leftrightarrow$ `$table->unsignedBigInteger('admin_id')->nullable()` (PASS)
  - `diperiksa_at`: `TIMESTAMP NULL` $\leftrightarrow$ `$table->timestamp('diperiksa_at')->nullable()` (PASS)
  - `catatan_admin`: `TEXT NULL` $\leftrightarrow$ `$table->text('catatan_admin')->nullable()` (PASS)
  - `dosen_1_usulan_id`: `BIGINT UNSIGNED NULL` $\leftrightarrow$ `$table->unsignedBigInteger('dosen_1_usulan_id')->nullable()` (PASS)
  - `dosen_2_usulan_id`: `BIGINT UNSIGNED NULL` $\leftrightarrow$ `$table->unsignedBigInteger('dosen_2_usulan_id')->nullable()` (PASS)
  - `terkini_slot`: `VARCHAR(20) GENERATED ALWAYS AS (CASE WHEN is_terkini = 1 THEN 'CURRENT' ELSE NULL END) VIRTUAL` $\leftrightarrow$ `$table->string('terkini_slot', 20)->virtualAs(...)` (PASS)
* **Kesesuaian Constraints & Indeks:**
  - FK `fk_skdok_skripsi`: to `skripsi(id_skripsi)` `ON DELETE RESTRICT ON UPDATE CASCADE` (PASS)
  - FK `fk_skdok_mahasiswa`: to `mahasiswa(id_mahasiswa)` `ON DELETE RESTRICT ON UPDATE CASCADE` (PASS)
  - FK `fk_skdok_admin`: to `users(id)` `ON DELETE SET NULL ON UPDATE CASCADE` (PASS)
  - FK `fk_skdok_dosen1`: to `dosen(id_dosen)` `ON DELETE SET NULL ON UPDATE CASCADE` (PASS)
  - FK `fk_skdok_dosen2`: to `dosen(id_dosen)` `ON DELETE SET NULL ON UPDATE CASCADE` (PASS)
  - Composite FK `fk_skdok_induk_composite`: `(induk_dokumen_id, skripsi_id)` references `sk_skripsi_dokumen(id, skripsi_id)` `ON DELETE RESTRICT ON UPDATE CASCADE` (PASS)
  - Unique `uk_dokumen_id_skripsi`: `(id, skripsi_id)` (PASS)
  - Unique `uk_skripsi_versi`: `(skripsi_id, versi_ke)` (PASS)
  - Unique `uk_skripsi_dokumen_terkini`: `(skripsi_id, terkini_slot)` (PASS)
  - Index `idx_skdok_verifikasi`: `(skripsi_id, status_dokumen, is_terkini)` (PASS)
  - Index `idx_skdok_hash`: `file_hash_sha256` (PASS)

---

### 3.2. Tabel `penugasan_pembimbing` (Migrasi 000002)
* **Kesesuaian Kolom:**
  - `id`: `BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY` $\leftrightarrow$ `$table->id()` (PASS)
  - `skripsi_id`: `BIGINT UNSIGNED NOT NULL` $\leftrightarrow$ `$table->unsignedBigInteger('skripsi_id')` (PASS)
  - `tahap`: `ENUM('proposal', 'skripsi') NOT NULL` $\leftrightarrow$ `$table->enum('tahap', ['proposal', 'skripsi'])` (PASS)
  - `pembimbing_ke`: `TINYINT UNSIGNED NOT NULL` $\leftrightarrow$ `$table->unsignedTinyInteger('pembimbing_ke')` (PASS)
  - `dosen_id`: `BIGINT UNSIGNED NOT NULL` $\leftrightarrow$ `$table->unsignedBigInteger('dosen_id')` (PASS)
  - `sk_dokumen_id`: `BIGINT UNSIGNED NULL` $\leftrightarrow$ `$table->unsignedBigInteger('sk_dokumen_id')->nullable()` (PASS)
  - `pengajuan_dospem_id`: `BIGINT UNSIGNED NULL` $\leftrightarrow$ `$table->unsignedBigInteger('pengajuan_dospem_id')->nullable()` (PASS)
  - `status_penugasan`: `ENUM('AKTIF', 'DIGANTI', 'DIBATALKAN') DEFAULT 'AKTIF'` $\leftrightarrow$ `$table->enum(...)` (PASS)
  - `tanggal_mulai`: `DATE NOT NULL` $\leftrightarrow$ `$table->date('tanggal_mulai')` (PASS)
  - `tanggal_selesai`: `DATE NULL` $\leftrightarrow$ `$table->date('tanggal_selesai')->nullable()` (PASS)
  - `digantikan_oleh_id`: `BIGINT UNSIGNED NULL` $\leftrightarrow$ `$table->unsignedBigInteger('digantikan_oleh_id')->nullable()` (PASS)
  - `active_slot`: `VARCHAR(50) GENERATED ALWAYS AS (CASE WHEN status_penugasan = 'AKTIF' THEN CONCAT(tahap, '_', pembimbing_ke) ELSE NULL END) VIRTUAL` $\leftrightarrow$ `$table->string('active_slot', 50)->virtualAs(...)` (PASS)
* **Kesesuaian Constraints & Indeks:**
  - FK `fk_penugasan_skripsi`: to `skripsi(id_skripsi)` `ON DELETE CASCADE ON UPDATE CASCADE` (PASS)
  - FK `fk_penugasan_dosen`: to `dosen(id_dosen)` `ON DELETE RESTRICT ON UPDATE CASCADE` (PASS)
  - FK `fk_penugasan_pengajuan`: to `pengajuan_dospem(id)` `ON DELETE SET NULL ON UPDATE CASCADE` (PASS)
  - FK `fk_penugasan_silsilah`: to `penugasan_pembimbing(id)` `ON DELETE SET NULL ON UPDATE CASCADE` (PASS)
  - Composite FK `fk_penugasan_sk_composite`: `(sk_dokumen_id, skripsi_id)` references `sk_skripsi_dokumen(id, skripsi_id)` `ON DELETE RESTRICT ON UPDATE CASCADE` (PASS)
  - Unique `uk_skripsi_active_slot`: `(skripsi_id, active_slot)` (PASS)
  - Index `idx_penugasan_dosen_beban`: `(dosen_id, status_penugasan)` (PASS)
  - Index `idx_penugasan_lookup`: `(skripsi_id, tahap, status_penugasan)` (PASS)
  - Raw SQL CHECK 1: `chk_penugasan_tahap_sk_bifurcation` via `DB::statement` (PASS)
  - Raw SQL CHECK 2: `chk_penugasan_pembimbing_ke_domain` via `DB::statement` (PASS)

---

### 3.3. Tabel `penugasan_pembimbing_logs` (Migrasi 000003)
* **Kesesuaian Kolom & Constraints:**
  - `id`: PK BIGINT UNSIGNED (PASS)
  - `penugasan_pembimbing_id`: FK to `penugasan_pembimbing(id)` `ON DELETE RESTRICT ON UPDATE CASCADE` (PASS)
  - `user_id`: FK to `users(id)` `ON DELETE SET NULL ON UPDATE CASCADE` (PASS)
  - `aktor_nama`: `VARCHAR(150) NOT NULL` (PASS)
  - `aktor_role`: `VARCHAR(50) NOT NULL` (PASS)
  - `tindakan`: `ENUM('PENETAPAN_AWAL', 'PERGANTIAN_PEMBIMBING', 'PEMBATALAN_PENUGASAN', 'PENGAKTIFAN_KEMBALI')` (PASS)
  - `status_sebelum`: `VARCHAR(50) NULL`, `status_sesudah`: `VARCHAR(50) NOT NULL` (PASS)
  - `snapshot_dosen_lama_id`: `BIGINT UNSIGNED NULL`, `snapshot_dosen_baru_id`: `BIGINT UNSIGNED NULL` (PASS)
  - `catatan`: `TEXT NULL` (PASS)
  - `created_at`: `TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP` $\leftrightarrow$ `$table->timestamp('created_at')->useCurrent()` (PASS)
  - Index `idx_penugasan_log_history`: `(penugasan_pembimbing_id, created_at)` (PASS)

---

### 3.4. Tabel `bchat_rooms` (Migrasi 000004)
* **Kesesuaian Kolom & Constraints:**
  - `id`: PK BIGINT UNSIGNED (PASS)
  - `room_uuid`: `CHAR(36) NOT NULL UNIQUE` (PASS)
  - `penugasan_pembimbing_id`: `BIGINT UNSIGNED NOT NULL UNIQUE`, FK to `penugasan_pembimbing(id)` `ON DELETE RESTRICT ON UPDATE CASCADE` (PASS)
  - `tahap`: `ENUM('proposal', 'skripsi') NOT NULL` (PASS)
  - `pembimbing_ke`: `TINYINT UNSIGNED NOT NULL DEFAULT 1` (PASS)
  - `current_sequence`: `BIGINT UNSIGNED NOT NULL DEFAULT 0` (PASS)
  - `status`: `ENUM('PENDING_OFFICIAL_SK', 'PENDING_WELCOME_ACK', 'ACTIVE', 'PENDING_REKEYING', 'SUSPENDED', 'ARCHIVED_READONLY', 'TERMINATED') DEFAULT 'PENDING_WELCOME_ACK'` (PASS)
  - `current_epoch`: `BIGINT UNSIGNED NOT NULL DEFAULT 0` (uint64) (PASS)
  - `group_id_hash`: `CHAR(64) NULL` (PASS)
  - Index `idx_room_lifecycle`: `(status, tahap)` (PASS)

---

### 3.5. Tabel `bchat_room_members` (Migrasi 000005)
* **Kesesuaian Kolom & Constraints:**
  - `id`: PK BIGINT UNSIGNED (PASS)
  - `room_id`: FK to `bchat_rooms(id)` `ON DELETE CASCADE ON UPDATE CASCADE` (PASS)
  - `user_id`: FK to `users(id)` `ON DELETE CASCADE ON UPDATE CASCADE` (PASS)
  - `role`: `ENUM('mahasiswa', 'dosen_pembimbing') NOT NULL` (PASS)
  - `is_initiator`: `BOOLEAN NOT NULL DEFAULT FALSE` (PASS)
  - `is_active`: `BOOLEAN NOT NULL DEFAULT FALSE` (PASS)
  - `joined_at_epoch`: `BIGINT UNSIGNED NOT NULL DEFAULT 0` (PASS)
  - `welcome_ack_at`: `TIMESTAMP NULL` (PASS)
  - Unique `uk_room_member`: `(room_id, user_id)` (PASS)
  - Index `idx_member_auth`: `(user_id, is_active)` (PASS)

---

### 3.6. Tabel `bchat_key_packages` (Migrasi 000006)
* **Kesesuaian Kolom & Constraints:**
  - `id`: PK BIGINT UNSIGNED (PASS)
  - `user_id`: FK to `users(id)` `ON DELETE CASCADE ON UPDATE CASCADE` (PASS)
  - `key_package_hash`: `CHAR(64) NOT NULL UNIQUE` (PASS)
  - `key_package_data`: `LONGBLOB NOT NULL` $\leftrightarrow$ `$table->longBinary('key_package_data')` (PASS)
  - `is_consumed`: `BOOLEAN NOT NULL DEFAULT FALSE` (PASS)
  - `consumed_at`: `TIMESTAMP NULL` (PASS)
  - Index `idx_keypkg_available`: `(user_id, is_consumed)` (PASS)

---

### 3.7. Tabel `bchat_welcome_challenges` (Migrasi 000007)
* **Kesesuaian Kolom & Constraints:**
  - `id`: PK BIGINT UNSIGNED (PASS)
  - `welcome_operation_uuid`: `CHAR(36) NOT NULL UNIQUE` (PASS)
  - `room_id`: FK to `bchat_rooms(id)` `ON DELETE RESTRICT ON UPDATE CASCADE` (PASS)
  - `recipient_user_id`: FK to `users(id)` `ON DELETE RESTRICT ON UPDATE CASCADE` (PASS)
  - `welcome_message`: `LONGBLOB NOT NULL` $\leftrightarrow$ `$table->longBinary('welcome_message')` (PASS)
  - `group_id_hash`: `CHAR(64) NOT NULL` (PASS)
  - `epoch`: `BIGINT UNSIGNED NOT NULL` (PASS)
  - `operation_status`: `ENUM('ACTIVE', 'CONSUMED', 'REVOKED_SUPERSEDED') DEFAULT 'ACTIVE'` (PASS)
  - `challenge_uuid`: `CHAR(36) NOT NULL UNIQUE` (PASS)
  - `challenge_nonce`: `CHAR(64) NOT NULL UNIQUE` (PASS)
  - `challenge_generation`: `INT UNSIGNED NOT NULL DEFAULT 1` (PASS)
  - `challenge_status`: `ENUM('ISSUED', 'CONSUMED', 'EXPIRED') DEFAULT 'ISSUED'` (PASS)
  - `expires_at`: `TIMESTAMP NOT NULL` (PASS)
  - `consumed_at`: `TIMESTAMP NULL` (PASS)
  - `ack_payload_hash`: `CHAR(64) NULL` (PASS)
  - `ack_signature`: `TEXT NULL` (PASS)
  - `ip_address`: `VARCHAR(45) NULL` (PASS)
  - `user_agent`: `TEXT NULL` (PASS)
  - `active_operation_slot`: `VARCHAR(64) GENERATED ALWAYS AS (CASE WHEN operation_status = 'ACTIVE' THEN CONCAT(room_id, '_', recipient_user_id) ELSE NULL END) VIRTUAL` $\leftrightarrow$ `$table->string('active_operation_slot', 64)->virtualAs(...)` (PASS)
  - Unique `uk_challenge_active_slot`: `(active_operation_slot)` (PASS)
  - Index `idx_challenge_lookup`: `(room_id, recipient_user_id, operation_status)` (PASS)
  - Index `idx_challenge_verify`: `(challenge_uuid, challenge_status, expires_at)` (PASS)

---

### 3.8. Tabel `bchat_messages` (Migrasi 000008)
* **Kesesuaian Kolom & Constraints:**
  - `id`: PK BIGINT UNSIGNED (PASS)
  - `room_id`: FK to `bchat_rooms(id)` `ON DELETE CASCADE ON UPDATE CASCADE` (PASS)
  - `server_sequence`: `BIGINT UNSIGNED NOT NULL` (PASS)
  - `message_uuid`: `CHAR(36) NOT NULL` (PASS)
  - `sender_id`: FK to `users(id)` `ON DELETE CASCADE ON UPDATE CASCADE` (PASS)
  - `epoch`: `BIGINT UNSIGNED NOT NULL` (uint64) (PASS)
  - `generation`: `INT UNSIGNED NOT NULL` (PASS)
  - `content_type`: `TINYINT UNSIGNED NOT NULL DEFAULT 1` (PASS)
  - `ciphertext_payload`: `LONGBLOB NOT NULL` $\leftrightarrow$ `$table->longBinary('ciphertext_payload')` (PASS)
  - `payload_hash_sha256`: `CHAR(64) NOT NULL` (PASS)
  - `client_sent_at`: `TIMESTAMP NOT NULL` (PASS)
  - Unique `uk_room_sequence`: `(room_id, server_sequence)` (PASS)
  - Unique `uk_msg_idempotency`: `(room_id, sender_id, message_uuid)` (PASS)
  - Unique `uk_msg_id_room`: `(id, room_id)` (Target Composite FK bagi lampiran) (PASS)
  - Index `idx_msg_pagination`: `(room_id, server_sequence)` (PASS)

---

### 3.9. Tabel `bchat_message_receipts` (Migrasi 000009)
* **Kesesuaian Kolom & Constraints:**
  - `id`: PK BIGINT UNSIGNED (PASS)
  - `message_id`: FK to `bchat_messages(id)` `ON DELETE CASCADE ON UPDATE CASCADE` (PASS)
  - `user_id`: FK to `users(id)` `ON DELETE CASCADE ON UPDATE CASCADE` (PASS)
  - `status`: `ENUM('DELIVERED', 'READ') NOT NULL` (PASS)
  - `status_at`: `TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP` $\leftrightarrow$ `$table->timestamp('status_at')->useCurrent()` (PASS)
  - Unique `uk_msg_user_status`: `(message_id, user_id, status)` (PASS)
  - Index `idx_receipt_lookup`: `(user_id, status)` (PASS)

---

### 3.10. Tabel `bchat_upload_sessions` (Migrasi 000010)
* **Kesesuaian Kolom & Constraints:**
  - `id`: PK BIGINT UNSIGNED (PASS)
  - `session_uuid`: `CHAR(36) NOT NULL UNIQUE` (PASS)
  - `room_id`: FK to `bchat_rooms(id)` `ON DELETE CASCADE ON UPDATE CASCADE` (PASS)
  - `user_id`: FK to `users(id)` `ON DELETE CASCADE ON UPDATE CASCADE` (PASS)
  - `total_bytes`: `BIGINT UNSIGNED NOT NULL` (PASS)
  - `total_chunks`: `INT UNSIGNED NOT NULL` (PASS)
  - `status`: `ENUM('UPLOADING', 'COMPLETED', 'FAILED_ABANDONED', 'EXPIRED') DEFAULT 'UPLOADING'` (PASS)
  - `expires_at`: `TIMESTAMP NOT NULL` (PASS)
  - Unique `uk_upsess_id_room`: `(id, room_id)` (Target Composite FK bagi lampiran) (PASS)
  - Index `idx_upsess_cleanup`: `(status, expires_at)` (PASS)

---

### 3.11. Tabel `bchat_attachments` (Migrasi 000011)
* **Kesesuaian Kolom & Constraints:**
  - `id`: PK BIGINT UNSIGNED (PASS)
  - `attachment_uuid`: `CHAR(36) NOT NULL UNIQUE` (PASS)
  - `upload_session_id`: `BIGINT UNSIGNED NOT NULL` (PASS)
  - `room_id`: `BIGINT UNSIGNED NOT NULL` (PASS)
  - `message_id`: `BIGINT UNSIGNED NULL` (PASS)
  - `plaintext_bytes`: `BIGINT UNSIGNED NOT NULL` (PASS)
  - `ciphertext_bytes`: `BIGINT UNSIGNED NOT NULL` (PASS)
  - `total_chunks`: `INT UNSIGNED NOT NULL` (PASS)
  - `file_hash_sha256`: `CHAR(64) NOT NULL` (PASS)
  - `status`: `ENUM('PENDING_MESSAGE', 'ATTACHED', 'ORPHANED') DEFAULT 'PENDING_MESSAGE'` (PASS)
  - Composite FK 1 `fk_attach_session_composite`: `(upload_session_id, room_id)` references `bchat_upload_sessions(id, room_id)` `ON DELETE RESTRICT ON UPDATE CASCADE` (PASS)
  - Composite FK 2 `fk_attach_msg_composite`: `(message_id, room_id)` references `bchat_messages(id, room_id)` `ON DELETE CASCADE ON UPDATE CASCADE` (PASS)
  - Index `idx_attach_room`: `(room_id, status)` (PASS)

---

### 3.12. Tabel `bchat_attachment_chunks` (Migrasi 000012)
* **Kesesuaian Kolom & Constraints:**
  - `id`: PK BIGINT UNSIGNED (PASS)
  - `attachment_id`: FK to `bchat_attachments(id)` `ON DELETE CASCADE ON UPDATE CASCADE` (PASS)
  - `chunk_index`: `INT UNSIGNED NOT NULL` (zero-based) (PASS)
  - `payload_length`: `INT UNSIGNED NOT NULL` (Li + 28 Byte) (PASS)
  - `storage_path`: `VARCHAR(255) NOT NULL` (PASS)
  - `storage_hash_sha256`: `CHAR(64) NOT NULL` (PASS)
  - Unique `uk_attach_chunk_index`: `(attachment_id, chunk_index)` (PASS)

---

### 3.13. Tabel `bchat_crl_revocations` (Migrasi 000013)
* **Kesesuaian Kolom & Constraints:**
  - `id`: PK BIGINT UNSIGNED (PASS)
  - `user_id`: FK to `users(id)` `ON DELETE RESTRICT ON UPDATE CASCADE` (PASS)
  - `revoked_credential_uuid`: `CHAR(36) NOT NULL UNIQUE` (PASS)
  - `reason`: `VARCHAR(255) NOT NULL` (PASS)
  - `revoked_at`: `TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP` $\leftrightarrow$ `$table->timestamp('revoked_at')->useCurrent()` (PASS)
  - Index `idx_crl_user`: `(user_id, revoked_at)` (PASS)

---

## 4. Hasil Pemeriksaan Setiap Kategori Wajib

### A. Rekonsiliasi 13 Tabel
Setiap kolom, unsigned integer, nullability, default value, precision, primary key, unique index, plain index, foreign key, dan CHECK constraint telah diverifikasi bit-demi-bit terhadap dokumen induk `B-CHAT-13_Rancangan_DDL_Final.md`.  
**Status: PASS.**

### B. Generated Virtual Columns
1. **`terkini_slot` pada `sk_skripsi_dokumen`:**
   - DDL: `VARCHAR(20) GENERATED ALWAYS AS (CASE WHEN is_terkini = 1 THEN 'CURRENT' ELSE NULL END) VIRTUAL`
   - Migrasi 1: `$table->string('terkini_slot', 20)->virtualAs("CASE WHEN `is_terkini` = 1 THEN 'CURRENT' ELSE NULL END")->nullable()`
   - Unique Index: `uk_skripsi_dokumen_terkini (skripsi_id, terkini_slot)`
   - Status: **PASS.**

2. **`active_slot` pada `penugasan_pembimbing`:**
   - DDL: `VARCHAR(50) GENERATED ALWAYS AS (CASE WHEN status_penugasan = 'AKTIF' THEN CONCAT(tahap, '_', pembimbing_ke) ELSE NULL END) VIRTUAL`
   - Migrasi 2: `$table->string('active_slot', 50)->virtualAs("CASE WHEN `status_penugasan` = 'AKTIF' THEN CONCAT(`tahap`, '_', `pembimbing_ke`) ELSE NULL END")->nullable()`
   - Unique Index: `uk_skripsi_active_slot (skripsi_id, active_slot)`
   - Status: **PASS.**

3. **`active_operation_slot` pada `bchat_welcome_challenges`:**
   - DDL: `VARCHAR(64) GENERATED ALWAYS AS (CASE WHEN operation_status = 'ACTIVE' THEN CONCAT(room_id, '_', recipient_user_id) ELSE NULL END) VIRTUAL`
   - Migrasi 7: `$table->string('active_operation_slot', 64)->virtualAs("CASE WHEN `operation_status` = 'ACTIVE' THEN CONCAT(`room_id`, '_', `recipient_user_id`) ELSE NULL END")->nullable()`
   - Unique Index: `uk_challenge_active_slot (active_operation_slot)`
   - Status: **PASS.**

### C. Composite Foreign Keys
Empat relasi foreign key komposit diperiksa:
1. `(induk_dokumen_id, skripsi_id)` $\rightarrow$ `sk_skripsi_dokumen(id, skripsi_id)`: Target memiliki `uk_dokumen_id_skripsi`. (PASS)
2. `(sk_dokumen_id, skripsi_id)` $\rightarrow$ `sk_skripsi_dokumen(id, skripsi_id)`: Target memiliki `uk_dokumen_id_skripsi`. (PASS)
3. `(upload_session_id, room_id)` $\rightarrow$ `bchat_upload_sessions(id, room_id)`: Target memiliki `uk_upsess_id_room`. (PASS)
4. `(message_id, room_id)` $\rightarrow$ `bchat_messages(id, room_id)`: Target memiliki `uk_msg_id_room`. (PASS)  
Seluruh pasangan kolom memiliki urutan yang konsisten dan target index unik yang valid pada tabel induk.  
**Status: PASS.**

### D. Tabel Akademik Eksisting
Inspeksi langsung berkas migrasi eksisting menghasilkan verifikasi eksak:
- `users`: PK kolom `id` (`$table->id()`), tipe `BIGINT UNSIGNED`. (PASS)
- `mahasiswa`: PK kolom `id_mahasiswa` (`$table->id('id_mahasiswa')`), tipe `BIGINT UNSIGNED`. (PASS)
- `dosen`: PK kolom `id_dosen` (`$table->id('id_dosen')`), tipe `BIGINT UNSIGNED`. (PASS)
- `skripsi`: PK kolom `id_skripsi` (`$table->id('id_skripsi')`), tipe `BIGINT UNSIGNED`. (PASS)
- `pengajuan_dospem`: PK kolom `id` (`$table->id()`), tipe `BIGINT UNSIGNED`. (PASS)  
Seluruh foreign key menuju tabel-tabel ini dideklarasikan dengan tipe `unsignedBigInteger` dan menunjuk langsung ke nama kolom PK kustom masing-masing secara akurat.  
**Status: PASS.**

### E. Welcome Operation Lifecycle & Rotasi Challenge
Pemeriksaan pada `bchat_welcome_challenges` membuktikan:
- Konteks operasi bersifat tunggal per baris (`welcome_operation_uuid` UNIQUE).
- Tantangan generasi aktif dipisahkan dalam kolom mutasi terotasi (`challenge_uuid`, `challenge_nonce`, `challenge_generation`, `challenge_status`, `expires_at`).
- Slot aktif `active_operation_slot` bernilai `CONCAT(room_id, '_', recipient_user_id)` hanya selama `operation_status = 'ACTIVE'`, dan otomatis beralih menjadi `NULL` saat operasi mencapai `CONSUMED` atau `REVOKED_SUPERSEDED`.
- Hash canonical payload `ack_payload_hash` (CHAR 64) dan tanda tangan digital Ed25519 `ack_signature` (TEXT) tersimpan pada baris operasi yang sama untuk keperluan audit forensik.  
**Status: PASS.**

### F. CHECK Constraints & Raw SQL Strategy
Dua CHECK constraint pada `penugasan_pembimbing` ditegakkan via `DB::statement`:
1. `chk_penugasan_tahap_sk_bifurcation`: Memisahkan secara ketat proposal (`sk_dokumen_id IS NULL AND pembimbing_ke = 1`) dari skripsi (`sk_dokumen_id IS NOT NULL AND pembimbing_ke IN (1, 2)`).
2. `chk_penugasan_pembimbing_ke_domain`: Membatasi `pembimbing_ke IN (1, 2)`.  
Strategi Rollback: Metode `down()` menggunakan `Schema::dropIfExists('penugasan_pembimbing')`. Pada MySQL 8.0.30 InnoDB, operasi `DROP TABLE` secara atomik melenyapkan seluruh constraint yang menempel pada tabel tersebut tanpa menimbulkan resiko *orphan constraint*.  
**Status: PASS.**

### G. Up dan Down (Rollback Isolation)
- Urutan `up()` (000001 s.d. 000013) membentuk graf DAG linear tanpa dependensi melingkar.
- Urutan `down()` Laravel mengeksekusi secara terbalik (LIFO: 000013 s.d. 000001), sehingga tabel anak selalu dihapus sebelum tabel induk.
- Tidak ada panggilan `dropIfExists` pada tabel akademik eksisting (`users`, `mahasiswa`, `dosen`, `skripsi`, `pengajuan_dospem`). Rollback sepenuhnya terisolasi dan non-destruktif terhadap data universitas.  
**Status: PASS.**

### H. Audit Khusus Batas Database vs Aplikasi
Audit memetakan pemisahan tanggung jawab secara jernih:
- **Tanggung Jawab Database (Enforced by Engine):**
  - Integritas relasional dan penolakan cascading ilegal (`ON DELETE RESTRICT`).
  - Idempotensi pesan: `uk_msg_idempotency (room_id, sender_id, message_uuid)`.
  - Sekuens monotonik server per room: `uk_room_sequence (room_id, server_sequence)`.
  - Concurrency active slot per skripsi dan per welcome operation.
  - Isolasi lampiran berbasis room lewat Composite FK ganda.
- **Tanggung Jawab Aplikasi (Application Transaction Guard):**
  - Batas 10.000.000 byte per berkas (divalidasi oleh Request Validator & Chunk Assembler).
  - Batas 25.000.000 byte plaintext akumulasi per pesan (divalidasi atomik via `SELECT ... FOR UPDATE` saat manifest terbit).
  - Batas maksimal 5 lampiran per pesan (divalidasi saat manifest terbit).
  - Potongan chunk 1.048.576 byte dan framing 28 byte (divalidasi oleh upload controller).
  Semua kolom penyimpanan memiliki kapasitas presisi yang memadai (`BIGINT UNSIGNED`, `INT UNSIGNED`, `CHAR(64)`).  
**Status: PASS.**

---

## 5. Temuan Audit Khusus: Klarifikasi `active_operation_slot` vs `active_op_slot`

### 5.1. Analisis Perbandingan Nama Kolom

| Sumber Acuan | Nama Kolom yang Tercantum | Tipe Data & Panjang | Nama Unique Constraint |
| :--- | :--- | :--- | :--- |
| **Keputusan B-CHAT-13D** (Baris 83 & 101) | `active_operation_slot` | `VARCHAR(64)` | `uk_challenge_active_slot` |
| **Dokumen DDL Induk B-CHAT-13E** (Baris 308 & 326) | `active_operation_slot` | `VARCHAR(64)` | `uk_challenge_active_slot` |
| **Berkas Migrasi Fisik 000007** (Baris 61 & 68) | **`active_operation_slot`** | **`VARCHAR(64)`** | **`uk_challenge_active_slot`** |
| *Teks Laporan B-CHAT-15* (Bab 3.2 & Bab 4) | *`active_op_slot`* | *`VARCHAR(80)`* | *`uk_active_welcome_op`* |

### 5.2. Kesimpulan Temuan
1. Berkas migrasi fisik **`2026_11_07_000007_create_bchat_welcome_challenges_table.php`** terbukti **100% SESUAI DENGAN DDL INDUK (`B-CHAT-13E`) DAN KEPUTUSAN ARSITEKTUR (`B-CHAT-13D`)**.
2. Variasi nama `active_op_slot` dan `uk_active_welcome_op` murni merupakan **inkonsistensi tipografi editorial pada teks dokumen laporan B-CHAT-15**, dan **TIDAK PERNAH MASUK KE DALAM KODE BERKAS MIGRASI FISIK**.
3. Tidak ada tindakan perbaikan kode yang diperlukan karena kode migrasi yang ada sudah tepat dan sahih.

---

## 6. Daftar Ketidaksesuaian dan Dampak Teknis

Berdasarkan audit komparasi menyeluruh, ditemukan 2 catatan teknis berklasifikasi **INFO**:

| No | Komponen / Berkas | Catatan Temuan | Dampak Teknis | Status |
| :---: | :--- | :--- | :--- | :---: |
| 1 | `bchat_messages` (Migrasi 000008) | DDL induk menulis `INDEX idx_msg_pagination (room_id, server_sequence DESC)`, sedangkan migrasi mendefinisikan `$table->index(['room_id', 'server_sequence'], 'idx_msg_pagination')` (default ASC). | **Nol Dampak Negatif.** Mesin B-Tree MySQL 8.0.30 InnoDB mendukung *backward index scan* secara native dengan efisiensi setara untuk query pagination `ORDER BY server_sequence DESC`. | **INFO (PASS)** |
| 2 | Laporan B-CHAT-15 (Dokumentasi) | Teks ringkasan Bab 3.2 & Bab 4 pada dokumen laporan B-CHAT-15 mencantumkan `active_op_slot` dan `uk_sk_nomor_sk`. | **Nol Dampak pada Kode.** Berkas migrasi fisik 1 dan 7 telah menggunakan skema yang tepat sesuai DDL Induk (`active_operation_slot` dan `nomor_sk` non-unique). | **INFO (RESOLVED)** |

---

## 7. Status Item Terbuka (OPEN-10 s.d. OPEN-19)

Audit mengonfirmasi bahwa ke-13 berkas migrasi tidak mengambil asumsi yang melanggar batas *Open Decisions*:

- **OPEN-10 (Durable Storage Boundary ACK):** DDL migrasi hanya memverifikasi bukti kriptografis Ed25519 dan anti-replay nonce; tidak ada pemaksaan pembuktian IndexedDB peramban pada tingkat database server. (Status: **OPEN**)
- **OPEN-11 (Distribusi CEK Lampiran):** Skema `bchat_attachments` tidak menyimpan CEK, netral terhadap Model B (CEK via MLS payload). (Status: **OPEN**)
- **OPEN-15 & OPEN-16 (Template & Kedaluwarsa SK):** Kolom `tanggal_selesai` tetap nullable dan template PDF dikelola di layer aplikasi. (Status: **OPEN**)
- **OPEN-17 (SK Penggantian Pembimbing):** Skema `penugasan_pembimbing` mendukung model penetapan parsial per slot `pembimbing_ke`. (Status: **OPEN**)
- **OPEN-18 (Retensi Non-Destruktif):** Constraint `ON DELETE RESTRICT` diterapkan pada dokumen SK, penugasan, log, CRL, dan welcome challenges. (Status: **OPEN**)
- **OPEN-19 (MLS Epoch uint64):** Tipe kolom `epoch` dan `current_epoch` secara konsisten bertipe `BIGINT UNSIGNED` di seluruh tabel terkait. (Status: **OPEN**)

---

## 8. Keputusan Akhir dan Rekomendasi Gate

Berdasarkan hasil audit statis menyeluruh:
1. Seluruh 13 berkas Laravel Migration telah memenuhi standar perancangan basis data B-CHAT-13E tanpa kecacatan struktur atau foreign key mismatch.
2. Tidak ada circular foreign key, missing index pada composite target, atau ambiguitas tipe data eksisting.
3. Seluruh berkas lolos uji linting PHP 8.2.

Rekomendasi Keputusan Akhir:
$$\mathbf{PASS\ WITH\ OPEN\ ITEMS\ —\ STATIC\ REVIEW\ COMPLETE}$$

> [!IMPORTANT]
> **GATE PERINGATAN KESELAMATAN:**  
> Hasil evaluasi **PASS** ini adalah verifikasi statis atas berkas kode migrasi.  
> **DILARANG MENJALANKAN `php artisan migrate`** ke basis data aktif sampai diterbitkan mandat pengujian lingkungan terisolasi (*test database dry-run*) secara tertulis dan formal oleh Pemilik Proyek.

---
*Laporan audit statis ini disusun secara independen dan read-only, disahkan sebagai bukti verifikasi kepatuhan mandat B-CHAT-15A.*

