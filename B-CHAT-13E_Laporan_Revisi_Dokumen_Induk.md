# B-CHAT-13E — LAPORAN REVISI TERPADU DOKUMEN INDUK RANCANGAN DDL
## SISTEM INFORMASI MANAJEMEN SKRIPSI UNUHA

| Parameter | Keterangan |
| :--- | :--- |
| **Proyek** | Sistem Informasi Manajemen Skripsi UNUHA |
| **Fitur** | Bimbingan Online Chat Real-Time E2EE (B-CHAT) |
| **Tahap** | B-CHAT-13E — Laporan Hasil Revisi Terpadu Dokumen Induk Rancangan DDL |
| **Mode Operasi** | DOCUMENT REVISION ONLY — ZERO IMPLEMENTATION |
| **Dokumen Induk Direvisi**| [`B-CHAT-13_Rancangan_DDL_Final.md`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-13_Rancangan_DDL_Final.md) |
| **Dokumen Input Konsolidasi**| [`B-CHAT-13A`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-13A_Koreksi_Terarah_Rancangan_DDL.md), [`B-CHAT-13B`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-13B_Finalisasi_Invarian_DDL.md), [`B-CHAT-13C`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-13C_Audit_Konsistensi_Final_DDL_dan_Welcome_ACK.md), [`B-CHAT-13D`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-13D_Finalisasi_Model_Welcome_Operation_Challenge.md) |
| **Target Engine** | MySQL 8.0.30+ / MariaDB 10.3+ (InnoDB, UTF8MB4 Unicode CI) |
| **Waktu Pelaksanaan** | 2026-11-06 |
| **Auditor** | Tim Arsitektur & Kriptografi E2EE Antigravity |

---

## 1. Ringkasan Eksekutif Hasil Revisi Terpadu

Mandat **B-CHAT-13E** telah berhasil diselesaikan secara sempurna. Dokumen induk [`B-CHAT-13_Rancangan_DDL_Final.md`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-13_Rancangan_DDL_Final.md) telah diperbarui secara menyeluruh dan terpadu, menyatukan seluruh ketetapan, perbaikan, bukti kriptografis, dan invarian dari dokumen koreksi B-CHAT-13A, 13B, 13C, dan 13D ke dalam **satu naskah referensi tunggal yang mandiri (*self-contained canonical specification*)**.

Pembaca dan perekayasa perangkat lunak tidak lagi diharuskan menggabungkan atau menyilangkan catatan perbaikan dari berbagai laporan terpisah.

---

## 2. Bagian Dokumen Induk yang Direvisi

Revisi terpadu mencakup perbaikan dan restrukturisasi mendalam pada 9 bagian utama dokumen induk:

1. **Metadata & Header Naskah:**  
   Penyelarasan judul menjadi *Rancangan DDL Final Sistem Bimbingan Online E2EE (Terpadu)* dan penambahan referensi konsolidasi B-CHAT-13A s.d. B-CHAT-13D.
2. **Subbab 1.1 (Rekonsiliasi 13 Tabel Konseptual):**  
   Pembaruan deskripsi ringkas 13 tabel konseptual mencakup pemindahan paket Welcome biner ke tabel challenge, proteksi arsip hukum `ON DELETE RESTRICT`, dan penegasan slot operasi aktif.
3. **Subbab 2 (DDL Tiga Tabel Akademik dan Dokumen):**  
   * Subbab 2.1 (`sk_skripsi_dokumen`): Perubahan foreign key `skripsi_id` dan `mahasiswa_id` menjadi `ON DELETE RESTRICT`, penyesuaian kuota fisik maksimum 10.000.000 byte desimal, dan penyimpanan path relatif storage privat.
   * Subbab 2.2 (`penugasan_pembimbing`): Pemeliharaan CHECK bifurkasi ketat Proposal vs Skripsi, composite FK anti-tukar skripsi, dan virtual slot `active_slot`.
   * Subbab 2.3 (`penugasan_pembimbing_logs`): Perubahan foreign key `penugasan_pembimbing_id` menjadi `ON DELETE RESTRICT` dan penegasan sifat *append-only*.
4. **Subbab 3 (DDL Sepuluh Tabel B-CHAT):**  
   * Subbab 3.1 (`bchat_rooms`): Perubahan `current_epoch` menjadi `BIGINT UNSIGNED`, penghapusan kolom skalar `welcome_message` dan `welcome_epoch` (dipindahkan ke challenge).
   * Subbab 3.2 (`bchat_room_members`): Penegasan model pairwise dan tipe data `joined_at_epoch BIGINT UNSIGNED`.
   * Subbab 3.4 (`bchat_welcome_challenges`): Transformasi total mengadopsi model final B-CHAT-13D (Single-Row per Operation dengan Rotasi In-Place Terkelola, penambahan `challenge_uuid`, `challenge_generation`, pemisahan `operation_status` vs `challenge_status`, virtual column `active_operation_slot`, dan proteksi `ON DELETE RESTRICT` pada room dan penerima).
   * Subbab 3.5 (`bchat_messages`): Perubahan `epoch` ke `BIGINT UNSIGNED`, penghapusan global unique `message_uuid`, penegakan `CONSTRAINT uk_msg_idempotency UNIQUE (room_id, sender_id, message_uuid)`, dan penambahan `CONSTRAINT uk_msg_id_room UNIQUE (id, room_id)`.
   * Subbab 3.7 (`bchat_upload_sessions`): Perubahan nama UUID publik menjadi `session_uuid CHAR(36) NOT NULL UNIQUE` (menghilangkan ambiguitas penamaan terhadap FK), penambahan `uk_upsess_id_room UNIQUE (id, room_id)`, dan penambahan kolom `expires_at TIMESTAMP`.
   * Subbab 3.8 (`bchat_attachments`): Penegakan Composite FK ganda `fk_attach_session_composite` dan `fk_attach_msg_composite`, serta pemisahan kolom ukuran `plaintext_bytes` dan `ciphertext_bytes`.
   * Subbab 3.9 (`bchat_attachment_chunks`): Penegasan kolom `storage_path` sebagai relative storage key terkelola Laravel.
   * Subbab 3.10 (`bchat_crl_revocations`): Perubahan foreign key `user_id` menjadi `ON DELETE RESTRICT`.
5. **Subbab 4 (Parameter Kriptografis EXP-02 dan Kontrak Welcome ACK):**  
   * Pemulihan mutlak Tabel Layout AAD Kanonikal 83-Byte EXP-02 baku ($4 + 2 + 1 + 16 + 16 + 16 + 16 + 4 + 4 + 4 = 83\text{ byte}$).
   * Penegasan batas kuota resmi: 10.000.000 byte per berkas (10 MB desimal), 25.000.000 byte gabungan per pesan, maksimal 5 berkas, chunk 1.048.576 byte (1 MiB biner) dengan overhead framing 28 byte.
   * Penambahan Tabel Struktur Canonical Welcome ACK 158-Byte baku ($26 + 16 + 8 + 16 + 16 + 4 + 32 + 8 + 32 = 158\text{ byte}$).
   * Tata kelola crash-safety nonce, larangan keras nonce-reuse, dan pengakuan jujur batas memori peramban `fill(0)`.
6. **Subbab 5 (Dokumentasi Transaksi Atomik dan Mitigasi Balapan):**  
   * Transaksi 1: Verifikasi SK fisik dan penetapan 2 pembimbing atomik sesuai lembar SK Kaprodi & Dekan (dosen kembar ditolak).
   * Transaksi 2: Pengalihan endpoint mutatif menjadi **`POST /api/bchat/rooms/{roomUuid}/welcome-challenge`**, evaluasi expiry, dan rotasi in-place CSPRNG.
   * Transaksi 3: Verifikasi Welcome ACK 158-byte via Ed25519, idempotensi 7 kasus, konsumsi challenge, dan aktivasi room atomik.
   * Subbab 5.4: Matriks mitigasi 4 skenario balapan (Race Condition Matrix) berbasis row lock `FOR UPDATE`.
   * Subbab 5.5: State Machine terpadu Welcome Operation dan Challenge (Mermaid).
   * Subbab 5.8: Validasi transaksional manifest lampiran (kuota 25 MB dan batas 5 berkas).
   * Subbab 5.9: Pengiriman pesan idempotensi sejati dan eliminasi klaim gapless sequence.
7. **Subbab 6 (Urutan Dependensi Migrasi 13 Tabel DAG):**  
   Penyelarasan diagram alir DAG migrasi dengan composite constraint baru.
8. **Subbab 7 (Matriks Rekonsiliasi OPEN Items):**  
   Pembaruan matriks status OPEN-10 sampai OPEN-19 dengan batasan desain dan perilaku non-destruktif.
9. **Subbab 8 (Evaluasi Kesiapan Akhir):**  
   Penetapan rekomendasi status konsolidasi final.

---

## 3. Seluruh Koreksi Khusus yang Berhasil Diintegrasikan

| Butir Koreksi Khusus | Sumber Keputusan | Deskripsi Integrasi Teknis pada Dokumen Induk |
| :--- | :---: | :--- |
| **Welcome ACK 158 Byte** | B-CHAT-13C, 13D | Disahkan 9 komponen kanonikal: DomSep (26B `"UNUHA-BCHAT-WELCOME-ACK-V1"`) + RoomUUID (16B) + RecipientUserID (8B uint64be) + OpUUID (16B) + ChUUID (16B) + Gen (4B uint32be) + GroupIDHash (32B biner SHA-256) + Epoch (8B uint64be) + Nonce (32B biner). Total tepat **158 byte**. Dituangkan pada Subbab 4.2 dan divalidasi pada Subbab 5.3. |
| **Model Welcome Operation Baris Tunggal** | B-CHAT-13D | Disahkan model satu baris `bchat_welcome_challenges` per operasi Welcome. Rotasi challenge kedaluwarsa dilakukan *in-place* menaikkan `challenge_generation`. Operasi aktif dibatasi tepat 1 per room & penerima via Generated Virtual Column `active_operation_slot` dan `uk_challenge_active_slot`. Dituangkan pada Subbab 3.4. |
| **Endpoint Mutatif POST & Idempotensi** | B-CHAT-13D, 13E | Operasi penerbitan dan rotasi challenge ditetapkan menggunakan endpoint mutatif **`POST /welcome-challenge`** (bukan GET). Request ulang saat challenge aktif mengembalikan respons identik tanpa rotasi (idempotent). Dituangkan pada Subbab 5.2. |
| **Kebijakan Retensi Non-Destruktif** | B-CHAT-13A, 13B, 13E | Klausa `ON DELETE CASCADE` pada tabel `sk_skripsi_dokumen`, `penugasan_pembimbing_logs`, `bchat_crl_revocations`, dan `bchat_welcome_challenges` diubah menjadi **`ON DELETE RESTRICT`**. Menjamin bukti persetujuan ACK, arsip SK fisik, riwayat penugasan, dan rekaman CRL tidak terhapus otomatis akibat mutasi entitas induk. Dituangkan pada Subbab 2.1, 2.3, 3.4, 3.10, dan 7. |
| **Format AAD 83-Byte EXP-02** | B-CHAT-13A, 13B | Struktur AAD dipulihkan 100% ke 10 komponen kanonikal 83-byte EXP-02 (`UNH1` + Ver + Domain + 4x UUID biner + ChunkIdx + TotalChunks + Li). Dituangkan pada Subbab 4.1. |
| **Presisi Batas Kuota Desimal** | B-CHAT-13B | Batas lampiran ditetapkan: 10.000.000 byte per berkas (10 MB desimal), 25.000.000 byte gabungan (25 MB), maksimal 5 berkas, chunk plaintext 1.048.576 byte, framing overhead 28 byte ($L_i + 28\text{ byte}$). Dituangkan pada Subbab 2.1, 4.1, dan 5.8. |
| **Idempotensi Pesan & Monotonik Sekuens** | B-CHAT-13A, 13B | Constraint idempotensi diubah menjadi `CONSTRAINT uk_msg_idempotency UNIQUE (room_id, sender_id, message_uuid)`. Doktrin gapless dihapus; sekuens server monotonik naik dan unik per room, di mana celah akibat rollback ditoleransi. Dituangkan pada Subbab 3.5 dan 5.9. |
| **Resolusi Naming Session UUID** | B-CHAT-13C | Kolom UUID publik pada `bchat_upload_sessions` dinamai **`session_uuid CHAR(36) NOT NULL UNIQUE`**, membedakannya secara tegas dari foreign key `upload_session_id BIGINT UNSIGNED` pada `bchat_attachments`. Dituangkan pada Subbab 3.7 dan 3.8. |

---

## 4. Daftar Keputusan Terbuka (OPEN Items) yang Dipertahankan

Dokumen induk mempertahankan seluruh item keputusan terbuka secara transparan tanpa mengaburkan batasan ilmiah:

1. **OPEN-10 (Library MLS Produksi & Batas Bukti Durable Storage IndexedDB):**  
   Server memverifikasi tanda tangan digital Ed25519 dan nonce anti-replay, namun tidak dapat membuktikan secara fisik bahwa media penyimpanan IndexedDB di peramban klien berhasil menyimpan state MLS secara persisten (*client storage boundary*).
2. **OPEN-11 (Distribusi CEK Lampiran OD-17 B vs A):**  
   Skema DDL netral terhadap kedua pendekatan; tabel `bchat_attachments` tidak menyimpan CEK di basis data. Pendekatan B didukung penuh tanpa modifikasi tabel.
3. **OPEN-15 (Format Template Dokumen SK Skripsi):**  
   Format berkas SK generik dikelola sebagai aset statis aplikasi Laravel di luar skema database.
4. **OPEN-16 (Masa Berlaku Kedaluwarsa SK Skripsi):**  
   Kolom `penugasan_pembimbing.tanggal_selesai` dibiarkan nullable (`DATE NULL`). Otomatisasi masa kedaluwarsa menunggu aturan akademik resmi.
5. **OPEN-17 (Bentuk SK Penggantian Pembimbing):**  
   Model dekomposisi per `pembimbing_ke` mendukung SK parsial maupun SK utuh baru berkat isolasi slot `active_slot`.
6. **OPEN-18 (Kebijakan Retensi & Pemusnahan Berkas Digital):**  
   DDL menetapkan perilaku non-destruktif (`ON DELETE RESTRICT`) pada seluruh tabel arsip legal, log audit, CRL, dan tantangan Welcome. Pemusnahan berkas otomatis ditiadakan sampai terbit SK Rektor UNUHA.
7. **OPEN-19 (Representasi Integer Epoch Library MLS):**  
   DDL menetapkan tipe data `BIGINT UNSIGNED` (uint64 RFC 9420 Section 6). Kesesuaian representasi integer pada library klien (WASM/JavaScript) tetap menjadi materi verifikasi implementasi berikutnya.

---

## 5. Pemeriksaan Konsistensi SQL dan Dependensi

Pemeriksaan konsistensi terhadap skrip DDL lengkap pada dokumen induk mengonfirmasi:
1. **Total 13 Tabel Konseptual:**  
   Tidak ada penambahan tabel ke-14. Seluruh kebutuhan operasional dan kriptografi terakomodasi sempurna di dalam 13 tabel.
2. **Kesesuaian Tipe Data PK/FK Eksisting:**  
   Seluruh foreign key yang merujuk tabel master eksisting (`users.id`, `mahasiswa.id_mahasiswa`, `dosen.id_dosen`, `skripsi.id_skripsi`, `pengajuan_dospem.id`) menggunakan tipe data `BIGINT UNSIGNED` yang identik dengan signedness dan panjang kolom induk.
3. **Pencegahan Ketergantungan Melingkar (Zero Circular FK):**  
   Graf dependensi migrasi basis data (Subbab 6 Dokumen Induk) terbukti membentuk *Directed Acyclic Graph* (DAG) murni. Urutan eksekusi migrasi:
   $$\text{Eksisting} \rightarrow T_1 \rightarrow T_2 \rightarrow T_3 \rightarrow T_4 \rightarrow T_5 \rightarrow T_6 \rightarrow T_7 \rightarrow T_8 \rightarrow T_9 \rightarrow T_{10} \rightarrow T_{11} \rightarrow T_{12} \rightarrow T_{13}$$
   dapat dieksekusi linear dari awal hingga akhir tanpa kesalahan constraint referensial.

---

## 6. Pemeriksaan Status Git dan Kepatuhan Zero Mutation

Pemeriksaan status Git secara *read-only* telah dieksekusi:

```text
PS D:\Aplikasi\laragon\www\sistem_manajamen_skripsi> git status --short
 M B-CHAT-13_Rancangan_DDL_Final.md
?? B-CHAT-13E_Laporan_Revisi_Dokumen_Induk.md
```

### Pernyataan Kepatuhan Zero Mutation:
* **Nol Modifikasi Kode Aplikasi:** Tidak ada berkas pada direktori `manajemen_skripsi/` atau `frontend_manajemen_skripsi/` yang ditambah, diubah, atau dihapus.
* **Nol Migrasi Basis Data:** Tidak ada perintah `php artisan migrate`, pembuatan berkas migrasi fisik Laravel baru, atau eksekusi query DDL/DML ke database MySQL/MariaDB aktif.
* **Nol Perubahan Dependensi & Konfigurasi:** Berkas Composer (`composer.json`, `composer.lock`), NPM (`package.json`), dan konfigurasi lingkungan (`.env`) tetap perawan tanpa modifikasi.
* **Nol Operasi Git:** Tidak ada aksi commit, staging, branch, push, checkout, atau reset yang dijalankan ke repositori Git.
* **Kepatuhan Berkas:** Hanya berkas target [`B-CHAT-13_Rancangan_DDL_Final.md`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-13_Rancangan_DDL_Final.md) yang direvisi dan berkas laporan [`B-CHAT-13E_Laporan_Revisi_Dokumen_Induk.md`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-13E_Laporan_Revisi_Dokumen_Induk.md) yang dibuat. Dokumen historis B-CHAT-13A sampai 13D dibiarkan utuh tanpa perubahan.

---

## 7. Status Kesiapan Akhir

Sesuai kriteria penyelesaian mandat B-CHAT-13E:

$$\mathbf{PASS\ WITH\ OPEN\ ITEMS\ —\ MASTER\ DDL\ DOCUMENT\ CONSOLIDATED}$$

### Penegasan Akhir dan Penutupan Mandat:
1. Naskah induk [`B-CHAT-13_Rancangan_DDL_Final.md`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-13_Rancangan_DDL_Final.md) kini telah resmi **terkonsolidasi penuh, terbebas dari inkonsistensi, dan menjadi satu-satunya referensi desain DDL final B-CHAT**.
2. **Mandat B-CHAT-13E resmi berakhir.**
3. Status ini adalah pengesahan atas spesifikasi perancangan dokumen DDL dan **BUKAN IZIN MEMBUAT MIGRATION LARAVEL**, bukan izin memodifikasi kode sumber aplikasi, dan bukan klaim kesiapan produksi mutlak. Segala tindakan implementasi fisik wajib menunggu mandat, instruksi, dan persetujuan formal terpisah dari Pemilik Proyek.

---
*Laporan revisi terpadu ini disahkan secara resmi oleh Tim Arsitektur & Kriptografi E2EE Antigravity Sistem Informasi Manajemen Skripsi UNUHA.*

