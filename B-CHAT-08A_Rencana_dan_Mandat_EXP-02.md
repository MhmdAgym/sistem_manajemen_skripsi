# B-CHAT-08A — RENCANA DAN MANDAT EKSPERIMEN EXP-02
## PROTOTIPE ENKRIPSI LAMPIRAN BERBASIS CHUNKING PLAINTEXT 1 MiB, AEAD AES-256-GCM, AAD KANONIKAL 83-BYTE, DAN WEB CRYPTO API

**Status Dokumen:** DOKUMEN PERENCANAAN DAN MANDAT TEKNIS (*PLAN ONLY — ZERO EXECUTION*)  
**Mode Operasi:** STRICTLY READ-ONLY — PLAN AND DOCUMENTATION ONLY  
**Otorisasi Pelaksanaan:** **BELUM DIBERIKAN** *(Menunggu peninjauan dan persetujuan tertulis Pemilik Proyek)*  
**Dokumen Acuan Utama:**
- [B-CHAT-06D_Mandat_Eksekusi_Terbatas_Gerbang_2.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-06D_Mandat_Eksekusi_Terbatas_Gerbang_2.md) (Revisi B-CHAT-06D.2 §3.2)
- [B-CHAT-05_Spesifikasi_Final_E2EE.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-05_Spesifikasi_Final_E2EE.md) (Keputusan Desain OD-02 & OD-17)
- Rangkaian Dokumen Forensik EXP-01: [B-CHAT-07A.1](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A_Laporan_Eksekusi_EXP-01.md) s.d. [B-CHAT-07A.7](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.7_Koreksi_Editorial_dan_Klasifikasi_Bukti_Final_EXP-01.md)  
**Proyek:** Sistem Informasi Manajemen Skripsi UNUHA  
**Fitur:** Bimbingan Online Chat Real-Time E2EE  
**Tahap:** Persiapan Pelaksanaan EXP-02 (Gerbang 2)  
**Tanggal:** 5 November 2026  

---

> [!IMPORTANT]
> **PERINGATAN STATUS OPERASIONAL MUTLAK:**  
> Dokumen ini adalah **rancangan teknis dan mandat perencanaan semata**. Penerbitan dokumen ini **BUKAN** merupakan izin atau persetujuan untuk mengeksekusi eksperimen EXP-02. Tidak ada kode prototipe, skrip pengujian, benchmark, kompilasi, instalasi dependensi, modifikasi aplikasi backend Laravel, frontend Next.js, skema basis data, maupun perintah Git yang dijalankan selama penyusunan dokumen ini. Pelaksanaan eksperimen EXP-02 hanya dapat dimulai setelah dokumen ini ditinjau dan disetujui secara tertulis oleh Pemilik Proyek.

---

## 1. TUJUAN DAN HIPOTESIS EKSPERIMEN

### 1.1. Tujuan Eksperimen
Tujuan utama eksperimen **EXP-02** adalah mengevaluasi kelayakan teknis, integritas kriptografis, dan efisiensi memori dari subsistem pemrosesan berkas lampiran bimbingan skripsi berbasis pemecahan data (*chunking*), enkripsi terautentikasi AEAD AES-256-GCM, dan antarmuka standar Web Crypto API:
1. Memvalidasi implementasi pemecahan berkas menjadi chunk standar $C = 1.048.576\text{ byte}$ ($1\text{ MiB}$ biner) sesuai ketentuan resmi OD-02.
2. Membuktikan keandalan enkripsi dan dekripsi *Authenticated Encryption with Associated Data* (AEAD) berbasis **AES-256-GCM** dengan tag autentikasi 128-bit ($16\text{ byte}$) menggunakan Web Crypto API native (`crypto.subtle`).
3. Memvalidasi serialisasi dan pengikatan **AAD Kanonikal 83-Byte** pada setiap chunk berkas untuk mencegah manipulasi konteks sesi, substitusi lampiran, modifikasi indeks, dan pemalsuan metadata.
4. Mengevaluasi strategi pengelolaan **Nonce 96-Bit** yang aman dan bebas dari penggunaan ulang (*nonce-reuse prevention*).
5. Menguji ketahanan dekripsi terhadap berbagai skenario serangan manipulasi data (*tampering*, *bit-flip*, *chunk reordering*, *truncation*, dan *session hijacking*).
6. Mengukur karakteristik konsumsi memori (*memory footprint*) antara pemrosesan *in-memory* vs *streaming chunk-by-chunk* guna menjamin ketiadaan pemblokiran antarmuka pengguna (*UI freeze*).
7. Memisahkan secara ketat hasil pengujian lingkungan Node.js dengan lingkungan peramban web nyata (*real browser*).

### 1.2. Hipotesis Teknis yang Diuji
- **Hipotesis H1 (Fungsionalitas Kriptografi):** Web Crypto API native (`crypto.subtle`) mampu mengeksekusi enkripsi dan dekripsi chunk AES-256-GCM dengan AAD kanonikal 83-byte secara deterministik, menghasilkan plaintext yang identik bit-demi-bit (SHA-256 match $100\%$) antara proses enkripsi dan dekripsi.
- **Hipotesis H2 (Integritas dan Autentikasi):** Setiap perubahan sekecil apa pun ($1\text{ bit}$) pada ciphertext chunk, tag autentikasi, maupun salah satu field AAD 83-byte akan secara deterministik memicu kegagalan autentikasi (`OperationError` pada Web Crypto API) dan menolak dekripsi.
- **Hipotesis H3 (Disiplin Batas Kuota):** Penegakan batas kuota lampiran ($10.000.000\text{ byte}$ per berkas, $25.000.000\text{ byte}$ total pesan, maksimal 5 berkas, dan penolakan berkas 0 byte) dapat divalidasi pada lapisan pra-enkripsi sebelum pemrosesan chunk dilakukan.
- **Hipotesis H4 (Efisiensi Alokasi Memori):** Pemrosesan berkas secara *streaming* per-chunk ($1\text{ MiB}$ buffer) mampu menjaga alokasi memori kerja tetap konstan ($\le 20\text{ MB}$ puncak buffer), terlepas dari ukuran berkas mendekati batas maksimal $10.000.000\text{ byte}$.

---

## 2. RUANG LINGKUP DAN PENGECUALIAN

### 2.1. Ruang Lingkup Pekerjaan (In-Scope)
1. Pembagian berkas plaintext menjadi $N$ chunk berukuran tepat $1.048.576\text{ byte}$ ($1\text{ MiB}$ biner), kecuali chunk terakhir yang mengikuti fungsi piecewise modulo.
2. Konstruksi data biner AAD kanonikal 83-byte sesuai spesifikasi proyek UNUHA.
3. Enkripsi dan dekripsi chunk menggunakan AES-256-GCM dengan kunci simetris 256-bit (menggunakan kunci sintetis yang disimulasikan sebagai luaran CEK dari MLS Exporter).
4. Evaluasi skema nonce 96-bit (deterministik counter vs pseudorandom CSPRNG).
5. Pengujian ketahanan manipulasi (*adversarial tampering test suite*).
6. Pengujian variasi ukuran berkas: $1\text{ byte}$, $512\text{ KiB}$, $1.048.576\text{ byte}$, $1.048.577\text{ byte}$, $5.000.000\text{ byte}$, dan batas atas $10.000.000\text{ byte}$.
7. Pengujian validasi batas kuota file tunggal, kuota gabungan, jumlah berkas, dan berkas kosong.
8. Evaluasi komparatif antara pemrosesan *buffer penuh* vs *streaming chunking*.

### 2.2. Batasan dan Pengecualian (Out-of-Scope)
1. **Tidak Mencakup Kompilasi Rust-WASM MLS:** EXP-02 **tidak menguji** pustaka OpenMLS atau mls-rs. EXP-02 berfokus murni pada lapisan enkripsi lampiran payload AEAD menggunakan native Web Crypto API. Kunci CEK diumpankan secara sintetis sebagai kunci AES-GCM 256-bit (`crypto.subtle.importKey`).
2. **Tidak Mengubah Kode Aplikasi Produksi:** Prototipe hanya dibangun di dalam direktori terisolasi [`scratch/bchat_prototype/exp02_chunking_aad/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/scratch/bchat_prototype/). Tidak ada berkas di [`manajemen_skripsi/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/manajemen_skripsi/) atau [`frontend_manajemen_skripsi/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/frontend_manajemen_skripsi/) yang dimodifikasi.
3. **Tidak Melakukan Pengiriman Jaringan ke Server:** EXP-02 tidak menguji transmisi HTTP multipart/form-data atau WebSocket ke backend Laravel; pengujian dilakukan pada lapisan komputasi enkripsi/dekripsi lokal dan verifikasi keutuhan berkas.
4. **Tidak Mengklaim Hasil Node.js sebagai Hasil Browser:** Pengujian di Node.js hanya membuktikan kepatuhan spesifikasi Web Crypto API pada engine V8. Kinerja peramban web nyata harus diuji secara terpisah dalam lingkungan peramban.

---

## 3. SPESIFIKASI KRIPTOGRAFI

Spesifikasi kriptografi yang digunakan dalam EXP-02 merujuk pada standar industri yang diakui secara luas:

```
+-------------------------------------------------------------------------------------------------------------+
|                                    SPESIFIKASI KRIPTOGRAFI RESMI EXP-02                                     |
+--------------------------+----------------------------------------------------------------------------------+
| Parameter                | Nilai / Ketentuan Teknis                                                         |
+--------------------------+----------------------------------------------------------------------------------+
| Algoritma Enkripsi       | AES-GCM (Advanced Encryption Standard in Galois/Counter Mode)                    |
| Standar Rujukan          | NIST Special Publication 800-38D / RFC 5116                                      |
| Ukuran Kunci (CEK)       | 256-bit (32 byte) simetris                                                       |
| Ukuran Tag Autentikasi   | 128-bit (16 byte)                                                                |
| Panjang Nonce / IV       | 96-bit (12 byte)                                                                 |
| Antarmuka Pemrograman   | Web Crypto API (W3C Recommendation) -> crypto.subtle                             |
| Operasi Enkripsi         | crypto.subtle.encrypt({ name: 'AES-GCM', iv, additionalData, tagLength: 128 }, k) |
| Operasi Dekripsi         | crypto.subtle.decrypt({ name: 'AES-GCM', iv, additionalData, tagLength: 128 }, k) |
| Data Autentikasi (AAD)   | Tepat 83 byte kanonikal per chunk                                                |
| Format Penyimpanan Chunk | Nonce (12 B) || Ciphertext (L B) || Auth Tag (16 B)                             |
+--------------------------+----------------------------------------------------------------------------------+
```

> [!NOTE]
> **KEDUDUKAN TAG AUTENTIKASI GCM:**  
> Pada Web Crypto API W3C, fungsi `crypto.subtle.encrypt` secara otomatis menggabungkan tag autentikasi 16-byte di akhir ciphertext ($L + 16\text{ byte}$). Pada saat proses dekripsi, `crypto.subtle.decrypt` memverifikasi tag autentikasi tersebut terhadap ciphertext dan AAD sebelum mengekstrak plaintext asli. Jika autentikasi gagal, operasi secara otomatis melempar galat penolakan (*exception*) tanpa membocorkan plaintext parsial.

---

## 4. STRUKTUR CHUNK DAN AAD KANONIKAL

### 4.1. Formula Matematis Pembagian Chunk
Berdasarkan keputusan resmi OD-02, parameter pemecahan berkas didefinisikan sebagai berikut:
- Kuota ukuran maksimum berkas tunggal: $S_{\max} = 10.000.000\text{ byte}$ ($10\text{ MB}$ desimal).
- Ukuran standar chunk plaintext: $C = 1.048.576\text{ byte}$ ($1\text{ MiB}$ biner, $2^{20}\text{ byte}$).
- Berkas kosong dengan ukuran $S = 0\text{ byte}$ **wajib ditolak secara mutlak**.
- Jumlah total chunk ($N$) dihitung menggunakan fungsi pembulatan ke atas (*ceiling*):
  $$N = \left\lceil \frac{S}{C} \right\rceil$$
  Untuk $1 \le S \le 10.000.000\text{ byte}$, maka $1 \le N \le 10\text{ chunk}$.

- Ukuran plaintext dari setiap chunk ke-$i$ ($0 \le i \le N - 1$):
  - Untuk chunk awal dan tengah ($0 \le i < N - 1$):
    $$L_i = C = 1.048.576\text{ byte}$$
  - Untuk chunk terakhir ($i = N - 1$):
    $$L_{\text{terakhir}} = \begin{cases} S \bmod C, & \text{jika } S \bmod C \ne 0 \\ C, & \text{jika } S \bmod C = 0 \end{cases}$$

### 4.2. Layout Struktur AAD Kanonikal 83-Byte
Setiap chunk berkas diikat secara kriptografis menggunakan *Additional Authenticated Data* (AAD) kanonikal berukuran tepat **$83\text{ byte}$**:

```
+-------------------------------------------------------------------------------------------------------------+
|                               LAYOUT BINER AAD LAMPIRAN KANONIKAL (83 BYTE)                                 |
+----+-----------------------+-------------+---------------+--------------------------------------------------+
| No | Komponen Field        | Ukuran      | Tipe Data     | Deskripsi & Nilai Kanonikal                      |
+----+-----------------------+-------------+---------------+--------------------------------------------------+
| 1  | Magic Header          | 4 byte      | ASCII String  | Konstanta 'UNH1' (0x55 0x4E 0x48 0x31)           |
| 2  | Protocol Version      | 2 byte      | uint16 BE     | Versi protokol E2EE (0x0001)                     |
| 3  | Domain Separator      | 1 byte      | uint8         | Tipe domain lampiran (0x02 = ATTACHMENT_CHUNK)   |
| 4  | Room UUID             | 16 byte     | Binary Octets | raw 16-byte UUID binary representation           |
| 5  | Message UUID          | 16 byte     | Binary Octets | raw 16-byte UUID binary representation           |
| 6  | Attachment UUID       | 16 byte     | Binary Octets | raw 16-byte UUID binary representation           |
| 7  | Upload Session ID     | 16 byte     | Binary Octets | raw 16-byte UUID binary representation           |
| 8  | Chunk Index           | 4 byte      | uint32 BE     | Indeks chunk zero-based (0 <= index <= N - 1)    |
| 9  | Total Chunks          | 4 byte      | uint32 BE     | Jumlah total chunk (N = ceil(S / C))             |
| 10 | Plaintext Length      | 4 byte      | uint32 BE     | Ukuran aktual plaintext chunk ini (L_i byte)     |
+----+-----------------------+-------------+---------------+--------------------------------------------------+
|    | TOTAL PANJANG EKSAK   | 83 byte     | -             | Tepat 83 byte biner tanpa padding                |
+----+-----------------------+-------------+---------------+--------------------------------------------------+
```

> [!WARNING]
> **PENEGASAN PEMISAHAN KONTEKS EXPORTER VS AAD KANONIKAL:**  
> Ditegaskan secara mutlak bahwa **Konteks MLS-Exporter ($48\text{ byte}$)** dan **AAD Lampiran Kanonikal ($83\text{ byte}$)** adalah dua struktur yang berbeda tujuan:
> - Konteks Exporter ($48\text{ byte}$) = $\text{RoomUUID}\ (16\text{ B}) \parallel \text{MessageUUID}\ (16\text{ B}) \parallel \text{AttachmentUUID}\ (16\text{ B})$. Digunakan untuk menurunkan Kunci Enkripsi Konten (CEK) pada lapisan MLS Key Schedule.
> - AAD Kanonikal ($83\text{ byte}$) = Diikatkan pada cipher AES-GCM pada saat enkripsi setiap chunk berkas untuk menjamin keaslian dan urutan chunk. Seluruh AAD **tidak boleh dijadikan** konteks exporter.

---

## 5. STRATEGI PENGELOLAAN DAN KEAMANAN NONCE

### 5.1. Syarat Keamanan Nonce AES-GCM (NIST SP 800-38D)
Penggunaan algoritma AES-GCM mensyaratkan bahwa pasangan $(\text{Kunci}, \text{Nonce})$ **tidak boleh digunakan ulang lebih dari satu kali (*never reuse a nonce under the same key*)**. Penggunaan ulang nonce pada dua ciphertext berbeda dengan kunci yang sama akan membocorkan kunci autentikasi Galois ($H$) dan merusak integritas seluruh skema kriptografi secara permanen (*catastrophic authentication breakdown*).

### 5.2. Opsi Strategi Pembangkitan Nonce 96-Bit
Untuk mengevaluasi pendekatan terbaik pada fitur bimbingan skripsi UNUHA, EXP-02 merancang pengujian terhadap dua strategi pembangkitan nonce:

#### Opsi 1 — Deterministik Nonce Berbasis Counter Terstruktur (Rekomendasi Utama)
Struktur Nonce 96-bit ($12\text{ byte}$) dibentuk secara deterministik terstruktur:
$$\text{Nonce} = \text{UploadSessionSalt}\ (8\text{ byte}) \parallel \text{uint32be}(\text{chunk\_index})\ (4\text{ byte})$$
- **UploadSessionSalt (8 byte):** Dihasilkan satu kali per sesi unggah lampiran menggunakan generator acak kriptografis terpercaya (`crypto.getRandomValues`).
- **uint32be(chunk_index) (4 byte):** Indeks urut chunk berbasis nol ($0, 1, \dots, N-1$).
- **Keunggulan:** Jaminan matematis $100\%$ bahwa tidak akan pernah terjadi tabrakan nonce antar-chunk dalam satu sesi unggah berkas yang sama, karena nilai counter $4\text{ byte}$ bersifat strictly monotonik naik dan unik untuk setiap chunk ($0 \le \text{chunk\_index} \le 9$).

#### Opsi 2 — Nonce Acak Mandiri (*Random Nonce per Chunk*)
Setiap chunk membangkitkan nonce 96-bit secara acak menggunakan `crypto.getRandomValues(new Uint8Array(12))`:
- **Batas Probabilistik (*Birthday Bound*):**  
  Untuk $q$ operasi enkripsi di bawah kunci yang sama, probabilitas terjadinya tabrakan nonce diperkirakan melalui formula *birthday bound*:
  $$P_{\text{collision}} \approx \frac{q^2}{2 \times 2^{96}} = \frac{q^2}{2^{97}}$$
  Untuk satu berkas lampiran dengan maksimal $10\text{ chunk}$ ($q = 10$):
  $$P_{\text{collision}} \approx \frac{100}{1,58 \times 10^{29}} \approx 6,3 \times 10^{-28}$$
- **Catatan Disiplin:** Meskipun probabilitas tabrakan pada $q = 10$ secara astronomis sangat kecil, penggunaan random nonce **tidak memberikan jaminan matematis keunikan absolut**. Risiko kegagalan generator entropi pada sistem klien (*entropy exhaustion*) tetap harus dimitigasi.

### 5.3. Penanganan Kegagalan Pembangkitan Nonce
Prototipe wajib mengimplementasikan pemeriksaan keamanan: jika pemanggilan API entropi `crypto.getRandomValues` gagal (misalnya melempar pengecualian `QuotaExceededError` atau menghasilkan nilai buffer nol seluruhnya), operasi enkripsi **harus segera dibatalkan seketika (*fail-closed*)** tanpa mengirimkan data apapun ke media penyimpanan.

---

## 6. MATRIKS RANCANGAN PENGUJIAN EXP-02

Rancangan pengujian EXP-02 mencakup 6 kelompok pengujian (Uji A sampai Uji F) yang dirancang untuk membuktikan seluruh aspek fungsional, batas kuota, ketahanan manipulasi, dan performa:

```
+-----------------------------------------------------------------------------------------------------------------------------------------+
|                                             MATRIKS RANCANGAN PENGUJIAN LENGKAP EXP-02                                                  |
+----+--------+---------------------------------------+------------------------------------+----------------------------------------------+
| No | Kode   | Nama Skenario Pengujian               | Parameter Data Uji / Varian        | Target Hasil yang Diharapkan                 |
+----+--------+---------------------------------------+------------------------------------+----------------------------------------------+
| 1  | UJI-A  | Enkripsi & Dekripsi Siklus Normal     | File acak 1 MiB (1.048.576 byte)   | Plaintext pulih 100% identik (SHA-256 match) |
+----+--------+---------------------------------------+------------------------------------+----------------------------------------------+
| 2  | UJI-B1 | Berkas Minimal (1 Byte)               | File 1 byte (N = 1, L_last = 1 B)  | 1 chunk terenkripsi, dekripsi valid 1 byte   |
| 3  | UJI-B2 | Berkas Tepat 1 Chunk (1 MiB)          | File 1.048.576 B (N = 1, L = 1 MiB)| 1 chunk terenkripsi, dekripsi valid 1 MiB    |
| 4  | UJI-B3 | Berkas Sedikit > 1 Chunk              | File 1.048.577 B (N = 2, L2 = 1 B) | 2 chunk terenkripsi, pembagian chunk akurat  |
| 5  | UJI-B4 | Berkas Multi-Chunk Menengah           | File 5.000.000 B (N = 5 chunk)     | 5 chunk terenkripsi, rekonstruksi utuh       |
| 6  | UJI-B5 | Berkas Tepat Batas Maksimum File      | File 10.000.000 B (N = 10 chunk)   | 10 chunk terenkripsi, batas atas dipenuhi    |
+----+--------+---------------------------------------+------------------------------------+----------------------------------------------+
| 7  | UJI-C1 | Validasi File Kosong (0 Byte)         | File 0 byte (S = 0)                | REJECT: Galat 'EmptyFileError'               |
| 8  | UJI-C2 | Validasi File Melebihi Kuota Tunggal  | File 10.000.001 byte               | REJECT: Galat 'FileQuotaExceededError'       |
| 9  | UJI-C3 | Validasi Gabungan Melebihi Kuota Pesan| 3 file x 9.000.000 B = 27.000.000 B| REJECT: Galat 'TotalQuotaExceededError'      |
| 10 | UJI-C4 | Validasi Jumlah File Melebihi Batas   | 6 file valid (@ 100 KB)            | REJECT: Galat 'MaxAttachmentCountExceeded'   |
+----+--------+---------------------------------------+------------------------------------+----------------------------------------------+
| 11 | UJI-D1 | Integritas: Modifikasi Ciphertext     | 1 bit dibalik pada ciphertext ch-0 | REJECT: OperationError (GCM Tag Mismatch)    |
| 12 | UJI-D2 | Integritas: Modifikasi AAD RoomUUID   | Mengubah 1 byte pada RoomUUID AAD  | REJECT: OperationError (AAD Auth Failed)     |
| 13 | UJI-D3 | Integritas: Modifikasi AAD SessionID  | Mengubah SessionID pada AAD ch-1   | REJECT: OperationError (AAD Auth Failed)     |
| 14 | UJI-D4 | Integritas: Nonce Mismatch            | Menggunakan nonce ch-0 untuk ch-1  | REJECT: OperationError (Auth Failed)         |
| 15 | UJI-D5 | Integritas: Tag Autentikasi Rusak     | Mengubah 1 byte pada auth tag 16 B | REJECT: OperationError (Tag Corrupted)       |
| 16 | UJI-D6 | Integritas: Chunk Hilang              | Chunk 2 dari 5 dihapus             | REJECT: AssemblyError (MissingChunkDetected) |
| 17 | UJI-D7 | Integritas: Chunk Duplikat            | Chunk 0 disisipkan ulang 2 kali    | REJECT: AssemblyError (DuplicateChunkIndex)  |
| 18 | UJI-D8 | Integritas: Chunk Tertukar Urutan     | Chunk 1 dan Chunk 2 ditukar posisi | REJECT: AssemblyError (OutOfOrderChunkIndex) |
| 19 | UJI-D9 | Integritas: Pemalsuan PlaintextLength | Field PlaintextLength AAD diubah   | REJECT: OperationError / LengthMismatchError |
+----+--------+---------------------------------------+------------------------------------+----------------------------------------------+
| 20 | UJI-E1 | Deteksi Penggunaan Ulang Nonce        | Simulasi duplikasi nonce pada kunci| REJECT / DETECTED: NonceReuseViolation       |
| 21 | UJI-E2 | Kegagalan Entropi CSPRNG              | Mock entropy failure pada getRandom| REJECT: SecureEntropyFailure (Fail-Closed)   |
+----+--------+---------------------------------------+------------------------------------+----------------------------------------------+
| 22 | UJI-F1 | Evaluasi Streaming Buffer Memori      | Pemrosesan chunk bertahap (1 MiB)  | Peak buffer heap <= 20 MB pada file 10 MB    |
| 23 | UJI-F2 | Evaluasi Full-Buffer In-Memory        | Memuat seluruh file 10 MB sekaligus| Peak heap > 30 MB (sebagai kontrol komparasi)|
+----+--------+---------------------------------------+------------------------------------+----------------------------------------------+
```

---

## 7. KRITERIA KEBERHASILAN (PASS / FAIL / UNVERIFIED)

Evaluasi pelaksanaan EXP-02 dievaluasi berdasarkan kriteria keberhasilan yang objektif dan terukur:

```
+-----------------------------------------------------------------------------------------------------------------------------------------+
|                                              KRITERIA KEBERHASILAN OBJEKTIF EXP-02                                                      |
+----+----------------------------+------------------------------------+------------------------------------+-----------------------------+
| No | Parameter Kriteria         | Kriteria LULUS (PASS)              | Kriteria GAGAL (FAIL)              | Status Awal (Rencana)       |
+----+----------------------------+------------------------------------+------------------------------------+-----------------------------+
| 1  | Keutuhan Plaintext (SHA256)| 100% identik bit-demi-bit pada     | Terdapat perbedaan bit, hash tidak | UNVERIFIED (Akan diuji)     |
|    | Hasil Enkripsi & Dekripsi  | seluruh skenario ukuran berkas valid| cocok, atau dekripsi corrupt       |                             |
+----+----------------------------+------------------------------------+------------------------------------+-----------------------------+
| 2  | Ketahanan Autentikasi GCM  | Seluruh manipulasi data (UJI-D1 s.d| Ada manipulasi yang lolos tanpa    | UNVERIFIED (Akan diuji)     |
|    | Terhadap Tampering         | UJI-D5) memicu galat penolakan     | terdeteksi, atau plaintext parsial |                             |
|    |                            | seketika tanpa membocorkan data    | bocor saat autentikasi gagal       |                             |
+----+----------------------------+------------------------------------+------------------------------------+-----------------------------+
| 3  | Integritas Urutan & Jumlah | Chunk hilang, tertukar, duplikat,  | Berkas berhasil direkonstruksi     | UNVERIFIED (Akan diuji)     |
|    | Chunk pada Assembling      | atau salah panjang ditolak tegas   | meskipun urutan chunk salah        |                             |
+----+----------------------------+------------------------------------+------------------------------------+-----------------------------+
| 4  | Penegakan Batas Kuota      | File 0 B ditolak; File > 10 MB     | File kosong diterima; Kuota > 10 MB| UNVERIFIED (Akan diuji)     |
|    | Berkas dan Pesan           | ditolak; Total > 25 MB ditolak;    | diproses; atau > 5 file diizinkan  |                             |
|    |                            | Jumlah file > 5 ditolak            |                                    |                             |
+----+----------------------------+------------------------------------+------------------------------------+-----------------------------+
| 5  | Formula Pembagian Chunk    | N = ceil(S/C) dan L_terakhir tepat | Jumlah chunk atau ukuran chunk     | UNVERIFIED (Akan diuji)     |
|    | Matematis Piecewise        | sesuai rumus untuk semua varian    | terakhir tidak sesuai rumus        |                             |
+----+----------------------------+------------------------------------+------------------------------------+-----------------------------+
| 6  | Keunikan Nonce             | Tidak ada nonce yang sama dalam    | Terdeteksi ada nonce duplikat pada | UNVERIFIED (Akan diuji)     |
|    | Lingkup Kunci yang Sama    | kunci CEK yang sama                | kunci yang sama                    |                             |
+----+----------------------------+------------------------------------+------------------------------------+-----------------------------+
| 7  | Konsumsi Memori Streaming  | Peak alokasi buffer streaming      | Alokasi memori meledak > 50 MB     | PROPOSED (Target Usulan:    |
|    | (Heap Footprint)           | terkontrol <= 20 MB (PROPOSED)     | untuk berkas 10 MB                 | Peak Buffer <= 20 MB)       |
+----+----------------------------+------------------------------------+------------------------------------+-----------------------------+
| 8  | Pemisahan Validasi         | Hasil Node.js dan browser nyata    | Hasil Node.js diklaim otomatis     | MANDATORI (Wajib dipatuhi)  |
|    | Lingkungan Eksekusi        | dicatat secara terpisah            | membuktikan lingkungan peramban    |                             |
+----+----------------------------+------------------------------------+------------------------------------+-----------------------------+
```

---

## 8. RANCANGAN PENGUKURAN DAN TELEMETRI

### 8.1. Metrik yang Dicatat
Pengujian EXP-02 akan mencatat data telemetri komprehensif pada setiap operasi pengujian:
1. **Durasi Enkripsi per Chunk ($t_{\text{enc\_chunk}}$):** Waktu (ms) yang dibutuhkan oleh `crypto.subtle.encrypt` untuk satu chunk $1\text{ MiB}$.
2. **Durasi Dekripsi per Chunk ($t_{\text{dec\_chunk}}$):** Waktu (ms) yang dibutuhkan oleh `crypto.subtle.decrypt` untuk satu chunk $1\text{ MiB}$.
3. **Throughput Enkripsi/Dekripsi:** Kecepatan pemrosesan dalam satuan $\text{MiB/s}$ atau $\text{MB/s}$.
4. **Alokasi Memori Heap Puncak (*Peak Heap Memory*):** Konsumsi memori (MB) yang dicatat melalui `process.memoryUsage()` di Node.js.
5. **Overhead AAD dan Tag:** Verifikasi ukuran tetap $83\text{ byte}$ AAD dan $16\text{ byte}$ tag per chunk.

### 8.2. Format Penyimpanan Laporan Mentah
Seluruh hasil pengukuran mentah wajib disimpan secara otomatis dalam format JSON pada direktori sandbox:
- [`scratch/bchat_prototype/reports/EXP02_raw_measurements.json`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/scratch/bchat_prototype/reports/EXP02_raw_measurements.json)
- [`scratch/bchat_prototype/reports/EXP02_summary.json`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/scratch/bchat_prototype/reports/EXP02_summary.json)

---

## 9. KEBUTUHAN LINGKUNGAN DAN ISOLASI SANDBOX

### 9.1. Lingkungan Runtime
1. **Node.js Environment:**
   - Node.js versi mutakhir (v25.x / v20.x LTS) yang mendukung native Web Crypto API (`globalThis.crypto.subtle`).
   - Tidak memerlukan dependensi eksternal npm pihak ketiga (menggunakan pustaka bawaan `crypto` dan `buffer`).
2. **Browser Environment (Uji Terpisah di Masa Depan):**
   - Peramban modern berbasis Chromium (Chrome, Edge) dan Gecko (Firefox) yang mendukung Web Crypto API native dan `File.stream()`.

### 9.2. Batas Isolasi Direktori Sandbox
Seluruh kode prototipe, berkas uji sintetis, dan hasil telemetri EXP-02 ditempatkan secara terisolasi penuh di dalam:
```
d:\Aplikasi\laragon\www\sistem_manajamen_skripsi\scratch\bchat_prototype\exp02_chunking_aad\
├── chunker.js               # Logika pemecahan dan perakitan chunk (1 MiB biner, piecewise modulo)
├── aad_builder.js           # Serializer AAD kanonikal 83-byte (Big-Endian numbers & binary UUIDs)
├── crypto_gcm.js            # Wrapper Web Crypto API AES-256-GCM (encrypt, decrypt, nonce handling)
├── streaming_pipeline.js    # Simulasi pipeline streaming per-chunk
├── test_cases_boundary.js   # Pengujian ukuran berkas dan batas kuota (UJI-B & UJI-C)
├── test_cases_tampering.js  # Pengujian adversarial tampering & manipulasi AAD/Tag (UJI-D)
└── test_runner_exp02.js     # Runner pengujian otomatis EXP-02 & pencatat telemetri
```

---

## 10. RISIKO TEKNIS DAN MITIGASI

```
+-------------------------------------------------------------------------------------------------------------+
|                                    ANALISIS RISIKO DAN MITIGASI EXP-02                                      |
+----+----------------------------+--------------------------------+------------------------------------------+
| No | Potensi Risiko             | Dampak Keamanan / Kinerja      | Strategi Mitigasi Teknis                 |
+----+----------------------------+--------------------------------+------------------------------------------+
| 1  | Penggunaan Ulang Nonce     | Fatal: Bocornya kunci autentikasi| Menggunakan nonce terstruktur counter    |
|    | (Nonce Reuse)              | GCM dan integritas runtuh      | strictly monotonik atau verifikasi unik  |
+----+----------------------------+--------------------------------+------------------------------------------+
| 2  | Lonjakan Memori pada Berkas| Menghabiskan RAM peramban, UI  | Menerapkan streaming pipeline per-chunk; |
|    | Besar (Memory Overflow)    | freeze, crash tab peramban     | buffer dibatasi maksimal 2 MiB per siklus|
+----+----------------------------+--------------------------------+------------------------------------------+
| 3  | Ambiguitas Ukuran MB/MiB   | Inkonsistensi batas kuota pada | Mematok satuan secara eksplisit: kuota   |
|    |                            | lapisan validasi frontend/backend| file desimal (10.000.000 B), chunk biner |
+----+----------------------------+--------------------------------+------------------------------------------+
| 4  | Modifikasi Header AAD oleh | Penyerang memanipulasi metadata| Seluruh 83 byte AAD diikatkan ke auth tag|
|    | Server Penyimpanan         | lampiran di server penyimpanan | AES-GCM; modifikasi 1 bit memicu galat   |
+----+----------------------------+--------------------------------+------------------------------------------+
| 5  | Truncation Attack          | Penyerang menghapus chunk akhir| Field Total Chunks diikat di dalam AAD   |
|    | (Pemotongan Berkas)        | berkas agar naskah tidak utuh  | pada setiap chunk; penerima memvalidasi N|
+----+----------------------------+--------------------------------+------------------------------------------+
```

---

## 11. BATAS OPERASIONAL MUTLAK

Selama penyusunan dan peninjauan dokumen **B-CHAT-08A**, batasan operasional berikut berlaku secara mutlak:

1. **Zero Execution EXP-02:** Tidak ada kode prototipe EXP-02 yang dieksekusi atau dijalankan.
2. **Zero Code Creation:** Tidak ada direktori baru atau berkas kode program yang dibuat di dalam `scratch/bchat_prototype/exp02_chunking_aad/` sebelum mandat ini disetujui.
3. **Zero Compilation, Benchmark, & Testing:** Tidak ada uji coba kompilasi, benchmark kinerja, atau unit test yang dijalankan.
4. **Zero Toolchain & Dependency Installation:** Tidak ada dependensi npm eksternal, pustaka kriptografi tambahan, atau compiler yang dipasang ke sistem.
5. **Zero Main Codebase Changes:** Direktori backend Laravel ([`manajemen_skripsi/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/manajemen_skripsi/)) dan frontend Next.js ([`frontend_manajemen_skripsi/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/frontend_manajemen_skripsi/)) tidak boleh disentuh.
6. **Zero Database Changes:** Tidak ada manipulasi basis data, migrasi, query, atau seeding yang dijalankan.
7. **Zero Git Operations:** Tidak ada perintah Git (`git status`, `git diff`, `git commit`, `git push`, dsb.) yang dijalankan.
8. **Pelestarian Arsip Historis:** Dokumen laporan dan koreksi EXP-01 ([B-CHAT-07A](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A_Laporan_Eksekusi_EXP-01.md) s.d. [B-CHAT-07A.7](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.7_Koreksi_Editorial_dan_Klasifikasi_Bukti_Final_EXP-01.md)) tidak boleh diubah atau ditimpa.
9. **Penundaan Eksperimen Lanjutan:** Eksperimen EXP-03, EXP-04, dan EXP-05 tetap berstatus ditangguhkan (*standby*).

---

## 12. DAFTAR KEPUTUSAN YANG MEMBUTUHKAN PERSETUJUAN PEMILIK PROYEK

Sebelum eksperimen EXP-02 dapat dieksekusi secara fisik di sandbox, butir-butir keputusan teknis berikut diajukan kepada Pemilik Proyek untuk mendapatkan persetujuan tertulis:

```
+-----------------------------------------------------------------------------------------------------------------------------------------+
|                                  DAFTAR BUTIR KEPUTUSAN UNTUK PERSETUJUAN PEMILIK PROYEK                                                |
+----+----------------------------+---------------------------------------------+---------------------------------------------------------+
| No | Butir Keputusan Teknis     | Pilihan Rekomendasi Teknis                  | Implikasi dan Justifikasi                               |
+----+----------------------------+---------------------------------------------+---------------------------------------------------------+
| 1  | Strategi Nonce Utama       | OPSI 1: Deterministik Counter Terstruktur   | Menghilangkan risiko tabrakan nonce secara mutlak       |
|    | untuk Chunk Enkripsi       | (UploadSessionSalt 8 B || ChunkIndex 4 B)   | dalam satu berkas; bebas dari kelemahan birthday bound. |
+----+----------------------------+---------------------------------------------+---------------------------------------------------------+
| 2  | Format Pembungkusan        | FORMAT A: Nonce (12 B) diprefiks pada       | Mempermudah dekripsi independen setiap chunk tanpa      |
|    | (Framing) Ciphertext Chunk | setiap ciphertext chunk (Total = L + 28 B)  | ketergantungan urutan transmisi.                        |
+----+----------------------------+---------------------------------------------+---------------------------------------------------------+
| 3  | Ambang Batas Memori Puncak | AMBANG USULAN: Peak buffer heap <= 20 MB    | Menjamin pemrosesan streaming tidak membebani perangkat |
|    | Streaming Buffer (PROPOSED)| pada pengujian berkas 10.000.000 byte       | pengguna berdaya komputasi rendah (*low-end device*).   |
+----+----------------------------+---------------------------------------------+---------------------------------------------------------+
| 4  | Otorisasi Eksekusi EXP-02  | Persetujuan tertulis untuk memulai eksekusi | Memberikan mandat resmi untuk membuat sandbox dan       |
|    | di Sandbox Terisolasi      | prototipe EXP-02 di scratch/bchat_prototype/| menjalankan UJI-A sampai UJI-F secara terkendali.       |
+----+----------------------------+---------------------------------------------+---------------------------------------------------------+
```

---
*(Dokumen B-CHAT-08A selesai disusun secara paripurna sebagai rencana dan mandat resmi pengujian EXP-02, siap ditinjau oleh Pemilik Proyek).*

