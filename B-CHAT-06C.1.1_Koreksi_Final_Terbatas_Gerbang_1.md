# B-CHAT-06C.1.1 — KOREKSI FINAL TERBATAS GERBANG 1
## PENYEMPURNAAN KONSISTENSI RESIDUAL RENCANA PROTOTIPE E2EE UNUHA

**Status Operasi:** STRICTLY READ-ONLY — PLAN ONLY — ZERO EXECUTION  
**Proyek:** Sistem Informasi Manajemen Skripsi UNUHA  
**Fitur:** Bimbingan Online Chat Real-Time E2EE  
**Tahap:** B-CHAT-06C.1.1 (Finalisasi Dokumen Perencanaan Gerbang 1)  
**Dokumen Acuan Utama:** Dokumen Perencanaan B-CHAT-06C dan Laporan Koreksi B-CHAT-06C.1  
**Dokumen Pendukung:** B-CHAT-01, B-CHAT-04, B-CHAT-05, B-CHAT-05A, B-CHAT-06A.2, B-CHAT-06B.1.3, serta Keputusan Pemilik Proyek tanggal 5 November 2026.  
**Tanggal Evaluasi:** 5 November 2026  

---

## 1. IDENTITAS DOKUMEN DAN BATASAN OPERASI

Dokumen ini merupakan instrumen koreksi final terbatas atas dokumen perencanaan **B-CHAT-06C** dan **B-CHAT-06C.1**. Tujuan utamanya adalah menyempurnakan konsistensi teknis, mengeliminasi klaim yang terlalu mutlak, menyeimbangkan parameter pengujian empiris, serta mengunci seluruh keputusan yang telah ditetapkan oleh Pemilik Proyek agar dokumen perencanaan siap dinilai pada **Gerbang 1 (Persetujuan Rencana)**.

### Batasan Operasi Mutlak:
- **Strictly Read-Only — Plan Only — Zero Execution:** Tidak ada kode aplikasi, konfigurasi, pustaka pihak ketiga, maupun skema basis data yang disentuh.
- **Pengecualian Tunggal:** Pembuatan berkas dokumentasi markdown ini (`B-CHAT-06C.1.1_Koreksi_Final_Terbatas_Gerbang_1.md`) merupakan satu-satunya tindakan yang diizinkan.
- **Tanpa Prototipe Mandiri:** Direktori scratch prototipe tidak dibuat pada turn ini. Eksperimen EXP-01 sampai EXP-05 tidak dijalankan.
- **Pemisahan Protokol Gerbang:** Laporan ini adalah bahan evaluasi Gerbang 1. Eksekusi pengujian prototipe tetap membutuhkan persetujuan dan mandat terpisah pada **Gerbang 2 (Persetujuan Eksekusi)**.

---

## 2. KEPUTUSAN PEMILIK PROYEK YANG DIPERTAHANKAN

Rencana prototipe ini mempertahankan secara mutlak 3 (tiga) keputusan pengarah arsitektural yang telah disetujui Pemilik Proyek:

1. **OD-01 (Kandidat Mesin MLS):**
   - Rust-WASM sebagai kandidat utama evaluasi implementasi RFC 9420.
   - TypeScript sebagai antarmuka integrasi peramban.
   - Web Crypto API native (`crypto.subtle`) untuk kriptografi simetris berkas (AES-256-GCM) dan *hashing* SHA-256.
2. **OD-02 (Batas Berkas Satuan Desimal):**
   - Maksimal 10.000.000 byte per file naskah (plaintext).
   - Maksimal 25.000.000 byte gabungan seluruh lampiran per pesan.
   - Maksimal 5 file lampiran per pesan.
   - Kandidat ukuran chunk plaintext standar: 1.048.576 byte (1 MiB).
3. **OD-17 (Uji Komparatif Sumber Kunci Lampiran):**
   - Approach A: MLS Exporter Secret (RFC 9420 §8.5).
   - Approach B: CEK acak 256-bit dari CSPRNG yang didistribusikan melalui payload pesan aplikasi MLS.
   - Kedua pendekatan dibandingkan secara obyektif berdasarkan 8 kriteria terstandarisasi tanpa penetapan pemenang apriori.

---

## 3. RINGKASAN TUJUH KOREKSI

Tujuh temuan teknis residual pada dokumen B-CHAT-06C.1 diselesaikan secara konsisten:

1. **Koreksi Pola URL Web Worker (Temuan 1):** Mengadopsi pola konstruktor standar bundler Next.js `new Worker(new URL('./mls.worker.js', import.meta.url), { type: 'module' })`, dengan catatan eksplisit bahwa path relatif bergantung pada lokasi modul pemanggil dan tidak serta-merta menjamin kompatibilitas bundler tanpa validasi empiris di EXP-01.
2. **Pembatasan Klaim Penyimpanan Kunci IndexedDB (Temuan 2):** Menghapus klaim mutlak bahwa kunci di IndexedDB "dapat digunakan kapan saja". Kunci tetap dapat digunakan paska rotasi epoch selama material kunci tersedia, valid, dan diizinkan oleh kebijakan aplikasi. Menegaskan bahwa CryptoKey *non-extractable* bukan proteksi mutlak terhadap serangan XSS pada origin yang sama.
3. **Penyelarasan Idempotensi Transactional Outbox (Temuan 3):** Menegaskan prinsip *at-least-once delivery dengan deduplikasi*. Menghapus klaim *exactly-once* otomatis; menetapkan alur transaksi kanonikal 10 tahap; serta merumuskan skenario penanganan crash, retry dispatcher, dan mekanisme *event locking/claiming* pada EXP-04.
4. **Pemisahan Pengujian Tiga Lapisan Anti-Replay (Temuan 4):** Memisahkan pengujian anti-replay ke dalam tiga domain yang berbeda secara fundamental: (A) Replay protokol MLS (epoch & generation tracking RFC 9420), (B) Replay ciphertext lampiran (pengikatan AAD, manifest, dan chunk tracking), serta (C) Duplikasi pengiriman aplikasi (HTTP retry, lost ACK, UUID, dan fingerprint payload).
5. **Presisi Serialisasi AAD 83-Byte dan Validasi Chunk (Temuan 5):** Mempertahankan layout eksak 83 byte; menegaskan representasi byte kanonikal 16-byte raw binary Big-Endian untuk seluruh UUID; mengklarifikasi bahwa batas `TotalChunks` (1..10) berlaku per berkas 10.000.000 byte; serta merinci aturan validasi `PlaintextLength` pada chunk terminal dan non-terminal.
6. **Rekonsiliasi Target Eksperimen vs Circuit Breaker (Temuan 6):** Mengembalikan parameter awal yang disetujui (target: bundle WASM $\le 2,5$ MB gzip, memori worker $\le 50$ MB, inisialisasi $\le 300$ ms; circuit breaker: memori worker $> 100$ MB, UI freeze $> 500$ ms). Mengklasifikasikan angka baru (WASM $> 5$ MB, inisialisasi $> 1.500$ ms, UI freeze target 0 ms) sebagai *usulan eksperimen yang belum disetujui*. Memisahkan metrik heap worker dari UI main-thread long task.
7. **Restorasi Kondisi Idle EXP-05 dan Batas Statistik (Temuan 7):** Mengembalikan kondisi pemicu rotasi *idle timeout 7 hari* pada skenario E4 EXP-05. Menegaskan bahwa pengukuran 10 kali pengulangan (median dan p95) murni merupakan data eksploratif awal dan tidak boleh diklaim sebagai estimasi statistik produksi yang stabil.

---

## 4. PENJELASAN TEKNIS RINCI TUJUH KOREKSI

### 4.1. Koreksi 1 — Pola URL Web Worker Next.js
- **Kondisi Sebelum:** B-CHAT-06C.1 Bagian 3 menggunakan path absolut string `new Worker(new URL('/workers/mls.worker.js', import.meta.url), ...)`. Pola ini mengasumsikan berkas berada pada root public directory dan mengabaikan resolusi modul internal bundler Next.js.
- **Koreksi Presisi:**
  Pola konstruksi worker diselaraskan mengikuti konvensi modul bundler Webpack/Turbopack Next.js:
  ```javascript
  // Pola kandidat bundler (relatif terhadap lokasi file pemanggil)
  const worker = new Worker(
    new URL('./mls.worker.js', import.meta.url),
    { type: 'module' }
  );
  ```
- **Batasan Teknis:**
  1. Penggunaan pola `import.meta.url` dengan path relatif (`./mls.worker.js`) memerlukan penyesuaian lokasi fisik modul dalam struktur direktori `src/workers/` atau `src/components/`.
  2. Sintaks ini **tidak otomatis menjamin kompatibilitas langsung** pada Next.js 14 App Router tanpa konfigurasi loader Webpack/Turbopack yang tepat untuk berkas biner `.wasm`.
  3. Verifikasi pemuatan modul WASM, isolasi eksekusi murni di sisi klien (*client-only execution*), pencegahan crash pada fase SSR, dan efisiensi transfer data via *transferable ArrayBuffer* dipertahankan sebagai agenda pengujian teknis wajib pada **EXP-01**.

---

### 4.2. Koreksi 2 — Pembatasan Klaim Penyimpanan Kunci IndexedDB
- **Kondisi Sebelum:** B-CHAT-06C.1 menyatakan bahwa kunci lampiran yang tersimpan di IndexedDB "dapat digunakan kapan saja untuk membuka lampiran tanpa terpengaruh rotasi epoch". Klaim ini mengabaikan kebijakan kedaluwarsa lokal dan memberikan kesan keamanan absolut.
- **Koreksi Presisi:**
  1. **Prinsip Ketersediaan Kunci:**
     > *"Kunci lampiran yang telah diperoleh dapat tetap digunakan setelah rotasi epoch selama material kunci masih tersedia, valid, dan penggunaan lokalnya diizinkan oleh kebijakan keamanan serta akses aplikasi."*
  2. **Pembedaan Mekanisme Penyimpanan:**
     - *CryptoKey Non-Extractable:* Objek kunci Web Crypto yang disimpan langsung di IndexedDB dengan atribut `extractable: false`. Kunci ini tidak dapat diekspor menjadi raw bytes melalui JavaScript.
     - *Encrypted Key Material:* Raw key bytes yang dibungkus (*wrapped*) menggunakan Master Key lokal sebelum ditulis ke IndexedDB.
  3. **Evaluasi Batas Keamanan terhadap XSS:**
     - Menegaskan bahwa flag `extractable: false` pada CryptoKey **bukan proteksi mutlak terhadap serangan Cross-Site Scripting (XSS)**. Skrip jahat yang berhasil dieksekusi pada origin yang sama memang tidak dapat membaca raw bytes kunci, namun skrip tersebut **tetap dapat memanggil API `crypto.subtle.decrypt()` atau `crypto.subtle.encrypt()`** menggunakan referensi CryptoKey yang ada untuk memproses data sewenang-wenang.
  4. **Agenda Pengujian EXP-01 / EXP-03:**
     - Menguji isolasi penyimpanan IndexedDB antar-akun pengguna pada peramban yang sama.
     - Menguji prosedur sanitasi lokal saat pengguna logout, penarikan hak akses, dan pemulihan kunci paska impor snapshot cadangan.

---

### 4.3. Koreksi 3 — Jaminan Idempotensi Transactional Outbox
- **Kondisi Sebelum:** B-CHAT-06C.1 menyebut alur transaksi 7 tahap pada narasi teks tetapi menjabarkan 10 tahap pada diagram, serta mengklaim bahwa pemrosesan ulang event Outbox "otomatis tidak menghasilkan duplikasi" seolah menjamin *exactly-once delivery*.
- **Koreksi Presisi:**
  1. **Prinsip Pengiriman:** Sistem menerapkan jaminan **At-Least-Once Delivery dengan Deduplikasi**, bukan *Exactly-Once* murni. Dispatcher berpotensi mengirimkan event siaran lebih dari satu kali saat terjadi kegagalan jaringan atau timeout.
  2. **Penyelarasan Alur Kanonikal 10 Tahap Transaksi (EXP-04):**
     ```
     Tahap 1 : Mulai transaksi basis data (DB::beginTransaction);
     Tahap 2 : Kunci baris ruang percakapan (SELECT * FROM chat_rooms WHERE id = :id FOR UPDATE);
     Tahap 3 : Validasi keunikan UUID & kesesuaian fingerprint payload immutable;
     Tahap 4 : Simpan pesan ciphertext ke tabel `chat_messages`;
     Tahap 5 : Alokasikan sequence_cursor dan perbarui metadata ruang;
     Tahap 6 : Tulis rekaman event ke tabel `outbox_events` (status: 'PENDING') dalam transaksi yang sama;
     Tahap 7 : COMMIT transaksi basis data (kunci baris dilepas);
     Tahap 8 : Dispatcher membaca dan mengklaim event dari `outbox_events` paska commit;
     Tahap 9 : Siarkan event ke Laravel Reverb secara idempoten (membawa sequence_cursor);
     Tahap 10: Tandai status event di `outbox_events` sebagai 'PROCESSED'.
     ```
  3. **Skenario Kegagalan Simulatir pada EXP-04:**
     - *Crash paska Commit (Tahap 7 sukses, Tahap 8-9 belum jalan):* Data pesan aman di database; event berstatus `PENDING` di outbox. Saat dispatcher restart, event diambil kembali dan disiarkan.
     - *Kegagalan Sementara Broadcast (Tahap 9 timeout):* Dispatcher menerapkan retry dengan *exponential backoff*.
     - *Crash paska Broadcast sebelum Update Outbox (Tahap 9 sukses, Tahap 10 gagal):* Event masih `PENDING`. Saat dispatcher restart, event disiarkan ulang. Klien penerima mendeduplikasi event berdasarkan `sequence_cursor` dan `client_message_uuid`.
     - *Konkurensi Dua Dispatcher:* Untuk mencegah dua proses dispatcher memproses event yang sama secara simultan, EXP-04 wajib mengevaluasi mekanisme klaim event berbasis *pessimistic lock* (`SELECT ... FOR UPDATE SKIP LOCKED`) atau kolom `claimed_at`.

---

### 4.4. Koreksi 4 — Pemisahan Pengujian Tiga Lapisan Anti-Replay
- **Kondisi Sebelum:** B-CHAT-06C.1 menggabungkan mitigasi anti-replay dan hanya menyatakan bahwa pengujian replay harus dipisahkan dari idempotensi aplikasi tanpa merinci domain uji masing-masing.
- **Koreksi Presisi:**
  Pengujian anti-replay pada eksperimen EXP-04 dan EXP-01 dipisahkan secara tegas ke dalam **tiga lapisan independen**:

```
+-------------------------------------------------------------------------------------------------------------+
|                                    TIGA LAPISAN PENGUJIAN ANTI-REPLAY & DEDUPLIKASI                         |
+-------------------+---------------------------------------------+-------------------------------------------+
| Lapisan Domain    | Fokus Ancaman & Mekanisme Pengujian         | Standar / Parameter Evaluasi              |
+-------------------+---------------------------------------------+-------------------------------------------+
| **A. Lapisan MLS**| Injeksi paket jabat tangan / ciphertext lama| Evaluasi kepatuhan engine terhadap aturan |
|    (Protokol E2EE)| oleh penyerang jaringan aktif.              | epoch, generation counter, dan ratcheting |
|                   |                                             | standar RFC 9420 (tanpa membuat tracking  |
|                   |                                             | pohon mandiri di luar spesifikasi).       |
+-------------------+---------------------------------------------+-------------------------------------------+
| **B. Lapisan Berkas**| Pengiriman ulang ciphertext chunk valid  | Autentikasi GCM memverifikasi integritas, |
|    (AES-256-GCM)  | yang dicegat di jaringan storage.           | tetapi deduplikasi chunk bergantung pada  |
|                   |                                             | konteks: Attachment UUID, Upload Session, |
|                   |                                             | Chunk Index, dan status Manifest.         |
+-------------------+---------------------------------------------+-------------------------------------------+
| **C. Lapisan HTTP**| Transmisi ganda akibat timeout, reconnect,  | Idempotensi aplikasi: constraint basis    |
|    (Aplikasi Web) | lost ACK, atau penekanan tombol berulang.   | data `UNIQUE(room_id, client_message_uuid)`|
|                   |                                             | dan verifikasi fingerprint payload.       |
+-------------------+---------------------------------------------+-------------------------------------------+
```

---

### 4.5. Koreksi 5 — Validasi dan Serialisasi Kanonikal AAD 83 Byte
- **Kondisi Sebelum:** Pembahasan AAD pada B-CHAT-06C.1 mencantumkan batas 1..10 chunk tanpa penegasan apakah batas tersebut untuk satu berkas atau seluruh pesan, serta belum merinci serialisasi biner UUID secara eksplisit.
- **Koreksi Presisi:**
  1. **Layout Biner Kanonikal 83 Byte (Fixed-Length):**
     $$\text{Panjang Total} = 4 + 2 + 1 + (16 \times 4) + 4 + 4 + 4 = \mathbf{83\text{ Byte}}$$
  2. **Aturan Serialisasi Tiap Field:**
     - **Field 1 (Magic Header, 4B):** Biner ASCII `UNH1` (`0x55, 0x4E, 0x48, 0x31`).
     - **Field 2 (Protocol Version, 2B):** `uint16be` bernilai `1` (`0x00, 0x01`).
     - **Field 3 (Domain Separator, 1B):** `uint8` bernilai `0x02` (Domain khusus Chunk Lampiran).
     - **Field 4 s.d. 7 (UUIDs, 4 x 16B = 64B):** `RoomUUID`, `MessageUUID`, `AttachmentUUID`, dan `UploadSessionID` **wajib diserialisasi menjadi tepat 16 byte raw binary Big-Endian**. Dilarang menggunakan representasi string heksadesimal 36 karakter (yang berukuran 36 byte). Pengembang wajib mengonversi string UUID menjadi buffer 16 byte biner kanonikal sebelum diinjeksi ke buffer AAD.
     - **Field 8 (Chunk Index, 4B):** `uint32be` berbasis nol: $0 \le \text{idx} < \text{TotalChunks}$.
     - **Field 9 (Total Chunks, 4B):** `uint32be` dengan rentang valid $1 \le \text{TotalChunks} \le 10$.
       *Klarifikasi Batas:* Batas 1..10 chunk berlaku **eksklusif untuk satu berkas lampiran** berukuran maksimal 10.000.000 byte. Total chunk kumulatif untuk seluruh pesan (maksimal 5 berkas, batas gabungan 25.000.000 byte) dapat mencapai **28 chunk** (akibat partisi pecahan).
     - **Field 10 (Plaintext Length, 4B):** `uint32be` yang merepresentasikan **panjang byte plaintext dari chunk yang bersangkutan**:
       - Chunk non-terminal (penuh): $1.048.576$ Byte (`0x00100000`).
       - Chunk terminal (terakhir desimal): $562.816$ Byte (`0x00089680`) untuk berkas 10 MB pas, atau sisa $S$ byte ($1 \le S \le 1.048.576$).
       - Nilai harus $> 0$ dan $\le 1.048.576$.
  3. **Integritas Manifest:** Metadata ukuran total berkas asli, total chunk, checksum SHA-256 per chunk, dan nama berkas asli ditempatkan di dalam Manifest terenkripsi.
  4. **Pembedaan Konteks Pendekatan OD-17:**
     - *Approach A (MLS Exporter):* Konteks exporter wajib unik per lampiran (`RoomUUID || MessageUUID || AttachmentUUID`) dan menyertakan domain separator untuk mencegah penggunaan ulang material kunci pada tujuan kriptografi lain.
     - *Approach B (CSPRNG via Payload):* CEK 256-bit dibangkitkan unik per lampiran dan diikat secara autentik ke dalam payload pesan MLS bersama ID lampiran yang bersangkutan.

---

### 4.6. Koreksi 6 — Rekonsiliasi Target Kinerja dan Circuit Breaker
- **Kondisi Sebelum:** Dokumen B-CHAT-06C.1 menambahkan angka baru pada tabel (WASM $> 5$ MB, inisialisasi $> 1.500$ ms, target UI freeze 0 ms) seolah-olah telah menjadi keputusan yang disetujui, serta mengaburkan perbedaan antara memori heap worker dan pemblokiran UI thread.
- **Koreksi Presisi:**
  1. **Klasifikasi Parameter Ambang Pengukuran:**
     - Parameter awal yang telah dibahas dan disepakati dipertahankan:
       - *Target Eksperimen Awal:* Bundel WASM $\le 2,5$ MB gzip; Alokasi memori Worker $\le 50$ MB; Waktu inisialisasi $\le 300$ ms.
       - *Circuit Breaker (Ambang Penghentian Darurat):* Alokasi memori Worker $> 100$ MB; Durasi UI freeze $> 500$ ms.
     - Angka tambahan diklasifikasikan secara formal sebagai **"Usulan Ambang Eksperimen — Belum Disetujui Pemilik Proyek"**:
       - Usulan batas maksimal WASM uncompressed: $> 5,0$ MB.
       - Usulan batas timeout cold-start inisialisasi: $> 1.500$ ms.
       - Usulan target UI long task: 0 ms (diakui sebagai target ideal, bukan klaim yang sudah terbukti).
  2. **Pemisahan Metrik Memori Worker vs UI Freeze:**
     - Penggunaan memori Web Worker berada pada heap V8 terpisah. Konsumsi memori worker tidak identik dengan memori browser keseluruhan dan tidak selalu dapat diukur secara konsisten di semua vendor peramban (misal perbedaan implementasi `performance.memory` pada Chrome vs Safari).
     - UI Freeze diukur secara spesifik melalui *PerformanceObserver* untuk mendeteksi *Long Tasks* pada main thread ($> 50$ ms) dan pemblokiran event loop ($> 500$ ms). Keterlambatan komputasi di worker tidak otomatis memicu freeze pada UI thread.
  3. **Pencatatan Konteks Pengukuran Empiris:**
     Setiap pengujian performa di Gerbang 2 wajib mendokumentasikan spesifikasi perangkat keras, OS, versi browser, kondisi cache, ukuran pohon MLS, dan simulasi jaringan secara transparan.

---

### 4.7. Koreksi 7 — Restorasi Kondisi Idle EXP-05 dan Batas Statistik
- **Kondisi Sebelum:** Dokumen B-CHAT-06C.1 menghapus kondisi rotasi *idle timeout 7 hari* dari skenario E4, serta menyebut sampel 10 kali pengulangan dengan representasi p95 seolah merupakan estimasi statistik produksi yang stabil.
- **Koreksi Presisi:**
  1. **Restorasi Skenario Rotasi Epoch (EXP-05):**
     Rancangan 4 skenario rotasi dipertahankan lengkap sesuai desain konsolidasi:
     - **E1 (Event-Driven Murni):** Rotasi hanya dipicu oleh perubahan keanggotaan/perangkat (Add/Remove Device) atau perubahan status administratif ruang bimbingan.
     - **E2 (Hybrid 25 Pesan):** Rotasi dipicu oleh perubahan perangkat ATAU akumulasi 25 pesan aplikasi.
     - **E3 (Hybrid 50 Pesan):** Rotasi dipicu oleh perubahan perangkat ATAU akumulasi 50 pesan aplikasi.
     - **E4 (Hybrid 100 Pesan / Idle 7 Hari):** Rotasi dipicu oleh perubahan perangkat ATAU akumulasi 100 pesan aplikasi ATAU **kondisi menganggur (*idle timeout*) selama 7 hari kalender**.
  2. **Kualifikasi Batas Statistik Pengukuran:**
     - Pengujian dengan **minimal 10 kali pengulangan** pada Gerbang 2 ditegaskan murni sebagai **pengukuran eksploratif awal**.
     - Nilai *median* dan *percentile ke-95 (p95)* dari 10 sampel hanya memberikan indikasi variasi awal dan **dilarang diklaim sebagai estimasi statistik produksi yang stabil**.
     - Laporan pengujian wajib menyajikan data sampel mentah, sebaran variasi (*range / standard deviation*), serta mencatat anomali pencilan (*outliers*). Keputusan ambang produksi final akan memerlukan metodologi sampel yang lebih besar paska prototipe.

---

## 5. TABEL REKONSILIASI KONDISI SEBELUM DAN SESUDAH

| No. | Temuan Evaluasi | Kondisi B-CHAT-06C.1 | Koreksi Presisi B-CHAT-06C.1.1 | Dampak pada Rencana Gerbang 1 |
|:---:|:---|:---|:---|:---|
| **1** | **Pola URL Web Worker** | Menggunakan path absolut `/workers/mls.worker.js`; mengabaikan resolusi modul bundler. | Menggunakan pola kandidat bundler Next.js `new Worker(new URL('./mls.worker.js', import.meta.url), { type: 'module' })`; validasi di EXP-01. | Mencegah error runtime bundler; path disesuaikan struktur proyek. |
| **2** | **Klaim Penyimpanan Kunci IndexedDB** | Mengklaim kunci dapat digunakan kapan saja paska rotasi epoch; CryptoKey diklaim aman dari XSS. | Kunci dapat digunakan selama material tersedia, valid, dan diizinkan kebijakan lokal; CryptoKey non-extractable bukan proteksi mutlak XSS. | Mengarahkan pengujian EXP-01 pada mitigasi XSS dan tata kelola logout/revokasi. |
| **3** | **Jaminan Idempotensi Outbox** | Diagram tidak selaras dengan narasi tahap; menyiratkan exactly-once otomatis. | Menetapkan prinsip *at-least-once delivery dengan deduplikasi*; menyelaraskan alur kanonikal 10 tahap; merinci skenario crash dispatcher di EXP-04. | Mencegah event hilang paska commit dan memastikan deduplikasi di klien penerima. |
| **4** | **Pemisahan Pengujian Anti-Replay** | Uraian anti-replay umum dan belum terstruktur per lapisan ancaman. | Memisahkan pengujian ke dalam 3 lapisan: (A) Replay protokol MLS RFC 9420, (B) Replay ciphertext chunk berkas, dan (C) Duplikasi HTTP aplikasi. | Menjamin skenario pengujian EXP-04 dan EXP-01 terarah pada domain yang tepat. |
| **5** | **Validasi & Layout Biner AAD** | Batas TotalChunks 1..10 belum diperjelas per berkas vs pesan; konversi UUID belum dirinci. | AAD 83-byte eksak; seluruh UUID wajib 16B raw binary; TotalChunks 1..10 per file (maks 28 per pesan); PlaintextLength divalidasi per chunk. | Menghasilkan spesifikasi biner non-ambigu yang siap diimplementasikan pada parser. |
| **6** | **Target Kinerja vs Circuit Breaker** | Angka baru dicampur pada tabel seolah keputusan final; memori worker disamakan dengan UI freeze. | Mengembalikan ambang yang disetujui; angka baru diklasifikasikan sebagai usulan eksperimen; memori worker dipisahkan dari UI main-thread long task. | Menghilangkan klaim sepihak dan menetapkan kriteria kegagalan darurat yang proporsional. |
| **7** | **Restorasi Idle EXP-05 & Statistik** | Menghapus kondisi idle 7 hari pada skenario E4; p95 dari 10 sampel diklaim stabil. | Mengembalikan kondisi pemicu idle timeout 7 hari pada E4; menegaskan sampel 10 kali murni eksploratif awal dan bukan statistik produksi. | Menjaga kelengkapan skenario rotasi konsolidasi dan menegakkan kejujuran statistik. |

---

## 6. DAMPAK TERHADAP LIMA EKSPERIMEN (EXP-01 S.D. EXP-05)

Penyempurnaan B-CHAT-06C.1.1 mempertajam protokol teknis masing-masing eksperimen:

```
+-------------------------------------------------------------------------------------------------------------+
|                                    PENAJAMAN PROTOKOL PENGUJIAN LIMA EKSPERIMEN                             |
+---------------+---------------------------------------------------------------------------------------------+
| ID Eksperimen | Protokol Pengujian yang Diperketat                                                          |
+---------------+---------------------------------------------------------------------------------------------+
| **EXP-01**    | - Validasi pemuatan WASM melalui konstruktor `import.meta.url` pada Webpack/Turbopack.      |
|               | - Verifikasi isolasi eksekusi murni di sisi klien (SSR-safe).                               |
|               | - Pengujian anti-replay protokol MLS berbasis aturan epoch & generation RFC 9420.           |
|               | - Evaluasi batas keamanan penyimpanan CryptoKey di IndexedDB terhadap risiko XSS.           |
+---------------+---------------------------------------------------------------------------------------------+
| **EXP-02**    | - Validasi serialisasi biner AAD 83-byte kanonikal dengan UUID 16-byte raw bytes.           |
|               | - Verifikasi field PlaintextLength: $1.048.576$ B (chunk 1-9) dan $562.816$ B (chunk 10).   |
|               | - Uji verifikasi batas TotalChunks: 1..10 per berkas naskah 10 MB.                         |
+---------------+---------------------------------------------------------------------------------------------+
| **EXP-03**    | - Evaluasi komparatif Pendekatan A (MLS Exporter) vs Pendekatan B (CSPRNG via Payload)     |
|               |   berdasarkan 8 kriteria objektif tanpa penetapan pemenang apriori.                         |
|               | - Pengujian keunikan konteks exporter dan domain separation (Pendekatan A).                 |
|               | - Pengujian keunikan CEK per lampiran dan pencegahan reuse (Pendekatan B).                  |
|               | - Evaluasi pemulihan lampiran historis paska rotasi epoch secara setara.                    |
+---------------+---------------------------------------------------------------------------------------------+
| **EXP-04**    | - Validasi alur kanonikal 10 tahap Transactional Outbox (DB commit mendahului broadcast).   |
|               | - Pengujian kegagalan simulatir: server crash paska commit, retry dispatcher, dan lost ACK. |
|               | - Uji deduplikasi event di sisi klien penerima berdasarkan sequence_cursor.                 |
|               | - Pengujian penolakan duplikasi aplikasi via `UNIQUE(room_id, client_message_uuid)`.        |
+---------------+---------------------------------------------------------------------------------------------+
| **EXP-05**    | - Evaluasi komparatif 4 skenario rotasi epoch: E1 (Event-driven), E2 (Hybrid 25 pesan),     |
|               |   E3 (Hybrid 50 pesan), dan E4 (Hybrid 100 pesan / Idle timeout 7 hari).                    |
|               | - Pencatatan parameter lingkungan pengujian lengkap dan analisis sebaran statistik sampel.  |
+---------------+---------------------------------------------------------------------------------------------+
```

---

## 7. DAFTAR KETIDAKPASTIAN YANG BELUM TERVERIFIKASI

Sesuai batas operasi *strictly read-only*, aspek-aspek berikut diklasifikasikan secara formal sebagai **belum terverifikasi** dan menjadi subjek pembuktian empiris pada Gerbang 2:
1. **[BELUM TERVERIFIKASI] Kompatibilitas Pola URL Worker pada Turbopack Next.js 14:** Apakah pola `new URL('./mls.worker.js', import.meta.url)` dapat di-bundle secara mulus oleh Turbopack tanpa konfigurasi kustom tambahan.
2. **[BELUM TERVERIFIKASI] Stabilitas Ekspor API Exporter pada Pustaka Rust-WASM:** Apakah binding TypeScript dari `OpenMLS` atau `mls-rs` mengekspos primitif `MLS-Exporter` secara stabil untuk integrasi Pendekatan A.
3. **[BELUM TERVERIFIKASI] Konsistensi Pengukuran Memori Worker Antar-Browser:** Sejauh mana API peramban (misal Chrome Performance Memory vs Safari Web Inspector) dapat mengukur alokasi heap worker secara presisi dan terisolasi.
4. **[BELUM TERVERIFIKASI] Durasi UI Main-Thread Long Task Nyata:** Apakah transfer data biner chunk 1 MiB dari Web Worker ke Main Thread memicu *Long Task* ($> 50$ ms) pada perangkat mobile berspesifikasi rendah.

---

## 8. DAFTAR HAL YANG MEMBUTUHKAN PERSETUJUAN PEMILIK PROYEK

Hal-hal berikut ditegaskan berada di luar wewenang teknis agen dan **memerlukan keputusan formal Pemilik Proyek**:
1. **[MEMERLUKAN KEPUTUSAN PEMILIK PROYEK] Pemilihan Pendekatan Kunci Lampiran Produksi (OD-17):** Menetapkan pilihan final antara Pendekatan A (MLS Exporter) atau Pendekatan B (CSPRNG Terdistribusi) untuk fase produksi, yang akan diajukan **setelah hasil pengujian komparatif Gerbang 2 selesai dievaluasi**.
2. **[MEMERLUKAN KEPUTUSAN PEMILIK PROYEK] Pengesahan Gerbang 1:** Menyetujui dokumen perencanaan B-CHAT-06C beserta revisi B-CHAT-06C.1 dan B-CHAT-06C.1.1 sebagai cetak biru resmi perencanaan prototipe.
3. **[MEMERLUKAN KEPUTUSAN PEMILIK PROYEK] Penerbitan Mandat Eksekusi Gerbang 2:** Memberikan izin resmi bagi tim kerja untuk menyiapkan lingkungan terisolasi (`scratch/`) dan mengeksekusi pengujian empiris EXP-01 sampai EXP-05.
4. **[USULAN EKSPERIMEN] Konfirmasi Ambang Tambahan Kinerja:** Menyetujui atau menyesuaikan usulan ambang batas kinerja tambahan (WASM uncompressed $> 5$ MB dan batas timeout inisialisasi $> 1.500$ ms) untuk pengujian Gerbang 2.

---

## 9. KESIMPULAN REKOMENDASI KELAYAKAN GERBANG 1

Berdasarkan penyelesaian menyeluruh atas tujuh temuan teknis:
- Seluruh ketidakkonsistenan arsitektural dan ambiguitas layout data telah direkonsiliasi secara presisi.
- Keputusan Pemilik Proyek terkait OD-01, OD-02, dan OD-17 dipertahankan secara utuh.
- Batasan strictly read-only dipatuhi tanpa manipulasi kode atau eksekusi runtime sistem.
- Dokumen perencanaan telah memenuhi seluruh kriteria penerimaan teknis.

### Rekomendasi Status:
$$\mathbf{LAYAK\ DIAJUKAN\ UNTUK\ PERSETUJUAN\ GERBANG\ 1\ —\ MENUNGGU\ PENGESAHAN\ PEMILIK\ PROYEK}$$

---

## 10. PERNYATAAN BATAS AUDIT DAN INTEGRITAS SISTEM

Dengan ini ditegaskan secara transparan bahwa selama penyusunan dokumen B-CHAT-06C.1.1:
1. **Zero Application Modification:** Tidak ada kode backend (`manajemen_skripsi/`), kode frontend (`frontend_manajemen_skripsi/`), berkas konfigurasi, maupun environment yang dimodifikasi atau dibuat.
2. **Zero Database Execution:** Tidak ada migrasi basis data, perubahan struktur tabel, maupun eksekusi kueri manipulasi data yang dijalankan.
3. **Zero Dependency Changes:** Tidak ada pustaka atau dependensi baru yang dipasang pada `composer.json` maupun `package.json`.
4. **Zero Runtime / Prototype Execution:** Tidak ada pengujian unit, benchmark performa, build produksi, atau pembuatan direktori scratch prototipe yang dijalankan. Eksperimen EXP-01 sampai EXP-05 **belum dieksekusi**.
5. **Zero Git Operations:** Tidak ada perintah Git (commit, push, checkout, stash, merge, atau rebase) yang dijalankan.
6. **Kepatuhan Protokol Dua Gerbang:** Pekerjaan pada turn ini **berhenti sepenuhnya pada Gerbang 1**. Tim kerja **tidak akan melangkah ke eksekusi prototipe (Gerbang 2)** sebelum diterbitkannya persetujuan resmi dan mandat tertulis dari Pemilik Proyek.

---
*(Dokumen B-CHAT-06C.1.1 selesai disusun secara paripurna).*

