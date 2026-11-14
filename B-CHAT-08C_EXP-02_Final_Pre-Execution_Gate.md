# B-CHAT-08C — EXP-02 FINAL PRE-EXECUTION GATE
## GERBANG FINAL KESIAPAN EKSEKUSI EKSPERIMEN CHUNKING, AES-256-GCM, DAN AAD

**Status Mandat:** DOKUMEN GERBANG PRE-EKSEKUSI RESMI (*STRICTLY DOCUMENTATION ONLY — ZERO EXECUTION*)  
**Status Otorisasi EXP-02:** **EXP-02 NOT AUTHORIZED** *(Eksperimen EXP-02 belum diotorisasi)*  
**Dokumen Induk Gerbang 2:** [B-CHAT-06D_Mandat_Eksekusi_Terbatas_Gerbang_2.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-06D_Mandat_Eksekusi_Terbatas_Gerbang_2.md) (Revisi B-CHAT-06D.2 §3.2)  
**Dokumen Acuan Teknis Tertinggi:** [B-CHAT-08A.5_Final_Editorial_Precision_Closure_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.5_Final_Editorial_Precision_Closure_EXP-02.md)  
**Dokumen Audit Kesiapan Terkoreksi:** [B-CHAT-08B.1_Koreksi_Presisi_Final_Readiness_Audit_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08B.1_Koreksi_Presisi_Final_Readiness_Audit_EXP-02.md)  
**Proyek:** Sistem Informasi Manajemen Skripsi UNUHA  
**Fitur:** Bimbingan Online Chat Real-Time E2EE  
**Tahap Proyek:** Penutupan Gerbang 2 (Final Pre-Execution Gate EXP-02)  
**Tanggal Penerbitan:** 5 November 2026  

---

> [!CAUTION]
> **PENEGASAN STATUS OPERASIONAL MUTLAK:**  
> Dokumen **B-CHAT-08C** ini berstatus **STRICTLY DOCUMENTATION ONLY — ZERO EXECUTION — EXP-02 NOT AUTHORIZED**. Penerbitan dokumen gerbang ini **BUKAN** merupakan izin atau otorisasi untuk memulai pembuatan kode, instalasi dependensi, atau eksekusi prototipe EXP-02 di sandbox. Dokumen ini disusun khusus sebagai instrumen verifikasi final untuk diserahkan kepada Pemilik Proyek guna mempertimbangkan pemberian keputusan otorisasi terpisah. Selama penyusunan dokumen ini, tidak ada kode sumber yang dibuat, tidak ada sandbox yang dibuat, tidak ada dependensi atau toolchain yang dipasang, tidak ada benchmark/pengujian yang dijalankan, dan tidak ada operasi Git. Seluruh dokumen historis dilestarikan tanpa penimpaan (*zero overwrite*).

---

## 1. RINGKASAN EKSEKUTIF DAN TUJUAN GERBANG FINAL

Dokumen **B-CHAT-08C** diterbitkan sebagai instrumen gerbang final (*pre-execution readiness gate*) untuk mengevaluasi secara definitif apakah rancangan eksperimen **EXP-02 (Prototipe Enkripsi Lampiran Berbasis Chunking Plaintext 1 MiB, AEAD AES-256-GCM, AAD Kanonikal 83-Byte, dan Web Crypto API)** telah mencapai tingkat kematangan spesifikasi yang memadai untuk diajukan kepada Pemilik Proyek guna memperoleh keputusan otorisasi terpisah.

Dokumen ini dibangun di atas hasil konsolidasi teknis [B-CHAT-08A.5](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.5_Final_Editorial_Precision_Closure_EXP-02.md) dan rekonsiliasi audit kesiapan [B-CHAT-08B.1](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08B.1_Koreksi_Presisi_Final_Readiness_Audit_EXP-02.md), tanpa mengulang audit teknis yang telah selesai.

### Lima Prioritas Utama Gerbang Final:
1. **Memastikan Prasyarat Pengujian Eksplisit:** Mendefinisikan secara operasional dan ketat empat prasyarat utama: fixture manifest sintetis tepercaya di sandbox, batasan durabilitas persistensi lokal dan crash recovery safety, disiplin pembersihan memori (*best-effort cleanup*), serta arsitektur *application-level nonce ledger*.
2. **Menetapkan Batas Klaim Eksperimen yang Ketat:** Membatasi klaim pembuktian hanya pada lingkungan prototipe Node.js CLI terisolasi, serta secara eksplisit melarang klaim performa browser web nyata, ketiadaan UI freeze pada Next.js, dan integrasi penuh MLS.
3. **Menetapkan Kriteria Hasil Baku:** Menegakkan klasifikasi objektif untuk hasil `PASS`, `FAIL`, dan `UNVERIFIED` tanpa toleransi terhadap asumsi yang belum teruji secara empiris.
4. **Memisahkan Parameter Final dari Keputusan Terbuka:** Mengunci 14 parameter kanonikal tetap, membiarkan parameter pengukuran empiris terbuka untuk dibuktikan oleh eksperimen, dan mengidentifikasi secara eksplisit keputusan desain produksi yang masih memerlukan persetujuan Pemilik Proyek (`DESIGN DECISION REQUIRED`).
5. **Menjamin Disiplin Zero Execution:** Mengunci seluruh aktivitas rekayasa dalam mode *strictly read-only* sampai terbit otorisasi tertulis eksplisit dari Pemilik Proyek.

---

## 2. SUMBER RUJUKAN RESMI DAN HIERARKI SPESIFIKASI

### 2.1. Daftar Sumber Rujukan dan Rentang Bagian yang Dibaca
Penyusunan dokumen ini didasarkan pada penelaahan langsung terhadap berkas-berkas otoritatif repositori dengan rentang bagian yang benar-benar dibaca dan diuji silang:

1. [B-CHAT-08B.1_Koreksi_Presisi_Final_Readiness_Audit_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08B.1_Koreksi_Presisi_Final_Readiness_Audit_EXP-02.md)  
   *Rentang yang dibaca:* Bagian 1 (Identitas & Tujuan), Bagian 3 (Temuan Koreksi), Bagian 4 (Rekonsiliasi 35 Skenario Granular), Bagian 5 (Kondisionalitas Nonce & Probabilitas CEK), Bagian 6 (Hierarki Dokumen), Bagian 7 (Statistik & Metrik Memori), dan Bagian 8 (UJI-E1).
2. [B-CHAT-08B.0 (Laporan Audit Kesiapan Pre-Otorisasi EXP-02)](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.5_Final_Editorial_Precision_Closure_EXP-02.md)  
   *Rentang yang dibaca:* Bagian 3 (Rekonsiliasi Spesifikasi Kanonikal), Bagian 8 (Matriks Skenario), Bagian 9 (Temuan Teknis Historis), dan Bagian 10 (Kesiapan Lingkungan).
3. [B-CHAT-08A.5_Final_Editorial_Precision_Closure_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.5_Final_Editorial_Precision_Closure_EXP-02.md)  
   *Rentang yang dibaca:* Bagian 1 (Identitas), Bagian 2 (Latar Belakang), Bagian 3 (Koreksi Batas Cakupan `external`), Bagian 4 (Koreksi Klasifikasi `OperationError`), dan Bagian 5 (Pernyataan Konsistensi Spesifikasi Kanonikal).
4. [B-CHAT-08A.4_Koreksi_Teknis_Final_Mandat_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.4_Koreksi_Teknis_Final_Mandat_EXP-02.md)  
   *Rentang yang dibaca:* Bagian 2 (Spesifikasi Kanonikal), Bagian 3 (Enam Koreksi Wajib A–F), Bagian 4 (Tiga Penyempurnaan Kecil), dan Bagian 5 (Matriks 15 Skenario Terdampak).
5. [B-CHAT-08A.1_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.1_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md)  
   *Rentang yang dibaca:* Bagian 1 (Ringkasan Teknis), Bagian 2 (Tabel Perbandingan Sebelum/Sesudah), Bagian 3 (Matriks 32 Skenario Induk), dan Bagian 4 (Kriteria Keberhasilan).
6. [B-CHAT-06D_Mandat_Eksekusi_Terbatas_Gerbang_2.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-06D_Mandat_Eksekusi_Terbatas_Gerbang_2.md) (Revisi B-CHAT-06D.2)  
   *Rentang yang dibaca:* Bagian 2 (Koridor Pengujian Terisolasi), Bagian 3.2 (Spesifikasi EXP-02), dan Bagian 3.3 (EXP-03 OD-17).
7. [B-CHAT-05_Spesifikasi_Final_E2EE.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-05_Spesifikasi_Final_E2EE.md)  
   *Rentang yang dibaca:* Bagian 3.2 (Lampiran Terenkripsi OD-02) dan Bagian 3.3 (Upload Bertahap, Chunking, dan Manifest).
8. [B-CHAT-05A_Rekonsiliasi_Temuan_Audit.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-05A_Rekonsiliasi_Temuan_Audit.md)  
   *Rentang yang dibaca:* Bagian 3.2 (Pemeriksaan Keamanan File) dan Bagian 6.5 (Target Kinerja dan Skenario Lampiran).
9. [B-CHAT-07A_Laporan_Eksekusi_EXP-01.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A_Laporan_Eksekusi_EXP-01.md)  
   *Rentang yang dibaca:* Bagian 2 (Profil Lingkungan Pengujian dan Workstation Faktual).

### 2.2. Hierarki Spesifikasi Resmi EXP-02
Sesuai konsolidasi [B-CHAT-08B.1](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08B.1_Koreksi_Presisi_Final_Readiness_Audit_EXP-02.md) Bagian 6, hierarki rujukan otoritatif ditetapkan sebagai berikut:
1. **Tingkat 1 — Acuan Teknis Tertinggi:** [B-CHAT-08A.5](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.5_Final_Editorial_Precision_Closure_EXP-02.md) (didukung [B-CHAT-08A.4](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.4_Koreksi_Teknis_Final_Mandat_EXP-02.md)). Berfungsi mengikat definisi AAD 83-byte, klasifikasi `OperationError`, metrik memori, dan formulasi matematika.
2. **Tingkat 2 — Acuan Suite Pengujian Komprehensif:** [B-CHAT-08A.1](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.1_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md) (memuat 32 skenario sumber yang direkonsiliasi menjadi 35 skenario granular pasca-pemecahan UJI-D9 dan UJI-D10).
3. **Tingkat 3 — Mandat Induk Gerbang 2:** [B-CHAT-06D](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-06D_Mandat_Eksekusi_Terbatas_Gerbang_2.md) (Revisi B-CHAT-06D.2). Ketentuan draf historis yang bertentangan (seperti klaim AAD 67 byte atau kuota berkas lawas) dinyatakan telah usang (*superseded*) oleh Tingkat 1 tanpa mengubah fisik berkas historis.

---

## 3. PARAMETER KANONIKAL YANG TELAH DIKUNCI

Seluruh parameter berikut telah dikunci secara mutlak dan dilarang dimodifikasi dalam pelaksanaan prototipe EXP-02 kelak:

```
+-------------------------------------------------------------------------------------------------------------+
|                                    SPESIFIKASI KANONIKAL MUTLAK EXP-02                                      |
+----+----------------------------+------------------------------------------+--------------------------------+
| No | Parameter                  | Nilai Tetap yang Wajib Dipatuhi          | Satuan / Format                |
+----+----------------------------+------------------------------------------+--------------------------------+
| 1  | Algoritma Enkripsi Lampiran| AES-256-GCM                              | NIST SP 800-38D / RFC 5116     |
| 2  | Panjang Kunci Simetris(CEK)| 256-bit (32 byte)                        | CSPRNG per upload session      |
| 3  | Panjang Nonce / IV AES-GCM | 96-bit (12 byte)                         | Salt 8 byte || Counter 4 byte  |
| 4  | Panjang Authentication Tag | 128-bit (16 byte)                        | Di luar kuota plaintext        |
| 5  | Ukuran Plaintext Chunk (C) | Tepat 1.048.576 byte                     | 1 MiB biner (2^20 byte)        |
| 6  | Batas Maksimum Satu Berkas | Maksimum 10.000.000 byte                 | Desimal (10 MB kuota file)     |
| 7  | Batas Gabungan Satu Pesan  | Maksimum 25.000.000 byte                 | Desimal (25 MB kuota pesan)    |
| 8  | Batas Maksimum Jumlah File | Maksimum 5 berkas per pesan chat         | Integer (1 <= k <= 5)          |
| 9  | Panjang AAD Kanonikal      | Tepat 83 byte                            | 10 field biner terstruktur     |
| 10 | Panjang Konteks Exporter   | Tepat 48 byte                            | Room || Message || Attachment  |
| 11 | Format Penyimpanan Chunk   | Format A: Nonce || Ciphertext || AuthTag | L_i + 28 byte per chunk        |
| 12 | Overhead Framing per Chunk | Tepat 28 byte (Nonce 12 B + Tag 16 B)    | Di luar kuota plaintext        |
| 13 | Formula Jumlah Chunk Total | N = ceil(S / C), berkas 0 byte ditolak   | 1 <= N <= 10                   |
| 14 | Formula Validasi Indeks    | 0 <= chunk_index < N                     | chunk_index >= N ditolak mutlak|
+----+----------------------------+------------------------------------------+--------------------------------+
```

### 3.1. Ketentuan Format Biner Spesifik:
1. **Representasi UUID:** Seluruh nilai UUID (`Room UUID`, `Message UUID`, `Attachment UUID`, dan `Upload Session ID`) **wajib direpresentasikan sebagai 16 byte biner mentah (*raw 16-byte UUID binary representation*)**, bukan representasi string heksadesimal 36 karakter bertanda hubung.
2. **Struktur Nonce Opsi 1 (12 byte):**
   $$\text{Nonce} = \text{UploadSessionSalt}\ (8\text{ byte}) \parallel \text{uint32be}(\text{chunk\_index})\ (4\text{ byte})$$
3. **Layout AAD Kanonikal 83-Byte (B-CHAT-08A.5 Bagian 2):**
   - Field 1: `Magic Header` = 4 byte ASCII `UNH1` (`0x55 0x4E 0x48 0x31`)
   - Field 2: `Protocol Version` = 2 byte `uint16be` (`0x0001`)
   - Field 3: `Domain Separator` = 1 byte `uint8` (`0x02` = ATTACHMENT_CHUNK)
   - Field 4: `Room UUID` = 16 byte biner mentah
   - Field 5: `Message UUID` = 16 byte biner mentah
   - Field 6: `Attachment UUID` = 16 byte biner mentah
   - Field 7: `Upload Session ID` = 16 byte biner mentah
   - Field 8: `Chunk Index` = 4 byte `uint32be` ($0 \le \text{chunk\_index} < N$)
   - Field 9: `Total Chunks` = 4 byte `uint32be` ($1 \le N \le 10$)
   - Field 10: `Plaintext Length` = 4 byte `uint32be` ($1 \le L_i \le 1.048.576$)
4. **Formula Ukuran Chunk Terakhir ($L_{\text{last}}$):**
   $$L_{\text{last}} = \begin{cases} S \bmod C, & \text{jika } S \bmod C \ne 0 \\ C, & \text{jika } S \bmod C = 0 \end{cases}$$
5. **Konteks MLS Exporter Secret OD-17 (48 byte biner):**
   $$\text{Exporter Context} = \text{RoomUUID}\ (16\text{ B}) \parallel \text{MessageUUID}\ (16\text{ B}) \parallel \text{AttachmentUUID}\ (16\text{ B})$$

---

## 4. EMPAT PRAKONDISI EKSPERIMEN YANG TELAH DITENTUKAN

### 4.1. Trusted Synthetic Manifest Fixture
1. **Status Sumber Manifest:**  
   Eksperimen EXP-02 **wajib menggunakan manifest sintetis yang dibuat khusus untuk fixture pengujian (*test fixture*)** di dalam sandbox terisolasi. Fixture ini memuat parameter deterministik: `file_uuid`, `total_size_bytes`, `total_chunks`, daftar `chunk_index`, ukuran tiap chunk, hash ciphertext tiap chunk, dan hash SHA-256 plaintext akhir.
2. **Keabsahan Terbatas pada Sandbox:**  
   Sumber fixture manifest dianggap tepercaya (*trusted origin*) **hanya di dalam lingkungan pengujian terisolasi sandbox Node.js**.
3. **Pemisahan Tiga Lapisan Kegagalan Manifest vs Kriptografi:**  
   Pengujian wajib membedakan secara tegas tiga mekanisme penolakan:
   - **Kasus 1 (Modifikasi AAD Kriptografis — UJI-D9A):** Field AAD dimodifikasi $\rightarrow$ Gagal pada lapisan dekripsi AES-GCM melempar `OperationError`.
   - **Kasus 2 (Modifikasi Metadata Manifest — UJI-D9B):** AAD asli dan valid, dekripsi GCM sukses, namun metadata panjang/jumlah chunk pada manifest dipalsukan $\rightarrow$ Gagal pada lapisan perakitan aplikasi melempar **`ManifestConsistencyError`**.
   - **Kasus 3 (Kerusakan/Ketidaklengkapan Chunk — UJI-D6, UJI-D7, UJI-D11):** Chunk hilang, duplikat, atau urutan terputus $\rightarrow$ Gagal pada validasi struktur perakitan melempar **`AssemblyError`**.
4. **Batas Klaim Keaslian Manifest:**  
   Dilarang mengklaim bahwa EXP-02 membuktikan autentikasi manifest produksi. AAD kanonikal 83-byte **tidak memproteksi manifest secara langsung**. Karena protokol distribusi manifest aman melalui pesan chat E2EE belum diuji, status autentikasi manifest produksi tetap:
   $$\mathbf{STATUS:\ DESIGN\ DECISION\ REQUIRED\ /\ UNVERIFIED}$$

---

### 4.2. Persistensi dan Crash Recovery Safety
1. **Ketentuan Pengiriman Ulang (Retry):**  
   Pengiriman ulang chunk akibat gangguan jaringan **hanya diizinkan menggunakan ciphertext yang telah terbukti tersimpan utuh di media penyimpanan lokal**, tanpa memanggil operasi enkripsi ulang.
2. **Kewajiban Pembatalan Sesi (Session Abandonment):**  
   Apabila terjadi crash, pemutusan proses mendadak, kegagalan penulisan disk, atau ketidakpastian apakah proses enkripsi/persistensi telah selesai secara sempurna, sesi unggah tersebut **wajib ditinggalkan seutuhnya (*session abandonment*)**.
3. **Pembangkitan Tiga Entitas Sesi Baru:**  
   Sesi unggah baru yang menggantikan sesi yang gagal wajib membangkitkan:
   - **CEK baru** via CSPRNG (kunci simetris AES-256 baru),
   - **UploadSessionID baru** (UUID v4 baru), dan
   - **UploadSessionSalt baru** via CSPRNG (salt acak 64-bit baru).
4. **Batas Persistensi Sandbox:**  
   Pada lingkungan prototipe EXP-02, penyimpanan lokal disimulasikan menggunakan media file biner sementara lokal (`scratch/`). EXP-02 **tidak menguji ketahanan terhadap *power loss* atau crash fisik sistem operasi**. Skenario ketahanan penyimpanan fisik (*crash durability*) diklasifikasikan sebagai:
   $$\mathbf{STATUS:\ UNVERIFIED}$$
   *(Dilarang memperluas eksperimen menjadi pengujian IndexedDB atau database produksi).*

---

### 4.3. Cleanup Memori dan Keterbatasan Runtime JavaScript
Pengelolaan memori pada skenario pembatalan staging (UJI-D13) secara disiplin memisahkan tiga fase:
1. **Fase 1 — Pengisian Nol (`buffer.fill(0)`):**  
   Penimpaan nilai biner nol pada instance `Uint8Array` buffer yang masih berada dalam jangkauan referensi aktif kode JavaScript (*best-effort cleanup*).
2. **Fase 2 — Pelepasan Referensi (`buffer = null`):**  
   Penghapusan referensi objek JavaScript agar buffer memenuhi syarat untuk didaur ulang (*eligible for garbage collection*). Tindakan ini **tidak menyebabkan eksekusi Garbage Collector secara instan**.
3. **Fase 3 — Garbage Collection oleh Runtime V8:**  
   Reklamasi memori fisik yang sepenuhnya berada di bawah kendali engine V8 dan sistem operasi host.
4. **Aturan Pelaporan Cleanup:**
   - Dilarang keras mengklaim *secure memory erasure*, *physical memory wipe*, atau *plaintext dipastikan terhapus dari RAM fisik*.
   - Pengamatan metrik memori pasca-cleanup bersifat **observasional empiris**.
   - Hasil cleanup di Node.js bukan bukti bahwa seluruh salinan data internal Web Crypto/OpenSSL telah musnah.

---

### 4.4. Nonce Ledger dan Anti-Reuse (UJI-E1)
1. **Hakikat Pengujian UJI-E1:**  
   Skenario UJI-E1 secara eksklusif menguji **keandalan komponen *nonce ledger* pada prototipe aplikasi klien UNUHA**.
2. **Karakteristik Stateless Web Crypto API:**  
   Web Crypto API W3C (`crypto.subtle.encrypt`) **tidak memiliki mekanisme pendeteksi nonce reuse bawaan**. API ini akan tetap mengeksekusi enkripsi sekalipun pasangan $(\text{CEK}, \text{Nonce})$ yang sama digunakan ulang.
3. **Mekanisme Kerja Ledger Prototipe:**  
   Ledger aplikasi prototipe wajib mencatat setiap pasangan $(\text{CEK\_ID}, \text{Nonce})$ seketika sejak operasi enkripsi dimulai. Jika ada pemanggilan enkripsi baru dengan pasangan yang sudah tercatat, ledger wajib menolak operasi secara *fail-closed* sebelum `crypto.subtle.encrypt` dipanggil.
4. **Batas Generalisasi Bukti:**  
   Keberhasilan UJI-E1 hanya membuktikan logika ledger prototipe di sandbox Node.js; **tidak membuktikan keselamatan nonce pada arsitektur produksi peramban multi-tab atau multi-worker**.

---

## 5. KEPUTUSAN DESAIN YANG MASIH TERBUKA

Untuk menjaga integritas pengujian dan tidak mendahului hasil empiris maupun kewenangan arsitektur, gerbang final membedakan dengan tegas parameter yang sengaja dibiarkan terbuka dari keputusan desain produksi yang membutuhkan persetujuan Pemilik Proyek:

### 5.1. Parameter yang Dibiarkan Terbuka untuk Dibuktikan Empiris melalui EXP-02
Parameter berikut **TIDAK DIKUNCI** sebelum eksperimen dan sengaja dibiarkan terbuka untuk diukur dan dianalisis secara faktual di sandbox:
1. **Baseline Memori Empiris:** Nilai riil konsumsi alokasi native Node.js V8 sebelum pemrosesan berkas dimulai pada workstation laboratorium.
2. **Lonjakan Alokasi Memori Aktual ($\Delta\text{arrayBuffers}$):** Nilai puncak alokasi buffer biner saat pemrosesan streaming berkas ukuran maksimum 10 MB.
3. **Validasi Ambang Memori Usulan ($\le 20\text{ MB}$):** Menguji apakah target usulan (*proposed*) $\le 20\text{ MB}$ realistis atau memerlukan penyesuaian berbasis data sebaran faktual ($N$, median, $p95$, mean, max).
4. **Latensi Pemrosesan Empiris:** Waktu yang dibutuhkan untuk operasi pemotongan chunk, serialisasi biner AAD 83-byte, enkripsi AES-256-GCM, dan dekripsi per chunk 1 MiB serta keseluruhan berkas 10 MB.
5. **Karakteristik Siklus Garbage Collection V8:** Pola penurunan kurva memori pasca-pelepasan buffer (`buffer = null`) pada runtime Node.js v25.3.0.

### 5.2. Keputusan Desain yang Memerlukan Keputusan Pemilik Proyek (DESIGN DECISION REQUIRED)
Keputusan desain berikut **BERADA DI LUAR LINGKUP EXP-02** dan wajib diputuskan oleh Pemilik Proyek sebelum implementasi fitur bimbingan online masuk ke tahap produksi:
1. **Format Wire Message Chat untuk Distribusi Manifest Terenkripsi:**  
   Menentukan apakah metadata manifest lampiran (daftar hash chunk, nama file asli, MIME type, ukuran total) disematkan sebagai payload pesan kontrol MLS terenkripsi, atau menggunakan skema metadata terpisah yang ditandatangani secara kriptografis.  
   $$\mathbf{Status:\ DESIGN\ DECISION\ REQUIRED}$$
2. **Mekanisme Transfer Kunci Lampiran OD-17 (Approach A vs Approach B):**  
   Memilih antara Pendekatan A (CEK acak per berkas yang dibungkus dengan kunci simetris per-pesan) atau Pendekatan B (derivasi deterministik CEK langsung dari MLS Exporter Secret 48-byte) yang akan dievaluasi pada eksperimen EXP-03.  
   $$\mathbf{Status:\ DESIGN\ DECISION\ REQUIRED}$$
3. **Strategi Penyimpanan Persisten dan Staging Klien Produksi:**  
   Menentukan teknologi persistensi offline pada browser Next.js klien (apakah menggunakan IndexedDB dengan enkripsi data-at-rest, Origin Private File System / OPFS, atau Cache API) serta mekanisme penanganan crash peramban web nyata.  
   $$\mathbf{Status:\ DESIGN\ DECISION\ REQUIRED}$$
4. **Penanganan Sesi Crash dan Pembersihan Berkas Yatim Piatu pada Backend:**  
   Menetapkan masa kedaluwarsa (*Time-To-Live / TTL*) bagi upload session yang ditinggalkan (*abandoned sessions*) pada backend Laravel, serta cron job pembersihan chunk parsial yang tidak pernah selesai dirakit.  
   $$\mathbf{Status:\ DESIGN\ DECISION\ REQUIRED}$$
5. **Arsitektur Sinkronisasi Nonce Ledger Lintas-Konteks Peramban:**  
   Menetapkan arsitektur ledger anti-reuse nonce yang aman dari *race condition* jika pengguna membuka aplikasi Next.js pada beberapa tab browser bersamaan (*multi-tab concurrency*) atau menggunakan Web Worker ganda.  
   $$\mathbf{Status:\ DESIGN\ DECISION\ REQUIRED}$$

---

## 6. BATAS RUANG LINGKUP EKSPERIMEN (IN-SCOPE VS OUT-OF-SCOPE)

Tabel berikut menetapkan batas demarkasi yang tegas antara komponen yang diuji dalam prototipe EXP-02 dan komponen sistem produksi yang dilarang disentuh:

```
+-------------------------------------------------------------------------------------------------------------+
|                                    BATAS RUANG LINGKUP EKSPERIMEN EXP-02                                    |
+----+----------------------------------------------+--------------------+------------------------------------+
| No | Komponen / Aktivitas                         | Status Lingkup     | Batasan Teknis & Koridor Pengujian |
+----+----------------------------------------------+--------------------+------------------------------------+
| 1  | Pemotongan berkas menjadi chunk 1 MiB        | IN-SCOPE           | S <= 10.000.000 B, 1 <= N <= 10    |
| 2  | Serialisasi & Validasi AAD Kanonikal 83-Byte | IN-SCOPE           | 10 field biner terurut ketat       |
| 3  | Enkripsi & Dekripsi AES-256-GCM Web Crypto   | IN-SCOPE           | globalThis.crypto.subtle Node.js   |
| 4  | Framing Format A (Nonce || Cipher || Tag)    | IN-SCOPE           | Overhead tepat 28 byte per chunk   |
| 5  | Adversarial Tampering Test Suite (35 Kasus)  | IN-SCOPE           | Tampering ciphertext, tag, AAD     |
| 6  | Perakitan Transport Reordering (Acak/Terbalik| IN-SCOPE           | Assembled by authenticated index   |
| 7  | Validasi Fixture Manifest Sintetis           | IN-SCOPE           | Uji konsistensi assembly lokal     |
| 8  | Penegakan Batas Kuota Berlapis (OD-02)       | IN-SCOPE           | 10 MB file, 25 MB pesan, 5 berkas  |
| 9  | Nonce-Use Ledger Prototipe (UJI-E1)          | IN-SCOPE           | Kontrol state aplikasi prototipe   |
| 10 | Observasi Metrik Memori Streaming (UJI-F1/F2)| IN-SCOPE           | delta arrayBuffers, external, rss  |
+----+----------------------------------------------+--------------------+------------------------------------+
| 11 | Kompilasi & Integrasi Pustaka MLS Rust-WASM  | OUT-OF-SCOPE       | Ranah EXP-01 dan evaluasi lanjutan |
| 12 | Evaluasi Komparatif Kunci Lampiran OD-17     | OUT-OF-SCOPE       | Ranah khusus eksperimen EXP-03     |
| 13 | Autentikasi Manifest Produksi via MLS Chat   | OUT-OF-SCOPE       | Memerlukan integrasi pesan kontrol |
| 14 | Pengujian Browser Nyata (Chromium/Gecko/Kit) | OUT-OF-SCOPE       | Di luar lingkungan sandbox Node.js |
| 15 | Pengukuran UI Freeze / Browser Long Task     | OUT-OF-SCOPE       | NOT MEASURED pada Node.js CLI      |
| 16 | Integrasi Backend Laravel & Next.js Produksi | OUT-OF-SCOPE       | Direktori aplikasi dilarang disentuh|
| 17 | Transmisi Jaringan HTTP Multipart / WebSocket| OUT-OF-SCOPE       | Pengujian lokal di memori sandbox  |
| 18 | Ketahanan Crash Durability Media Fisik       | OUT-OF-SCOPE       | Memerlukan pengujian storage crash |
| 19 | Jaminan Secure Memory Erasure pada Hardware  | OUT-OF-SCOPE       | Keterbatasan arsitektur runtime V8 |
| 20 | Proteksi Menyeluruh Anti-Session Hijacking   | OUT-OF-SCOPE       | AAD hanya mengikat UploadSessionID |
+----+----------------------------------------------+--------------------+------------------------------------+
```

---

## 7. LINGKUNGAN PENGUJIAN DAN BATASAN BUKTI ILMIAH

### 7.1. Identifikasi Profil Workstation Aktual (Audit Statis Terverifikasi)
Pengujian EXP-02 direncanakan berjalan pada workstation laboratorium dengan profil sistem terverifikasi faktual:
- **Sistem Operasi:** Microsoft Windows 11 Enterprise (NT 10.0.26300.0) 64-bit
- **Arsitektur CPU:** AMD64 (x86_64)
- **Runtime JavaScript:** Node.js v25.3.0 (V8 Engine dengan antarmuka native Web Crypto API pada `globalThis.crypto`)
- **Package Manager:** NPM v11.6.2
- **Framework Target Produksi:** Next.js v14.2.10 (React 18.2.0)
- **Pustaka Worker:** Node.js `worker_threads` (jika prototipe mengevaluasi eksekusi latar belakang)

> [!WARNING]
> **PENEGASAN PEMISAHAN WORKER:**  
> Worker pada Node.js (`worker_threads`) **TIDAK SAMA** dengan Web Worker pada peramban web nyata. Perbedaan mekanisme alokasi memori, penyalinan buffer (*structured cloning*), antarmuka `postMessage`, dan ketiadaan konteks antarmuka pengguna (DOM / UI renderer) mengharuskan seluruh hasil eksekusi latar belakang di Node.js diisolasi secara ketat dan dilarang diklaim mewakili karakteristik peramban web nyata.

---

### 7.2. Batas Bukti Ilmiah yang Sah
- **Bukti yang Sah Dihasilkan dari EXP-02:**
  1. Bukti fungsional pemotongan chunk 1 MiB, serialisasi biner AAD 83-byte, dan framing Format A pada engine V8.
  2. Bukti ketahanan penolakan AES-256-GCM terhadap modifikasi ciphertext, auth tag, dan field AAD pada Web Crypto Node.js.
  3. Bukti keandalan penataan ulang chunk (*reordering*) berdasarkan indeks AAD terautentikasi.
  4. Bukti penolakan pelanggaran batas kuota pada lapisan pra-serialisasi aplikasi prototipe.
  5. Profil latensi pemrosesan lokal dan konsumsi alokasi buffer native ($\Delta\text{arrayBuffers}$) di Node.js.
- **Klaim yang DILARANG KERAS Dibuat dari EXP-02:**
  1. Klaim bahwa seluruh peramban web nyata (Chrome, Edge, Firefox, Safari) kompatibel.
  2. Klaim bahwa aplikasi Next.js 14 tidak akan mengalami UI freeze di peramban web (*UI Freeze is NOT MEASURED on Node.js CLI*).
  3. Klaim bahwa sistem produksi telah terlindungi dari pembajakan sesi secara menyeluruh (*full session hijacking protection*).
  4. Klaim bahwa data plaintext telah musnah secara fisik dari RAM sistem operasi (*secure wipe*).
  5. Klaim bahwa penyimpanan lokal kebal terhadap kegagalan daya atau crash perangkat keras (*crash-proof storage*).

---

## 8. METRIK TELEMETRI DAN DISIPLIN STATISTIK

### 8.1. Hierarki Empat Metrik Memori Node.js (`process.memoryUsage()`)
1. **Metrik Primer yang Diamati:**  
   $$\Delta\text{arrayBuffers} = \text{arrayBuffers}_{\text{peak}} - \text{arrayBuffers}_{\text{baseline}}$$  
   Mencatat secara spesifik lonjakan alokasi memori *backing store* untuk objek `ArrayBuffer` dan `Buffer` Node.js yang dialokasikan di luar heap JavaScript V8.
2. **Metrik Pendukung:**  
   $$\Delta\text{external} = \text{external}_{\text{peak}} - \text{external}_{\text{baseline}}$$  
   Mencatat memori C++ native yang terikat pada objek JavaScript V8.
3. **Metrik Konteks:**  
   - $\text{rss}$ (*Resident Set Size*): Konteks total RAM fisik proses Node.js pada sistem operasi.
   - $\text{heapUsed}$: Konteks alokasi objek JavaScript murni pada heap engine V8.
4. **Larangan Penjumlahan Redundan:**  
   **DILARANG KERAS menjumlahkan $\text{external} + \text{arrayBuffers}$** karena alokasi `arrayBuffers` sudah merupakan bagian dari alokasi yang dicakup oleh `external`.
5. **Keterbatasan Sampling:**  
   Sampling periodik dapat melewatkan lonjakan memori transien (*transient spikes*) yang terjadi di antara interval sampling.
6. **Status Ambang Batas Usulan:**  
   Ambang $\Delta\text{arrayBuffers} \le 20\text{ MB}$ tetap berstatus usulan (**`PROPOSED`**) untuk divalidasi oleh hasil empiris, bukan angka mutlak yang mengikat kriteria kelulusan sistem produksi.

---

### 8.2. Disiplin Statistik dan Pelaporan Sampel
1. **Pemisahan Kategori Statistik:**
   - **Pengulangan 5–10 Iterasi:** Diklasifikasikan secara jujur sebagai **observasi eksploratif awal**, bukan bukti statistik performa tergeneralisasi.
   - **Pemisahan Notasi:** Wajib membedakan secara ketat antara jumlah iterasi pengulangan benchmark ($I$), jumlah operasi alur kerja ($O$), dan jumlah sampel telemetri aktual ($N$).
2. **Kewajiban Pelaporan Parameter Sebaran Lengkap:**  
   Setiap kali metrik latensi atau konsumsi memori dilaporkan dalam laporan pengujian, pelaporan wajib menyertakan nilai faktual:
   - Jumlah sampel aktual ($N$),
   - Median,
   - Persentil 95 ($p95$),
   - Mean (rata-rata aritmetika),
   - Minimum dan Maksimum,
   - Standar Deviasi ($\sigma$).  
   *(Dilarang keras menyajikan rata-rata tunggal tanpa parameter sebaran, dilarang mengarang angka sampel, dan dilarang mengekstrapolasi data yang tidak diukur langsung).*

---

## 9. KRITERIA HASIL EKSPERIMEN (PASS, FAIL, UNVERIFIED)

Evaluasi terhadap 35 skenario granular EXP-02 diklasifikasikan secara ketat ke dalam tiga kategori kriteria hasil objektif:

```
+-------------------------------------------------------------------------------------------------------------+
|                                    KLASIFIKASI KRITERIA HASIL EKSPERIMEN                                    |
+-------------+-----------------------------------------------------------------------------------------------+
| Kategori    | Definisi dan Kondisi Evaluasi                                                                 |
+-------------+-----------------------------------------------------------------------------------------------+
| PASS        | Kondisi yang diharapkan terpenuhi secara sempurna berdasarkan pengujian faktual yang benar-   |
|             | benar dieksekusi di sandbox dan hasilnya terverifikasi secara objektif:                       |
|             | 1. Plaintext hasil dekripsi identik 100% byte-by-byte (SHA-256 match) dengan berkas asal.     |
|             | 2. Seluruh 35 skenario pengujian menghasilkan respons sesuai target hasil spesifikasi.        |
|             | 3. Manipulasi biner (cipher, tag, AAD) ditolak mutlak melempar OperationError.                |
|             | 4. Pelanggaran kuota (file, pesan, jumlah) ditolak sebelum enkripsi dijalankan.               |
|             | 5. Chunk tiba acak berhasil dirakit sempurna berdasarkan indeks AAD terautentikasi.          |
|             | 6. Retry transmisi berhasil mengirimkan ciphertext tersimpan tanpa memicu re-enkripsi.        |
+-------------+-----------------------------------------------------------------------------------------------+
| FAIL        | Terjadi kegagalan pemenuhan spesifikasi atau pelanggaran prinsip kriptografi/fungsional:      |
|             | 1. Pasangan (CEK, Nonce) digunakan ulang untuk operasi enkripsi yang berbeda.                 |
|             | 2. File > 10.000.000 B, pesan > 25.000.000 B, atau jumlah berkas > 5 lolos pemrosesan.       |
|             | 3. Terjadi kebocoran plaintext parsial sebelum seluruh chunk dan manifest tervalidasi.        |
|             | 4. Data termanipulasi berhasil didekripsi tanpa melempar OperationError.                      |
|             | 5. Plaintext hasil dekripsi berbeda bit-demi-bit dari berkas plaintext asal.                 |
|             | 6. Sesi crash mengulang enkripsi dengan CEK atau salt lama (pelanggaran session abandonment). |
+-------------+-----------------------------------------------------------------------------------------------+
| UNVERIFIED  | Aspek-aspek teknis yang belum diuji, tidak diukur, atau berada di luar batas lingkup EXP-02:   |
|             | 1. Perilaku Web Crypto API pada mesin peramban web nyata (Chromium/Gecko/WebKit).             |
|             | 2. Ketiadaan UI Freeze / browser Long Task pada frontend Next.js 14.                          |
|             | 3. Mekanisme autentikasi dan integritas manifest produksi melalui pesan chat MLS.             |
|             | 4. Ketahanan media penyimpanan lokal terhadap power loss atau abrupt crash fisik.             |
|             | 5. Jaminan penghapusan data secara fisik dari RAM hardware (secure memory erasure).           |
|             | 6. Evaluasi komparatif pendekatan kunci lampiran OD-17 (Approach A vs Approach B).            |
+-------------+-----------------------------------------------------------------------------------------------+
```

> [!CAUTION]
> **ATURAN INTEGRITAS PELAPORAN MUTLAK:**  
> Parameter atau kondisi yang tidak dieksekusi atau tidak terukur secara empiris di sandbox **DILARANG KERAS DIBERI STATUS `PASS`**. Parameter tersebut wajib secara jujur diberi status **`UNVERIFIED`** atau **`NOT MEASURED`**.

---

## 10. GERBANG OTORISASI (PRE-EXECUTION READINESS CHECKLIST)

Sebelum Pemilik Proyek memutuskan pemberian otorisasi pelaksanaan fisik EXP-02, daftar periksa kesiapan berikut dievaluasi:

```
+-------------------------------------------------------------------------------------------------------------+
|                                  CHECKLIST GERBANG PRE-EKSEKUSI EXP-02                                      |
+----+-------------------------------------------------------------------------+------------+-----------------+
| No | Parameter Pemeriksaan Kesiapan                                          | Status     | Rujukan Dokumen |
+----+-------------------------------------------------------------------------+------------+-----------------+
| 1  | Parameter teknis kanonikal telah direkonsiliasi tanpa pertentangan      | TERPENUHI  | B-08C Bagian 3  |
| 2  | Hierarki dokumen tidak menyisakan konflik spesifikasi operasional       | TERPENUHI  | B-08B.1 Bagian 6|
| 3  | Fixture manifest sintetis tepercaya telah ditentukan koridornya         | TERPENUHI  | B-08C Bagian 4.1|
| 4  | Batas persistensi dan aturan crash recovery telah ditetapkan            | TERPENUHI  | B-08C Bagian 4.2|
| 5  | Batas klaim cleanup memori (best-effort) telah ditetapkan               | TERPENUHI  | B-08C Bagian 4.3|
| 6  | Desain application-level nonce ledger telah ditetapkan operasionalnya   | TERPENUHI  | B-08C Bagian 4.4|
| 7  | Matriks skenario telah direkonsiliasi tuntas (35 skenario granular)     | TERPENUHI  | B-08B.1 Bagian 4|
| 8  | Lingkungan eksekusi workstation aktual telah diidentifikasi faktual     | TERPENUHI  | B-08C Bagian 7.1|
| 9  | Direktori sandbox terisolasi telah ditentukan koridor batasannya        | TERPENUHI  | B-08C Bagian 11 |
| 10 | Batas berkas yang boleh dibuat saat otorisasi terbit telah jelas        | TERPENUHI  | B-08C Bagian 11 |
| 11 | Kriteria evaluasi PASS, FAIL, dan UNVERIFIED telah didefinisikan baku   | TERPENUHI  | B-08C Bagian 9  |
| 12 | Kondisi penghentian darurat (circuit breaker / fail-closed) telah jelas | TERPENUHI  | B-08C Bagian 11 |
+----+-------------------------------------------------------------------------+------------+-----------------+
```

### Hasil Evaluasi Gerbang Final:
Seluruh 12 butir prakondisi kesiapan teknis telah terpenuhi secara paripurna dalam dokumentasi, tanpa ada pertentangan spesifikasi kanonikal yang tersisa. Oleh karena itu, status evaluasi gerbang ditetapkan sebagai:

$$\mathbf{HASIL\ GERBANG:\ READY\ FOR\ OWNER\ AUTHORIZATION\ REVIEW}$$

> [!IMPORTANT]
> **PENEGASAN HASIL GERBANG:**  
> Status **`READY FOR OWNER AUTHORIZATION REVIEW`** menyatakan bahwa seluruh prasyarat dokumentasi telah matang dan siap ditinjau oleh Pemilik Proyek. Status ini **BUKAN MERUPAKAN IZIN ATAU OTORISASI** untuk memulai eksekusi fisik prototipe EXP-02.

---

## 11. BATAS OPERASIONAL DAN INTEGRITAS ARSIP

Mandat penyusunan dokumen B-CHAT-08C telah dilaksanakan dengan kepatuhan penuh terhadap batas operasional:

1. **Koridor Sandbox Terisolasi:**  
   Jika kelak diotorisasi tertulis oleh Pemilik Proyek, ruang kerja prototipe dibatasi secara eksklusif pada:
   $$\text{Direktori Sandbox:\ } \texttt{scratch/bchat\_prototype/exp02\_chunking\_aad/}$$
2. **Batas Berkas yang Diizinkan Dibuat (Hanya Setelah Otorisasi Terbit):**  
   - Berkas konfigurasi sandbox lokal: `package.json` dan `tsconfig.json`.
   - Modul logika prototipe: generator berkas sintetis, serializer AAD 83-byte, AES-GCM cipher, serializer Format A, validator manifest sintetis, dan *in-memory nonce ledger*.
   - Berkas data uji sintetis lokal (ekstensi `.bin`, ukuran acak $1\text{ B}$ s.d. $10.000.000\text{ B}$).
   - Laporan pengukuran telemetri JSON mentah: `reports/EXP02_raw_measurements.json` dan `reports/EXP02_summary.json`.
3. **Kepatuhan Larangan Operasional Mutlak Saat Ini (Tujuh Komitmen Zero Execution):**
   - **Zero Prototype Code:** Direktori sandbox di atas **belum dibuat**, dan tidak ada baris kode prototipe yang ditulis.
   - **Zero Execution, Benchmark, & Testing:** Tidak ada uji coba runtime, benchmark memori, simulasi, maupun build yang dijalankan.
   - **Zero Dependency Installation:** Tidak ada paket npm, pustaka eksternal, atau compiler yang dipasang.
   - **Zero Main Codebase Changes:** Direktori aplikasi Laravel ([`manajemen_skripsi/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/manajemen_skripsi/)) dan Next.js ([`frontend_manajemen_skripsi/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/frontend_manajemen_skripsi/)) tidak disentuh sama sekali.
   - **Zero Database Changes:** Tidak ada migrasi basis data, perubahan skema, atau query SQL yang dieksekusi.
   - **Zero Git Operations:** Tidak ada perintah Git (`git status`, `git diff`, `git commit`, `git checkout`, `git push`, dsb.) yang dijalankan.
   - **Zero Historical Overwrite:** Seluruh dokumen historis ([B-CHAT-05](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-05_Spesifikasi_Final_E2EE.md), [B-CHAT-06D](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-06D_Mandat_Eksekusi_Terbatas_Gerbang_2.md), [B-CHAT-08A](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A_Rencana_dan_Mandat_EXP-02.md) s.d. [B-CHAT-08A.5](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.5_Final_Editorial_Precision_Closure_EXP-02.md), dan [B-CHAT-08B.1](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08B.1_Koreksi_Presisi_Final_Readiness_Audit_EXP-02.md)) tetap tersimpan utuh di repositori.

---

## 12. PERNYATAAN EKSPLISIT STATUS OTORISASI EXP-02

$$\mathbf{STATUS\ FINAL\ OTORISASI\ EXP\text{-}02:\ EXP\text{-}02\ NOT\ AUTHORIZED}$$

> [!CAUTION]
> **PERINGATAN OPERASIONAL PENUTUP:**  
> Dokumen **B-CHAT-08C** ini secara resmi menutup seluruh rangkaian evaluasi dan persiapan teknis Gerbang 2 untuk eksperimen EXP-02. Pelaksanaan fisik di sandbox **TIDAK BOLEH DIMULAI** sebelum Pemilik Proyek memeriksa dokumen gerbang ini dan menerbitkan **persetujuan tertulis eksplisit terpisah**.

---
*(Dokumen B-CHAT-08C selesai disusun secara paripurna sebagai gerbang final pre-eksekusi EXP-02, siap diserahkan untuk peninjauan Pemilik Proyek).*
