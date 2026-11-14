# B-CHAT-08C.2 — FINAL GATE EVIDENCE & INTEGRITY PRECISION CORRECTION EXP-02
## KOREKSI BUKTI, INTEGRITAS CIPHERTEXT, DAN KONSISTENSI GERBANG FINAL PRE-EKSEKUSI EXP-02

**Status Dokumen:** DOKUMEN AMENDEMEN GERBANG PRE-EKSEKUSI FINAL KEDUA (*SECOND CONTROLLED GATE AMENDMENT*)  
**Mode Operasi:** STRICTLY DOCUMENTATION ONLY — ZERO EXECUTION  
**Status Otorisasi EXP-02:** **EXP-02 NOT AUTHORIZED** *(Eksperimen EXP-02 belum diotorisasi)*  
**Dokumen Induk:** [B-CHAT-08C_EXP-02_Final_Pre-Execution_Gate.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08C_EXP-02_Final_Pre-Execution_Gate.md)  
**Dokumen Amendemen Sebelumnya:** [B-CHAT-08C.1_Final_Gate_Precision_Correction_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08C.1_Final_Gate_Precision_Correction_EXP-02.md)  
**Dokumen Acuan Evaluasi Terverifikasi:**
1. [B-CHAT-08B.1_Koreksi_Presisi_Final_Readiness_Audit_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08B.1_Koreksi_Presisi_Final_Readiness_Audit_EXP-02.md)
2. [B-CHAT-08A.5_Final_Editorial_Precision_Closure_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.5_Final_Editorial_Precision_Closure_EXP-02.md)
3. [B-CHAT-07A_Laporan_Eksekusi_EXP-01.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A_Laporan_Eksekusi_EXP-01.md)  
**Proyek:** Sistem Informasi Manajemen Skripsi UNUHA  
**Fitur:** Bimbingan Online Chat Real-Time E2EE  
**Tahap Proyek:** Penutupan Final Gerbang 2 Pre-Eksekusi EXP-02  
**Tanggal Penerbitan:** 5 November 2026  

---

> [!CAUTION]
> **PENEGASAN STATUS OPERASIONAL MUTLAK:**  
> Dokumen **B-CHAT-08C.2** ini berstatus **STRICTLY DOCUMENTATION ONLY — ZERO EXECUTION — EXP-02 NOT AUTHORIZED**. Dokumen ini diterbitkan khusus untuk melengkapi dan menyempurnakan dokumen gerbang [B-CHAT-08C](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08C_EXP-02_Final_Pre-Execution_Gate.md) dan amendemen [B-CHAT-08C.1](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08C.1_Final_Gate_Precision_Correction_EXP-02.md). Penerbitan dokumen ini **BUKAN** merupakan izin atau otorisasi untuk memulai pembuatan kode, direktori sandbox, instalasi dependensi, atau eksekusi prototipe EXP-02. Selama penyusunan dokumen ini, tidak ada kode sumber yang dibuat, tidak ada direktori sandbox yang dibuat, tidak ada berkas fixture/hash yang dihasilkan, tidak ada dependensi atau toolchain yang dipasang, tidak ada benchmark/pengujian yang dijalankan, dan tidak ada operasi Git. Seluruh dokumen induk dan dokumen historis repositori dilestarikan utuh tanpa penimpaan (*zero overwrite*).

---

## 1. TUJUAN DAN RUANG LINGKUP AMENDEMEN

Dokumen **B-CHAT-08C.2** diterbitkan sebagai instrumen amendemen kedua untuk menyelesaikan lima temuan evaluasi kritis terhadap dokumen B-CHAT-08C.1:

1. **Koreksi A — Definisi Verifikasi Integritas Ciphertext Tersimpan:** Mengoreksi simplifikasi bahwa pemeriksaan ukuran $L_i + 28\text{ byte}$ membuktikan integritas artefak; merumuskan pemisahan tegas antara lima lapisan verifikasi keabsahan data; dan menetapkan batas fungsi hash lokal penyimpanan.
2. **Koreksi B — Pengikatan Identitas Artefak ke Sesi Upload:** Menetapkan sepuluh parameter pengikatan identitas artefak terhadap sesi unggah, melarang keras pencatatan CEK dalam log atau metadata diagnostik, dan menetapkan aturan retry yang menolak artefak lintas-sesi.
3. **Koreksi C — Konsistensi dan Rekonsiliasi Log Pembacaan Sumber:** Memperbaiki diskrepansi antara log analisis dan klaim rentang bacaan pada laporan terdahulu, menetapkan status bukti secara jujur, dan memverifikasi rentang aktual secara empiris.
4. **Koreksi D — Rekonsiliasi Kejujuran Checklist 12 Butir:** Menilai ulang seluruh 12 prasyarat gerbang berdasarkan bukti dokumentatif faktual dan mencatat kondisi tersisa secara transparan.
5. **Koreksi E — Formalisasi Circuit Breaker Prototipe:** Mengklasifikasikan kegagalan fatal (*emergency stop*) vs non-fatal (*scenario-level failure*), menegakkan prinsip *fail-closed*, dan menetapkan ambang numerik yang belum disepakati sebagai *Design Decision Required*.

Dokumen ini **tidak menulis ulang dokumen induk maupun amendemen sebelumnya**, melainkan menetapkan koreksi presisi yang mengikat secara hierarkis.

---

## 2. DAFTAR SUMBER YANG DIGUNAKAN DAN REKONSILIASI LOG PEMBACAAN

Untuk menegakkan integritas metodologis dan ketiadaan rekayasa log, tabel berikut menyajikan perbandingan faktual antara rentang yang diklaim pada laporan sebelumnya dengan rentang yang benar-benar diperiksa langsung pada sistem:

```
+--------------------------------------------------------------------------------------------------------------------------------------------------+
|                                           TABEL REKONSILIASI LOG PEMBACAAN SUMBER DOKUMENTATIF                                                   |
+----+--------------------------------------------+-----------------------+--------------------------------------+---------------------------------+
| No | Dokumen Sumber Otoritatif                  | Rentang Diklaim 08C.1 | Rentang Aktual Benar-Benar Diperiksa | Status Bukti Faktual            |
+----+--------------------------------------------+-----------------------+--------------------------------------+---------------------------------+
| 1  | B-CHAT-08C_EXP-02_Final_Pre-Execution_Gate | Baris 37-67, 118-178, | Baris 37-180 (§2 Rujukan & §4),      | VERIFIED DOCUMENTALLY           |
|    | .md (Dokumen Induk Gerbang Final)          | 180-210, 248-278,     | Baris 248-395 (§7 Lingkungan, §8     | (Dibaca lengkap pada sesi ini   |
|    |                                            | 360-395               | Metrik, §9 Kriteria, §10 Checklist)  | via read_file tool call)        |
+----+--------------------------------------------+-----------------------+--------------------------------------+---------------------------------+
| 2  | B-CHAT-08C.1_Final_Gate_Precision_        | N/A (Dokumen yang     | Baris 1-140 (Bab 1-5),               | VERIFIED DOCUMENTALLY           |
|    | Correction_EXP-02.md (Amendemen Pertama)   | dievaluasi)           | Baris 141-281 (Bab 5-9)              | (Seluruh 281 baris dibaca penuh)|
+----+--------------------------------------------+-----------------------+--------------------------------------+---------------------------------+
| 3  | B-CHAT-08B.1_Koreksi_Presisi_Final_        | Baris 1-67, 69-120,   | Baris 1-100 (Bab 1-4),               | VERIFIED DOCUMENTALLY           |
|    | Readiness_Audit_EXP-02.md                  | 121-155, 157-187,     | Baris 101-250 (Bab 4-8),             | (Seluruh 292 baris dibaca penuh)|
|    |                                            | 224-263, 266-292      | Baris 251-292 (Bab 9-11)             |                                 |
+----+--------------------------------------------+-----------------------+--------------------------------------+---------------------------------+
| 4  | B-CHAT-08B.0 (Laporan Audit Kesiapan       | Bagian 3, 8, 9, 10    | Audit filesystem root dilakukan;     | REFERENCE UNVERIFIED            |
|    | Pre-Otorisasi EXP-02)                      |                       | Berkas fisik TIDAK DITEMUKAN di disk | (Tidak ada berkas fisik lokal;  |
|    |                                            |                       | karena mode NO FILE CREATION         | rekonsiliasi lewat B-08B.1)     |
+----+--------------------------------------------+-----------------------+--------------------------------------+---------------------------------+
| 5  | B-CHAT-08A.5_Final_Editorial_Precision_    | Baris 1-51, 53-77,    | Baris 1-100 (Bab 1-4),               | VERIFIED DOCUMENTALLY           |
|    | Closure_EXP-02.md (Acuan Teknis Tertinggi) | 79-100, 101-171       | Baris 101-171 (Bab 5-8 selesai dibaca| (Seluruh 171 baris telah tuntas |
|    |                                            |                       | langsung pada turn saat ini)         | diverifikasi langsung)          |
+----+--------------------------------------------+-----------------------+--------------------------------------+---------------------------------+
| 6  | B-CHAT-07A_Laporan_Eksekusi_EXP-01.md      | Baris 1-56            | Baris 1-90 (Bab 1-3 Profil Work-     | VERIFIED DOCUMENTALLY           |
|    | (Laporan Historis Pelaksanaan EXP-01)      |                       | station dan Struktur Sandbox EXP-01) | (Bukti historis terverifikasi)  |
+----+--------------------------------------------+-----------------------+--------------------------------------+---------------------------------+
```

---

## 3. KOREKSI A — VERIFIKASI INTEGRITAS CIPHERTEXT TERSIMPAN

### 3.1. Keterbatasan Pemeriksaan Panjang Artefak
Pemeriksaan ukuran berkas tersimpan ($L_i + 28\text{ byte}$) yang diuraikan pada B-CHAT-08C.1 Tahap 4 **hanya membuktikan kesesuaian panjang framing (*framing length consistency*)**. Pemeriksaan panjang saja **TIDAK DAPAT** membuktikan integritas byte artefak, karena berkas yang mengalami korupsi bit (*bit-flip*), terisi blok byte kosong `0x00`, atau tertukar dengan chunk lain yang berukuran sama akan tetap lolos uji panjang framing.

### 3.2. Pemisahan Tegas Lima Lapisan Keabsahan Data
Untuk mencegah kerancuan konsep pengujian, ditetapkan pemisahan formal antara lima lapisan keabsahan:

1. **Pemeriksaan Panjang Artefak (*Framing Length Check*):**  
   Memverifikasi bahwa ukuran berkas fisik tepat sama dengan formula framing Format A ($L_i + 28\text{ byte}$, di mana 28 byte merupakan gabungan Nonce 12 byte dan Auth Tag 16 byte).
2. **Pemeriksaan Identitas Artefak (*Artifact Identity & Session Binding*):**  
   Memverifikasi bahwa artefak terikat secara unik pada metadata sesi upload yang sah (Room, Message, Attachment, UploadSessionID, ChunkIndex) dan bukan berkas dari sesi lain.
3. **Pemeriksaan Integritas Byte Artefak (*Storage Byte Integrity / Local Hash Check*):**  
   Kalkulasi nilai digest (misalnya SHA-256) atas artefak ciphertext yang baru selesai ditulis ke disk dan pencocokannya dengan nilai digest sesaat sebelum artefak dikirim ulang (*retry*). Berfungsi khusus untuk mendeteksi *silent storage corruption* atau penulisan parsial pada media lokal.
4. **Autentikasi Kriptografis AES-256-GCM (*Cryptographic AEAD Authentication*):**  
   Verifikasi integritas dan keaslian ciphertext serta AAD 83-byte menggunakan kunci rahasia CEK oleh fungsi native `crypto.subtle.decrypt`. Ini adalah **satu-satunya lapisan kriptografis** yang menjamin bahwa data belum termanipulasi.
5. **Validasi Manifest (*Manifest Consistency & File Assembly Validation*):**  
   Memverifikasi struktur perakitan berkas secara makro (pencocokan urutan seluruh chunk, ukuran total berkas, dan perbandingan hash SHA-256 plaintext akhir terhadap expected hash manifest).

### 3.3. Batasan dan Status Desain Mekanisme Hash Penyimpanan
- **Independensi Expected Hash:** Expected hash integritas penyimpanan wajib dihasilkan dari kalkulasi independen terhadap buffer ciphertext yang dihasilkan enkripsi, bukan dari fungsi validasi yang sedang diuji (*anti-circular*).
- **Bukan Pengganti AEAD:** Hash lokal penyimpanan **BUKAN** pengganti autentikasi AES-256-GCM dan **BUKAN** bukti autentikasi manifest produksi.
- **Ketiadaan Proteksi Anti-Tamper Lokal:** Hash lokal yang disimpan dalam berkas teks/metadata biasa yang tidak ditandatangani secara kriptografis **tidak memproteksi dari penyerang lokal** yang memiliki akses menulis ke sistem berkas (penyerang dapat memodifikasi artefak sekaligus memperbarui hash-nya).
- **Status Spesifikasi:** Format penyimpanan hash lokal, lokasi penyimpanan metadata digest, dan mekanisme perlindungannya belum dispesifikasikan secara detail. Oleh karena itu, statusnya ditetapkan sebagai:  
  $$\mathbf{STATUS:\ DESIGN\ DECISION\ REQUIRED}$$

> [!IMPORTANT]
> Dalam amendemen B-CHAT-08C.2 ini, **TIDAK ADA pembuatan kode program, berkas hash, atau data fixture**.

---

## 4. KOREKSI B — PENGIKATAN IDENTITAS ARTEFAK KE SESI UPLOAD

### 4.1. Sepuluh Parameter Pengikatan Identitas Artefak
Agar artefak ciphertext yang disimpan di media lokal tidak tertukar, tidak tercampur antar-sesi, dan tidak disalahgunakan pada skenario pengiriman ulang (*retry*), identitas setiap artefak chunk wajib terikat secara eksplisit pada sepuluh parameter:

1. `Room UUID` (16 byte biner mentah)
2. `Message UUID` (16 byte biner mentah)
3. `Attachment UUID` (16 byte biner mentah)
4. `UploadSessionID` (16 byte biner mentah UUID v4)
5. `UploadSessionSalt` (8 byte biner mentah CSPRNG)
6. `CEK Binding Identifier` (Identitas sesi terikat pada CEK yang aktif)
7. `Chunk Index` ($0 \le \text{chunk\_index} < N$)
8. `Total Chunks` ($1 \le N \le 10$)
9. `Plaintext Length` ($1 \le L_i \le 1.048.576\text{ byte}$)
10. `Ciphertext Storage Fingerprint` (Hash digest SHA-256 dari blok biner ciphertext lengkap)

### 4.2. Aturan Keamanan Kunci (CEK Protection Rule)
> [!CAUTION]
> **LARANGAN EKSPOS KUNCI SIMETRIS:**  
> Kunci enkripsi konten (CEK) **DILARANG KERAS dicatat dalam log teks biasa (*plaintext logs*), metadata penyimpanan lokal yang tidak terenkripsi, atau pesan diagnostik galat**. Pengikatan artefak ke sesi dilakukan menggunakan identifier sesi non-rahasia (`UploadSessionID` dan `UploadSessionSalt`), bukan dengan menyimpan atau mengekspos kunci CEK mentah.

### 4.3. Ketentuan Operasional Retry Transmisi
1. **Validasi Lengkap Sebelum Retry:**  
   Pengiriman ulang (*retry*) hanya diizinkan untuk artefak ciphertext yang telah terbukti melewati konfirmasi penutupan berkas (I/O close) dan lulus uji integritas byte lokal.
2. **Kesesuaian Identitas Sesi:**  
   Artefak yang dikirim ulang wajib cocok 100% dengan metadata sesi (`UploadSessionID`, `UploadSessionSalt`, `ChunkIndex`, `AttachmentUUID`) yang saat itu sedang aktif.
3. **Penolakan Artefak Asing:**  
   Artefak dari sesi sebelumnya atau sesi yang berbeda **DILARANG KERAS DITERIMA** meskipun memiliki ukuran framing ($L_i + 28\text{ byte}$) dan struktur format yang cocok.
4. **Larangan Mutlak Enkripsi Ulang (No Re-Encryption):**  
   Retry transmisi **TIDAK BOLEH** memanggil ulang fungsi enkripsi Web Crypto dengan pasangan $(\text{CEK}, \text{Nonce})$ yang sama.
5. **Kebijakan Fail-Closed Saat Diskrepansi Identitas:**  
   Jika hubungan artefak dengan sesi yang aktif tidak dapat dipastikan atau metadata pengikat tidak cocok, artefak tersebut wajib ditolak seketika (*fail-closed*).
6. **Kewajiban Pembatalan Sesi (Session Abandonment):**  
   Jika integritas artefak atau status penulisan lokal tidak pasti, sesi unggah tersebut wajib ditinggalkan seutuhnya (*session abandonment*). Sesi baru yang dibangkitkan wajib menggunakan:
   - **CEK baru** via CSPRNG (256-bit),
   - **UploadSessionID baru** (UUID v4), dan
   - **UploadSessionSalt baru** via CSPRNG (64-bit).

*Catatan Metodologis:* Seluruh ketentuan di atas merupakan **spesifikasi desain wajib untuk prototipe EXP-02 kelak**, bukan klaim bahwa sistem telah diuji atau diimplementasikan pada aplikasi produksi saat ini.

---

## 5. KOREKSI C — KONSISTENSI LOG PEMBACAAN DAN INTEGRITAS BUKTI

### 5.1. Rekonsiliasi Rujukan Dokumen B-CHAT-08B.0
- Hasil verifikasi repositori faktual mengonfirmasi bahwa tidak ada berkas fisik `B-CHAT-08B.0_Audit_Kesiapan_Otorisasi_EXP-02.md` di disk.
- Laporan dialog B-CHAT-08B.0 dihasilkan dalam mode *STRICTLY READ-ONLY — NO FILE CREATION*.
- Status rujukan berkas fisiknya dikukuhkan secara permanen sebagai **`REFERENCE UNVERIFIED`**.
- Keberadaan temuan audit historis tetap diakui melalui dokumen rekonsiliasi formal tertulis yang benar-benar tersimpan di repositori, yaitu [B-CHAT-08B.1](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08B.1_Koreksi_Presisi_Final_Readiness_Audit_EXP-02.md).

### 5.2. Penuntasan Verifikasi Berkas B-CHAT-08A.5
- Pada pembuatan B-CHAT-08C.1 sebelumnya, pembacaan berkas fisik [B-CHAT-08A.5](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.5_Final_Editorial_Precision_Closure_EXP-02.md) baru mencakup Baris 1–100, sementara Baris 101–171 belum dieksekusi pemanggilannya melalui tool.
- Pada sesi penyusunan dokumen B-CHAT-08C.2 ini, sisa baris 101–171 telah dieksekusi pembacaannya secara langsung melalui antarmuka `read_file`.
- Dengan demikian, seluruh Baris 1–171 dari B-CHAT-08A.5 telah terverifikasi secara penuh dan status buktinya sah sebagai **`VERIFIED DOCUMENTALLY`**.

---

## 6. KOREKSI D — REKONSILIASI KEJUJURAN CHECKLIST 12 BUTIR

Berdasarkan temuan Koreksi A, B, dan C, status checklist 12 butir dari B-CHAT-08C direkonsiliasi secara objektif. Status tidak dipertahankan secara artifisial, melainkan diturunkan sesuai kecukupan bukti faktual:

```
+--------------------------------------------------------------------------------------------------------------------------------------------------+
|                                           TABEL REKONSILIASI KEJUJURAN CHECKLIST 12 BUTIR PRE-EKSEKUSI EXP-02                                    |
+----+--------------------------------------------+-----------------------+-----------------------+---------------------------------+--------------+
| No | Prasyarat Kesiapan                         | Status B-CHAT-08C.1   | Status Terkoreksi 08C2| Bukti Dokumentatif Faktual      | Kondisi Sisa |
+----+--------------------------------------------+-----------------------+-----------------------+---------------------------------+--------------+
| 1  | Parameter teknis kanonikal telah           | VERIFIED DOCUMENTALLY | VERIFIED DOCUMENTALLY | Tabel 14 parameter kanonikal    | Tidak ada    |
|    | direkonsiliasi tanpa pertentangan          |                       |                       | terkunci di B-08A.5 §2 & §5.    | (Terkunci)   |
+----+--------------------------------------------+-----------------------+-----------------------+---------------------------------+--------------+
| 2  | Hierarki dokumen tidak menyisakan konflik  | VERIFIED DOCUMENTALLY | VERIFIED DOCUMENTALLY | B-08B.1 §6 & B-08C §2.2:        | Tidak ada    |
|    | spesifikasi operasional                    |                       |                       | Tingkat 1 B-08A.5 mengikat.     | (Terkunci)   |
+----+--------------------------------------------+-----------------------+-----------------------+---------------------------------+--------------+
| 3  | Fixture manifest sintetis tepercaya telah  | PARTIALLY VERIFIED    | PARTIALLY VERIFIED    | Prinsip independensi & anti-    | Format biner/|
|    | ditentukan koridornya                      |                       |                       | circular oracle jelas (§4).     | JSON = DDR   |
+----+--------------------------------------------+-----------------------+-----------------------+---------------------------------+--------------+
| 4  | Batas persistensi dan aturan crash recovery| VERIFIED DOCUMENTALLY | PARTIALLY VERIFIED    | 4 tahapan I/O & pengikatan 10   | Format hash  |
|    | telah ditetapkan operasionalnya            |                       | [DITURUNKAN KE        | metadata sesi ditetapkan (§4.1);| lokal = DDR; |
|    |                                            |                       | PARTIAL]              | durabilitas fsync = UNVERIFIED. | fsync = UNV  |
+----+--------------------------------------------+-----------------------+-----------------------+---------------------------------+--------------+
| 5  | Batas klaim cleanup memori (best-effort)   | VERIFIED DOCUMENTALLY | VERIFIED DOCUMENTALLY | 3 fase pembersihan memori &     | Tidak ada    |
|    | telah ditetapkan                           |                       |                       | larangan secure wipe (§4.3).    | (Terkunci)   |
+----+--------------------------------------------+-----------------------+-----------------------+---------------------------------+--------------+
| 6  | Desain application-level nonce ledger telah| VERIFIED DOCUMENTALLY | VERIFIED DOCUMENTALLY | Arsitektur in-memory fail-closed| Tidak ada    |
|    | ditetapkan operasionalnya                  |                       |                       | sebelum encrypt (B-08B.1 §8).   | (Terkunci)   |
+----+--------------------------------------------+-----------------------+-----------------------+---------------------------------+--------------+
| 7  | Matriks skenario telah direkonsiliasi      | VERIFIED DOCUMENTALLY | VERIFIED DOCUMENTALLY | Rekonsiliasi matematis 35       | Tidak ada    |
|    | tuntas (35 skenario granular)              |                       |                       | skenario granular (B-08B.1 §4). | (Terkunci)   |
+----+--------------------------------------------+-----------------------+-----------------------+---------------------------------+--------------+
| 8  | Lingkungan eksekusi workstation aktual     | UNVERIFIED            | UNVERIFIED            | Data disalin dari EXP-01; belum | Audit empiris|
|    | telah diverifikasi faktual                 |                       |                       | diaudit empiris saat ini.       | = Prasyarat  |
+----+--------------------------------------------+-----------------------+-----------------------+---------------------------------+--------------+
| 9  | Direktori sandbox terisolasi telah         | VERIFIED DOCUMENTALLY | VERIFIED DOCUMENTALLY | Koridor sandbox ditetapkan di   | Tidak ada    |
|    | ditentukan koridor batasannya              |                       |                       | scratch/bchat_prototype/...     | (Terkunci)   |
+----+--------------------------------------------+-----------------------+-----------------------+---------------------------------+--------------+
| 10 | Batas berkas yang boleh dibuat saat        | VERIFIED DOCUMENTALLY | VERIFIED DOCUMENTALLY | Daftar berkas yang diizinkan    | Tidak ada    |
|    | otorisasi terbit telah jelas               |                       |                       | tercantum ketat di B-08C §11.   | (Terkunci)   |
+----+--------------------------------------------+-----------------------+-----------------------+---------------------------------+--------------+
| 11 | Kriteria evaluasi PASS, FAIL, dan          | VERIFIED DOCUMENTALLY | VERIFIED DOCUMENTALLY | Kriteria baku PASS, FAIL, dan   | Tidak ada    |
|    | UNVERIFIED telah didefinisikan baku        |                       |                       | UNVERIFIED terkunci (B-08C §9). | (Terkunci)   |
+----+--------------------------------------------+-----------------------+-----------------------+---------------------------------+--------------+
| 12 | Kondisi penghentian darurat (circuit       | PARTIALLY VERIFIED    | PARTIALLY VERIFIED    | Taksonomi fatal vs non-fatal    | Ambang batas |
|    | breaker / fail-closed) telah jelas         |                       |                       | dirumuskan (B-08C.2 §7).        | angka = DDR  |
+----+--------------------------------------------+-----------------------+-----------------------+---------------------------------+--------------+
```

### Rekapitulasi Status Keterlacakan:
- **8 Butir `VERIFIED DOCUMENTALLY`:** Butir 1, 2, 5, 6, 7, 9, 10, 11.
- **3 Butir `PARTIALLY VERIFIED`:** Butir 3 (Skema manifest), Butir 4 (Penyimpanan hash integritas lokal), Butir 12 (Ambang batas numerik circuit breaker).
- **1 Butir `UNVERIFIED`:** Butir 8 (Audit empiris lingkungan workstation aktual saat ini).

---

## 7. KOREKSI E — FORMALISASI CIRCUIT BREAKER PROTOTIPE

Untuk mencegah pengujian prototipe terus berjalan saat terjadi kondisi anomali yang membahayakan integritas sistem atau melanggar prinsip kriptografi, arsitektur *circuit breaker* diformalkan ke dalam taksonomi dua tingkat:

### 7.1. Kegagalan Fatal yang Langsung Menghentikan Eksperimen (Emergency Hard Stop)
Kondisi berikut wajib memicu penghentian seketika seluruh test runner (*immediate fail-closed hard stop*):
1. **Deteksi Penggunaan Ulang Pasangan $(\text{CEK}, \text{Nonce})$:**  
   Jika modul *in-memory nonce ledger* mendeteksi pemanggilan enkripsi baru dengan pasangan kunci dan nonce yang telah tercatat sebelumnya.
2. **Kegagalan Antarmuka CSPRNG:**  
   Jika pemanggilan `crypto.getRandomValues()` gagal, melempar pengecualian runtime, atau mengembalikan data dengan keacakan rusak.
3. **Kebocoran Plaintext Parsial:**  
   Jika terjadi kondisi di mana data plaintext dapat diakses sebelum seluruh tag autentikasi atau manifest tervalidasi.
4. **Kegagalan Autentikasi AES-GCM pada Siklus Normal:**  
   Jika pengujian siklus normal (UJI-A atau UJI-B) menghasilkan `OperationError` pada ciphertext yang tidak termanipulasi.
5. **Pelanggaran Batas Kuota yang Lolos Pemrosesan:**  
   Jika berkas $> 10\text{ MB}$, pesan $> 25\text{ MB}$, atau jumlah $> 5$ berkas berhasil memasuki tahap enkripsi.

*Tindakan:* Test runner langsung keluar (*exit process*) dengan kode kesalahan fatal, mencatat log insiden darurat, dan membatalkan sisa pengujian. Hasil eksperimen diklasifikasikan sebagai **`FAIL`**.

### 7.2. Kegagalan Tingkat Skenario (Scenario-Level Failure)
Kondisi berikut dicatat sebagai kegagalan pada skenario spesifik tanpa menghentikan eksekusi skenario independen lainnya:
1. Kegagalan simulasi error I/O disk lokal yang sengaja diinjeksikan pada skenario pengujian negatif.
2. Penolakan galat sintaksis metadata pada skenario format UUID korup.
3. Kegagalan parsing manifest yang termodifikasi (melempar `ManifestConsistencyError` sesuai harapan).

### 7.3. Ambang Batas Kegagalan Berturut-turut (Consecutive Failure Threshold)
Penetapan ambang batas kuantitatif berapa kali kegagalan tingkat skenario berturut-turut diizinkan terjadi sebelum seluruh eksperimen dihentikan (misalnya apakah 3 kali berturut-turut atau 5 kali berturut-turut):

$$\mathbf{STATUS:\ DESIGN\ DECISION\ REQUIRED}$$

*Ketentuan Wajib:* Ambang batas numerik ini **wajib disepakati dan diformalkan dalam file konfigurasi pengujian sebelum pembuatan prototipe dimulai**, bukan diasumsikan sepihak oleh pengembang.

---

## 8. STATUS GERBANG AKHIR PRE-EKSEKUSI

Berdasarkan evaluasi komprehensif terhadap 12 butir checklist:
- Delapan butir telah berstatus *VERIFIED DOCUMENTALLY*.
- Tiga butir berstatus *PARTIALLY VERIFIED* (terkait skema fixture, mekanisme hash penyimpanan lokal, dan ambang numerik circuit breaker yang berstatus `DESIGN DECISION REQUIRED`).
- Satu butir berstatus *UNVERIFIED* (audit empiris lingkungan workstation aktual).

Sesuai kriteria Bagian 8 mandat, status Kategori A (*READY FOR OWNER AUTHORIZATION REVIEW*) belum dapat diberikan karena masih terdapat keputusan desain yang tertunda. Status Kategori C (*NOT READY*) tidak proporsional karena spesifikasi kanonikal inti, formula matematika, dan matriks 35 skenario telah terkunci kokoh tanpa konflik teknis kritis.

Dengan demikian, hasil evaluasi gerbang final pre-eksekusi secara resmi ditetapkan sebagai:

$$\mathbf{HASIL\ GERBANG:\ READY\ WITH\ EXPLICIT\ PRE\text{-}EXECUTION\ CONDITIONS}$$

---

### Empat Kondisi Prasyarat Pra-Pembuatan Prototipe (Pre-Execution Conditions):
Keempat kondisi berikut wajib diselesaikan secara tuntas **hanya setelah otorisasi tertulis Pemilik Proyek terbit**, sebelum direktori sandbox dibuat atau modul kode ditulis:

1. **Prasyarat 1 — Audit Verifikasi Lingkungan Workstation Aktual:**  
   Menjalankan skrip audit lingkungan non-destruktif untuk memverifikasi versi OS Windows, Node.js ($\ge \text{v20.0.0}$), NPM, dan antarmuka Web Crypto API native (`globalThis.crypto.subtle`).
2. **Prasyarat 2 — Finalisasi Dokumen Spesifikasi Skema Fixture Manifest:**  
   Menetapkan dokumen spesifikasi detail format biner/JSON fixture manifest sintetis yang independen dari SUT beserta expected hash SHA-256 ground truth.
3. **Prasyarat 3 — Penetapan Format dan Mekanisme Hash Integritas Penyimpanan:**  
   Menetapkan skema penyimpanan metadata digest lokal untuk membedakan deteksi korupsi penyimpanan dari autentikasi kriptografis AEAD.
4. **Prasyarat 4 — Formalisasi Ambang Numerik Circuit Breaker:**  
   Menetapkan nilai numerik batas kegagalan berturut-turut (*consecutive failure threshold*) dan parameter henti darurat di dalam berkas konfigurasi pengujian.

---

## 9. BATAS OPERASIONAL MUTLAK DAN INTEGRITAS KODE/DOKUMEN

Penyusunan dokumen amendemen B-CHAT-08C.2 telah mematuhi seluruh batasan mandat operasional secara sempurna:

1. **Zero Prototype Code:** Direktori sandbox `scratch/bchat_prototype/exp02_chunking_aad/` **belum dibuat**, dan tidak ada baris kode program atau modul prototipe yang ditulis.
2. **Zero Fixture Files:** Tidak ada berkas fixture manifest, skrip generator, atau data uji `.bin` yang dibuat di disk.
3. **Zero Test Execution & Benchmark:** Tidak ada pengujian kriptografi, simulasi runtime, kompilasi, maupun benchmark memori yang dijalankan.
4. **Zero Dependency Installation:** Tidak ada paket npm atau compiler sistem yang dipasang.
5. **Zero Main Codebase Changes:** Direktori aplikasi Laravel ([`manajemen_skripsi/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/manajemen_skripsi/)) dan Next.js ([`frontend_manajemen_skripsi/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/frontend_manajemen_skripsi/)) tidak disentuh sama sekali.
6. **Zero Database Changes:** Tidak ada migrasi basis data, perubahan struktur tabel, atau manipulasi data MySQL.
7. **Zero Git Operations:** Tidak ada perintah Git (`git status`, `git commit`, `git diff`, `git push`, dsb.) yang dijalankan.
8. **Zero Historical Overwrite:** Seluruh dokumen historis ([B-CHAT-05](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-05_Spesifikasi_Final_E2EE.md), [B-CHAT-06D](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-06D_Mandat_Eksekusi_Terbatas_Gerbang_2.md), [B-CHAT-07A](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A_Laporan_Eksekusi_EXP-01.md), [B-CHAT-08A](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A_Rencana_dan_Mandat_EXP-02.md) s.d. [B-CHAT-08A.5](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.5_Final_Editorial_Precision_Closure_EXP-02.md), [B-CHAT-08B.1](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08B.1_Koreksi_Presisi_Final_Readiness_Audit_EXP-02.md), [B-CHAT-08C](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08C_EXP-02_Final_Pre-Execution_Gate.md), dan [B-CHAT-08C.1](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08C.1_Final_Gate_Precision_Correction_EXP-02.md)) tetap tersimpan utuh di repositori.

---

## 10. PERNYATAAN EKSPLISIT STATUS OTORISASI EXP-02

$$\mathbf{STATUS\ FINAL\ OTORISASI\ EXP\text{-}02:\ EXP\text{-}02\ NOT\ AUTHORIZED}$$

> [!CAUTION]
> **PERINGATAN OPERASIONAL PENUTUP:**  
> Dokumen amendemen **B-CHAT-08C.2** ini secara resmi menutup rangkaian perbaikan bukti dan integritas gerbang final pre-eksekusi EXP-02. **TIDAK ADA OTORISASI OTOMATIS** untuk memulai pembuatan sandbox, instalasi pustaka, atau penulisan kode prototipe. Seluruh aktivitas rekayasa berada dalam status siaga penuh (*standby*) menunggu peninjauan dan penerbitan persetujuan tertulis eksplisit terpisah dari Pemilik Proyek.

---
*(Dokumen B-CHAT-08C.2 selesai disusun secara paripurna sebagai amendemen presisi bukti dan integritas gerbang final EXP-02, siap ditinjau oleh Pemilik Proyek).*

