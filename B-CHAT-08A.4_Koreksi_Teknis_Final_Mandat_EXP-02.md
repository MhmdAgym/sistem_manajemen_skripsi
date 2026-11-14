# B-CHAT-08A.4 — KOREKSI TEKNIS FINAL MANDAT EXP-02
## REKONSILIASI CEK COLLISION, NODE.JS MEMORY METRICS, VALIDASI INDEKS, DAN ERROR CLASSIFICATION

**Status Dokumen:** DOKUMEN KOREKSI TEKNIS FINAL (*FINAL TECHNICAL PRECISION AMENDMENT*)  
**Mode Operasi:** STRICTLY READ-ONLY TERHADAP IMPLEMENTASI — DOCUMENTATION ONLY  
**Status Otorisasi EXP-02:** **EXP-02 NOT AUTHORIZED** *(Eksperimen EXP-02 belum diotorisasi)*  
**Dokumen Sumber yang Dikoreksi:** [B-CHAT-08A.3_Koreksi_Presisi_Final_Mandat_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.3_Koreksi_Presisi_Final_Mandat_EXP-02.md)  
**Dokumen Acuan Historis:**
- [B-CHAT-08A.2_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.2_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md)
- [B-CHAT-08A.1_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.1_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md)
- [B-CHAT-08A_Rencana_dan_Mandat_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A_Rencana_dan_Mandat_EXP-02.md)
- [B-CHAT-06D_Mandat_Eksekusi_Terbatas_Gerbang_2.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-06D_Mandat_Eksekusi_Terbatas_Gerbang_2.md) (Revisi B-CHAT-06D.2 §3.2)
- [B-CHAT-05_Spesifikasi_Final_E2EE.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-05_Spesifikasi_Final_E2EE.md) (Keputusan Desain OD-02 & OD-17)
- Rangkaian Dokumen Forensik EXP-01: [B-CHAT-07A.1](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A_Laporan_Eksekusi_EXP-01.md) s.d. [B-CHAT-07A.7](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.7_Koreksi_Editorial_dan_Klasifikasi_Bukti_Final_EXP-01.md)  
**Proyek:** Sistem Informasi Manajemen Skripsi UNUHA  
**Fitur:** Bimbingan Online Chat Real-Time E2EE  
**Tahap:** Finalisasi Perencanaan Mandat Gerbang 2 (EXP-02)  
**Tanggal:** 5 November 2026  

---

> [!CAUTION]
> **PENEGASAN STATUS OPERASIONAL MUTLAK:**  
> Dokumen **B-CHAT-08A.4** ini berstatus **STRICTLY DOCUMENTATION ONLY — ZERO EXECUTION — EXP-02 NOT AUTHORIZED**. Penerbitan dokumen ini **BUKAN** merupakan izin atau otorisasi untuk memulai eksekusi fisik prototipe EXP-02 di sandbox. Persetujuan Gerbang 2 sebelumnya tidak memberikan kewenangan otomatis untuk menjalankan EXP-02. Pelaksanaan fisik di sandbox hanya dapat dimulai setelah Pemilik Proyek memeriksa dokumen koreksi teknis final ini dan memberikan persetujuan tertulis eksplisit terpisah. Selama penyusunan dokumen ini, tidak ada kode sumber yang dibuat, tidak ada direktori sandbox yang dibuat, tidak ada dependensi atau toolchain yang dipasang, tidak ada benchmark/pengujian yang dijalankan, dan tidak ada operasi Git yang dilakukan. Seluruh dokumen historis ([B-CHAT-08A](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A_Rencana_dan_Mandat_EXP-02.md), [B-CHAT-08A.1](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.1_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md), [B-CHAT-08A.2](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.2_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md), dan [B-CHAT-08A.3](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.3_Koreksi_Presisi_Final_Mandat_EXP-02.md)) tetap dipertahankan utuh di repositori tanpa diubah atau ditimpa (*zero overwrite*).

---

## 1. IDENTITAS, TUJUAN, DAN BATAS MANDAT OPERASIONAL

Dokumen **B-CHAT-08A.4** diterbitkan sebagai amendemen presisi teknis final (*final technical precision amendment*) terhadap dokumen [B-CHAT-08A.3_Koreksi_Presisi_Final_Mandat_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.3_Koreksi_Presisi_Final_Mandat_EXP-02.md). Dokumen ini mempertahankan seluruh isi dan keputusan desain B-CHAT-08A.3 yang sudah benar, serta menyempurnakan enam ketidakakuratan teknis dan tiga aspek penyelarasan redaksional yang ditemukan pada peninjauan akhir Pemilik Proyek.

### Ruang Lingkup Koreksi Teknis B-CHAT-08A.4:
1. **Koreksi Klaim Independensi CEK (§3.A):** Meluruskan premis Pilar 1 agar tidak mengasumsikan dua kunci acak pasti berbeda secara matematis; menegaskan bahwa tabrakan salt tidak memicu *nonce reuse* apabila kedua sesi memang menggunakan CEK yang berbeda; mempertahankan formula probabilitas collision; dan menegaskan bahwa jaminan keamanan AES-GCM bertumpu pada pelarangan penggunaan ulang pasangan $(\text{CEK}, \text{Nonce})$.
2. **Definisi Presisi `arrayBuffers` (§3.B):** Menegaskan bahwa `arrayBuffers` adalah ukuran memori *backing store* `ArrayBuffer` dan `SharedArrayBuffer` (termasuk alokasi Node.js `Buffer`), sedangkan `TypedArray` seperti `Uint8Array` adalah *view* terhadap backing store; bukan ukuran seluruh memori kriptografi; dan tidak menjamin seluruh alokasi internal native Web Crypto tercatat.
3. **Koreksi Batas Cakupan `external` (§3.C):** Menghapus klaim bahwa `external` mencakup seluruh alokasi internal Web Crypto/OpenSSL; menyatakan secara akurat bahwa `external` melaporkan memori C++ native yang terikat pada objek JavaScript menurut mekanisme pelaporan Node.js; dan melarang keras penjumlahan redundan $\text{external} + \text{arrayBuffers}$.
4. **Generalisasi Formula Validasi Indeks UJI-D10B (§3.D):** Mengganti batasan spesifik $\ge 10$ menjadi formula matematis umum $\text{chunk\_index} \ge N$ dengan $N = \lceil S / C \rceil$; indeks valid didefinisikan secara presisi sebagai $0 \le \text{chunk\_index} < N$; serta memperjelas perilaku untuk berbagai ukuran file (misalnya $N = 3$ dan $N = 10$).
5. **Koreksi Daftar Pesan Pengecualian `OperationError` (§3.E):** Menghapus seluruh daftar contoh teks pesan exception spesifik vendor/runtime; menegaskan bahwa pengujian hanya memeriksa tipe pengecualian `OperationError` dan bukan pencocokan string pesan; serta atribusi penyebab kegagalan murni berasal dari skenario pengujian (*test setup*).
6. **Kelengkapan Batasan Pengukuran Memori Node.js (§3.F):** Mendokumentasikan secara transparan bahwa $\Delta\text{arrayBuffers}$ dan $\Delta\text{external}$ tidak mencakup seluruh memori native OpenSSL; snapshot `process.memoryUsage()` memiliki keterbatasan sampling periodik yang dapat melewatkan lonjakan singkat (*transient memory spikes*); ambang $20\text{ MB}$ tetap berstatus `PROPOSED`; dan pengukuran Node.js bukan bukti konsumsi memori peramban web.
7. **Tiga Penyempurnaan Kecil (§4):** Menstandarkan nama galat konseptual `TypeRangeValidationError` (UJI-D10A) dan `ChunkIndexOutOfRangeError` (UJI-D10B) beserta catatan statusnya sebagai label konseptual rancangan pengujian; serta merapikan baris matriks UJI-F1 agar notasi $\Delta\text{arrayBuffers}$ dan metrik pendukung tertulis lengkap tanpa teks terpotong.

---

## 2. SPESIFIKASI TEKNIS KANONIKAL YANG TETAP BERLAKU

Seluruh parameter spesifikasi kanonikal yang telah disahkan pada Gerbang 1, Gerbang 2, dan disempurnakan pada B-CHAT-08A.2 serta B-CHAT-08A.3 dipertahankan secara utuh tanpa perubahan:

```
+-------------------------------------------------------------------------------------------------------------+
|                                    SPESIFIKASI KANONIKAL YANG TETAP BERLAKU                                 |
+--------------------------+----------------------------------------------------------------------------------+
| Parameter                | Nilai Resmi yang Wajib Dipatuhi                                                  |
+--------------------------+----------------------------------------------------------------------------------+
| Kuota Berkas Maksimum    | Maksimum 10.000.000 byte (desimal, kuota 10 MB per file)                         |
| Kuota Pesan Gabungan     | Maksimum 25.000.000 byte (desimal, kuota total 25 MB per pesan chat)             |
| Batas Jumlah Berkas      | Maksimum 5 berkas per pesan chat tunggal                                         |
| Ukuran Chunk Standar     | Tepat 1.048.576 byte (1 MiB biner, 2^20 byte; kecuali chunk terakhir)            |
| Kunci Simetris (CEK)     | AES-256 (256-bit / 32 byte), dibangkitkan per sesi unggah via CSPRNG             |
| Nonce / IV AES-GCM       | 96-bit (12 byte): UploadSessionSalt (8 B) || uint32be(chunk_index) (4 B)         |
| Tag Autentikasi AES-GCM  | 128-bit (16 byte), di luar kuota plaintext                                       |
| Total Ukuran AAD         | Tepat 83 byte (10 field kanonikal terstruktur)                                   |
| Konteks MLS Exporter     | Tepat 48 byte (RoomUUID || MessageUUID || AttachmentUUID, biner mentah)          |
| Format Framing Simpan    | Format A: Nonce (12 B) || Ciphertext (L_i B) || AuthTag (16 B) = (L_i + 28 B)    |
| Formula Jumlah Chunk     | N = ceil(S / C), berkas 0 byte ditolak mutlak (1 <= N <= 10)                     |
| Formula Chunk Terakhir   | L_last = S mod C (jika S mod C != 0), atau C (jika S mod C == 0)                 |
+--------------------------+----------------------------------------------------------------------------------+
```

### Layout Struktur Kanonikal AAD 83-Byte
1. `Magic Header`: 4 byte ASCII `UNH1` (`0x55 0x4E 0x48 0x31`)
2. `Protocol Version`: 2 byte `uint16be` (`0x0001`)
3. `Domain Separator`: 1 byte `uint8` (`0x02` = ATTACHMENT_CHUNK)
4. `Room UUID`: 16 byte (`raw 16-byte UUID binary representation`)
5. `Message UUID`: 16 byte (`raw 16-byte UUID binary representation`)
6. `Attachment UUID`: 16 byte (`raw 16-byte UUID binary representation`)
7. `Upload Session ID`: 16 byte (`raw 16-byte UUID binary representation`)
8. `Chunk Index`: 4 byte `uint32be` (zero-based: $0 \le \text{chunk\_index} < N$)
9. `Total Chunks`: 4 byte `uint32be` ($1 \le N \le 10$)
10. `Plaintext Length`: 4 byte `uint32be` ($1 \le L_i \le 1.048.576$)
**Total Panjang: Tepat 83 byte.**

---

## 3. ENAM KOREKSI TEKNIS WAJIB (A–F)

### Koreksi A — Koreksi Klaim Independensi CEK dan Probabilitas Tabrakan Kunci (§3.B)

1. **Prinsip Independensi Pembangkitan Kunci:**  
   Setiap sesi unggah lampiran membangkitkan Kunci Enkripsi Konten (*Content Encryption Key* / CEK) baru secara independen melalui generator bilangan acak semu kriptografis (*Cryptographically Secure Pseudo-Random Number Generator* / CSPRNG).
2. **Probabilitas Tabrakan Matematis:**  
   Probabilitas teoretis tabrakan kunci acak 256-bit independen adalah:
   $$P_{\text{key\_collision}} = 2^{-256} \approx 8{,}636 \times 10^{-78}$$
   Untuk kumpulan $m$ sesi unggah independen, batas probabilitas tabrakan kunci mengikuti *birthday bound*:
   $$P_{\text{collision}}(m) \approx \frac{m^2}{2 \times 2^{256}} = \frac{m^2}{2^{257}}$$
   Nilai ini teramat kecil (*negligible*) dalam skala praktis komputasi, namun **secara matematis tidak bernilai nol mutlak**. Oleh karena itu, independensi proses pembangkitan kunci **dilarang diklaim sebagai bukti matematis bahwa dua kunci acak pasti berbeda**.
3. **Formula Birthday Bound Salt 64-Bit:**  
   Untuk $m$ sesi unggah dengan `UploadSessionSalt` acak 64-bit independen via CSPRNG, probabilitas tabrakan salt antar-sesi tetap mematuhi formula:
   $$P_{\text{salt\_collision}} \approx \frac{m^2}{2 \times 2^{64}} = \frac{m^2}{2^{65}}$$
4. **Rumusan Baku Penjelasan Keamanan Salt dan CEK:**  
   > *Tabrakan UploadSessionSalt antar-sesi tidak dengan sendirinya menyebabkan nonce reuse apabila sesi-sesi tersebut menggunakan CEK yang berbeda. Karena CEK dibangkitkan secara independen melalui CSPRNG, probabilitas collision CEK 256-bit sangat kecil, tetapi tidak nol secara matematis. Oleh sebab itu, sistem tetap wajib menghindari penggunaan ulang pasangan $(\text{CEK}, \text{Nonce})$, dan tidak boleh mendasarkan jaminan keamanan pada asumsi bahwa dua CEK acak pasti berbeda.*
5. **Prinsip Keamanan Inti AES-GCM:**  
   Keamanan cipher AES-GCM bergantung mutlak pada ketiadaan penggunaan ulang pasangan $(\text{Kunci}, \text{Nonce})$ untuk mengenkripsi dua blok data yang berbeda. Jika terjadi kegagalan proses atau crash, sesi bersangkutan wajib dibatalkan (*session abandonment*) dan dimulai ulang sebagai sesi baru yang menghasilkan tiga entitas independen baru: **CEK baru, UploadSessionID baru, dan UploadSessionSalt baru**.

---

### Koreksi B — Definisi Presisi Metrik Memori `arrayBuffers` (§3.G)

1. **Definisi Presisi Backing Store:**  
   Metrik `arrayBuffers` pada keluaran `process.memoryUsage()` Node.js secara spesifik mengukur **total alokasi memori *backing store* yang dialokasikan untuk objek `ArrayBuffer` dan `SharedArrayBuffer`**, termasuk alokasi yang dikelola oleh objek `Buffer` bawaan Node.js.
2. **Kedudukan `TypedArray` sebagai View:**  
   Objek `TypedArray` (seperti `Uint8Array` yang digunakan dalam pipeline chunking) merupakan **tampilan (*view*) di atas backing store `ArrayBuffer`**, bukan kategori alokasi memori *backing store* terpisah. Membuat beberapa `Uint8Array` baru di atas satu `ArrayBuffer` yang sama tidak menambah alokasi backing store pada metrik `arrayBuffers`.
3. **Batas Representasi Memori Kriptografi:**  
   `arrayBuffers` **bukan ukuran seluruh konsumsi memori kriptografi**. Metrik ini hanya mencerminkan buffer biner JavaScript yang dikelola V8/Node.js; metrik ini **tidak mencatat seluruh salinan data internal, konteks algoritma, atau alokasi memori native C++ yang dilakukan oleh mesin Web Crypto/OpenSSL**.
4. **Peran sebagai Metrik Primer:**  
   $$\Delta\text{arrayBuffers} = \text{arrayBuffers}_{\text{peak}} - \text{arrayBuffers}_{\text{baseline}}$$
   $\Delta\text{arrayBuffers}$ ditetapkan sebagai **metrik primer** untuk mengevaluasi efisiensi pipeline streaming chunking 1 MiB karena mencerminkan siklus alokasi dan pelepasan buffer biner berkas secara langsung di sisi JavaScript.

---

### Koreksi C — Batas Cakupan Metrik Memori `external` (§3.G)

1. **Definisi Pelaporan `external`:**  
   Metrik `external` melaporkan **memori eksternal (C++ native) yang terikat pada objek JavaScript menurut mekanisme pelaporan internal Node.js**.
2. **Ketiadaan Jaminan Cakupan Total OpenSSL:**  
   Nilai `external` **tidak menjamin bahwa seluruh alokasi internal native Web Crypto maupun OpenSSL tercakup secara komprehensif**. Alokasi memori internal di dalam pustaka native C/C++ yang tidak didaftarkan ke engine V8 melalui mekanisme pelaporan alokasi eksternal (`Isolate::AdjustAmountOfExternalAllocatedMemory`) tidak akan terefleksi pada nilai `external`.
3. **Hubungan Subkategori dan Larangan Penjumlahan Redundan:**  
   Dalam mekanisme akuntansi memori Node.js modern, **alokasi `arrayBuffers` merupakan bagian (*subset*) yang sudah tercakup di dalam angka pelaporan `external`**.  
   $$\mathbf{DILARANG\ KERAS\ MENJUMLAHKAN:\ external + arrayBuffers}$$  
   Penjumlahan keduanya merupakan kesalahan metodologi (*double counting*) yang menghasilkan angka konsumsi memori semu yang tidak valid.
4. **Peran sebagai Metrik Pendukung:**  
   $$\Delta\text{external} = \text{external}_{\text{peak}} - \text{external}_{\text{baseline}}$$  
   $\Delta\text{external}$ ditetapkan sebagai **metrik pendukung** untuk memantau apakah ada lonjakan alokasi native C++ yang terikat pada siklus hidup objek streaming.
5. **Konteks Memori Proses Keseluruhan (`rss`):**  
   Metrik `rss` (*Resident Set Size*) mencerminkan total memori fisik proses Node.js secara keseluruhan (termasuk kode program, V8 engine, C++ runtime, heap, dan thread stack). `rss` digunakan sebagai **metrik konteks proses**, bukan ukuran khusus dari buffer lampiran yang sedang diproses.

---

### Koreksi D — Generalisasi Formula Validasi Indeks UJI-D10B (§3.C & §4)

1. **Formula Batas Indeks Umum:**  
   Jumlah chunk total untuk berkas berukuran $S$ byte dengan ukuran chunk $C = 1.048.576$ byte adalah:
   $$N = \left\lceil \frac{S}{C} \right\rceil \quad (1 \le N \le 10)$$
   Rentang indeks chunk yang valid secara matematis dan logis adalah:
   $$0 \le \text{chunk\_index} < N \quad \iff \quad \text{chunk\_index} \in \{0, 1, \dots, N-1\}$$
2. **Kriteria Penolakan UJI-D10B:**  
   Skenario pengujian UJI-D10B didefinisikan secara umum untuk setiap nilai:
   $$\text{chunk\_index} \ge N$$
   Setiap permintaan pemrosesan dengan $\text{chunk\_index} \ge N$ **wajib ditolak oleh lapisan logika perakitan chunk sebelum data diserialisasikan ke dalam AAD atau dikirim ke operasi enkripsi/dekripsi Web Crypto API**.
3. **Ilustrasi Variasi Jumlah Chunk:**  
   Dilarang mengasumsikan bahwa semua berkas memiliki 10 chunk. Aturan $\text{chunk\_index} \ge N$ berlaku konsisten untuk seluruh variasi ukuran berkas:
   - Jika berkas berukuran $2{,}5\text{ MB}$ ($N = 3$), maka indeks yang sah adalah $0, 1, 2$. Nilai $\text{chunk\_index} = 3$ **wajib ditolak**.
   - Jika berkas berukuran $10\text{ MB}$ ($N = 10$), maka indeks yang sah adalah $0, 1, \dots, 9$. Nilai $\text{chunk\_index} = 10$ **wajib ditolak**.
4. **Pemisahan Tiga Skenario Pengujian Indeks:**
   - **UJI-D10A (Validasi Boundary Input Negatif):** Nilai $\text{chunk\_index} < 0$ (misalnya `-1`) ditolak pada lapisan validasi input antarmuka aplikasi sebelum serialisasi AAD (`TypeRangeValidationError`).
   - **UJI-D10B (Validasi Rentang Chunk):** Nilai $\text{chunk\_index} \ge N$ ditolak pada lapisan validasi perakitan aplikasi sebelum serialisasi AAD (`ChunkIndexOutOfRangeError`).
   - **UJI-D10C (Integritas Kriptografis AAD Dekripsi):** Modifikasi byte field `Chunk Index` pada data AAD biner saat proses dekripsi menyebabkan penolakan autentikasi tag AES-GCM melempar `OperationError`.

---

### Koreksi E — Presisi Penanganan Galat Pengecualian `OperationError` (§3.F)

1. **Peniadaan Contoh String Pesan Spesifik Vendor:**  
   Daftar contoh teks string pesan exception dari berbagai browser/runtime (seperti Node.js, Chromium, Firefox, dan Safari) pada dokumen sebelumnya **secara resmi dihapus dan tidak digantikan dengan daftar teks pesan lainnya**.
2. **Ketiadaan Jaminan Keseragaman Pesan Antar-Runtime:**  
   Standar Web Cryptography API W3C hanya menetapkan nama kelas tipe pengecualian (*DOMException name*), yaitu `OperationError`. Teks deskripsi galat (*error message string*) merupakan implementasi internal masing-masing vendor mesin JavaScript dan **tidak dijamin identik atau konsisten antar-runtime**.
3. **Metodologi Pengujian Berbasis Tipe Exception:**  
   Pada seluruh skenario pengujian integritas kriptografis (UJI-D1 s.d. UJI-D5, UJI-D9A, UJI-D10C), assertion pengujian **hanya memeriksa bahwa exception yang dilempar bertipe `OperationError`**, dan dilarang mencocokkan string pesan teks tertentu.
4. **Atribusi Penyebab Berdasarkan Test Setup:**  
   Cipher AEAD (AES-256-GCM) secara sengaja didesain untuk tidak memberikan informasi diagnostik mengenai elemen mana yang rusak (ciphertext, auth tag, IV, atau field AAD) guna mencegah serangan *oracle side-channel*. Oleh karena itu, **atribusi penyebab kegagalan murni didasarkan pada kondisi injeksi kesalahan yang sengaja disiapkan pada lingkungan pengujian (*test setup*)**, bukan dari kemampuan runtime Web Crypto API untuk mendiagnosis komponen yang dimodifikasi.

---

### Koreksi F — Batasan Pengukuran Memori dan Metodologi Sampling (§3.G, §5–7)

Untuk menjaga kejujuran metodologi ilmiah, seluruh batasan pengukuran memori Node.js didokumentasikan secara eksplisit:
1. **Batas Cakupan Metrik Native:**  
   $\Delta\text{arrayBuffers}$ dan $\Delta\text{external}$ tidak menjamin seluruh alokasi internal native Web Crypto maupun OpenSSL terukur seutuhnya.
2. **Larangan Penjumlahan Metrik:**  
   $\Delta\text{external}$ adalah metrik pendukung dan dilarang keras dijumlahkan dengan `arrayBuffers`.
3. **Keterbatasan Snapshot Sampling:**  
   Pengambilan sampel memori secara periodik menggunakan `process.memoryUsage()` memiliki keterbatasan mendasar: lonjakan alokasi memori transien (*transient memory spikes*) yang berlangsung sangat singkat (dalam orde milidetik) dapat terlewat di antara interval sampling. Dilarang mengklaim bahwa sampling periodik pasti menangkap puncak memori riil.
4. **Metodologi Pengukuran Baseline dan Peak:**  
   Jika baseline dan peak digunakan dalam pengujian, keduanya wajib didefinisikan dengan metode sampling yang konsisten:
   - $\text{Memori}_{\text{baseline}}$: Diambil sesaat sebelum pemrosesan chunk pertama dimulai (setelah *warm-up* dan stabilisasi awal).
   - $\text{Memori}_{\text{peak}}$: Nilai maksimum yang tercatat dari *polling sampling* selama siklus baca-enkripsi-tulis chunk berlangsung.
   - $\Delta\text{Metrik} = \text{Memori}_{\text{peak}} - \text{Memori}_{\text{baseline}}$.
5. **Status Ambang Batas 20 MB:**  
   Target lonjakan alokasi buffer streaming $\Delta\text{arrayBuffers} \le 20\text{ MB}$ pada berkas $10\text{ MB}$ berstatus **`PROPOSED`** (target desain yang diusulkan), bukan jaminan batas total konsumsi memori proses (`rss`).
6. **Pemisahan dari Lingkungan Browser:**  
   Hasil pengukuran memori pada proses Node.js CLI **tidak dapat digunakan sebagai bukti konsumsi memori Web Worker di peramban web nyata**, karena perbedaan arsitektur manajemen memori, overhead DOM/renderer, dan implementasi native Web Crypto antar-platform.

---

## 4. TIGA PENYEMPURNAAN KECIL (A–C)

### Penyempurnaan A — Unifikasi Nama Galat Konseptual UJI-D10A
Nama galat penolakan nilai indeks negatif pada input aplikasi distandarkan secara seragam di seluruh tabel, teks, dan matriks menggunakan satu nama konseptual tunggal:
$$\mathbf{TypeRangeValidationError}$$
> [!NOTE]
> **Catatan Status Nama Galat:**  
> Nama `TypeRangeValidationError` merupakan **label konseptual rancangan pengujian (*test design conceptual label*)**, bukan klaim bahwa kelas exception tersebut telah diimplementasikan di kode sumber produksi aplikasi bimbingan skripsi.

### Penyempurnaan B — Unifikasi Nama Galat Konseptual UJI-D10B
Nama galat penolakan nilai indeks yang melampaui batas chunk total ($\text{chunk\_index} \ge N$) distandarkan secara seragam di seluruh dokumen menggunakan satu nama konseptual tunggal:
$$\mathbf{ChunkIndexOutOfRangeError}$$
> [!NOTE]
> **Catatan Status Nama Galat:**  
> Nama `ChunkIndexOutOfRangeError` merupakan **label konseptual rancangan pengujian (*test design conceptual label*)**, bukan klaim bahwa kelas exception tersebut telah diimplementasikan di kode sumber produksi aplikasi bimbingan skripsi.

### Penyempurnaan C — Perapian Notasi dan Kolom Matriks UJI-F1
Notasi pada baris UJI-F1 dirapikan sepenuhnya:
- Frasa teks terpotong `delta arrayBuff` diperbaiki menjadi notasi formal lengkap: **`ΔarrayBuffers`**.
- Keterangan metrik pendukung ditulis secara utuh dan seimbang: **`(Δexternal sebagai metrik pendukung)`**.
- Seluruh kolom tabel diseimbangkan agar mudah dibaca dan tidak menimbulkan ambiguitas tanda kurung.

---

## 5. MATRIKS 15 SKENARIO TERDAMPAK KOREKSI PRESISI FINAL

> [!IMPORTANT]
> **KEDUDUKAN TERHADAP 32 SKENARIO KOMPREHENSIF B-CHAT-08A.1:**  
> Matriks berikut merangkum **15 skenario pengujian yang secara langsung disempurnakan oleh mandat koreksi teknis B-CHAT-08A.4** (generalisasi formula indeks $\ge N$, unifikasi nama galat konseptual, penegasan kelengkapan 3 entitas sesi pemulihan crash, pembersihan staging best-effort, dan notasi metrik memori primer $\Delta\text{arrayBuffers}$).  
> Kelimabelas skenario ini **berfungsi menyempurnakan dan memperjelas, BUKAN menggantikan atau menghapus keseluruhan 32 skenario komprehensif** yang tercantum pada dokumen [B-CHAT-08A.1](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.1_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md). Skenario-skenario pengujian lainnya pada B-CHAT-08A.1 (seperti UJI-D1 s.d. UJI-D7 untuk uji integritas field AAD lainnya) tetap berlaku penuh dalam perencanaan EXP-02.

```
+-----------------------------------------------------------------------------------------------------------------------------------------+
|                                    MATRIKS 15 SKENARIO TERDAMPAK KOREKSI TEKNIS FINAL (B-CHAT-08A.4)                                    |
+----+------------+------------+---------------------------------------+------------------------------------------------------------------+
| No | ID B-08A.1 | ID B-08A.4 | Nama Skenario Pengujian               | Deskripsi Perbaikan Teknis & Target Hasil                        |
+----+------------+------------+---------------------------------------+------------------------------------------------------------------+
| 1  | UJI-A      | UJI-A      | Siklus Enkripsi & Dekripsi Normal     | Bukti utama: Komparasi Byte-by-Byte (SHA-256 checksum pendukung) |
+----+------------+------------+---------------------------------------+------------------------------------------------------------------+
| 2  | UJI-D9     | UJI-D9A    | Modifikasi Field PlaintextLength AAD  | Nilai length AAD saat dekripsi beda -> REJECT: GCM OperationError|
| 3  | (Baru)     | UJI-D9B    | Modifikasi Manifest Panjang Berkas    | AAD asli, manifest dipalsukan -> REJECT: ManifestConsistencyError|
+----+------------+------------+---------------------------------------+------------------------------------------------------------------+
| 4  | (Baru)     | UJI-D10A   | Validasi Input Indeks Negatif (< 0)   | Indeks negatif pada input -> REJECT: TypeRangeValidationError    |
| 5  | UJI-D10    | UJI-D10B   | Validasi Indeks Melebihi Batas (>= N) | chunk_index >= N -> REJECT: ChunkIndexOutOfRangeError            |
| 6  | (Baru)     | UJI-D10C   | Manipulasi Byte Indeks AAD Dekripsi   | Byte ChunkIndex pada AAD diubah -> REJECT: GCM OperationError    |
+----+------------+------------+---------------------------------------+------------------------------------------------------------------+
| 7  | UJI-D8     | UJI-D8     | Transport Reordering Urutan Terbalik  | Chunk tiba urutan terbalik -> PASS: Dirakit berdasarkan indeks AAD|
| 8  | UJI-D12    | UJI-D12    | Transport Reordering Permutasi Acak   | Chunk tiba urutan acak penuh -> PASS: Dirakit berdasar indeks AAD|
+----+------------+------------+---------------------------------------+------------------------------------------------------------------+
| 9  | UJI-E1     | UJI-E1     | Deteksi Nonce Reuse via Ledger        | Menguji ledger aplikasi penolak duplikasi pasangan (CEK, Nonce)  |
| 10 | UJI-E2     | UJI-E2     | Penanganan Kegagalan CSPRNG           | Mock exception getRandomValues -> FAIL-CLOSED (Tanpa ciphertext) |
| 11 | UJI-E3     | UJI-E3     | Skenario Pengiriman Ulang (Retry)     | Mengirim ciphertext lokal tersimpan -> PASS: Tanpa enkripsi ulang|
| 12 | UJI-E4     | UJI-E4     | Skenario Pembatalan Sesi (Crash/Fail) | Sesi crash ditinggalkan -> Wajib (1) CEK baru, (2) UploadSession |
|    |            |            |                                       | ID baru, dan (3) UploadSessionSalt baru                          |
+----+------------+------------+---------------------------------------+------------------------------------------------------------------+
| 13 | UJI-D13    | UJI-D13    | Isolasi Staging Chunk Terakhir Gagal  | Chunk N-1 gagal GCM -> REJECT: Staging dibatalkan; buffer dilepas|
|    |            |            |                                       | dan ditimpa nol sebagai best-effort cleanup                      |
+----+------------+------------+---------------------------------------+------------------------------------------------------------------+
| 14 | UJI-F1     | UJI-F1     | Streaming Pipeline Buffer 1 MiB       | PROPOSED: Lonjakan ΔarrayBuffers <= 20 MB                        |
|    |            |            |                                       | (Δexternal sebagai metrik pendukung)                             |
| 15 | UJI-F2     | UJI-F2     | Full-Buffer Control (In-Memory)       | OBSERVATIONAL: Kontrol komparasi alokasi V8 memory               |
+----+------------+------------+---------------------------------------+------------------------------------------------------------------+
```

---

## 6. TABEL KLASIFIKASI BUKTI ILMIAH TERREKONSILIASI

Seluruh aspek evaluasi dalam mandat EXP-02 diklasifikasikan secara ketat berdasarkan status pembuktian ilmiah objektif:

```
+-----------------------------------------------------------------------------------------------------------------------------------------+
|                                    TABEL KLASIFIKASI KRITERIA KEBERHASILAN DAN STATUS PEMBUKTIAN                                        |
+----+-----------------------------------------------------+---------------------------+--------------------------------------------------+
| No | Parameter Aspek Evaluasi                            | Klasifikasi Status        | Dasar Penentuan dan Batasan Pembuktian           |
+----+-----------------------------------------------------+---------------------------+--------------------------------------------------+
| 1  | Keutuhan Plaintext Byte-by-Byte (UJI-A & UJI-B)     | UNVERIFIED                | Menunggu eksekusi prototipe fisik di sandbox     |
| 2  | Penolakan Kriptografis GCM terhadap Tampering AAD   | UNVERIFIED                | Menunggu eksekusi prototipe fisik di sandbox     |
| 3  | Toleransi Perakitan Transport Reordering (D8 & D12) | UNVERIFIED                | Menunggu eksekusi prototipe fisik di sandbox     |
| 4  | Penegakan Batas Kuota Maksimum 10MB / 25MB / 5 File | UNVERIFIED                | Menunggu eksekusi prototipe fisik di sandbox     |
| 5  | Pencegahan Nonce Reuse via Ledger Aplikasi (UJI-E1) | UNVERIFIED                | Menguji ledger aplikasi, bukan fitur Web Crypto  |
| 6  | Mitigasi Fail-Closed Saat CSPRNG Error (UJI-E2)     | UNVERIFIED                | Menunggu pengujian mock exception di sandbox     |
| 7  | Pembersihan Staging Buffer saat GCM Gagal (UJI-D13) | UNVERIFIED                | Best-effort cleanup (bukan secure memory wipe)   |
| 8  | Integritas Hash Manifest Berkas Lampiran            | DESIGN DECISION REQUIRED  | Memerlukan mekanisme autentikasi kanal manifest  |
| 9  | Ambang Lonjakan Buffer Streaming <= 20 MB (UJI-F1)  | PROPOSED                  | Target desain usulan diukur dari ΔarrayBuffers   |
| 10 | Evaluasi Memori Full-Buffer In-Memory (UJI-F2)      | OBSERVATIONAL             | Kontrol pembanding observasional alokasi V8 RAM  |
| 11 | Kinerja Web Crypto API pada Peramban Nyata          | UNVERIFIED                | Di luar lingkup pengujian sandbox Node.js        |
| 12 | Ketiadaan UI Freeze / Long Task di Peramban Web     | NOT MEASURED              | Node.js CLI tidak mengukur event loop peramban   |
| 13 | Status Pelaksanaan Eksperimen Fisik EXP-02          | EXP-02 NOT AUTHORIZED     | Belum memperoleh izin tertulis Pemilik Proyek    |
+----+-----------------------------------------------------+---------------------------+--------------------------------------------------+
```

---

## 7. DAFTAR KEPUTUSAN TEKNIS YANG MEMERLUKAN PERSETUJUAN PEMILIK PROYEK

Sebelum pelaksanaan eksperimen fisik dapat dipertimbangkan, butir-butir keputusan teknis berikut diajukan kepada Pemilik Proyek:

```
+-----------------------------------------------------------------------------------------------------------------------------------------+
|                                  DAFTAR KEPUTUSAN TEKNIS YANG MEMERLUKAN PERSETUJUAN PEMILIK PROYEK                                     |
+----+----------------------------+---------------------------------------------+---------------------------------------------------------+
| No | Butir Keputusan Desain     | Rekomendasi Teknis yang Diajukan            | Dasar Pertimbangan & Implikasi Teknis                   |
+----+----------------------------+---------------------------------------------+---------------------------------------------------------+
| 1  | Strategi Nonce Opsi 1 &    | Menyetujui Opsi 1 dengan aturan crash safety| Nonce dianggap terpakai sejak enkripsi dimulai; crash    |
|    | Aturan Crash-Safety        | dan kewajiban 3 entitas baru per sesi baru  | sebelum persistensi mewajibkan pembatalan sesi total    |
|    |                            | (CEK baru, UploadSessionID baru, salt baru) | dengan CEK baru, UploadSessionID baru, & salt baru.     |
+----+----------------------------+---------------------------------------------+---------------------------------------------------------+
| 2  | Format Pembungkusan        | Menyetujui Format A: Nonce (12 B) diprefiks | Output Web Crypto (L + 16 B) dibungkus aplikasi menjadi |
|    | (Framing) Ciphertext       | pada ciphertext chunk (Total: L + 28 B)     | (L + 28 B) untuk kemandirian dekripsi per-chunk.         |
+----+----------------------------+---------------------------------------------+---------------------------------------------------------+
| 3  | Mekanisme Autentikasi      | Menetapkan kanal tepercaya atau tanda tangan| AAD 83 byte tidak memproteksi manifest hash; manifest  |
|    | Hash Manifest              | E2EE untuk melindungi manifest lampiran     | memerlukan perlindungan integritas terpisah.            |
+----+----------------------------+---------------------------------------------+---------------------------------------------------------+
| 4  | Ambang Batas Memori Usulan | Menyetujui ambang PROPOSED <= 20 MB dievalu-| Diukur secara primer dari lonjakan ΔarrayBuffers        |
|    | pada Pengujian Node.js     | asi berdasarkan ΔarrayBuffers di Node.js    | (didukung Δexternal) pada pemrosesan streaming berkas.  |
+----+----------------------------+---------------------------------------------+---------------------------------------------------------+
| 5  | Pemisahan Pengujian        | Menyetujui bahwa pengujian browser nyata    | Pengujian EXP-02 di sandbox Node.js tidak diklaim       |
|    | Lingkungan Peramban Web    | merupakan pekerjaan terpisah                | membuktikan kompatibilitas browser nyata.               |
+----+----------------------------+---------------------------------------------+---------------------------------------------------------+
| 6  | Otorisasi Pelaksanaan      | Otorisasi tertulis terpisah untuk membuat   | Eksperimen EXP-02 belum diizinkan dieksekusi sebelum ada|
|    | Fisik Eksperimen EXP-02    | sandbox dan menjalankan skenario pengujian  | persetujuan eksplisit tertulis dari Pemilik Proyek.     |
+----+----------------------------+---------------------------------------------+---------------------------------------------------------+
```

---

## 8. DAFTAR HAL YANG BELUM DIUJI DAN TIDAK BOLEH DIKLAIM

1. **Belum Ada Eksekusi Prototipe Fisik:** Seluruh skenario pengujian berstatus rencana dan **belum pernah dijalankan di sandbox**.
2. **Ketiadaan Pembuktian di Peramban Nyata:** Kompatibilitas dan kinerja Web Crypto API pada browser sesungguhnya (Chromium, Gecko, WebKit) belum diuji.
3. **Ketiadaan Pengujian Integrasi Next.js 14:** Antarmuka `File.stream()` dan Web Worker pada Next.js 14 belum diuji pada tahapan ini.
4. **Ketiadaan Jaminan Secure Memory Erasure:** Penimpaan buffer nol (`buffer.fill(0)`) pada JavaScript tidak boleh diklaim sebagai penghapusan memori fisik mutlak.
5. **Ketiadaan Diagnostik Internal pada `OperationError`:** Pengecualian Web Crypto API tidak menyediakan diagnosis field mana yang rusak; atribusi galat hanya berasal dari skenario injeksi kesalahan pengujian.

---

## 9. PERNYATAAN INTEGRITAS ARSIP DAN KEPATUHAN BATAS OPERASIONAL

Penyusunan dokumen koreksi teknis final **B-CHAT-08A.4** diselesaikan dengan kepatuhan penuh terhadap batas mandat operasional:

1. **Zero Prototype Creation:** Direktori sandbox `scratch/bchat_prototype/exp02_chunking_aad/` **belum dibuat**, dan tidak ada berkas kode program yang ditulis.
2. **Zero Execution, Benchmark, & Testing:** Tidak ada uji coba eksekusi, simulasi, benchmark memori, maupun unit test yang dijalankan.
3. **Zero Toolchain & Dependency Installation:** Tidak ada dependensi npm, compiler Rust, target WASM, atau paket sistem yang dipasang.
4. **Zero Main Codebase Changes:** Direktori backend Laravel ([`manajemen_skripsi/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/manajemen_skripsi/)) dan frontend Next.js ([`frontend_manajemen_skripsi/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/frontend_manajemen_skripsi/)) tidak disentuh sama sekali.
5. **Zero Database Changes:** Tidak ada migrasi basis data, modifikasi skema tabel, atau query database yang dijalankan.
6. **Zero Git Operations:** Tidak ada perintah Git (`git status`, `git diff`, `git commit`, `git checkout`, `git push`, dsb.) yang dijalankan.
7. **Pelestarian Arsip Historis:** Dokumen [B-CHAT-08A](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A_Rencana_dan_Mandat_EXP-02.md), [B-CHAT-08A.1](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.1_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md), [B-CHAT-08A.2](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.2_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md), [B-CHAT-08A.3](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.3_Koreksi_Presisi_Final_Mandat_EXP-02.md), dan seluruh dokumen forensik EXP-01 ([B-CHAT-07A.1](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A_Laporan_Eksekusi_EXP-01.md) s.d. [B-CHAT-07A.7](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.7_Koreksi_Editorial_dan_Klasifikasi_Bukti_Final_EXP-01.md)) tetap tersimpan utuh di repositori tanpa diubah atau ditimpa (*zero overwrite*).

---

## 10. PERNYATAAN EKSPLISIT STATUS OTORISASI EXP-02

$$\mathbf{STATUS\ OTORISASI\ EKSEKUSI\ EXP\text{-}02:\ EXP\text{-}02\ NOT\ AUTHORIZED}$$

> [!CAUTION]
> **PERINGATAN RESMI:**  
> Dokumen **B-CHAT-08A.4** adalah dokumen perencanaan dan mandat teknis (*documentation only*). Penerbitan dokumen ini **TIDAK MENGOTORISASI** eksekusi eksperimen EXP-02. Eksekusi fisik di sandbox hanya dapat dimulai setelah Pemilik Proyek memeriksa dokumen koreksi teknis final ini dan memberikan **persetujuan tertulis eksplisit terpisah**.

---
*(Dokumen B-CHAT-08A.4 selesai disusun secara paripurna sebagai koreksi teknis final mandat EXP-02, siap ditinjau oleh Pemilik Proyek).*

