# B-CHAT-06C.1.2 — CATATAN FINAL PERENCANAAN GERBANG 1
## KLARIFIKASI TEKNIS INDEKS CHUNK, VALIDASI PANJANG BERKAS, DAN IDLE TIMEOUT

**Status Operasi:** STRICTLY READ-ONLY — PLAN ONLY — ZERO EXECUTION  
**Proyek:** Sistem Informasi Manajemen Skripsi UNUHA  
**Fitur:** Bimbingan Online Chat Real-Time E2EE  
**Tahap:** B-CHAT-06C.1.2 (Adendum Final Perencanaan Gerbang 1)  
**Dokumen Acuan Utama:**  
1. Dokumen Perencanaan B-CHAT-06C (Rencana Prototipe Komparatif dan Validasi Teknis)  
2. Laporan Koreksi B-CHAT-06C.1 (Penyempurnaan Rencana Prototipe Komparatif)  
3. Laporan Koreksi B-CHAT-06C.1.1 (Koreksi Final Terbatas Gerbang 1)  
**Dokumen Fondasi:** [B-CHAT-01](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-01_Spesifikasi_Kebutuhan_Fitur_Bimbingan_Online.md), [B-CHAT-04](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-04_Rancangan_Teknis.md), [B-CHAT-05](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-05_Spesifikasi_Final_E2EE.md), [B-CHAT-05A](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-05A_Rekonsiliasi_Temuan_Audit.md), dan Keputusan Pemilik Proyek tanggal 5 November 2026.  
**Tanggal Evaluasi:** 5 November 2026  

---

## 1. IDENTITAS DOKUMEN DAN STATUS OPERASI

Dokumen ini disusun sebagai adendum klarifikasi final untuk melengkapi berkas perencanaan **B-CHAT-06C**, **B-CHAT-06C.1**, dan **B-CHAT-06C.1.1**. Fokus dokumen ini terbatas pada penegasan tiga aturan operasional teknis agar tidak menyisakan ambiguitas sedikit pun saat memasuki pengujian prototipe.

### Batasan Operasi Wajib:
- **Strictly Read-Only — Plan Only — Zero Execution:** Tidak ada kode aplikasi yang diubah atau dibuat.
- **Tanpa Perubahan Sistem:** Tidak ada migrasi basis data, perubahan konfigurasi, pemasangan dependensi baru, eksekusi build, benchmark, maupun pengujian unit.
- **Tanpa Operasi Git:** Status Git working tree tetap tidak disentuh (tidak ada commit, push, stash, atau checkout).
- **Tanpa Prototipe Mandiri:** Direktori scratch prototipe tidak dibuat. Eksperimen EXP-01 sampai EXP-05 **belum dijalankan**.
- **Pemisahan Protokol Gerbang:** Dokumen ini murni menyempurnakan bahan peninjauan **Gerbang 1 (Persetujuan Rencana)**. Eksekusi pengujian prototipe tetap memerlukan mandat terpisah pada **Gerbang 2 (Mandat Eksekusi)**.

---

## 2. REFERENSI DOKUMEN PERENCANAAN SEBELUMNYA

Dokumen ini melengkapi dan menyempurnakan:
1. **B-CHAT-06C:** Rencana prototipe komparatif yang memuat 5 eksperimen inti (EXP-01 s.d. EXP-05) dan dua gerbang persetujuan.
2. **B-CHAT-06C.1:** Laporan koreksi yang merekonsiliasi status pustaka MLS, Web Worker context, objektivitas komparasi OD-17, alur Transactional Outbox, layout biner AAD 83-byte, dan ambang batas circuit breaker.
3. **B-CHAT-06C.1.1:** Laporan koreksi final yang menyelaraskan pola URL Worker Next.js, pembatasan klaim penyimpanan CryptoKey di IndexedDB terhadap XSS, jaminan at-least-once delivery outbox, pemisahan 3 lapisan anti-replay, batas 1..10 TotalChunks per berkas, dan restorasi skenario rotasi idle 7 hari.

---

## 3. TIGA KLARIFIKASI TEKNIS FINAL

Tiga klarifikasi teknis berikut ditetapkan secara mengikat untuk memandu penyusunan prototipe dan spesifikasi implementasi:

```
+-------------------------------------------------------------------------------------------------------------+
|                                    TIGA KLARIFIKASI TEKNIS PERENCANAAN GERBANG 1                            |
+----+----------------------------+---------------------------------------------------------------------------+
| No | Parameter Klarifikasi      | Aturan Operasional Teknis yang Ditetapkan                                 |
+----+----------------------------+---------------------------------------------------------------------------+
| 1  | Indeks Chunk Kriptografis  | Zero-based indexing secara konsisten: chunk_index dimulai dari 0 s.d. 9.  |
|    |                            | UI boleh menampilkan "Chunk 1", tetapi Nonce dan AAD wajib 0-based uint32.|
+----+----------------------------+---------------------------------------------------------------------------+
| 2  | Validasi Panjang Plaintext | Berkas kosong (0 byte) ditolak mutlak. Chunk non-terminal = 1.048.576 B,  |
|    |                            | chunk terminal = sisa ukuran berkas. TotalChunks = ceil(Ukuran / 1 MiB).  |
+----+----------------------------+---------------------------------------------------------------------------+
| 3  | Definisi Idle Timeout MLS  | 7 hari dihitung dari ketiadaan pesan baru tersimpan dalam ruang chat       |
|    |                            | (referensi konseptual timestamp), BUKAN idle sesi web/browser pengguna.   |
+----+----------------------------+---------------------------------------------------------------------------+
```

---

### 3.1. Klarifikasi 1 — Penyeragaman Indeks Chunk Berbasis Nol (Zero-Based Indexing)

Untuk mencegah diskrepansi antara representasi lapisan aplikasi, serialisasi AAD, dan pembentukan nonce AES-256-GCM:

1. **Penetapan Indeks Kriptografis:**
   Nilai integer logis `chunk_index` **secara mutlak menggunakan penomoran berbasis nol (*zero-based indexing*)**:
   - Chunk pertama: $\mathbf{\text{chunk\_index} = 0}$
   - Chunk kedua: $\mathbf{\text{chunk\_index} = 1}$
   - Chunk ketiga: $\mathbf{\text{chunk\_index} = 2}$
   - ...
   - Chunk kesepuluh (maksimal untuk berkas 10 MB): $\mathbf{\text{chunk\_index} = 9}$
   - Secara umum, untuk berkas dengan $N$ chunk: $\mathbf{0 \le \text{chunk\_index} \le N - 1}$.
2. **Pemisahan Tampilan Pengguna (User Interface):**
   - Pada antarmuka pengguna (UI/UX), label indikator proses unggah/unduh diperbolehkan menggunakan penomoran berbasis satu (*one-based indexing*) demi kenyamanan membaca manusia (misalnya: *"Mengunggah bagian 1 dari 10 bagian"*).
   - Namun, pada level biner internal, pemanggilan fungsi Web Crypto API, pembentukan **Nonce 96-bit** (`0000000000000000 || uint32be(chunk_index)`), dan pengisian **Field ke-8 AAD** (`Chunk Index`), nilai yang diinjeksi **wajib berbasis nol**.

---

### 3.2. Klarifikasi 2 — Validasi Panjang Plaintext Aktual dan Penolakan Berkas Kosong

Aturan validasi ukuran chunk dan berkas lampiran diselaraskan secara matematis terhadap ukuran aktual masing-masing berkas yang diunggah:

1. **Penolakan Berkas Kosong (Zero-Byte File Rejection):**
   - Sistem menetapkan aturan eksplisit: **berkas lampiran berukuran 0 byte ditolak secara mutlak** ($S > 0$), kecuali keputusan desain berikutnya menyatakan sebaliknya. Klien dilarang memproses berkas kosong dan server wajib menolak inisialisasi sesi unggah untuk berkas 0 byte.
2. **Formula Pembagian Chunk dan Ukuran Chunk Terakhir:**
   Untuk setiap berkas dengan ukuran aktual $S$ byte ($1 \le S \le 10.000.000$ byte) dan ukuran chunk standar $C = 1.048.576$ byte (1 MiB):
   - **Jumlah Chunk ($N$):**
     $$N = \left\lceil \frac{S}{C} \right\rceil\quad (1 \le N \le 10)$$
   - **Ukuran Plaintext Chunk Non-Terminal (Indeks $0 \le i < N - 1$):**
     Masing-masing tepat berukuran penuh: $\mathbf{1.048.576\text{ Byte}}$ ($C$).
   - **Ukuran Plaintext Chunk Terakhir ($L_{\text{terakhir}}$, Indeks $i = N - 1$):**
     Dihitung berdasarkan rumus matematis:
     $$L_{\text{terakhir}} = \begin{cases} S \bmod C, & \text{jika } S \bmod C \ne 0 \\ C, & \text{jika } S \bmod C = 0 \end{cases}$$
3. **Tabel Contoh Validasi Ukuran Chunk:**

| Ukuran Berkas ($S$) | Jumlah Chunk ($N$) | Ukuran Chunk Terakhir ($L_{\text{terakhir}}$) |
|---:|---:|---:|
| 1.048.576 byte | 1 | 1.048.576 byte |
| 2.097.152 byte | 2 | 1.048.576 byte |
| 10.000.000 byte | 10 | 562.816 byte |

4. **Validasi Field `PlaintextLength` pada AAD 83-Byte:**
   - Field ke-10 pada buffer AAD wajib mencatat ukuran plaintext chunk aktual yang sedang diproses ($1 \le \text{PlaintextLength} \le 1.048.576$).
   - Penerima wajib mencocokkan bahwa akumulasi panjang seluruh chunk tepat sama dengan `total_file_size` yang tercantum di dalam Manifest terenkripsi.
5. **Batas Gabungan Pesan Tetap Utuh:**
   - Maksimal 10.000.000 byte per berkas naskah (maksimal 10 chunk per berkas).
   - Maksimal 5 berkas per pesan.
   - Maksimal 25.000.000 byte gabungan seluruh lampiran per pesan (maksimal teoritis 28 chunk gabungan akibat partisi pecahan).

---

### 3.3. Klarifikasi 3 — Definisi Operasional Idle Timeout Ruang Obrolan MLS

Untuk menghindari kerancuan dalam perancangan eksperimen **EXP-05 (Strategi Rotasi Epoch)**:

1. **Definisi Idle Timeout 7 Hari pada Skenario E4:**
   - Kondisi *idle timeout* 7 hari **secara mutlak dihitung berdasarkan tidak adanya pesan baru yang berhasil tersimpan di dalam ruang percakapan bimbingan privat terkait** (*Room Message Inactivity*).
   - Kondisi idle **bukan berarti pengguna tidak aktif membuka halaman portal web atau tidak aktif pada sesi browser**. Pengguna dapat aktif berselancar di sistem informasi, namun jika tidak ada pesan baru yang dikirim dalam ruang chat tersebut, ruang tetap dikategorikan *idle*.
2. **Titik Awal dan Referensi Timestamp Konseptual:**
   - Titik awal perhitungan adalah stempel waktu (*timestamp*) dari pesan terakhir yang berhasil tersimpan secara sah di basis data.
   - Penyebutan nama kolom seperti `chat_rooms.last_message_at` dalam dokumen perencanaan **hanyalah contoh nama referensi konseptual**, bukan klaim bahwa tabel, kolom, atau mekanisme pembaruannya sudah tersedia di basis data saat ini.
   - Keberadaan tabel, kolom, dan mekanisme pembaruan timestamp aktual belum diverifikasi pada basis data operasional dan wajib diperiksa/dirancang pada tahap implementasi yang telah memperoleh izin resmi.
   - Jika selisih antara waktu saat ini ($\text{Time}_{\text{now}}$) dan timestamp pesan terakhir telah melampaui $7 \times 24 \times 3600$ detik ($604.800$ detik), ruang tersebut memenuhi kriteria *idle* untuk memicu penerbitan MLS `Commit` pembaruan epoch kunci.
3. **Keterujian dalam Prototipe (EXP-05):**
   - Pada pengujian prototipe laboratorium, interval waktu 7 hari kalender disimulasikan secara deterministik melalui manipulasi *virtual clock* atau injeksi stempel waktu basis data masa lampau (`mocked timestamp`) untuk memverifikasi apakah transisi epoch terpicu secara tertib tanpa memicu desinkronisasi perangkat.

---

## 4. PENEGASAN INTEGRITAS KEPUTUSAN DESAIN YANG TELAH SAH

Ketiga klarifikasi teknis di atas murni merupakan penajaman aturan operasional dan **sama sekali tidak mengubah atau membatalkan keputusan yang telah disetujui Pemilik Proyek**:

1. **Keputusan OD-01 Tetap Utuh:** Rust-WASM tetap menjadi kandidat utama evaluasi MLS dengan antarmuka TypeScript; Web Crypto API tetap menjadi penyedia fungsi kriptografi simetris (AES-256-GCM).
2. **Keputusan OD-02 Tetap Utuh:** Batas lampiran tetap menggunakan satuan desimal ($10.000.000$ byte per berkas, $25.000.000$ byte gabungan per pesan, maksimal 5 berkas, dan ukuran chunk kandidat 1 MiB).
3. **Keputusan OD-17 Tetap Utuh:** Pendekatan A (MLS Exporter) dan Pendekatan B (CSPRNG via Payload) tetap dievaluasi secara objektif dan sejajar tanpa ada pemenang apriori.
4. **Model Fungsional [B-CHAT-01](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-01_Spesifikasi_Kebutuhan_Fitur_Bimbingan_Online.md) Tetap Utuh:**
   - Model ruang privat 1:1 (mahasiswa dan pembimbing) dipertahankan.
   - Pemisahan ruang skripsi dua pembimbing dipertahankan.
   - Ketiadaan hak akses isi chat bagi Admin dan Kaprodi dipertahankan.
   - Format Recovery Key sebagai kode acak panjang yang ditampilkan satu kali dipertahankan (bukan BIP-39).

---

## 5. DAFTAR HAL YANG MENUNGGU PEMBUKTIAN EMPIRIS GERBANG 2

Aspek-aspek berikut tetap diklasifikasikan sebagai agenda pembuktian teknis yang akan diuji begitu mandat eksekusi Gerbang 2 diterbitkan:

1. **EXP-01:** Verifikasi kompatibilitas bundler Webpack/Turbopack Next.js 14 terhadap pemuatan modul WASM Rust di Web Worker menggunakan pola `import.meta.url`.
2. **EXP-02:** Pengukuran latensi pemecahan berkas dan verifikasi AAD 83-byte pada naskah skripsi PDF/DOCX aktual di peramban desktop dan ponsel.
3. **EXP-03:** Pengumpulan data empiris (latensi dan konsumsi memori) untuk menentukan rekomendasi akhir antara Pendekatan A dan Pendekatan B.
4. **EXP-04:** Uji konkurensi alur transaksi 10 tahap basis data MySQL 8 dan deduplikasi pesan pada kondisi koneksi putus (*lost ACK*).
5. **EXP-05:** Pengujian komparatif beban ratchet tree pada skenario E1 s.d. E4 dengan simulasi *virtual clock* untuk kondisi idle 7 hari.

---

## 6. STATUS REKOMENDASI KELAYAKAN DOKUMEN

Dengan tuntasnya penyusunan adendum B-CHAT-06C.1.2:
- Dokumen perencanaan B-CHAT-06C beserta koreksi B-CHAT-06C.1 dan B-CHAT-06C.1.1 kini telah memiliki konsistensi teknis yang solid, presisi, dan bebas dari pertentangan terminologi.
- Seluruh ambiguitas matematis dan operasional telah dieliminasi.

### Status Rekomendasi:
$$\mathbf{LAYAK\ DIAJUKAN\ UNTUK\ PENGESAHAN\ GERBANG\ 1\ —\ MENUNGGU\ PENGESAHAN\ PEMILIK\ PROYEK}$$

*(Catatan: Status ini merupakan rekomendasi teknis formal dari tim kerja. Gerbang 1 belum dianggap sah sampai Pemilik Proyek memberikan konfirmasi pengesahan tertulis).*

---

## 7. PERNYATAAN BATAS AUDIT DAN INTEGRITAS SISTEM

Dengan ini ditegaskan secara transparan bahwa selama penyusunan dokumen B-CHAT-06C.1.2:
1. **Zero Code Modification:** Tidak ada satu pun baris kode backend (`manajemen_skripsi/`) maupun frontend (`frontend_manajemen_skripsi/`) yang disentuh atau dimodifikasi.
2. **Zero Database Changes:** Tidak ada migrasi basis data, pembuatan tabel, atau perubahan skema relasional yang dijalankan.
3. **Zero Dependency Changes:** Tidak ada pustaka baru yang dipasang pada `package.json` maupun `composer.json`.
4. **Zero Prototype Execution:** Direktori scratch prototipe tidak dibuat. Eksperimen EXP-01 sampai EXP-05 **tidak dijalankan**.
5. **Zero Git Operations:** Tidak ada perintah Git (commit, push, checkout, stash, atau merge) yang dieksekusi. Repositori tetap dalam kondisi bersih.
6. **Kepatuhan Protokol Dua Gerbang:** Tim kerja **berhenti sepenuhnya pada Gerbang 1** dan **dilarang melanjutkan ke Gerbang 2** tanpa adanya mandat eksekusi terpisah yang diterbitkan secara resmi oleh Pemilik Proyek.

---
*(Dokumen B-CHAT-06C.1.2 selesai disusun secara paripurna).*

