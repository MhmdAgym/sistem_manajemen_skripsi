# B-CHAT-08C.3 — FINAL CRYPTOGRAPHIC BINDING & RESULT CLASSIFICATION CORRECTION EXP-02
## KOREKSI PENGIKATAN KRIPTOGRAFIS, STORAGE HASH, DAN KLASIFIKASI HASIL GERBANG FINAL PRE-EKSEKUSI EXP-02

**Status Dokumen:** DOKUMEN AMENDEMEN GERBANG PRE-EKSEKUSI FINAL KETIGA (*THIRD CONTROLLED GATE AMENDMENT*)  
**Mode Operasi:** STRICTLY DOCUMENTATION ONLY — ZERO EXECUTION  
**Status Otorisasi EXP-02:** **EXP-02 NOT AUTHORIZED** *(Eksperimen EXP-02 belum diotorisasi)*  
**Dokumen Induk:** [B-CHAT-08C_EXP-02_Final_Pre-Execution_Gate.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08C_EXP-02_Final_Pre-Execution_Gate.md)  
**Dokumen Amendemen Acuan:**
1. [B-CHAT-08C.1_Final_Gate_Precision_Correction_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08C.1_Final_Gate_Precision_Correction_EXP-02.md)
2. [B-CHAT-08C.2_Final_Gate_Evidence_Integrity_Correction_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08C.2_Final_Gate_Evidence_Integrity_Correction_EXP-02.md)  
**Dokumen Acuan Teknis Tertinggi:** [B-CHAT-08A.5_Final_Editorial_Precision_Closure_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.5_Final_Editorial_Precision_Closure_EXP-02.md)  
**Proyek:** Sistem Informasi Manajemen Skripsi UNUHA  
**Fitur:** Bimbingan Online Chat Real-Time E2EE  
**Tahap Proyek:** Penutupan Final Gerbang 2 Pre-Eksekusi EXP-02  
**Tanggal Penerbitan:** 5 November 2026  

---

> [!CAUTION]
> **PENEGASAN STATUS OPERASIONAL MUTLAK:**  
> Dokumen **B-CHAT-08C.3** ini berstatus **STRICTLY DOCUMENTATION ONLY — ZERO EXECUTION — EXP-02 NOT AUTHORIZED**. Dokumen ini diterbitkan khusus untuk melengkapi dan menyempurnakan dokumen gerbang [B-CHAT-08C](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08C_EXP-02_Final_Pre-Execution_Gate.md), [B-CHAT-08C.1](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08C.1_Final_Gate_Precision_Correction_EXP-02.md), dan [B-CHAT-08C.2](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08C.2_Final_Gate_Evidence_Integrity_Correction_EXP-02.md). Penerbitan dokumen ini **BUKAN** merupakan izin atau otorisasi untuk memulai pembuatan kode, pembuatan direktori sandbox, instalasi dependensi, atau eksekusi prototipe EXP-02. Selama penyusunan dokumen ini, tidak ada kode sumber yang dibuat, tidak ada direktori sandbox yang dibuat, tidak ada berkas fixture/hash yang dihasilkan, tidak ada dependensi atau toolchain yang dipasang, tidak ada benchmark/pengujian yang dijalankan, dan tidak ada operasi Git. Dokumen induk dan seluruh dokumen historis repositori dilestarikan utuh tanpa penimpaan (*zero overwrite*).

---

## 1. TUJUAN DAN RUANG LINGKUP AMENDEMEN

Dokumen **B-CHAT-08C.3** diterbitkan sebagai instrumen amendemen ketiga untuk menyelesaikan enam temuan evaluasi kritis lanjutan terhadap arsitektur pengujian EXP-02:

1. **Koreksi A — Status dan Evaluasi `CEK Binding Identifier`:** Menganalisis kebutuhan teknis identifier CEK, menegakkan prinsip kerahasiaan kunci simetris, dan merekomendasikan penghapusannya dari metadata wajib guna mencegah kebocoran kunci.
2. **Koreksi B — Kelengkapan dan Validasi AAD Kanonikal 83 Byte:** Mengukuhkan kepatuhan penuh terhadap 10 field biner AAD 83-byte, validasi representasi biner mentah UUID, integer big-endian, rentang chunk, serta menegaskan batasan validasi struktur AAD vs autentikasi ciphertext AES-GCM.
3. **Koreksi C — Urutan Pembentukan dan Verifikasi Storage Hash:** Menetapkan alur konseptual 9 langkah independen untuk memverifikasi integritas artefak ciphertext sebelum dan saat retry tanpa jebakan *circular validation*.
4. **Koreksi D — Batasan Keamanan dan Perlindungan Metadata Storage Hash:** Membedakan lima skenario ancaman manipulasi penyimpanan, menegaskan bahwa hash lokal bukan MAC atau tanda tangan digital, dan menetapkan status mekanisme proteksinya sebagai *Design Decision Required*.
5. **Koreksi E — Operasionalisasi Klasifikasi Hasil PASS, FAIL, dan UNVERIFIED:** Merumuskan kriteria baku yang membedakan pengujian positif, pengujian negatif terverifikasi, kegagalan fatal (*hard stop circuit breaker*), kegagalan tingkat skenario, dan hasil tidak terverifikasi (*unverified*).
6. **Koreksi F — Penegasan Empat Prasyarat Wajib Pra-Pembuatan Prototipe:** Menegaskan secara mutlak bahwa empat prasyarat wajib diselesaikan sebelum direktori atau modul kode EXP-02 dibuat, dan otorisasi eksperimen tidak otomatis menggantikan pemenuhan prasyarat tersebut.

Dokumen ini **tidak menulis ulang dokumen induk maupun amendemen sebelumnya**, melainkan menyempurnakan aspek pengikatan kriptografis dan klasifikasi hasil secara formal.

---

## 2. DAFTAR SUMBER YANG DIGUNAKAN DAN RENTANG PEMBACAAN AKTUAL

Penyusunan dokumen ini didasarkan pada penelaahan langsung terhadap berkas-berkas otoritatif repositori dengan rentang baris aktual yang benar-benar diperiksa:

1. [B-CHAT-08C_EXP-02_Final_Pre-Execution_Gate.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08C_EXP-02_Final_Pre-Execution_Gate.md):  
   *Rentang aktual diperiksa:* Baris 37–180 (Bab 2 Rujukan Dokumen, Bab 3 Parameter Kanonikal, Bab 4 Prakondisi Pengujian) dan Baris 248–395 (Bab 7 Lingkungan & Batas Bukti, Bab 8 Metrik & Statistik, Bab 9 Kriteria Kelulusan, Bab 10 Checklist Kesiapan Gerbang).
2. [B-CHAT-08C.2_Final_Gate_Evidence_Integrity_Correction_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08C.2_Final_Gate_Evidence_Integrity_Correction_EXP-02.md):  
   *Rentang aktual diperiksa:* Baris 1–146 (Bab 1 Identitas, Bab 2 Rujukan, Bab 3 Koreksi A Integritas Ciphertext, Bab 4 Koreksi B Pengikatan Sesi) dan Baris 148–307 (Bab 5 Koreksi C Rekonsiliasi, Bab 6 Koreksi D Checklist, Bab 7 Koreksi E Circuit Breaker, Bab 8 Status Gerbang, Bab 9 Batas Operasional, Bab 10 Otorisasi).
3. [B-CHAT-08C.1_Final_Gate_Precision_Correction_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08C.1_Final_Gate_Precision_Correction_EXP-02.md):  
   *Rentang aktual diperiksa:* Baris 84–164 (Bab 4 Independensi Fixture Manifest & Bab 5 Siklus Hidup Ciphertext) dan Baris 166–255 (Bab 6 Tabel Checklist & Bab 7 Klasifikasi Gerbang).
4. [B-CHAT-08B.1_Koreksi_Presisi_Final_Readiness_Audit_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08B.1_Koreksi_Presisi_Final_Readiness_Audit_EXP-02.md):  
   *Rentang aktual diperiksa:* Baris 69–155 (Bab 4 Rekonsiliasi 35 Skenario Granular & Bab 5 Kondisionalitas Nonce) dan Baris 157–236 (Bab 6 Hierarki Dokumen & Bab 8 Nonce Ledger UJI-E1).
5. [B-CHAT-08A.5_Final_Editorial_Precision_Closure_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.5_Final_Editorial_Precision_Closure_EXP-02.md):  
   *Rentang aktual diperiksa:* Baris 53–132 (Bab 3 Koreksi Metrik `external`, Bab 4 Koreksi `OperationError`, dan Bab 5 Spesifikasi Kanonikal Lengkap).
6. [B-CHAT-07A_Laporan_Eksekusi_EXP-01.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A_Laporan_Eksekusi_EXP-01.md):  
   *Rentang aktual diperiksa:* Baris 29–56 (Bab 2 Lingkungan Pengujian dan Workstation Faktual EXP-01).

---

## 3. KOREKSI A — STATUS DAN REKOMENDASI PENGHAPUSAN `CEK BINDING IDENTIFIER`

### 3.1. Analisis Prinsip Keamanan Kriptografis CEK
Pada dokumen B-CHAT-08C.2 Bagian 4.1 Butir 6 tercantum parameter `CEK Binding Identifier`. Berdasarkan audit keamanan kriptografis mendalam, parameter ini dievaluasi terhadap prinsip-prinsip berikut:
1. **Kerahasiaan Mutlak CEK:** Kunci enkripsi konten (CEK) adalah rahasia kriptografis simetris 256-bit yang hanya boleh berada di memori aman *runtime* Web Crypto API.
2. **Larangan Pencatatan dan Eksposur:** CEK tidak boleh dicatat dalam berkas log teks biasa (*plaintext logs*), metadata diagnostik, header berkas, atau basis data lokal.
3. **Risiko Derivasi Identifier:** Membuat representasi identifier dari CEK (misalnya melalui hash parsial, truncate, atau salt) tanpa analisis keamanan formal memperkenalkan risiko kebocoran informasi kunci (*key leakage / side-channel*) dan menciptakan ilusi keamanan.
4. **Bukan Bukti Autentikasi:** Keberadaan identifier publik yang ditautkan ke kunci tidak membuktikan autentikasi data, karena hanya verifikasi Authentication Tag 128-bit via operasi dekripsi AES-GCM yang sah secara kriptografis.

### 3.2. Evaluasi Kebutuhan Teknis dan Redundansi
Pengikatan artefak chunk ke sesi unggah telah dijamin secara kokoh melalui parameter sesi non-rahasia berkekuatan tinggi:
- `UploadSessionID` (16 byte biner mentah UUID v4 unik global).
- `UploadSessionSalt` (8 byte biner mentah acak CSPRNG).
- `AttachmentUUID`, `MessageUUID`, dan `RoomUUID`.
- `ChunkIndex` dan `TotalChunks`.

Hubungan antara CEK dan ciphertext chunk sudah terikat secara intrinsik oleh algoritma AEAD AES-256-GCM itu sendiri melalui AAD kanonikal 83-byte. Jika suatu artefak didekripsi menggunakan CEK yang salah, algoritma AES-GCM secara matematis akan menolak artefak tersebut dan melempar `OperationError`.

### 3.3. Keputusan Resmi: Penghapusan dari Daftar Metadata Wajib
Karena tidak terdapat kebutuhan teknis yang sah dan untuk menghilangkan risiko kebocoran kunci, ditetapkan keputusan resmi:

$$\mathbf{KEPUTUSAN:\ CEK\ BINDING\ IDENTIFIER\ DIHAPUS\ DARI\ DAFTAR\ METADATA\ WAJIB}$$

Pengikatan artefak chunk ke sesi unggah disederhanakan menjadi **9 Parameter Metadata Non-Rahasia**:
1. `Room UUID` (16 byte biner mentah)
2. `Message UUID` (16 byte biner mentah)
3. `Attachment UUID` (16 byte biner mentah)
4. `UploadSessionID` (16 byte biner mentah UUID v4)
5. `UploadSessionSalt` (8 byte biner mentah CSPRNG)
6. `Chunk Index` ($0 \le \text{chunk\_index} < N$)
7. `Total Chunks` ($1 \le N \le 10$)
8. `Plaintext Length` ($1 \le L_i \le 1.048.576\text{ byte}$)
9. `Ciphertext Storage Fingerprint` (Hash digest SHA-256 independen dari blok ciphertext lengkap)

---

## 4. KOREKSI B — KELENGKAPAN DAN VALIDASI AAD KANONIKAL 83 BYTE

Struktur AAD Kanonikal 83-byte merupakan komponen pengikat integritas utama antara konteks sesi UNUHA dengan ciphertext AES-256-GCM. Struktur ini telah dikunci pada [B-CHAT-08A.5](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.5_Final_Editorial_Precision_Closure_EXP-02.md) dan wajib divalidasi dengan disiplin biner mutlak:

```
+-------------------------------------------------------------------------------------------------------------+
|                                  STRUKTUR BINER KANONIKAL AAD 83-BYTE                                       |
+----+----------------------------+----------+-------------------+--------------------------------------------+
| No | Field Name                 | Ukuran   | Format / Encoding | Ketentuan Validasi Operasional             |
+----+----------------------------+----------+-------------------+--------------------------------------------+
| 1  | Magic Header               | 4 byte   | ASCII ('UNH1')    | Wajib tepat 0x55 0x4E 0x48 0x31            |
| 2  | Protocol Version           | 2 byte   | uint16be          | Wajib tepat 0x0001                         |
| 3  | Domain Separator           | 1 byte   | uint8             | Wajib tepat 0x02 (ATTACHMENT_CHUNK)        |
| 4  | Room UUID                  | 16 byte  | Raw Binary UUID   | Wajib 16 oktet biner mentah RFC 4122       |
| 5  | Message UUID               | 16 byte  | Raw Binary UUID   | Wajib 16 oktet biner mentah RFC 4122       |
| 6  | Attachment UUID            | 16 byte  | Raw Binary UUID   | Wajib 16 oktet biner mentah RFC 4122       |
| 7  | Upload Session ID          | 16 byte  | Raw Binary UUID   | Wajib 16 oktet biner mentah RFC 4122       |
| 8  | Chunk Index                | 4 byte   | uint32be          | Validasi: 0 <= chunk_index < Total Chunks  |
| 9  | Total Chunks (N)           | 4 byte   | uint32be          | Validasi: 1 <= N <= 10, N = ceil(S / C)    |
| 10 | Plaintext Length (Li)      | 4 byte   | uint32be          | Validasi: 1 <= Li <= 1.048.576 byte        |
+----+----------------------------+----------+-------------------+--------------------------------------------+
|    | TOTAL PANJANG KANONIKAL    | 83 BYTE  | Binary Struct     | Panjang persis 83 byte tanpa padding liar  |
+----+----------------------------+----------+-------------------+--------------------------------------------+
```

### Enam Aturan Validasi Operasional AAD:
1. **Validasi Header & Domain:** `Magic Header` wajib tepat `UNH1`, `Protocol Version` wajib tepat `0x0001`, dan `Domain Separator` wajib tepat `0x02`. Ketidakcocokan satu bit pun wajib memicu penolakan seketika.
2. **Representasi Biner Mentah UUID:** Seluruh 4 field UUID wajib direpresentasikan sebagai **16 byte biner mentah (*16 raw octets*)**, bukan string heksadesimal 36 karakter bertanda hubung.
3. **Disiplin Big-Endian:** Seluruh field integer (`Protocol Version`, `Chunk Index`, `Total Chunks`, `Plaintext Length`) wajib dikodekan dan divalidasi menggunakan urutan byte *big-endian* jaringan.
4. **Validasi Batas Indeks dan Sesi:** Nilai `Chunk Index` wajib memenuhi $0 \le \text{chunk\_index} < N$. Nilai $\text{chunk\_index} \ge N$ wajib ditolak pada lapisan validasi aplikasi sebelum fungsi serialisasi AAD atau enkripsi dipanggil.
5. **Identitas Byte-demi-Byte Saat Dekripsi:** Buffer AAD yang dimasukkan ke parameter `additionalData` pada fungsi `crypto.subtle.decrypt` wajib identik byte-demi-byte dengan buffer AAD yang digunakan saat enkripsi.
6. **Batas Bukti Struktural AAD:** Validasi format struktur AAD pada kode aplikasi **HANYA** membuktikan kesesuaian skema metadata. Validasi struktur tersebut **SAMA SEKALI TIDAK MEMBUKTIKAN AUTENTIKASI CIPHERTEXT**. Bukti autentikasi kriptografis hanya terjadi apabila operasi `crypto.subtle.decrypt` berhasil memverifikasi Authentication Tag 128-bit terhadap AAD tersebut menggunakan kunci CEK.

---

## 5. KOREKSI C — URUTAN PEMBENTUKAN DAN VERIFIKASI STORAGE HASH

Untuk mendeteksi terjadinya *silent disk corruption*, penulisan berkas parsial, atau gangguan I/O lokal sebelum pengiriman ulang (*retry*) dilakukan, ditetapkan **Alur Konseptual 9 Langkah Verifikasi Storage Hash**:

```
+-------------------------------------------------------------------------------------------------------------+
|                            ALUR 9 LANGKAH PEMBENTUKAN DAN VERIFIKASI STORAGE HASH                           |
+--------+------------------------------------+---------------------------------------------------------------+
| Langkah| Aktivitas Operasional              | Deskripsi dan Batas Metodologis                               |
+--------+------------------------------------+---------------------------------------------------------------+
| Step 1 | Enkripsi Chunk Selesai             | Operasi crypto.subtle.encrypt menghasilkan buffer ciphertext  |
|        |                                    | lengkap Format A: Nonce (12 B) || Cipher (Li B) || Tag (16 B).|
+--------+------------------------------------+---------------------------------------------------------------+
| Step 2 | Kalkulasi Expected Hash Independen | Hitung SHA-256 atas buffer ciphertext hasil Step 1 di RAM     |
|        | (Pre-Write Hash)                   | sebelum operasi penulisan disk dimulai. Simpan sebagai H_exp. |
+--------+------------------------------------+---------------------------------------------------------------+
| Step 3 | Penulisan ke Media Penyimpanan     | Tuliskan aliran byte ciphertext ke media penyimpanan lokal.   |
+--------+------------------------------------+---------------------------------------------------------------+
| Step 4 | Penutupan Berkas & Cek I/O         | Tutup handle berkas (file close) dan verifikasi bahwa operasi |
|        |                                    | penulisan selesai tanpa galat I/O dari sistem operasi.        |
+--------+------------------------------------+---------------------------------------------------------------+
| Step 5 | Pembacaan Ulang (Read-Back)        | Buka dan baca kembali byte artefak ciphertext dari media disk.|
+--------+------------------------------------+---------------------------------------------------------------+
| Step 6 | Kalkulasi Actual Storage Hash      | Hitung SHA-256 atas byte hasil pembacaan ulang (Step 5).      |
|        |                                    | Hasilkan nilai digest aktual H_act.                           |
+--------+------------------------------------+---------------------------------------------------------------+
| Step 7 | Komparasi Integritas Penyimpanan   | Bandingkan H_act terhadap H_exp. Keduanya wajib cocok 100%.   |
|        |                                    | Jika tidak cocok -> disk korup -> buang berkas & fail-closed. |
+--------+------------------------------------+---------------------------------------------------------------+
| Step 8 | Penandaan Kelayakan Retry          | Hanya jika Step 7 cocok, artefak ditandai layak untuk retry.  |
+--------+------------------------------------+---------------------------------------------------------------+
| Step 9 | Re-Verifikasi Sesaat Sebelum Retry | Saat transmisi retry hendak dieksekusi, baca kembali berkas,  |
|        |                                    | hitung hash, dan bandingkan ulang terhadap H_exp awal.        |
+--------+------------------------------------+---------------------------------------------------------------+
```

### Ketentuan Metodologis Storage Hash:
1. **Pemisahan Pengujian:** Pemeriksaan panjang framing ($L_i + 28\text{ byte}$) tetap terpisah dari verifikasi storage hash.
2. **Ketiadaan Circular Validation:** Dilarang keras menghitung expected hash dari berkas yang telah disimpan lalu membandingkannya dengan berkas yang sama. Expected hash ($H_{\text{exp}}$) wajib berasal dari buffer in-memory hasil enkripsi sebelum penulisan media.
3. **Ketiadaan Klaim Durabilitas Crash Fisik:** Mekanisme 9 langkah ini **TIDAK MEMBUKTIKAN** ketahanan penyimpanan terhadap *power loss* atau kegagalan perangkat keras (tidak menguji `fsync` atau flush cache hardware). Status durabilitas fisik tetap **`UNVERIFIED`**.

---

## 6. KOREKSI D — BATASAN KEAMANAN DAN PERLINDUNGAN METADATA EXPECTED HASH

### 6.1. Taksonomi Lima Skenario Ancaman Manipulasi Penyimpanan
Untuk mencegah klaim keamanan berlebihan (*over-claiming*), ditetapkan batas ancaman yang dapat dan tidak dapat ditangani oleh mekanisme storage hash lokal:

```
+-------------------------------------------------------------------------------------------------------------+
|                                    TAKSONOMI ANCAMAN MANIPULASI PENYIMPANAN                                 |
+----+--------------------------------------------+-----------------------+-----------------------------------+
| No | Skenario Ancaman / Anomali Media           | Dapat Dideteksi Hash? | Mekanisme Pertahanan & Dampak     |
+----+--------------------------------------------+-----------------------+-----------------------------------+
| 1  | Korupsi byte ciphertext lokal tanpa        | YA, DAPAT DIDETEKSI   | H_act != H_exp memicu penolakan   |
|    | perubahan metadata hash (bit-flip / crash) |                       | sebelum retry dikirimkan.         |
+----+--------------------------------------------+-----------------------+-----------------------------------+
| 2  | Perubahan ciphertext dan metadata expected | TIDAK DAPAT DIDETEKSI | Penyerang lokal dapat mengubah    |
|    | hash secara simultan oleh penyerang lokal  | OLEH HASH LOKAL BIASA | artefak sekaligus mengupdate hash.|
+----+--------------------------------------------+-----------------------+-----------------------------------+
| 3  | Metadata expected hash hilang atau korup   | YA, MEMICU PENOLAKAN  | Fail-closed: ketiadaan H_exp      |
|    | akibat kegagalan baca I/O                  |                       | menyebabkan artefak ditolak retry.|
+----+--------------------------------------------+-----------------------+-----------------------------------+
| 4  | Metadata hash tidak cocok dengan metadata  | YA, MEMICU PENOLAKAN  | Pengikatan 9 parameter sesi       |
|    | sesi aktif (session mismatch)              |                       | menolak artefak sebelum hashing.  |
+----+--------------------------------------------+-----------------------+-----------------------------------+
| 5  | Injeksi artefak chunk dari sesi lain       | YA, MEMICU PENOLAKAN  | Ditolak oleh mismatch Upload-     |
|    | (foreign session injection)                |                       | SessionID dan UploadSessionSalt.  |
+----+--------------------------------------------+-----------------------+-----------------------------------+
```

### 6.2. Batas Keamanan dan Status Desain Mekanisme Proteksi
- **Bukan Pengganti MAC / Tanda Tangan:** Hash lokal biasa (SHA-256) **BUKAN** Message Authentication Code (MAC) dan **BUKAN** Tanda Tangan Digital. Hash tersebut tidak memiliki kunci rahasia (*unkeyed hash*) sehingga tidak memberikan jaminan autentisitas terhadap pihak yang memiliki izin tulis pada sistem berkas lokal.
- **Jaminan Autentikasi Sejati Berada pada AES-GCM:** Jika Skenario 2 terjadi (penyerang memodifikasi ciphertext dan expected hash lokal), manipulasi tersebut **pasti akan terdeteksi dan ditolak oleh lapisan kriptografi AES-256-GCM** pada sisi penerima melalui kegagalan verifikasi Authentication Tag 128-bit (melempar `OperationError`).
- **Status Spesifikasi Penyimpanan Metadata Hash:** Format representasi penyimpanan metadata digest $H_{\text{exp}}$, lokasi direktori penyimpanan metadata, dan siklus hidup pembersihannya belum ditetapkan secara operasional. Oleh karena itu, statusnya ditetapkan sebagai:  
  $$\mathbf{STATUS:\ DESIGN\ DECISION\ REQUIRED}$$

---

## 7. KOREKSI E — OPERASIONALISASI KLASIFIKASI HASIL PASS, FAIL, DAN UNVERIFIED

Setiap hasil pengujian pada evaluasi 35 skenario granular EXP-02 diklasifikasikan secara objektif tanpa bias asumsi:

```
+-------------------------------------------------------------------------------------------------------------+
|                                  OPERASIONALISASI KLASIFIKASI HASIL EXP-02                                  |
+-------------+-----------------------------------------------------------------------------------------------+
| Kategori    | Definisi Operasional dan Kondisi Pengujian Baku                                               |
+-------------+-----------------------------------------------------------------------------------------------+
| PASS        | Skenario memenuhi hasil yang diharapkan dan seluruh prasyarat pengujian terbukti valid:       |
|             | 1. Skenario Positif: Plaintext hasil dekripsi identik 100% byte-demi-byte (SHA-256 cocok)    |
|             |    dengan berkas asli pada siklus normal (UJI-A dan UJI-B).                                  |
|             | 2. Skenario Negatif Terverifikasi (Expected Rejection):                                       |
|             |    - Chunk di luar rentang (chunk_index >= N) ditolak sebelum serialisasi AAD (UJI-D10A/B).  |
|             |    - CSPRNG sengaja diinjeksi galat memicu penolakan fail-closed aplikasi (UJI-E2).         |
|             |    - Ciphertext/Tag/AAD termanipulasi ditolak dekripsi melempar OperationError (UJI-D1..D5). |
|             |    - Metadata manifest yang dipalsukan ditolak perakitan melempar ManifestConsistencyError.   |
|             |    - Pelanggaran batas kuota (file > 10MB, pesan > 25MB, file > 5) ditolak sebelum enkripsi.  |
|             | 3. Transport Reordering: Chunk tiba acak berhasil disusun ulang sesuai indeks AAD terotentikasi|
|             | 4. Retry Transmisi: Ciphertext terverifikasi dikirim ulang tanpa enkripsi ulang (UJI-E3).     |
+-------------+-----------------------------------------------------------------------------------------------+
| FAIL        | Terjadi kegagalan pemenuhan spesifikasi wajib pada kondisi pengujian yang sah:                |
|             | 1. Pelanggaran Kriptografi Fatal: Pasangan (CEK, Nonce) digunakan ulang untuk enkripsi baru.  |
|             | 2. Kegagalan Autentikasi Normal: Siklus normal (UJI-A/B) melempar OperationError.            |
|             | 3. Lolos Manipulasi: Ciphertext atau AAD termanipulasi berhasil didekripsi tanpa galat.      |
|             | 4. Kebocoran Plaintext: Data plaintext parsial terekspos sebelum seluruh chunk tervalidasi.  |
|             | 5. Lolos Batas Kuota: Berkas melebihi kuota berhasil dienkripsi oleh prototipe.              |
|             | 6. Sesi Crash Melanggar Aturan: Enkripsi diulang menggunakan CEK atau salt lama.             |
+-------------+-----------------------------------------------------------------------------------------------+
| UNVERIFIED  | Hasil pengujian tidak dapat disimpulkan secara sah karena instrumen atau bukti tidak memadai:  |
|             | 1. Lingkungan runtime aktual belum diaudit empiris di workstation laboratorium.              |
|             | 2. Eksekusi terhenti akibat ketiadaan pustaka dependensi atau kegagalan Web Crypto API host.  |
|             | 3. Bukti persistensi media tidak cukup untuk memverifikasi integritas penulisan berkas.       |
|             | 4. Karakteristik kinerja peramban web nyata (Chromium/Gecko/WebKit) dan ketiadaan UI Freeze.  |
|             | 5. Durabilitas penyimpanan fisik terhadap power loss atau abrupt crash perangkat keras.       |
+-------------+-----------------------------------------------------------------------------------------------+
```

### Aturan Circuit Breaker dan Ambang Numerik:
- **Immediate Hard Stop:** Terjadinya salah satu kondisi `FAIL` butir 1 sampai 6 langsung menghentikan seluruh test runner seketika (*fail-closed emergency exit*).
- **Scenario-Level Failure:** Kegagalan simulasi pada kasus injeksi kesalahan I/O disk dicatat sebagai kegagalan lokal skenario tanpa membatalkan skenario lainnya.
- **Ambang Numerik Kegagalan Beruntun:** Ambang batas berapa kali kegagalan skenario beruntun diizinkan sebelum memicu hard stop tetap berstatus:  
  $$\mathbf{STATUS:\ DESIGN\ DECISION\ REQUIRED}$$

---

## 8. KOREKSI F — PENEGASAN EMPAT PRASYARAT WAJIB PRA-PEMBUATAN PROTOTIPE

Empat prasyarat yang ditetapkan pada dokumen [B-CHAT-08C.2](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08C.2_Final_Gate_Evidence_Integrity_Correction_EXP-02.md) Bagian 8 dikukuhkan kembali sebagai **kondisi wajib yang harus diselesaikan sebelum direktori sandbox dibuat atau kode prototipe ditulis**:

1. **Prasyarat 1 — Audit Verifikasi Lingkungan Workstation Aktual:**  
   Menjalankan skrip audit lingkungan non-destruktif untuk memverifikasi versi OS Windows, Node.js ($\ge \text{v20.0.0}$), NPM, dan antarmuka Web Crypto API native (`globalThis.crypto.subtle`).
2. **Prasyarat 2 — Finalisasi Dokumen Spesifikasi Skema Fixture Manifest:**  
   Menetapkan dokumen spesifikasi detail format biner/JSON fixture manifest sintetis yang independen dari modul SUT beserta expected hash SHA-256 ground truth.
3. **Prasyarat 3 — Penetapan Format dan Mekanisme Hash Integritas Penyimpanan:**  
   Menetapkan skema penyimpanan metadata digest lokal untuk membedakan deteksi korupsi penyimpanan dari autentikasi kriptografis AEAD.
4. **Prasyarat 4 — Formalisasi Ambang Numerik Circuit Breaker:**  
   Menetapkan nilai numerik batas kegagalan berturut-turut (*consecutive failure threshold*) dan parameter henti darurat di dalam berkas konfigurasi pengujian.

> [!IMPORTANT]
> **PENEGASAN KEPUTUSAN OTORISASI:**  
> Status **`READY WITH EXPLICIT PRE-EXECUTION CONDITIONS`** **BUKAN MERUPAKAN IZIN OTOMATIS** untuk memenuhi prasyarat melalui eksekusi fisik. Pemenuhan prasyarat dan otorisasi eksekusi eksperimen merupakan dua keputusan manajerial yang terpisah di tangan Pemilik Proyek.

---

## 9. REKONSILIASI KOMPREHENSIF CHECKLIST 12 BUTIR

Tabel berikut menyajikan status rekonsiliasi akhir 12 butir checklist kesiapan gerbang pre-eksekusi EXP-02:

```
+--------------------------------------------------------------------------------------------------------------------------------------------------+
|                                           TABEL REKONSILIASI AKHIR CHECKLIST 12 BUTIR PRE-EKSEKUSI EXP-02                                        |
+----+--------------------------------------------+-----------------------+---------------------------------+--------------------------------------+
| No | Prasyarat Kesiapan                         | Status Terkoreksi     | Bukti Dokumentatif Faktual      | Kondisi Tersisa Sebelum Prototipe    |
+----+--------------------------------------------+-----------------------+---------------------------------+--------------------------------------+
| 1  | Parameter teknis kanonikal telah           | VERIFIED DOCUMENTALLY | Tabel 14 parameter kanonikal    | Tidak ada; parameter kanonikal telah |
|    | direkonsiliasi tanpa pertentangan          |                       | terkunci di B-08A.5 §2 & §5.    | terkunci secara mutlak.              |
+----+--------------------------------------------+-----------------------+---------------------------------+--------------------------------------+
| 2  | Hierarki dokumen tidak menyisakan konflik  | VERIFIED DOCUMENTALLY | B-08B.1 §6 & B-08C §2.2:        | Tidak ada; hierarki 3 tingkat telah  |
|    | spesifikasi operasional                    |                       | Tingkat 1 B-08A.5 mengikat.     | mengesampingkan draf lawas.          |
+----+--------------------------------------------+-----------------------+---------------------------------+--------------------------------------+
| 3  | Fixture manifest sintetis tepercaya telah  | PARTIALLY VERIFIED    | Prinsip independensi & anti-    | Format skema biner/JSON fixture      |
|    | ditentukan koridornya                      |                       | circular oracle jelas (08C.1 §4)| manifest = DESIGN DECISION REQUIRED. |
+----+--------------------------------------------+-----------------------+---------------------------------+--------------------------------------+
| 4  | Batas persistensi dan aturan crash recovery| PARTIALLY VERIFIED    | Alur 9 langkah storage hash & 9 | Skema penyimpanan metadata digest    |
|    | telah ditetapkan operasionalnya            |                       | metadata sesi ditetapkan (§4-5).| lokal = DDR; durabilitas OS = UNV.   |
+----+--------------------------------------------+-----------------------+---------------------------------+--------------------------------------+
| 5  | Batas klaim cleanup memori (best-effort)   | VERIFIED DOCUMENTALLY | 3 fase pembersihan memori &     | Tidak ada; batasan runtime V8 telah  |
|    | telah ditetapkan                           |                       | larangan secure wipe (08C §4.3).| diakui secara transparan.            |
+----+--------------------------------------------+-----------------------+---------------------------------+--------------------------------------+
| 6  | Desain application-level nonce ledger telah| VERIFIED DOCUMENTALLY | Arsitektur in-memory fail-closed| Tidak ada; spesifikasi ledger lokal  |
|    | ditetapkan operasionalnya                  |                       | sebelum encrypt (B-08B.1 §8).   | prototipe telah lengkap.             |
+----+--------------------------------------------+-----------------------+---------------------------------+--------------------------------------+
| 7  | Matriks skenario telah direkonsiliasi      | VERIFIED DOCUMENTALLY | Rekonsiliasi matematis 35       | Tidak ada; 35 skenario granular telah|
|    | tuntas (35 skenario granular)              |                       | skenario granular (B-08B.1 §4). | dipetakan tanpa ambiguitas.          |
+----+--------------------------------------------+-----------------------+---------------------------------+--------------------------------------+
| 8  | Lingkungan eksekusi workstation aktual     | UNVERIFIED            | Data disalin dari EXP-01; belum | Audit empiris workstation aktual     |
|    | telah diverifikasi faktual                 |                       | diaudit empiris saat ini.       | = Wajib pada Prasyarat 1.            |
+----+--------------------------------------------+-----------------------+---------------------------------+--------------------------------------+
| 9  | Direktori sandbox terisolasi telah         | VERIFIED DOCUMENTALLY | Koridor sandbox ditetapkan di   | Direktori belum dibuat; menunggu     |
|    | ditentukan koridor batasannya              |                       | scratch/bchat_prototype/...     | otorisasi fisik dan prasyarat tuntas.|
+----+--------------------------------------------+-----------------------+---------------------------------+--------------------------------------+
| 10 | Batas berkas yang boleh dibuat saat        | VERIFIED DOCUMENTALLY | Daftar berkas yang diizinkan    | Tidak ada; batas jenis berkas telah  |
|    | otorisasi terbit telah jelas               |                       | tercantum ketat di B-08C §11.   | terkunci ketat.                      |
+----+--------------------------------------------+-----------------------+---------------------------------+--------------------------------------+
| 11 | Kriteria evaluasi PASS, FAIL, dan          | VERIFIED DOCUMENTALLY | Definisi baku 3 kategori hasil  | Tidak ada; kriteria operasional telah|
|    | UNVERIFIED telah didefinisikan baku        |                       | dan circuit breaker terkunci(§7)| diformalkan secara objektif.         |
+----+--------------------------------------------+-----------------------+---------------------------------+--------------------------------------+
| 12 | Kondisi penghentian darurat (circuit       | PARTIALLY VERIFIED    | Taksonomi fatal vs non-fatal    | Ambang batas numerik kegagalan       |
|    | breaker / fail-closed) telah jelas         |                       | dirumuskan di B-08C.2 & §7.     | beruntun = DESIGN DECISION REQUIRED. |
+----+--------------------------------------------+-----------------------+---------------------------------+--------------------------------------+
```

---

## 10. STATUS AKHIR GERBANG PRE-EKSEKUSI

Berdasarkan hasil audit presisi kriptografis dan rekonsiliasi checklist:
- Delapan butir checklist telah berstatus **`VERIFIED DOCUMENTALLY`**.
- Tiga butir checklist berstatus **`PARTIALLY VERIFIED`** (Butir 3: Skema fixture manifest, Butir 4: Format storage hash, dan Butir 12: Ambang numerik circuit breaker, yang ketiganya berstatus `DESIGN DECISION REQUIRED`).
- Satu butir checklist berstatus **`UNVERIFIED`** (Butir 8: Verifikasi empiris lingkungan workstation aktual).

Karena seluruh spesifikasi kanonikal inti, formula piecewise, parameter biner AAD 83-byte, dan matriks 35 skenario granular telah terkunci kokoh tanpa menyisakan konflik teknis, sistem tidak berada pada status ditolak (*NOT READY*). Namun, karena masih terdapat empat kondisi prasyarat yang wajib diselesaikan sebelum penulisan kode, status Kategori A (*READY FOR OWNER AUTHORIZATION REVIEW*) belum dapat diberikan secara mutlak tanpa syarat.

Oleh karena itu, hasil evaluasi gerbang final pre-eksekusi secara resmi ditetapkan sebagai:

$$\mathbf{HASIL\ GERBANG:\ READY\ WITH\ EXPLICIT\ PRE\text{-}EXECUTION\ CONDITIONS}$$

> [!CAUTION]
> **PENEGASAN PEMBUATAN PROTOTIPE:**  
> Status **`READY WITH EXPLICIT PRE-EXECUTION CONDITIONS`** menyatakan bahwa dokumen perencanaan telah cukup matang untuk diajukan kepada Pemilik Proyek. Namun, **PROTOTIPE KODE ATAU BERKAS FIXTURE TIDAK BOLEH DIBUAT** sebelum Pemilik Proyek menerbitkan otorisasi tertulis eksplisit dan keempat prasyarat wajib telah diselesaikan secara tuntas.

---

## 11. BATAS OPERASIONAL MUTLAK DAN KOMITMEN ZERO EXECUTION

Seluruh kegiatan penyusunan dokumen amendemen B-CHAT-08C.3 telah dilaksanakan dengan kepatuhan penuh terhadap batas operasional mutlak:

1. **Zero Prototype Code:** Direktori sandbox `scratch/bchat_prototype/exp02_chunking_aad/` **belum dibuat**, dan tidak ada baris kode program atau modul prototipe yang ditulis.
2. **Zero Fixture/Hash Files:** Tidak ada berkas fixture manifest, hash, atau data uji `.bin` yang dibuat di disk.
3. **Zero Test Execution & Benchmark:** Tidak ada pengujian kriptografi, simulasi runtime, kompilasi, maupun benchmark memori yang dijalankan.
4. **Zero Dependency Installation:** Tidak ada paket npm atau compiler sistem yang dipasang.
5. **Zero Main Codebase Changes:** Direktori aplikasi Laravel ([`manajemen_skripsi/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/manajemen_skripsi/)) dan Next.js ([`frontend_manajemen_skripsi/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/frontend_manajemen_skripsi/)) tidak disentuh sama sekali.
6. **Zero Database Changes:** Tidak ada migrasi basis data, perubahan struktur tabel, atau manipulasi data MySQL.
7. **Zero Git Operations:** Tidak ada perintah Git (`git status`, `git commit`, `git diff`, `git push`, dsb.) yang dijalankan.
8. **Zero Historical Overwrite:** Seluruh dokumen historis repositori ([B-CHAT-05](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-05_Spesifikasi_Final_E2EE.md), [B-CHAT-06D](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-06D_Mandat_Eksekusi_Terbatas_Gerbang_2.md), [B-CHAT-07A](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A_Laporan_Eksekusi_EXP-01.md), [B-CHAT-08A](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A_Rencana_dan_Mandat_EXP-02.md) s.d. [B-CHAT-08A.5](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.5_Final_Editorial_Precision_Closure_EXP-02.md), [B-CHAT-08B.1](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08B.1_Koreksi_Presisi_Final_Readiness_Audit_EXP-02.md), [B-CHAT-08C](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08C_EXP-02_Final_Pre-Execution_Gate.md), [B-CHAT-08C.1](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08C.1_Final_Gate_Precision_Correction_EXP-02.md), dan [B-CHAT-08C.2](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08C.2_Final_Gate_Evidence_Integrity_Correction_EXP-02.md)) tetap tersimpan utuh di repositori.

---

## 12. PERNYATAAN EKSPLISIT STATUS OTORISASI EXP-02

$$\mathbf{STATUS\ FINAL\ OTORISASI\ EXP\text{-}02:\ EXP\text{-}02\ NOT\ AUTHORIZED}$$

> [!CAUTION]
> **PERINGATAN OPERASIONAL PENUTUP:**  
> Dokumen amendemen **B-CHAT-08C.3** ini secara resmi menutup rangkaian koreksi pengikatan kriptografis, storage hash, dan klasifikasi hasil gerbang final pre-eksekusi EXP-02. **TIDAK ADA OTORISASI OTOMATIS** untuk memulai pembuatan sandbox, instalasi dependensi, atau eksekusi prototipe EXP-02. Seluruh pekerjaan rekayasa berada dalam status siaga penuh (*standby*) menunggu peninjauan dan penerbitan persetujuan tertulis eksplisit terpisah dari Pemilik Proyek.

---
*(Dokumen B-CHAT-08C.3 selesai disusun secara paripurna sebagai amendemen presisi pengikatan kriptografis dan klasifikasi hasil gerbang final EXP-02, siap ditinjau oleh Pemilik Proyek).*

