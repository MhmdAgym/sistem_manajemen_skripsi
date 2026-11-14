# B-CHAT-06D — MANDAT EKSEKUSI TERBATAS GERBANG 2
## MANDAT DAN PEDOMAN OPERASIONAL PENGUJIAN PROTOTIPE KOMPARATIF E2EE UNUHA
### (REVISI TEKNIS B-CHAT-06D.2 — PENEGASAN NILAI KUOTA LAMPIRAN DALAM BYTE)

**Status Dokumen:** RANCANGAN MANDAT FORMAL (REVISI B-CHAT-06D.2) — MENUNGGU PENINJAUAN PEMILIK PROYEK  
**Status Operasi Saat Ini:** STRICTLY READ-ONLY — PLAN ONLY — ZERO EXECUTION  
**Proyek:** Sistem Informasi Manajemen Skripsi UNUHA  
**Fitur:** Bimbingan Online Chat Real-Time E2EE  
**Tahap Proyek:** Persiapan Gerbang 2 (Koreksi Terbatas Mandat Eksekusi B-CHAT-06D.2)  
**Dokumen Acuan:**  
1. Dokumen Perencanaan Induk B-CHAT-06C (Rencana Prototipe Komparatif dan Validasi Teknis)  
2. [B-CHAT-06C.1_Laporan_Koreksi_Terbatas_Gerbang_1.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-06C.1_Laporan_Koreksi_Terbatas_Gerbang_1.md)  
3. [B-CHAT-06C.1.1_Koreksi_Final_Terbatas_Gerbang_1.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-06C.1.1_Koreksi_Final_Terbatas_Gerbang_1.md)  
4. [B-CHAT-06C.1.2_Catatan_Final_Perencanaan_Gerbang_1.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-06C.1.2_Catatan_Final_Perencanaan_Gerbang_1.md)  
**Dokumen Fondasi:** [B-CHAT-01](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-01_Spesifikasi_Kebutuhan_Fitur_Bimbingan_Online.md), [B-CHAT-04](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-04_Rancangan_Teknis.md), [B-CHAT-05](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-05_Spesifikasi_Final_E2EE.md), [B-CHAT-05A](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-05A_Rekonsiliasi_Temuan_Audit.md), dan Keputusan Pemilik Proyek tanggal 5 November 2026.  
**Tanggal Penyusunan / Pembaruan:** 5 November 2026  

---

## 1. LATAR BELAKANG DAN DASAR PENYUSUNAN MANDAT

Pada tanggal 5 November 2026, Pemilik Proyek telah menyetujui seluruh paket perencanaan **Gerbang 1 (Persetujuan Rencana Prototipe Komparatif dan Validasi Teknis)** yang meliputi:
1. Dokumen perencanaan induk **B-CHAT-06C**.
2. Dokumen laporan koreksi teknis **B-CHAT-06C.1**.
3. Dokumen laporan koreksi final terbatas **B-CHAT-06C.1.1**.
4. Dokumen adendum catatan final perencanaan **B-CHAT-06C.1.2** (beserta penegasan matematis rumus piecewise modulo chunk terakhir dan referensi konseptual timestamp idle).

Persetujuan terhadap perencanaan Gerbang 1 menetapkan ruang lingkup, metodologi, dan tata kelola pengujian, namun **secara mutlak tidak memberikan izin otomatis untuk menjalankan pengujian atau memodifikasi lingkungan kerja**.

Pelaksanaan pengujian prototipe laboratorium membutuhkan penerbitan mandat terpisah yang mengikat, terukur, dan memiliki batasan tegas. Dokumen **B-CHAT-06D (Revisi B-CHAT-06D.2)** ini disusun khusus sebagai instrumen formal **Mandat Eksekusi Terbatas Gerbang 2** untuk diajukan kepada Pemilik Proyek dengan penegasan eksplisit terhadap nilai kuota lampiran dalam satuan byte.

> [!IMPORTANT]
> **PRINSIP PEMISAHAN GERBANG OPERASIONAL:**  
> Penyusunan dokumen revisi ini tidak mengubah status operasi sistem. Agen, asisten AI, maupun tim pengembang tetap berada pada mode operasional **STRICTLY READ-ONLY — PLAN ONLY — ZERO EXECUTION** sampai dokumen mandat ini disetujui secara tertulis oleh Pemilik Proyek. Tidak ada direktori prototipe yang dibuat dan tidak ada eksperimen yang dijalankan sebelum pengesahan Gerbang 2 diterbitkan.

---

## 2. RUANG LINGKUP DAN BATASAN MANDAT EKSEKUSI TERBATAS

Mandat Eksekusi Terbatas Gerbang 2 mendefinisikan batasan wewenang teknis dalam mengeksekusi 5 (lima) eksperimen pembuktian konsep (*proof of concept* / PoC) terisolasi:

```
+-------------------------------------------------------------------------------------------------------------+
            KORIDOR PENGUJIAN TERISOLASI GERBANG 2 (STRICT NON-PRODUCTION SANDBOX)                            
+-------------------------------------------------------------------------------------------------------------+
|                                                                                                             |
|   [Lingkungan Eksekusi Sandbox Terisolasi]            [Sistem Operasional / Produksi Utama]                |
|   - Direktori: scratch/bchat_prototype/                - backend: manajemen_skripsi/        (TIDAK TERSENTUH) |
|   - Node Engine: Sandbox package.json lokal            - frontend: frontend_manajemen_...   (TIDAK TERSENTUH) |
|   - Basis Data: MySQL 8 Uji Terpisah (Konkurensi) /    - Database MySQL Produksi            (DILARANG KERAS)  |
|                 SQLite (Hanya Fungsi Dasar)            - Data Riil Mahasiswa / Dosen UNUHA  (DILARANG KERAS)  |
|   - Data Uji: 100% Data Sintetis & Kunci Ephemeral                                                          |
|                                                                                                             |
+-------------------------------------------------------------------------------------------------------------+
```

### 2.1. Batasan Ruang Lingkup Pengujian
1. **Pengujian Kelayakan Arsitektur, Bukan Kesiapan Rilis:** Eksperimen Gerbang 2 bertujuan semata-mata untuk mengumpulkan bukti empiris, memverifikasi kompatibilitas peramban dan bundler, serta mengukur konsumsi sumber daya komputasi. Hasil Gerbang 2 tidak menghasilkan fitur siap produksi.
2. **Kemandirian Terhadap Repositori Utama:** Seluruh berkas kode sumber pengujian, konfigurasi pustaka, dan artefak hasil pengukuran wajib berada di dalam ruang kerja terisolasi.
3. **Larangan Modifikasi Kode Aplikasi:** Dilarang keras menambahkan, mengubah, atau menghapus kode pada direktori aplikasi utama (`manajemen_skripsi/` dan `frontend_manajemen_skripsi/`).
4. **Larangan Operasi Basis Data Utama:** Dilarang keras menjalankan migrasi database, membuat tabel, atau mengubah skema relasional pada basis data aplikasi utama.
5. **Larangan Penggunaan Data Riil:** Pengujian dilarang keras menggunakan data mahasiswa, dosen, judul skripsi, maupun dokumen naskah skripsi asli UNUHA. Semua data uji wajib berupa data sintetis yang dihasilkan secara acak (berkas sintetis 1 byte s.d. 10.000.000 byte).
6. **Larangan Persistensi Kunci Produksi:** Seluruh pasangan kunci kriptografi (X25519, Ed25519) dan kunci enkripsi konten (CEK) yang dibangkitkan selama eksperimen bersifat *ephemeral in-memory*. Dilarang menyimpan material kunci pengujian ke dalam repositori Git atau menggunakannya di luar sandbox.

---

## 3. SPESIFIKASI LIMA EKSPERIMEN TERBATAS (EXP-01 S.D. EXP-05)

Mandat Gerbang 2 memberikan otorisasi pelaksanaan terarah untuk 5 (lima) eksperimen berikut:

```
+-------------------------------------------------------------------------------------------------------------+
|                                        MATRIKS LIMA EKSPERIMEN GERBANG 2                                    |
+----+--------+------------------------------------+----------------------------------------------------------+
| No | ID Uji | Fokus Eksperimen                   | Sasaran Validasi Utama                                   |
+----+--------+------------------------------------+----------------------------------------------------------+
| 1  | EXP-01 | Evaluasi MLS Rust-WASM             | Kompatibilitas Next.js 14 Web Worker, lifecycle 1:1,     |
|    |        | (OpenMLS vs mls-rs)                | ketersediaan API MLS Exporter, bundle size, RAM, init.   |
+----+--------+------------------------------------+----------------------------------------------------------+
| 2  | EXP-02 | Chunking, AAD Kanonikal, & Nonce   | Validasi AES-256-GCM, kuota 10.000.000 byte/file,        |
|    |        |                                    | 25.000.000 byte/pesan, maks 5 file, chunk 1.048.576 byte |
|    |        |                                    | (1 MiB biner), piecewise modulo L_last, tag 16 B di luar |
|    |        |                                    | kuota plaintext, nonce 96-bit 0-based, AAD 83-byte.      |
+----+--------+------------------------------------+----------------------------------------------------------+
| 3  | EXP-03 | Komparasi Kunci OD-17              | Evaluasi sejajar Pendekatan A (MLS Exporter) vs          |
|    |        | (Exporter vs Payload CSPRNG)       | Pendekatan B (Payload CEK) pada 8 kriteria B-CHAT-06C    |
|    |        |                                    | dan 3 skenario akses, tanpa penetapan pemenang apriori.  |
+----+--------+------------------------------------+----------------------------------------------------------+
| 4  | EXP-04 | Idempotensi & Transactional Outbox | Transaksi 10 tahap DB MySQL 8 pada database uji terpisah,|
|    |        |                                    | row lock chat room konseptual, at-least-once + dedup,    |
|    |        |                                    | simulasi crash & lost ACK (SQLite hanya fungsi dasar).   |
+----+--------+------------------------------------+----------------------------------------------------------+
| 5  | EXP-05 | Evaluasi Strategi Rotasi Epoch     | Simulasi kebijakan E1 s.d. E4, idle 7 hari ruang chat    |
|    |        | (E1, E2, E3, E4)                   | via virtual clock/mocked timestamp, beban ratchet tree,  |
|    |        |                                    | data 10 sampel diakui murni eksploratif awal.            |
+----+--------+------------------------------------+----------------------------------------------------------+
```

---

### 3.1. EXP-01 — Evaluasi Kelayakan Pustaka MLS Rust-WASM

#### A. Tujuan Pengujian
1. Membandingkan kelayakan teknis implementasi RFC 9420 antara pustaka `OpenMLS` dan `mls-rs` yang dikompilasi ke WebAssembly (WASM).
2. Memverifikasi pemuatan modul Web Worker pada kerangka kerja Next.js 14 melalui pola konstruktor standar:
   ```javascript
   new Worker(new URL('./mls.worker.js', import.meta.url), { type: 'module' });
   ```
3. Memastikan pemisahan total eksekusi kriptografi dari *UI Main Thread* demi menjaga responsivitas antarmuka web.
4. Mengevaluasi kelengkapan *binding* antarmuka TypeScript untuk siklus hidup (*lifecycle*) grup MLS: `create_group`, `generate_key_package`, `add_member`, `process_commit`, dan `remove_device`.
5. Memverifikasi ketersediaan fungsi `MLS-Exporter` (RFC 9420 §8.5) pada masing-masing kandidat.

#### B. Lingkungan dan Data Pengujian
- **Node.js Environment:** Sandbox terisolasi Next.js 14.
- **Data Pengujian:** Identitas pengguna sintetis (dummy Alice dan Bob), key package sintetis, pasangan kunci ephemeral Ed25519/X25519.
- **Peramban Uji:** Chromium modern dan Gecko (Firefox) pada sistem operasi Windows 64-bit.

#### C. Parameter dan Metrik yang Diukur
1. **Ukuran Artefak Biner:** Ukuran biner `.wasm` mentah (*uncompressed*) dan ukuran setelah kompresi gzip.
2. **Waktu Inisialisasi Klien:** Waktu (ms) pemuatan modul WASM ke memori Web Worker (kondisi *cold start* dan *warm cache*).
3. **Konsumsi Memori Worker Heap:** Alokasi memori Web Worker (MB) selama inisialisasi, pembuatan grup, dan dekripsi pesan.
4. **Kelengkapan Fitur:** Dokumentasi ada/tidaknya dukungan fungsi MLS Exporter resmi pada pustaka terkait.

---

### 3.2. EXP-02 — Chunking Lampiran, AAD Kanonikal 83-Byte, dan Nonce AES-256-GCM

#### A. Ketentuan Resmi Kuota Lampiran dalam Satuan Byte (OD-02)
Sesuai keputusan Pemilik Proyek (OD-02), seluruh batas kuota lampiran bimbingan daring dinyatakan secara konsisten dan eksplisit dalam satuan byte guna mencegah ambiguitas antara megabyte desimal (MB) dan mebibyte biner (MiB):

| Parameter | Ketentuan Resmi | Basis Satuan & Definisi |
|---|---:|---|
| **Ukuran maksimum satu file** | **10.000.000 byte** | Satuan desimal ($10\text{ MB} = 10 \times 1.000.000\text{ byte}$) |
| **Ukuran maksimum gabungan satu pesan** | **25.000.000 byte** | Satuan desimal ($25\text{ MB} = 25 \times 1.000.000\text{ byte}$) |
| **Jumlah maksimum file per pesan** | **5 file** | Maksimal 5 lampiran berkas per pesan |
| **Ukuran chunk plaintext** | **1.048.576 byte (1 MiB)** | Satuan biner ($1\text{ MiB} = 2^{20}\text{ byte} = 1.048.576\text{ byte}$) |

**Lima Penegasan Operasional Kuota Lampiran:**
1. **Definisi Satuan MB Desimal:** 1 MB menggunakan satuan desimal: $1\text{ MB} = 1.000.000\text{ byte}$.
2. **Definisi Satuan MiB Biner:** 1 MiB menggunakan satuan biner: $1\text{ MiB} = 1.048.576\text{ byte}$.
3. **Dasar Penghitungan Kuota Plaintext:** Batas kuota ukuran file ($10.000.000\text{ byte}$) dan gabungan pesan ($25.000.000\text{ byte}$) dihitung secara mutlak berdasarkan **ukuran data asli (*plaintext*) sebelum enkripsi**.
4. **Pengecualian Overhead Autentikasi AES-GCM:** *Overhead* tag autentikasi AES-256-GCM sebesar **16 byte per chunk** ($16\text{ byte/chunk}$) **tidak dihitung sebagai bagian dari kuota plaintext**. Ukuran ciphertext aktual per chunk pada media transmisi dan penyimpanan terenkripsi adalah $(\text{panjang plaintext chunk} + 16\text{ byte})$.
5. **Integritas Ketentuan Teknis yang Disepakati:** Tidak ada perubahan terhadap formula pembagian chunk $N = \lceil S / C \rceil$, formula piecewise modulo ukuran chunk terakhir ($L_{\text{terakhir}}$), konstruksi nonce 96-bit *zero-based*, maupun layout serialisasi AAD kanonikal 83 byte yang telah disetujui.

#### B. Tujuan Pengujian
1. Memvalidasi implementasi pemecahan berkas naskah menjadi chunk standar $C = 1.048.576\text{ byte}$ ($1\text{ MiB}$, satuan biner) sesuai ketentuan resmi OD-02.
2. Memverifikasi penolakan mutlak berkas kosong berukuran 0 byte ($S > 0$).
3. Memvalidasi perhitungan matematis jumlah chunk $N = \lceil S / C \rceil$ ($1 \le N \le 10$) dan ukuran chunk terakhir ($L_{\text{terakhir}}$) berbasis fungsi piecewise modulo:
   $$L_{\text{terakhir}} = \begin{cases} S \bmod C, & \text{jika } S \bmod C \ne 0 \\ C, & \text{jika } S \bmod C = 0 \end{cases}$$
4. Memvalidasi konsistensi pembentukan **Nonce 96-bit** berbasis nol (*zero-based indexing*):
   $$\text{Nonce} = 0000000000000000_{16} \parallel \text{uint32be}(\text{chunk\_index})\quad (0 \le \text{chunk\_index} \le N - 1)$$
5. Memvalidasi serialisasi dan integritas **AAD Kanonikal 83-Byte** pada antarmuka Web Crypto API (`crypto.subtle.encrypt` dan `crypto.subtle.decrypt` dengan algoritma AES-256-GCM tag 128-bit / 16 byte):
   - Field 1: `AAD_VERSION` = `0x01` (1 byte)
   - Field 2: `CIPHER_SUITE` = `0x0001` (2 byte)
   - Field 3: `ROOM_UUID` = 16-byte raw binary Big-Endian
   - Field 4: `SENDER_DEVICE_UUID` = 16-byte raw binary Big-Endian
   - Field 5: `MESSAGE_UUID` = 16-byte raw binary Big-Endian
   - Field 6: `ATTACHMENT_INDEX` = `uint16be` (2 byte, $0 \le \text{index} \le 4$)
   - Field 7: `FILE_ID` = 16-byte raw binary Big-Endian
   - Field 8: `CHUNK_INDEX` = `uint32be` (4 byte, $0 \le \text{index} \le N - 1$)
   - Field 9: `TOTAL_CHUNKS` = `uint32be` (4 byte, $1 \le \text{total} \le 10$)
   - Field 10: `PLAINTEXT_LENGTH` = `uint32be` (4 byte, $1 \le \text{length} \le 1.048.576$)
   - **Total Panjang Eksak: Tepat 83 Byte.**
6. Menguji ketahanan dekripsi terhadap 5 (lima) skenario anomali manipulasi:
   - Chunk hilang (*missing chunk*).
   - Chunk rusak/termodifikasi (*bit-flipped ciphertext*).
   - Chunk tertukar urutan (*out-of-order chunk*).
   - Chunk terduplikasi (*duplicate chunk*).
   - Chunk tertukar berkas (*cross-file context tampering* / pertukaran AAD).
7. Memvalidasi integritas manifest lampiran dan penegakan batas gabungan maksimal $25.000.000\text{ byte}$ per pesan dan maksimal 5 berkas per pesan.

#### C. Lingkungan dan Data Pengujian
- Dokumen naskah sintetis acak berukuran: 1 byte, 512 KiB (524.288 byte), 1.048.576 byte (1 MiB), 2.097.152 byte (2 MiB), 5.000.000 byte, dan 10.000.000 byte (10 MB).
- Peramban pengujian menggunakan native Web Crypto API.

---

### 3.3. EXP-03 — Evaluasi Komparatif Pendekatan Kunci Lampiran OD-17

#### A. Tujuan Pengujian
1. Membandingkan secara objektif dan seimbang dua arsitektur penyediaan Kunci Enkripsi Konten (*Content Encryption Key* / CEK) lampiran:
   - **Pendekatan A (MLS Exporter):** Menurunkan CEK 256-bit secara deterministik dari state pohon MLS menggunakan fungsi `MLS-Exporter` (RFC 9420 §8.5) dengan label konteks kanonikal terikat ID berkas.
   - **Pendekatan B (CSPRNG via MLS Application Payload):** Membangkitkan CEK 256-bit acak murni dari Web Crypto API CSPRNG (`crypto.getRandomValues`), kemudian mendistribusikan CEK yang dibungkus di dalam application payload pesan MLS yang terenkripsi.
2. Mengevaluasi kedua pendekatan secara objektif berdampingan berdasarkan **8 (delapan) kriteria komparatif terstandarisasi B-CHAT-06C**:
   - **Kriteria 1 — Keamanan & Entropi Kunci:** Keterikatan erat pada MLS TreeKEM Epoch Secret (RFC 9420 §8.5) pada Pendekatan A vs Entropi independen CSPRNG 256-bit per berkas lampiran pada Pendekatan B.
   - **Kriteria 2 — Pemisahan Konteks Antar-Berkas:** Konteks diekspor dengan label kanonikal (`RoomUUID || MessageUUID || AttachmentUUID`) pada Pendekatan A vs CEK acak unik per lampiran dengan isolasi kriptografis per berkas pada Pendekatan B.
   - **Kriteria 3 — Keterikatan Terhadap Epoch MLS:** Kunci terikat pada epoch pesan (rotasi epoch membatalkan kemampuan ekspor baru dari epoch lampau) pada Pendekatan A vs Kunci terikat pada payload pesan dan independen dari pergeseran epoch berjalan pada Pendekatan B.
   - **Kriteria 4 — Perilaku Anggota Baru (Akses Lampiran Historis):** Perangkat/anggota baru di Epoch $E+1$ tidak dapat menurunkan kunci lampiran Epoch $E$ secara langsung pada Pendekatan A vs Perangkat baru dapat membaca kunci jika menerima pesan sinkronisasi riwayat terenkripsi pada Pendekatan B.
   - **Kriteria 5 — Kompatibilitas Backup & Sync:** Memerlukan mekanisme ekspor kunci statis ke dalam file backup terenkripsi pada Pendekatan A vs Kunci sudah tersimpan di dalam payload pesan backup pada Pendekatan B.
   - **Kriteria 6 — Kompleksitas Implementasi:** Tingkat kompleksitas integrasi fungsi exporter API pada pustaka Rust-WASM pada Pendekatan A vs Pemanfaatan antarmuka native Web Crypto API CSPRNG dan format JSON terenkripsi pada Pendekatan B.
   - **Kriteria 7 — Risiko Kesalahan Kriptografi:** Sensitivitas terhadap kesalahan formatting context string pada Pendekatan A vs Kebutuhan validitas entropi CSPRNG dan pencegahan reuse CEK/nonce pada Pendekatan B.
   - **Kriteria 8 — Kemudahan Uji & Pemeliharaan:** Ketergantungan pengujian terhadap status siklus hidup pohon MLS pada Pendekatan A vs Kemampuan pengujian modular independen tanpa menjalankan full engine MLS pada Pendekatan B.
3. Menganalisis perilaku kedua pendekatan pada 3 (tiga) kondisi operasional akses kunci:
   - **Kondisi 1:** Pengiriman dan dekripsi langsung saat ruang obrolan aktif.
   - **Kondisi 2:** Pengambilan dan dekripsi pesan tersimpan setelah terjadi satu atau beberapa kali rotasi epoch.
   - **Kondisi 3:** Pemulihan riwayat percakapan pada perangkat baru melalui sinkronisasi state.
4. **Larangan Mutlak Penetapan Pemenang Apriori:** Tim teknis dilarang keras menetapkan atau mengarahkan salah satu pendekatan sebagai pemenang sebelum data pengujian empiris terkumpul lengkap dan diserahkan untuk dievaluasi oleh Pemilik Proyek.

---

### 3.4. EXP-04 — Validasi Idempotensi dan Pola Transactional Outbox

#### A. Tujuan Pengujian
1. Menguji keandalan pola *Transactional Outbox* untuk menjamin persistensi pesan dan *event dispatch* secara atomik.
2. Memverifikasi alur transaksi kanonikal 10 tahap:
   ```
   [Alur Transaksi Kanonikal 10 Tahap EXP-04]
   1. DB::beginTransaction()
   2. Row-Level Lock pada Ruang Obrolan (SELECT * FROM chat_rooms WHERE id = ? FOR UPDATE)
   3. Validasi Otorisasi Peserta dan Status Ruang Aktif
   4. Pemeriksaan Idempotency Key (SELECT id FROM chat_messages WHERE idempotency_key = ?)
      -> Jika sudah ada: Rollback/Commit idempotensial, kembalikan record eksisting
   5. INSERT INTO chat_messages (status: PENDING_DISPATCH)
   6. INSERT INTO chat_outbox_events (event_type: MESSAGE_CREATED, status: READY)
   7. UPDATE chat_rooms SET last_message_at = ?, message_count = message_count + 1
   8. DB::commit() [Pesan & Event Outbox Tersimpan Secara Atomis]
   9. Asynchronous Event Dispatcher Mengambil Event (SELECT ... FOR UPDATE SKIP LOCKED)
   10. Dispatcher Mempublikasikan Pesan ke WebSocket / Push Notification Service
   ```
3. Memvalidasi jaminan **At-Least-Once Delivery dengan Deduplikasi Klien & Server**:
   - Menghapus klaim mutlak *exactly-once delivery* pada lapisan jaringan.
   - Menguji mekanisme penolakan duplikasi pada klien berdasarkan `message_uuid` dan `client_assigned_seq`.
4. Menguji simulasi skenario kegagalan operasional:
   - *Crash* pada dispatcher setelah transaksi basis data commit namun sebelum pesan terkirim ke jaringan.
   - Kehilangan tanda terima jaringan (*lost ACK*) dari klien penerima.
   - *Retry loop* dispatcher dengan strategi *exponential backoff*.
   - Eksekusi beberapa worker dispatcher secara bersamaan (*concurrent workers*) untuk memverifikasi pencegahan *race condition* dan penguncian baris (`SKIP LOCKED`).

> [!IMPORTANT]
> **PENEGASAN SKEMA KONSEPTUAL DAN LINGKUNGAN PENGUJIAN EXP-04:**
> 1. **Nama Skema Konseptual:** Nama tabel dan kolom yang dirujuk dalam alur di atas (`chat_rooms`, `chat_messages`, `chat_outbox_events`, dan `last_message_at`) **secara mutlak merupakan rancangan konseptual untuk pengujian eksperimen di lingkungan sandbox uji**, BUKAN skema yang sudah tersedia di basis data aplikasi utama saat ini. Tim penguji dilarang menyimpulkan bahwa tabel atau kolom tersebut telah ada pada sistem operasional.
> 2. **Ketepatan Lingkungan Basis Data:** SQLite **hanya dapat digunakan untuk pengujian fungsi logika dasar** yang tidak mengklaim membuktikan perilaku konkurensi MySQL. Untuk pengujian yang melibatkan `SELECT ... FOR UPDATE`, `SKIP LOCKED`, beberapa dispatcher bersamaan (*concurrent dispatchers*), *race condition*, dan penguncian baris (*locking*), **wajib menggunakan MySQL 8 pada database uji yang terpisah secara fisik dari database operasional**.
> 3. **Nama Database Uji Konseptual:** Penyebutan nama database `manajemen_skripsi_test` **hanya contoh nama konseptual**, bukan database yang sudah dibuat atau tersedia saat ini.

---

### 3.5. EXP-05 — Evaluasi Strategi Rotasi Epoch MLS

#### A. Tujuan Pengujian
1. Membandingkan performa komputasi dan kestabilan ratchet tree MLS pada 4 (empat) kebijakan rotasi epoch:
   - **Kebijakan E1 (Event-Driven):** Rotasi epoch hanya dipicu oleh perubahan keanggotaan grup (penambahan/pencabutan perangkat) atau permintaan rekeying eksplisit.
   - **Kebijakan E2 (Periodik Agresif):** Rotasi epoch dilakukan secara otomatis setiap akumulasi 25 pesan terkirim.
   - **Kebijakan E3 (Periodik Menengah):** Rotasi epoch dilakukan secara otomatis setiap akumulasi 50 pesan terkirim.
   - **Kebijakan E4 (Periodik Konservatif & Idle Timeout):** Rotasi epoch dilakukan setiap akumulasi 100 pesan terkirim ATAU jika ruang obrolan mengalami *idle timeout* selama 7 hari kalender.
2. Menguji definisi operasional *idle timeout 7 hari*:
   - Menegaskan bahwa *idle timeout* 7 hari **secara mutlak dihitung berdasarkan tidak adanya pesan baru yang berhasil tersimpan dalam ruang percakapan bimbingan privat terkait** (*Room Message Inactivity*), bukan berdasarkan aktivitas login web browser pengguna.
   - Menggunakan referensi konseptual timestamp pesan terakhir (seperti kolom konseptual `last_message_at` pada tabel konseptual `chat_rooms`, yang ditegaskan belum ada di basis data produksi).
   - Menyimulasikan interval waktu 7 hari kalender di lingkungan laboratorium menggunakan manipulasi jam virtual (*virtual clock*) atau injeksi stempel waktu masa lalu (`mocked timestamp`).
3. Mengukur beban transmisi paket kontrol `MLS Commit` dan `Proposal` pada masing-masing kebijakan.
4. **Penegasan Batasan Statistik Pengujian:**
   - Pengujian dilakukan minimal 10 kali pengulangan per skenario dengan mencatat nilai Median ($p_{50}$) dan Persentil ke-95 ($p_{95}$).
   - Hasil pengukuran 10 pengulangan ini secara formal **diakui murni sebagai data eksploratif awal laboratorium**, bukan bukti kestabilan statistik untuk lingkungan produksi berskala besar.

---

## 4. ARSITEKTUR LINGKUNGAN ISOLASI NON-PRODUKSI YANG DIUSULKAN

Untuk memastikan eksperimen Gerbang 2 tidak memberikan dampak risiko apa pun terhadap aplikasi operasional, ditetapkan arsitektur isolasi non-produksi (*sandbox*) sebagai berikut:

```
+-------------------------------------------------------------------------------------------------------------+
|                                  STRUKTUR DIREKTORI ISOLASI NON-PRODUKSI                                    |
+-------------------------------------------------------------------------------------------------------------+
  d:\Aplikasi\laragon\www\sistem_manajamen_skripsi\
  ├── manajemen_skripsi/                <--- [PRODUKSI/UTAMA: DILARANG DISENTUH / READ-ONLY]
  ├── frontend_manajemen_skripsi/       <--- [PRODUKSI/UTAMA: DILARANG DISENTUH / READ-ONLY]
  │
  └── scratch/bchat_prototype/          <--- [SANDBOX TERISOLASI GERBANG 2 - DIBUAT PASCA MANDAT DISAHKAN]
      ├── package.json                  <--- Konfigurasi dependensi lokal independen
      ├── tsconfig.json                 <--- Konfigurasi TypeScript lokal
      ├── cargo/ (jika diperlukan)      <--- Build crate Rust lokal terisolasi
      ├── exp01_mls_wasm/               <--- Kode uji EXP-01 (OpenMLS vs mls-rs Worker)
      ├── exp02_chunking_aad/           <--- Kode uji EXP-02 (Web Crypto chunk & AAD)
      ├── exp03_key_comparison/         <--- Kode uji EXP-03 (Komparasi OD-17)
      ├── exp04_outbox_idempotency/     <--- Kode uji EXP-04 (Simulasi DB & Outbox)
      ├── exp05_epoch_rotation/         <--- Kode uji EXP-05 (Simulasi Rotasi & Virtual Clock)
      ├── database/                     <--- Basis data pengujian lokal (SQLite / MySQL test konseptual)
      ├── test_data/                    <--- Berkas naskah sintetis (dummy PDF/DOCX)
      └── reports/                      <--- Log eksekusi dan matriks metrik mentah
```

### 4.1. Ketentuan Isolasi Dependensi
1. Sandbox prototipe mengelola dependensi mandiri di dalam `scratch/bchat_prototype/package.json`.
2. Dilarang menambahkan modul atau mengubah dependensi pada `frontend_manajemen_skripsi/package.json` maupun `manajemen_skripsi/composer.json`.

### 4.2. Ketentuan Isolasi Basis Data
1. Pengujian transaksi outbox (EXP-04) wajib membedakan lingkungan pengujian:
   - **SQLite Sandbox:** Digunakan terbatas untuk verifikasi fungsi logika dasar aplikasi, manipulasi array biner, dan parsing event lokal. Pengujian pada SQLite **tidak dapat dan tidak boleh diklaim sebagai pembuktian perilaku konkurensi atau keandalan penguncian transaksi MySQL**.
   - **MySQL 8 Uji Terpisah:** Untuk pengujian konkurensi transaksi sesungguhnya (`SELECT ... FOR UPDATE`, `SKIP LOCKED`, eksekusi bersamaan beberapa worker dispatcher, serta validasi konsistensi outbox), **wajib menggunakan database uji MySQL 8 yang terpisah secara fisik dari database aplikasi utama**.
2. Penyebutan nama database `manajemen_skripsi_test` merupakan **contoh nama konseptual**; database tersebut belum dibuat saat ini. Pembuatan database uji baru boleh dilakukan setelah mandat Gerbang 2 disahkan resmi.
3. Dilarang keras menghubungkan skrip pengujian apa pun ke database operasional `manajemen_skripsi`.

### 4.3. Ketentuan Sanitasi Data dan Akun
1. Identitas pengguna pengujian wajib menggunakan akun sintetis bertanda khusus (contoh: `user_test_mhs_01`, `user_test_dos_01`).
2. Berkas uji naskah skripsi dibangkitkan secara sintetis menggunakan generator biner acak (bukan dokumen skripsi karya sivitas akademika UNUHA).
3. Tidak ada lalu lintas pesan atau notifikasi yang dialirkan ke peramban atau perangkat pengguna nyata.

### 4.4. Ketentuan Sanitasi Material Kriptografi
1. Seluruh pasangan kunci kriptografi (kunci privat identitas, kunci sesi, prekey package) dibuat secara dinamis dan bersifat sementara (*in-memory ephemeral*).
2. Dilarang keras melakukan commit private key, seed frase, secret exporter, atau token otentikasi apa pun ke dalam sistem kendali versi Git.
3. Seluruh material kunci uji wajib dimusnahkan (*zeroized*) segera setelah proses pengukuran selesai.

---

## 5. KRITERIA PENGUKURAN, AMBANG BATAS KINERJA, DAN CIRCUIT BREAKERS

Pengujian performa pada Gerbang 2 dievaluasi berdasarkan ketentuan kuota resmi dalam byte, target hipotesis awal yang telah disetujui, indikator responsivitas pengamatan, mekanisme proteksi otomatis (*circuit breakers*), serta status pemisahan yang tegas terhadap usulan baru:

```
+----------------------------------------------------------------------------------------------------------------------+
|                                        MATRIKS AMBANG BATAS KINERJA GERBANG 2                                        |
+--------------------------+------------------------------+---------------------------+--------------------------------+
| Parameter Pengukuran     | Kategori Metrik              | Ambang Nilai              | Tindakan / Konsekuensi         |
+--------------------------+------------------------------+---------------------------+--------------------------------+
| Kuota Satu File          | Ketentuan Resmi OD-02        | 10.000.000 byte           | Batas plaintext sebelum enc    |
| Kuota Gabungan Pesan     | Ketentuan Resmi OD-02        | 25.000.000 byte           | Batas plaintext sebelum enc    |
| Batas Jumlah File        | Ketentuan Resmi OD-02        | Maksimal 5 file           | Validasi jumlah per pesan      |
| Ukuran Chunk Standar     | Ketentuan Resmi OD-02        | 1.048.576 byte (1 MiB)    | Partisi plaintext biner        |
| Overhead Tag AES-GCM     | Parameter Kriptografi        | 16 byte per chunk         | Tidak dihitung kuota plaintext |
+--------------------------+------------------------------+---------------------------+--------------------------------+
| Ukuran Bundle WASM       | Target Hipotesis Disetujui   | <= 2,5 MB (gzipped)       | Patokan evaluasi pengujian     |
| Memori Web Worker Heap   | Target Hipotesis Disetujui   | <= 50 MB                  | Patokan evaluasi pengujian     |
| Waktu Inisialisasi MLS   | Target Hipotesis Disetujui   | <= 300 ms (cold start)    | Patokan evaluasi pengujian     |
| UI Main-Thread Freeze    | Indikator Target Responsif   | <= 50 ms                  | Target pengamatan responsivitas|
+--------------------------+------------------------------+---------------------------+--------------------------------+
| Memori Web Worker Heap   | CIRCUIT BREAKER WAJIB        | > 100 MB                  | PENGHENTIAN SEKETIKA (ABORT)   |
| UI Main-Thread Freeze    | CIRCUIT BREAKER WAJIB        | > 500 ms                  | PENGHENTIAN SEKETIKA (ABORT)   |
| Nonce Indexing           | Kepatuhan Kriptografi Wajib  | Zero-based (0 .. N-1)     | Mismatch -> ABORT SEGERA       |
| AAD Serialization        | Kepatuhan Kriptografi Wajib  | Tepat 83 Byte Kanonikal   | != 83 Byte -> ABORT SEGERA     |
+--------------------------+------------------------------+---------------------------+--------------------------------+
| Ukuran Bundle WASM       | Status Usulan / Pending      | > 5 MB (uncompressed)     | Murni observasi non-kelulusan  |
| Timeout Inisialisasi MLS | Status Usulan / Pending      | > 1.500 ms                | Murni observasi non-kelulusan  |
| UI Main-Thread Freeze    | Status Usulan / Pending      | Target 0 ms               | Murni sasaran ideal usulan     |
+--------------------------+------------------------------+---------------------------+--------------------------------+
```

### 5.1. Batas Kuota Lampiran Resmi dan Basis Satuan Byte (OD-02)
1. **Ukuran Maksimum Satu File:** **10.000.000 byte** ($10\text{ MB}$, satuan desimal).
2. **Ukuran Maksimum Gabungan Satu Pesan:** **25.000.000 byte** ($25\text{ MB}$, satuan desimal).
3. **Jumlah Maksimum File per Pesan:** **5 file**.
4. **Ukuran Chunk Plaintext:** **1.048.576 byte** ($1\text{ MiB}$, satuan biner).
5. **Aturan Perhitungan Kuota:**
   - Kuota dihitung mutlak berdasarkan **ukuran plaintext asli sebelum enkripsi**.
   - Overhead autentikasi AES-GCM sebesar **16 byte per chunk** tidak dihitung sebagai bagian dari kuota plaintext.

### 5.2. Target Hipotesis Awal yang Telah Disetujui
1. **Ukuran Bundle WASM:** Maksimal $2,5\text{ MB}$ dalam bentuk terkompresi gzip.
2. **Konsumsi Memori Web Worker Heap:** Maksimal $50\text{ MB}$ selama pemrosesan sesi obrolan aktif.
3. **Waktu Inisialisasi Klien MLS:** Maksimal $300\text{ ms}$ pada pemuatan pertama (*cold start*).
4. **Penegasan Status Hipotesis:** Target hipotesis di atas merupakan tolok ukur awal pengujian laboratorium dan **dilarang dinaikkan statusnya menjadi standar produksi final** tanpa evaluasi komprehensif dan pengesahan resmi dari Pemilik Proyek.

### 5.3. Indikator Target Responsivitas Awal (Pengamatan)
- **UI Main-Thread Freeze $\le 50\text{ ms}$:** Ditetapkan sebagai indikator target responsivitas awal untuk mengamati kelancaran antarmuka web. Indikator ini merupakan sasaran kualitas antarmuka pengguna dalam pengujian, bukan aturan pemutus darurat (*circuit breaker*).

### 5.4. Ketentuan Wajib Circuit Breakers (Penghentian Seketika)
Jika selama pengujian prototipe terjadi kondisi berikut, proses eksekusi **wajib dihentikan seketika (*immediate abort*)**:
1. **Worker Memory Overflow:** Konsumsi memori Web Worker heap melampaui **$100\text{ MB}$**.
2. **Main Thread Freeze:** Terjadi pembekuan antarmuka utama (*UI main-thread blocking / long task*) yang melampaui **$500\text{ ms}$**.
3. **Penyimpangan Integritas Kriptografis:** Terjadi kegagalan verifikasi AAD 83-byte atau inkonsistensi nonce (misal: penggunaan indeks berbasis 1 pada lapisan biner) yang berpotensi menyebabkan kegagalan dekripsi masal.

### 5.5. Status Ambang Batas Tambahan (Usulan / Pending)
Parameter tambahan yang diidentifikasi pada dokumen B-CHAT-06C.1 dan B-CHAT-06C.1.1 (seperti batas biner *uncompressed* $> 5\text{ MB}$, batas timeout inisialisasi $> 1.500\text{ ms}$, dan target UI freeze $0\text{ ms}$) **secara tegas berstatus usulan eksperimen yang belum disahkan**. Parameter-parameter ini akan dicatat sebagai data observasi tambahan dan tidak boleh dianggap sebagai kriteria kelulusan formal sebelum disetujui Pemilik Proyek.

### 5.6. Prosedur Pengujian dan Pencatatan Metadata Lingkungan
1. Setiap skenario pengujian dijalankan minimal 10 kali pengulangan independen.
2. Data hasil pengukuran disajikan dalam bentuk Median ($p_{50}$) dan Persentil ke-95 ($p_{95}$).
3. Laporan pengujian wajib mencatat secara transparan metadata lingkungan pengujian:
   - Spesifikasi Perangkat Keras: Model prosesor (CPU), arsitektur, dan total kapasitas RAM.
   - Sistem Operasi: Nama dan nomor versi OS (Windows 64-bit).
   - Peramban Pengujian: Nama mesin dan nomor versi (Chromium / Gecko / WebKit).
   - Parameter MLS: Ukuran grup (1:1), jumlah perangkat aktif per pengguna.
   - Kondisi Jaringan: Latensi jaringan tiruan (0 ms, 50 ms, 200 ms) dan simulasi *packet loss*.
   - Kondisi Cache Peramban: Status *cold start* (cache dinonaktifkan) vs *warm cache* (aset tersimpan di cache).

---

## 6. PROSEDUR KEAMANAN, SANITASI, DAN PROTOKOL PENGHENTIAN DARURAT

Mandat Gerbang 2 mewajibkan penerapan protokol keamanan dan penanganan insiden yang ketat selama seluruh siklus pengujian:

### 6.1. Protokol Penghentian Darurat (Emergency Abort Protocol)
Eksperimen wajib segera dihentikan apabila terdeteksi salah satu dari kondisi bahaya berikut:
1. Terdeteksinya upaya koneksi jaringan dari skrip prototipe ke basis data produksi utama.
2. Terdeteksinya penulisan berkas di luar subdirektori sandbox `scratch/bchat_prototype/`.
3. Terjadinya lonjakan memori tak terkontrol yang mengarah pada *memory leak*.
4. Terjadinya kegagalan fungsi Web Crypto API yang menghasilkan ciphertext kosong atau nonce terduplikasi (*nonce reuse*).

### 6.2. Prosedur Penanganan Sentuhan Sumber Daya Utama Secara Tidak Sengaja
Jika pengujian secara tidak sengaja menyentuh, membaca, atau memodifikasi berkas aplikasi utama atau basis data operasional, prosedur wajib yang harus dipatuhi secara berurutan adalah:
1. **Langkah 1 (Hentikan Eksperimen Segera):** Hentikan seluruh proses eksekusi prototipe seketika (*immediate abort* / kill process worker/Node.js).
2. **Langkah 2 (Larangan Pemulihan Otomatis):** Dilarang keras melakukan perubahan, penulisan balik, atau pemulihan otomatis (*auto-restoration*) terhadap berkas aplikasi utama atau basis data operasional.
3. **Langkah 3 (Pengamanan Bukti dan Pencatatan Insiden):** Amankan bukti dan catat stempel waktu kejadian, ruang lingkup sumber daya yang tersentuh, serta kemungkinan dampaknya secara komprehensif. Pemeriksaan Git *read-only* (`git status` dan `git diff`) **hanya boleh digunakan sebagai sarana pemeriksaan indikasi perubahan berkas, BUKAN sebagai mekanisme pemulihan**.
4. **Langkah 4 (Pelaporan Transparan ke Pemilik Proyek):** Laporkan insiden, ruang lingkup sumber daya yang terdampak, serta hasil pemeriksaan secara terbuka dan tertulis kepada Pemilik Proyek.
5. **Langkah 5 (Otorisasi Pemulihan Terpisah):** Tindakan pemulihan atau penataan ulang sumber daya hanya boleh dilaksanakan setelah memperoleh instruksi dan otorisasi tertulis terpisah dari Pemilik Proyek.

### 6.3. Protokol Sanitasi dan Pembersihan Akhir (Teardown Protocol)
Setelah seluruh rangkaian pengujian EXP-01 sampai EXP-05 selesai dan laporan disahkan oleh Pemilik Proyek:
1. Hapus seluruh basis data pengujian lokal (instans database uji MySQL atau basis data SQLite uji).
2. Hapus seluruh berkas naskah sintetis dummy dari subdirektori pengujian.
3. Lakukan pembersihan cache build dan artefak biner temporer di dalam sandbox.
4. Pastikan tidak ada berkas sampah pengujian yang tertinggal di luar direktori laporan resmi.

---

## 7. FORMAT STANDAR PELAPORAN HASIL EKSPERIMEN

Setiap pengujian (EXP-01 sampai EXP-05) wajib dilaporkan dalam format terstandarisasi 10 (sepuluh) butir guna menjamin transparansi, komparabilitas, dan akuntabilitas evaluasi teknis:

```markdown
# LAPORAN EKSPERIMEN: [ID_EKSPERIMEN] — [JUDUL_EKSPERIMEN]

1. TUJUAN EKSPERIMEN
   - Penjelasan fokus pengujian dan hipotesis teknis yang diuji.

2. LINGKUNGAN DAN VERSI DEPENDENSI
   - Spesifikasi perangkat keras, sistem operasi, browser engine, versi Node.js.
   - Daftar pustaka eksternal beserta nomor versi eksak (contoh: openmls vX.Y, mls-rs vX.Y).

3. METODE PENGUJIAN DAN PROSEDUR EKSEKUSI
   - Langkah-langkah pengujian, skenario alur kerja, dan mekanisme pengambilan sampel.

4. SPESIFIKASI DATA PENGUJIAN
   - Karakteristik data sintetis yang digunakan (ukuran berkas, variasi chunk, jumlah pesan dummy).

5. HASIL PENGUKURAN EMPIRIS
   - Tabel hasil pengukuran (Cold start vs Warm cache, Median p50, Persentil p95, Min, Max).

6. TEMUAN KEAMANAN DAN INTEGRITAS KRIPTOGRAFIS
   - Hasil validasi AAD, pencegahan replay, isolasi material kunci, dan sanitasi memori.

7. KEGAGALAN, ANOMALI, DAN KETERBATASAN
   - Dokumentasi error yang muncul, kondisi batas (edge cases), dan keterbatasan alat uji.

8. ANALISIS TERHADAP KRITERIA KEBERHASILAN DAN CIRCUIT BREAKERS
   - Evaluasi apakah target hipotesis tercapai atau circuit breaker sempat terpicu.

9. REKOMENDASI TEKNIS AWAL TIM KERJA
   - Pertimbangan kelebihan dan kekurangan arsitektur berdasarkan bukti empiris yang diperoleh.

10. KEPUTUSAN DAN ARAHAN YANG MEMBUTUHKAN PERSETUJUAN PEMILIK PROYEK
   - Daftar butir evaluasi strategis yang membutuhkan keputusan resmi dari Pemilik Proyek.
```

> [!NOTE]
> **KLAUSUL PEMBATASAN KLAIM LAPORAN:**  
> Hasil yang disajikan dalam laporan eksperimen Gerbang 2 tidak boleh digunakan untuk menyatakan atau mengklaim bahwa sistem chat E2EE telah siap untuk diintegrasikan ke lingkungan produksi.

---

## 8. MATRIKS KEWENANGAN DAN HAK EKSKLUSIF PEMILIK PROYEK

Untuk mencegah terjadinya pengambilan keputusan sepihak di luar wewenang teknis rekayasa, disusun matriks kewenangan yang memisahkan hak eksekusi teknis tim kerja dari hak prerogatif eksklusif Pemilik Proyek:

```
+-------------------------------------------------------------------------------------------------------------+
|                                  MATRIKS KEWENANGAN DAN HAK PREROGATIF                                      |
+--------------------------------------------------------------------+------------------+---------------------+
| Domain Keputusan / Tindakan Teknis                                 | Tim Kerja Teknis | Pemilik Proyek      |
|                                                                    | (Engineering)    | (Project Owner)     |
+--------------------------------------------------------------------+------------------+---------------------+
| 1. Perancangan skrip pengujian terisolasi di sandbox scratch       | Pelaksana Mandat | Pengawas            |
| 2. Pembangkitan data sintetis dan simulasi beban kerja uji         | Pelaksana Mandat | Pengawas            |
| 3. Pengukuran metrik performa (bundle size, RAM, latensi, CPU)     | Pelaksana Mandat | Penerima Laporan    |
| 4. Dokumentasi temuan anomali dan circuit breakers                 | Pelaksana Mandat | Penerima Laporan    |
| 5. Pemberian rekomendasi teknis berbasis bukti empiris             | Rekomendator     | Pengambil Keputusan |
+--------------------------------------------------------------------+------------------+---------------------+
| [HAK PREROGATIF EKSKLUSIF PEMILIK PROYEK - DILARANG DIAMBIL SEPIHAK OLEH TIM TEKNIS]                       |
+--------------------------------------------------------------------+------------------+---------------------+
| 6. Pemilihan Pemenang OD-17 (Pendekatan A vs B) untuk Produksi    | DILARANG MEMUTUS | HAK EKSKLUSIF PENUH |
| 7. Pengesahan Ambang Batas Performa Produksi Final                 | DILARANG MEMUTUS | HAK EKSKLUSIF PENUH |
| 8. Perubahan Batas Kuota Lampiran (OD-02: 10.000.000 byte/file,   | DILARANG MENGUBAH| HAK EKSKLUSIF PENUH |
|    25.000.000 byte/pesan, maks 5 file, chunk 1.048.576 byte)      |                  |                     |
| 9. Perubahan Kebijakan Retensi Arsip Akademik (OD-15)              | DILARANG MENGUBAH| HAK EKSKLUSIF PENUH |
| 10. Perubahan Keputusan Pengarah OD-01, OD-02, atau OD-17          | DILARANG MENGUBAH| HAK EKSKLUSIF PENUH |
| 11. Pengesahan Mandat Gerbang 2 (Izin Memulai Eksekusi Prototipe)  | DILARANG MEMULAI | HAK EKSKLUSIF PENUH |
| 12. Pengesahan Gerbang 3 (Izin Integrasi Kode ke Aplikasi Utama)   | DILARANG MEMULAI | HAK EKSKLUSIF PENUH |
| 13. Pelaksanaan Migrasi Database Produksi                          | DILARANG KERAS   | HAK EKSKLUSIF PENUH |
| 14. Penerbitan dan Deployment Fitur Chat E2EE ke Produksi          | DILARANG KERAS   | HAK EKSKLUSIF PENUH |
+--------------------------------------------------------------------+------------------+---------------------+
```

---

## 9. DAFTAR RISIKO TEKNIS DAN RENCANA MITIGASI

Pengujian Gerbang 2 mengidentifikasi 6 (enam) risiko utama beserta langkah mitigasi preventif dan kuratif yang disiapkan:

```
+-------------------------------------------------------------------------------------------------------------+
|                                    ANALISIS RISIKO DAN RENCANA MITIGASI                                     |
+----+--------------------------------+---------+-------------------------------------------------------------+
| ID | Deskripsi Risiko               | Tingkat | Tindakan Mitigasi Preventif dan Kuratif                     |
+----+--------------------------------+---------+-------------------------------------------------------------+
| R1 | Inkompatibilitas Bundler       | TINGGI  | Gunakan pola konstruktor resmi Next.js 14 Web Worker        |
|    | Next.js 14 terhadap pemuatan   |         | (new Worker(new URL(..., import.meta.url), {type:'module'})|
|    | biner WASM di Web Worker       |         | dan jalankan uji cold-start pada Turbopack & Webpack.       |
+----+--------------------------------+---------+-------------------------------------------------------------+
| R2 | Lonjakan Memori Worker         | TINGGI  | Pasang pemantau alokasi heap Web Worker secara ketat;       |
|    | (Memory Leak) saat menangani   |         | terapkan Circuit Breaker (> 100 MB) untuk membatalkan proses|
|    | pemecahan chunking berkas      |         | seketika sebelum membebani memori sistem.                   |
+----+--------------------------------+---------+-------------------------------------------------------------+
| R3 | Ketiadaan API MLS-Exporter     | TINGGI  | Lakukan audit antarmuka binding TypeScript OpenMLS & mls-rs |
|    | resmi pada kandidat Rust-WASM  |         | di awal EXP-01; catat sebagai blocker Pendekatan A jika API |
|    |                                |         | belum stabil atau tidak diekspos ke WASM.                   |
+----+--------------------------------+---------+-------------------------------------------------------------+
| R4 | Deadlock / Lock Contention     | SEDANG  | Batasi durasi transaksi basis data; terapkan database uji   |
|    | pada transaksi outbox MySQL 8  |         | MySQL 8 mandiri dan klausa SELECT ... FOR UPDATE SKIP LOCKED|
|    | akibat konkurensi pengiriman   |         | untuk mencegah pemblokiran baris secara berantai.           |
+----+--------------------------------+---------+-------------------------------------------------------------+
| R5 | Desinkronisasi Kunci Klien     | SEDANG  | Uji coba penanganan event outbox berurutan dan verifikasi   |
|    | akibat rotasi epoch agresif    |         | mekanisme pemulihan state lokal saat menerima Commit yang   |
|    | atau koneksi jaringan putus    |         | melompati epoch pada pengujian EXP-05.                      |
+----+--------------------------------+---------+-------------------------------------------------------------+
| R6 | Kebocoran Material Kunci atau  | SEDANG  | Seluruh kunci kriptografi dibuat ephemeral dalam memori     |
|    | Data Uji ke Git Repository     |         | sandbox; sertakan pola `.gitignore` ketat pada direktori    |
|    |                                |         | scratch untuk memblokir ekstensi kunci dan berkas biner.    |
+----+--------------------------------+---------+-------------------------------------------------------------+
```

---

## 10. CHECKLIST PERSETUJUAN FORMAL MANDAT GERBANG 2

Lembar evaluasi berikut disiapkan untuk ditinjau dan ditandatangani secara terpisah oleh Pemilik Proyek sebelum ada tindakan pembuatan lingkungan atau pengujian teknis apa pun:

```
+-------------------------------------------------------------------------------------------------------------+
|                                   LEMBAR PENGESAHAN MANDAT GERBANG 2                                        |
|                       SISTEM INFORMASI MANAJEMEN SKRIPSI UNIVERSITAS NURUL HUDA                             |
+-------------------------------------------------------------------------------------------------------------+

[ ] BUTIR EVALUASI 1: RUANG LINGKUP DAN BATASAN
    Ruang lingkup pengujian Gerbang 2 terbatas pada 5 eksperimen PoC (EXP-01 s.d. EXP-05) di lingkungan 
    non-produksi terisolasi dan tidak menyentuh kode aplikasi utama atau basis data operasional.
    Status Peninjauan: [  ] DISETUJUI    [  ] PERLU REVISI    [  ] DITOLAK

[ ] BUTIR EVALUASI 2: ARSITEKTUR ISOLASI SANDBOX
    Direktori kerja scratch/bchat_prototype/ beserta dependensi mandiri dan basis data uji terpisah 
    (MySQL 8 untuk konkurensi dan/atau SQLite untuk fungsi dasar) telah memenuhi syarat keamanan sistem.
    Status Peninjauan: [  ] DISETUJUI    [  ] PERLU REVISI    [  ] DITOLAK

[ ] BUTIR EVALUASI 3: KRITERIA AMBANG BATAS & CIRCUIT BREAKERS
    Batas performa hipotesis (WASM <= 2,5 MB gzip, RAM Worker <= 50 MB, Init <= 300 ms), indikator 
    responsivitas (UI Freeze <= 50 ms), dan circuit breakers (RAM Worker > 100 MB, UI Freeze > 500 ms) 
    disetujui sebagai aturan pengujian dan penghentian otomatis.
    Status Peninjauan: [  ] DISETUJUI    [  ] PERLU REVISI    [  ] DITOLAK

[ ] BUTIR EVALUASI 4: INTEGRITAS KEPUTUSAN PENGARAH & KUOTA EKSPLISIT BYTE (OD-01, OD-02, OD-17)
    Pengujian mempertahankan Rust-WASM (OD-01), batas kuota lampiran resmi: 10.000.000 byte/file 
    (10 MB desimal), 25.000.000 byte/pesan (25 MB desimal), maks 5 file, chunk 1.048.576 byte (1 MiB biner) 
    sebelum enkripsi dengan overhead tag 16 byte/chunk di luar kuota (OD-02), serta evaluasi objektif 
    8 kriteria B-CHAT-06C tanpa penetapan pemenang apriori (OD-17).
    Status Peninjauan: [  ] DISETUJUI    [  ] PERLU REVISI    [  ] DITOLAK

[ ] BUTIR EVALUASI 5: MATRIKS KEWENANGAN PREROGATIF
    Pemilik Proyek tetap memegang hak prerogatif mutlak atas keputusan arsitektur produksi final, 
    kebijakan retensi arsip, dan izin deployment ke lingkungan operasional.
    Status Peninjauan: [  ] DISETUJUI    [  ] PERLU REVISI    [  ] DITOLAK

---------------------------------------------------------------------------------------------------------------
KEPUTUSAN AKHIR PEMILIK PROYEK:
[  ] MANDAT GERBANG 2 DISAHKAN PENUH (IZIN EKSEKUSI PROTOTIPE TERISOLASI DIBERIKAN)
[  ] MANDAT GERBANG 2 DITUNDA / MEMERLUKAN PENYESUAIAN TAMBAHAN
[  ] MANDAT GERBANG 2 DITOLAK

Catatan / Arahan Tambahan Pemilik Proyek:
...............................................................................................................
...............................................................................................................

Tanggal Pengesahan : ...................................................
Tanda Tangan / Otorisasi: 


( _________________________________________________ )
Pemilik Proyek Sistem Informasi Manajemen Skripsi UNUHA
+-------------------------------------------------------------------------------------------------------------+
```

---

## 11. PERNYATAAN INTEGRITAS SISTEM DAN BATAS TURN OPERASI

Dengan ini dinyatakan dan ditegaskan secara transparan bahwa selama penyusunan dan pembaruan dokumen **B-CHAT-06D (Revisi B-CHAT-06D.2)**:
1. **Zero Code Changes:** Tidak ada satu pun baris kode backend (`manajemen_skripsi/`) maupun frontend (`frontend_manajemen_skripsi/`) yang diubah, ditambahkan, atau dihapus.
2. **Zero Database Modifications:** Tidak ada skema basis data, tabel, kolom, indeks, migrasi, atau seeder yang dijalankan.
3. **Zero Package Alterations:** Tidak ada dependensi baru yang dipasang pada `package.json` maupun `composer.json`.
4. **Zero Prototype Execution:** Direktori `scratch/bchat_prototype/` **belum dibuat**. Pengujian EXP-01 sampai EXP-05 **belum dijalankan sama sekali**.
5. **Zero Git Operations:** Tidak ada perintah Git (commit, push, checkout, branch, stash, merge) yang dijalankan. Repositori tetap dalam kondisi awal yang terlindungi.
6. **Kepatuhan Protokol Gerbang:** Status operasional tim kerja secara mutlak **berhenti pada tahap ini dan menunggu peninjauan Pemilik Proyek**. Mandat Gerbang 2 belum disahkan dan belum memberikan izin eksekusi sebelum dokumen disetujui secara tertulis.

---
*(Dokumen B-CHAT-06D Revisi B-CHAT-06D.2 selesai diperbarui secara paripurna dan siap diserahkan kepada Pemilik Proyek).*
