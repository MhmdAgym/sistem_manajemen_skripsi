# B-CHAT-08D.2 — FINAL TECHNICAL PRECISION CORRECTION EXP-02
## KOREKSI TEKNIS FINAL ATAS PENGHAPUSAN CEK BINDING IDENTIFIER, LIMA LAPISAN KEABSAHAN DATA, DETERMINISME CIRCUIT BREAKER, DAN RETRY PERSISTENSI

**Status Dokumen:** DOKUMEN AMENDEMEN KEPUTUSAN DESAIN PRASYARAT PRE-EKSEKUSI KEDUA (*SECOND CONTROLLED DESIGN PREREQUISITE AMENDMENT*)  
**Mode Operasi:** STRICTLY DOCUMENTATION-ONLY / ZERO EXECUTION  
**Status Evaluasi Desain:** **FINAL TECHNICAL REVIEW REQUIRED** *(Menunggu peninjauan teknis final dan persetujuan tertulis Pemilik Proyek)*  
**Status Otorisasi EXP-02:** **EXP-02 NOT AUTHORIZED** *(Eksperimen EXP-02 belum diotorisasi)*  
**Dokumen Induk:** [B-CHAT-08D_Design_Prerequisite_Resolution_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08D_Design_Prerequisite_Resolution_EXP-02.md)  
**Dokumen Amendemen Terdahulu:** [B-CHAT-08D.1_Koreksi_Presisi_Terbatas_Desain_Prasyarat_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08D.1_Koreksi_Presisi_Terbatas_Desain_Prasyarat_EXP-02.md)  
**Dokumen Rangkaian Pre-Eksekusi Acuan:**
1. [B-CHAT-08C_EXP-02_Final_Pre-Execution_Gate.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08C_EXP-02_Final_Pre-Execution_Gate.md)
2. [B-CHAT-08C.1_Final_Gate_Precision_Correction_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08C.1_Final_Gate_Precision_Correction_EXP-02.md)
3. [B-CHAT-08C.2_Final_Gate_Evidence_Integrity_Correction_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08C.2_Final_Gate_Evidence_Integrity_Correction_EXP-02.md)
4. [B-CHAT-08C.3_Final_Cryptographic_Binding_Result_Classification_Correction_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08C.3_Final_Cryptographic_Binding_Result_Classification_Correction_EXP-02.md)
5. [B-CHAT-08C.4_Final_Security_Claim_Chunk_Consistency_Checklist_Reconciliation_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08C.4_Final_Security_Claim_Chunk_Consistency_Checklist_Reconciliation_EXP-02.md)  
**Dokumen Acuan Teknis Tertinggi:** [B-CHAT-08A.5_Final_Editorial_Precision_Closure_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.5_Final_Editorial_Precision_Closure_EXP-02.md)  
**Proyek:** Sistem Informasi Manajemen Skripsi UNUHA  
**Fitur:** Bimbingan Online Chat Real-Time E2EE  
**Tahap Proyek:** Penyempurnaan Presisi Teknis Final Gerbang 2 Pre-Eksekusi EXP-02  
**Tanggal Penerbitan:** 5 November 2026  

---

> [!CAUTION]
> **PENEGASAN STATUS OPERASIONAL MUTLAK:**  
> Dokumen **B-CHAT-08D.2** ini berstatus **STRICTLY DOCUMENTATION-ONLY / ZERO EXECUTION — EXP-02 NOT AUTHORIZED**. Dokumen ini diterbitkan khusus untuk melakukan penyempurnaan presisi teknis final terhadap empat hal spesifik pada B-CHAT-08D.1: (1) koreksi alasan teknis penghapusan CEK Binding Identifier, (2) pemulihan dan penegasan kembali pemisahan formal lima lapisan keabsahan data, (3) perumusan tabel transisi deterministik counter circuit breaker (`F_category` dan `U_category`) beserta contoh urutan hasil, dan (4) batasan retry transien serta bukti persistensi fisik media. Penerbitan dokumen ini **BUKAN** merupakan izin atau otorisasi untuk memulai pembuatan kode prototipe, pembuatan direktori sandbox, pembuatan berkas fixture fisik, instalasi dependensi, atau eksekusi eksperimen kriptografi apa pun. Dokumen induk dan seluruh dokumen historis repositori dilestarikan utuh tanpa penimpaan (*zero overwrite*).

---

## 1. TUJUAN DAN BATAS KOREKSI

### 1.1. Tujuan Koreksi Presisi Teknis Final
Berdasarkan hasil peninjauan teknis mendalam terhadap dokumen [B-CHAT-08D.1](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08D.1_Koreksi_Presisi_Terbatas_Desain_Prasyarat_EXP-02.md), dokumen **B-CHAT-08D.2** diterbitkan sebagai instrumen amendemen kedua untuk menyelesaikan empat temuan teknis yang memerlukan penajaman presisi:
1. **Presisi Alasan Teknis Penghapusan `CEK Binding Identifier`:** Mengoreksi formulasi naratif sebelumnya yang menyiratkan bahwa setiap identifier kunci pasti menyebabkan kebocoran kunci secara otomatis, dan menggantikannya dengan argumen teknis objektif bahwa identifier tidak sama dengan nilai kunci, tidak membuktikan kebenaran kunci, tidak menggantikan autentikasi AEAD, dan menambah kompleksitas tanpa nilai kriptografis mandiri.
2. **Pemulihan Utuh Lima Lapisan Keabsahan Data:** Mengukuhkan kembali secara formal hierarki lima lapisan keabsahan data (L-1 Framing, L-2 Pengikatan Sesi, L-3 Integritas Penyimpanan Lokal, L-4 Autentikasi Kriptografis AEAD, dan L-5 Validasi Manifest & Perakitan) dengan penegasan bahwa persamaan akumulatif $\sum L_i = S$ hanyalah salah satu syarat konsistensi panjang, bukan bukti autentikasi manifest produksi.
3. **Determinisme Transisi Counter Circuit Breaker:** Menetapkan model state machine deterministik dengan dua counter terpisah per kategori (`F_category` dan `U_category`), tabel transisi state formal untuk setiap kemungkinan hasil, serta lima contoh konkret urutan hasil pengujian guna membuktikan konsistensi keputusan.
4. **Batas Retry Transien dan Realitas Bukti Persistensi:** Memisahkan secara tegas antara retry I/O lokal pada berkas yang sama ($K_{\text{io\_retry}} = 1$) dari pengulangan skenario pengujian, mengklasifikasikan jenis kegagalan yang boleh dan tidak boleh dicoba ulang, serta mengakui bahwa read-back sukses bukanlah bukti ketahanan terhadap pemadaman daya (*power-loss fsync durability*).

Pekerjaan ini bersifat **terbatas dan terfokus**. Dokumen ini tidak mengulang audit yang telah selesai dan **TIDAK MENGUBAH SATU PUN** parameter teknis kanonikal EXP-02 yang telah disahkan pada dokumen B-CHAT-08A.5.

### 1.2. Batas Eksekusi Mutlak
Penyusunan dokumen B-CHAT-08D.2 tunduk pada larangan eksekusi mutlak:
- **Zero Prototype Code:** Dilarang membuat direktori sandbox di `scratch/bchat_prototype/exp02_chunking_aad/` dan dilarang menulis modul kode program prototipe.
- **Zero Fixture & Hash Files:** Dilarang membuat berkas fixture manifest fisik `.json`, berkas biner `.bin`, berkas digest, atau data ciphertext nyata di disk.
- **Zero Test & Benchmark:** Dilarang menjalankan pengujian unit, simulasi kegagalan I/O, atau pengukuran lonjakan memori.
- **Zero Dependency Installation:** Dilarang memasang atau memperbarui paket NPM.
- **Zero Runtime Commands:** Dilarang menjalankan perintah Node.js, OpenSSL CLI, Web Worker, framework Next.js, framework Laravel, atau audit runtime workstation host.
- **Zero Main Codebase Changes:** Direktori aplikasi Laravel ([`manajemen_skripsi/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/manajemen_skripsi/)) dan Next.js ([`frontend_manajemen_skripsi/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/frontend_manajemen_skripsi/)) serta basis data MySQL tidak disentuh sama sekali.
- **Zero Git Operations:** Tidak ada perintah Git yang dijalankan (`git commit`, `git checkout`, `git reset`, `git push`, dsb.).
- **Zero Historical Overwrite:** Seluruh dokumen historis repositori tetap terlestarikan utuh tanpa penimpaan.

---

## 2. DAFTAR SUMBER OTORITATIF DAN RENTANG PEMBACAAN AKTUAL

Penyusunan amendemen ini didasarkan pada penelaahan langsung terhadap berkas-berkas otoritatif repositori dengan rentang baris faktual yang diperiksa:

```
+--------------------------------------------------------------------------------------------------------------------------------------------------+
|                                           TABEL REKONSILIASI LOG PEMBACAAN SUMBER DOKUMENTATIF                                                   |
+----+--------------------------------------------+------------------------------------------+-----------------------+-----------------------------+
| No | Dokumen Sumber Otoritatif                  | Rentang Baris yang Benar-Benar Diperiksa | Status Bukti Faktual  | Fokus Verifikasi Teknis     |
+----+--------------------------------------------+------------------------------------------+-----------------------+-----------------------------+
| 1  | B-CHAT-08D.1_Koreksi_Presisi_Terbatas_     | Baris 165-205 (Bab 4 CEK Binding & Retry)| VERIFIED DOCUMENTALLY | Alasan penolakan CEK identi-|
|    | Desain_Prasyarat_EXP-02.md (Amendemen Lalu)| Baris 130-156 (Bab 3.5 Tingkatan Validasi| (Bagian terdampak     | fier, pemisahan lapisan     |
|    |                                            | Baris 246-312 (Bab 5 Circuit Breaker),   |  diperiksa langsung)  | validasi, determinisme CB,  |
|    |                                            | Baris 317-362 (Bab 6 Matriks Keputusan), |                       | dan aturan retry persistensi|
|    |                                            | Baris 365-424 (Bab 7 Rekonsiliasi Checkl)|                       |                             |
+----+--------------------------------------------+------------------------------------------+-----------------------+-----------------------------+
| 2  | B-CHAT-08D_Design_Prerequisite_Resolution_ | Baris 242-275 (Bab 5.1 5 Lapisan Data),  | VERIFIED DOCUMENTALLY | Pemulihan 5 lapisan data    |
|    | EXP-02.md (Dokumen Induk Desain)           | Baris 276-329 (Bab 5.2-5.5 Metadata/Retr)| (Pondasi lapisan data | L-1 s.d. L-5 dan batasan    |
|    |                                            | Baris 360-425 (Bab 6.2 Parameter CB)     |  dan CB ditelaah)     | digest lokal)               |
+----+--------------------------------------------+------------------------------------------+-----------------------+-----------------------------+
| 3  | B-CHAT-08C.4_Final_Security_Claim_Chunk_   | Baris 79-93 (Bab 3 Pengikatan Metadata), | VERIFIED DOCUMENTALLY | Istilah session & artifact  |
|    | Consistency_Checklist_Reconciliation_      | Baris 96-112 (Bab 4 Batas Klaim AES-GCM),| (Batas klaim keamanan | identity binding, 5 syarat  |
|    | EXP-02.md (Amendemen Keempat Gerbang Final)| Baris 178-226 (Bab 7 Konsistensi Chunk)  |  dan chunk konsistensi)AES-GCM, dan batas sum(Li).  |
+----+--------------------------------------------+------------------------------------------+-----------------------+-----------------------------+
| 4  | B-CHAT-08C.3_Final_Cryptographic_Binding_  | Baris 59-85 (Bab 3 Rekomendasi Penghapus-| VERIFIED DOCUMENTALLY | Analisis awal kerahasiaan   |
|    | Result_Classification_Correction_EXP-02.md |  an CEK Binding Identifier)              | (Pondasi CEK binding) | CEK dan parameter sesi      |
+----+--------------------------------------------+------------------------------------------+-----------------------+-----------------------------+
| 5  | B-CHAT-08C.2_Final_Gate_Evidence_Integrity | Baris 76-105 (Bab 3 Integritas Ciphertext| VERIFIED DOCUMENTALLY | Rumusan awal 5 lapisan data |
|    | _Correction_EXP-02.md (Amendemen Kedua)    |  dan Pemisahan 5 Lapisan Keabsahan),     | (Pondasi 5 lapisan)   | dan 10 parameter pengikatan |
|    |                                            | Baris 107-146 (Bab 4 Pengikatan Sesi)    |                       | sesi sebelum direvisi       |
+----+--------------------------------------------+------------------------------------------+-----------------------+-----------------------------+
| 6  | B-CHAT-08A.5_Final_Editorial_Precision_    | Baris 101-132 (Bab 5 Konsistensi         | VERIFIED DOCUMENTALLY | Tabel 14 parameter kanonikal|
|    | Closure_EXP-02.md (Acuan Teknis Tertinggi) |  Spesifikasi Teknis Kanonikal Lengkap)   | (Parameter terkunci)  | mutlak yang wajib dipatuhi. |
+----+--------------------------------------------+------------------------------------------+-----------------------+-----------------------------+
| 7  | B-CHAT-08B.0 (Laporan Audit Kesiapan       | Penelusuran struktur direktori repositori| REFERENCE UNVERIFIED  | Berkas fisik tidak ada di   |
|    | Pre-Otorisasi EXP-02)                      | root; berkas fisik lokal TIDAK DITEMUKAN | (Mode NO FILE         | disk karena mode mandat;    |
|    |                                            | di workspace                             |  CREATION mandat lalu)| rekonsiliasi via B-08B.1.   |
+----+--------------------------------------------+------------------------------------------+-----------------------+-----------------------------+
```

---

## 3. KOREKSI A — REKONSILIASI ALASAN TEKNIS PENGHAPUSAN `CEK BINDING IDENTIFIER`

### 3.1. Penegasan Status Desain
Keputusan untuk **TIDAK MEMASUKKAN** `CEK Binding Identifier` ke dalam daftar metadata wajib penyimpanan lokal maupun metadata pengikatan retry tetap **dipertahankan secara penuh**.

### 3.2. Koreksi Presisi Argumen Teknis
Formulasi pada dokumen terdahulu yang menyatakan bahwa derivasi identifier kunci "otomatis mengekspos rahasia kunci" atau "pasti menyebabkan kebocoran kunci" dikoreksi secara objektif. Penolakan atas `CEK Binding Identifier` didasarkan pada lima pertimbangan rekayasa teknis dan kriptografis yang presisi:
1. **Identifier Bukan Nilai Kunci (*Identifier is Not the Key Value*):** Suatu identifier atau referensi kunci (seperti hash parsial, key ID acak, atau fingerprint kunci) secara teknis tidak sama dengan nilai biner kunci enkripsi simetris (CEK) itu sendiri. Adanya suatu identifier tidak secara otomatis mengekspos byte rahasia CEK jika dirancang dengan fungsi satu arah yang benar.
2. **Ketiadaan Pembuktian Kebenaran Kunci (*Identifier Does Not Prove Key Correctness*):** Pencocokan identifier kunci di dalam metadata lokal **sama sekali tidak membuktikan bahwa kunci CEK yang tersimpan di memori runtime klien adalah kunci yang benar atau belum rusak**. Identifier hanyalah label metadata yang dapat disalin atau dipalsukan.
3. **Identifier Tidak Menggantikan Autentikasi AES-GCM:** Satu-satunya mekanisme yang membuktikan kebenaran pasangan kunci dan data adalah verifikasi Authentication Tag 128-bit oleh fungsi kriptografi native. Keberadaan identifier publik tidak menambah kekuatan pembuktian kriptografis apa pun.
4. **Beban Kompleksitas dan Ilusi Keamanan (*False Sense of Security*):** Menyertakan identifier kunci yang tidak dibutuhkan dalam skema metadata lokal menambah kompleksitas parsing, membuka risiko salah tafsir operasional (*operational misinterpretation*), dan menciptakan ilusi keamanan yang keliru seolah-olah verifikasi metadata lokal telah membuktikan keabsahan identitas kunci secara kriptografis.
5. **Redundansi Parameter Sesi Non-Rahasia:** Pengikatan artefak fisik terhadap sesi telah dijamin secara kokoh melalui kombinasi identifier sesi non-rahasia berkekuatan tinggi (`UploadSessionID`, `UploadSessionSalt`, `AttachmentUUID`, `MessageUUID`, `RoomUUID`, `ChunkIndex`, `TotalChunks`, dan `PlaintextLength`).

### 3.3. Batas Penjaminan Keamanan Autentikasi
Dokumen B-CHAT-08D.2 menegaskan bahwa verifikasi autentikasi artefak ciphertext **HANYA BERGANTUNG PADA**:
- Penggunaan kunci simetris CEK yang benar, sah, dan diperoleh melalui kanal penetapan kunci tepercaya (*trusted key establishment channel* via MLS Exporter Secret).
- Rekonstruksi buffer AAD 83-byte yang identik byte-demi-byte dan tervalidasi dari parameter sesi non-rahasia.
- Pemeriksaan keberhasilan verifikasi Authentication Tag 128-bit via antarmuka native `crypto.subtle.decrypt`.
- Disiplin *zero unverified plaintext* (plaintext tidak dirilis sebelum verifikasi tuntas).
- Perilaku penanganan galat *fail-closed* saat terjadi `OperationError`.

*Ketentuan Mutlak:* **Dilarang menambahkan identifier kunci baru apa pun ke dalam daftar metadata wajib**.

---

## 4. KOREKSI B — PEMULIHAN FORMAL LIMA LAPISAN KEABSAHAN DATA

Untuk mencegah penyederhanaan yang mengaburkan batas arsitektural antara verifikasi framing, pengikatan sesi, integritas penyimpanan lokal, autentikasi kriptografis, dan perakitan makro manifest, ditegaskan kembali pemisahan formal **Lima Lapisan Keabsahan Data**:

```
+-------------------------------------------------------------------------------------------------------------+
|                                    LIMA LAPISAN KEABSAHAN DATA PADA EXP-02                                  |
+--------+------------------------------------+-----------------------+---------------------------------------+
| Lapisan| Nama Lapisan Keabsahan             | Objek & Mekanisme     | Karakteristik Bukti & Batas Jaminan   |
+--------+------------------------------------+-----------------------+---------------------------------------+
| L-1    | Framing dan Panjang Artefak        | Panjang berkas fisik  | Memverifikasi bahwa aliran byte fisik |
|        | (Framing Length Check)             | tepat == Li + 28 B;   | memenuhi Format A: Nonce (12 B) ||    |
|        |                                    | struktur 3 segmen.    | Cipher (Li B) || Tag (16 B). BUKAN    |
|        |                                    |                       | bukti integritas atau keaslian data.  |
+--------+------------------------------------+-----------------------+---------------------------------------+
| L-2    | Pengikatan Identitas Sesi & Artefak| Pencocokan 9 parameter| Memverifikasi bahwa artefak terikat   |
|        | (Session & Artifact Identity)      | metadata non-rahasia  | pada sesi pengunggahan aktif saat ini;|
|        |                                    | terhadap sesi aktif.  | konsistensi struktural skema aplikasi;|
|        |                                    |                       | BUKAN bukti autentikasi kunci CEK.    |
+--------+------------------------------------+-----------------------+---------------------------------------+
| L-3    | Integritas Byte Penyimpanan Lokal  | SHA-256 pre-write di  | Mendeteksi korupsi media lokal, silent|
|        | (Storage Byte Integrity / Digest)  | RAM vs read-back disk | disk flip, atau penulisan terputus;   |
|        |                                    | (H_act == H_exp).     | unkeyed digest; TIDAK melindungi dari |
|        |                                    |                       | penyerang lokal berizin tulis media.  |
+--------+------------------------------------+-----------------------+---------------------------------------+
| L-4    | Autentikasi Kriptografis AEAD      | crypto.subtle.decrypt | SATU-SATUNYA pembuktian kriptografis; |
|        | (Cryptographic AEAD Authentication)| dengan CEK, Nonce, &  | membuktikan bahwa ciphertext dan AAD  |
|        |                                    | AAD 83-B (Tag 128-bit)| belum diubah sejak dienkripsi pengirim|
|        |                                    |                       | sah. Kegagalan melempar OperationError|
+--------+------------------------------------+-----------------------+---------------------------------------+
| L-5    | Validasi Manifest & Perakitan      | Assembly Validator:   | Memvalidasi struktur perakitan makro; |
|        | (Manifest Validation & Assembly)   | kelengkapan chunk, no | konsistensi sekuensial seluruh berkas;|
|        |                                    | gap, sum(Li) == S,    | batas mekanisme sesuai rancangan;     |
|        |                                    | status autentikasi.   | BUKAN bukti autentikasi manifest prod.|
+--------+------------------------------------+-----------------------+---------------------------------------+
```

### Rincian Sembilan Parameter Evaluasi Lapisan L-5 (Validasi Manifest & Perakitan):
Modul perakitan berkas (*file assembly validator*) pada Lapisan L-5 wajib memvalidasi setidaknya sembilan parameter struktural sebelum berkas dideklarasikan utuh:
1. **Kelengkapan Seluruh Chunk:** Seluruh chunk dari indeks $0$ hingga $N - 1$ wajib tersedia lengkap di media penyimpanan lokal.
2. **Kesesuaian Identitas Sesi dan Artefak:** Seluruh chunk wajib memiliki parameter `UploadSessionID`, `RoomUUID`, `MessageUUID`, dan `AttachmentUUID` yang identik dan cocok dengan sesi aktif.
3. **Kesesuaian Nilai `TotalChunks`:** Nilai field `TotalChunks` pada setiap chunk wajib tepat sama dengan jumlah chunk aktual ($N$).
4. **Rentang dan Keunikan `ChunkIndex`:** Nilai indeks wajib memenuhi $0 \le \text{chunk\_index} < N$, tanpa nilai duplikat.
5. **Kesinambungan Tanpa Celah (*No Gap*):** Chunk wajib tersusun secara berurutan monotonik ($0, 1, 2, \dots, N-1$) tanpa ada chunk yang melompat atau hilang.
6. **Konsistensi `PlaintextLength` Tiap Chunk:** Setiap chunk non-final ($0 \le i < N - 1$) wajib memiliki $L_i = 1.048.576\text{ byte}$, dan chunk final ($i = N - 1$) wajib memenuhi formula piecewise $L_{\text{last}}$.
7. **Konsistensi Total Panjang Plaintext dengan Ukuran Berkas:** Jumlah total byte seluruh chunk wajib sama persis dengan ukuran berkas $S$:
   $$\sum_{i=0}^{N-1} L_i = S$$
8. **Kesesuaian Seluruh Metadata Manifest:** Metadata perakitan berkas pada manifest wajib cocok dengan atribut berkas sumber.
9. **Status Autentikasi Manifest Sesuai Batas Rancangan:** Verifikasi keabsahan manifest dibatasi pada koridor masukan tepercaya (*trusted input*) di dalam sandbox EXP-02.

> [!IMPORTANT]
> **BATASAN PEMBUKTIAN FORMULA $\sum L_i = S$ DAN KETIADAAN MANIFEST PRODUKSI:**  
> Persamaan matematis $\sum_{i=0}^{N-1} L_i = S$ **hanyalah salah satu pengujian konsistensi panjang akumulatif, BUKAN bukti autentikasi kriptografis dan BUKAN bukti validitas keseluruhan manifest**.  
> **Dilarang keras mengklaim bahwa autentikasi manifest tingkat produksi telah tersedia** apabila mekanisme tanda tangan digital atau kanal transmisi manifest yang aman belum dirancang pada gerbang eksperimen ini.

---

## 5. KOREKSI C — FORMULASI DETERMINISTIK TRANSISI COUNTER CIRCUIT BREAKER

Untuk memastikan implementasi test runner menghasilkan keputusan yang deterministik, konsisten, dan dapat direproduksi untuk urutan hasil pengujian yang sama, didefinisikan dua variabel counter terpisah untuk setiap kelompok kategori pengujian:
- **$F_{\text{category}}$:** Jumlah skenario unik dengan hasil `FAIL` definitif beruntun pada kategori yang bersangkutan.
- **$U_{\text{category}}$:** Jumlah skenario dengan hasil `UNVERIFIED` beruntun pada kategori yang bersangkutan.

### 5.1. Tabel Transisi State Deterministik Circuit Breaker
Setiap kali suatu skenario selesai dievaluasi, state kedua counter diperbarui secara eksklusif mengikuti aturan transisi deterministik berikut:

```
+-------------------------------------------------------------------------------------------------------------+
|                                  TABEL TRANSISI DETERMINISTIK COUNTER CIRCUIT BREAKER                       |
+----+--------------------------+-----------------------+-----------------------+-----------------------------+
| No | Hasil Definitif Skenario | Nilai F_category Baru | Nilai U_category Baru | Tindakan & Status Evaluasi  |
+----+--------------------------+-----------------------+-----------------------+-----------------------------+
| 1  | PASS                     | Di-reset ke 0 (F = 0) | Di-reset ke 0 (U = 0) | Lanjutkan skenario berikut  |
+----+--------------------------+-----------------------+-----------------------+-----------------------------+
| 2  | FAIL Definitif           | Bertambah 1 (F = F+1) | Di-reset ke 0 (U = 0) | Jika F >= 3 -> CATEGORY STOP|
|    |                          |                       |                       | Jika F < 3  -> Lanjut sken. |
+----+--------------------------+-----------------------+-----------------------+-----------------------------+
| 3  | UNVERIFIED               | Tetap (F = F)         | Bertambah 1 (U = U+1) | Jika U >= 2 -> CAT. BLOCKED |
|    |                          |                       |                       | Jika U < 2  -> Lanjut sken. |
+----+--------------------------+-----------------------+-----------------------+-----------------------------+
| 4  | Fatal Security Violation | GLOBAL HARD STOP      | GLOBAL HARD STOP      | Test runner keluar seketika;|
|    |                          | (Suite Berhenti Total)| (Suite Berhenti Total)| Status eksperimen FAIL      |
+----+--------------------------+-----------------------+-----------------------+-----------------------------+
```

### 5.2. Sembilan Ketentuan Transisi Deterministik:
1. **Cakupan Counter Lokal per Kategori (*Strict Category Scope*):** Counter $F_{\text{category}}$ dan $U_{\text{category}}$ berlaku lokal per kelompok skenario (Kategori B, C, D, E, F) dan tidak berlaku global.
2. **Isolasi Reset Antar-Kategori:** Hasil `PASS` pada suatu kategori (misalnya Kategori C) **sama sekali tidak mengubah** nilai counter kategori lain (misalnya Kategori D).
3. **Ambang Henti Kategori ($F_{\text{category}} \ge 3$):** Terjadinya 3 skenario unik dengan hasil `FAIL` definitif berturut-turut memicu penghentian otomatis kelompok kategori tersebut (*Category Stop*).
4. **Ambang Blokir Kategori ($U_{\text{category}} \ge 2$):** Terjadinya 2 hasil `UNVERIFIED` berturut-turut memicu penahanan kelompok kategori tersebut (*Category Blocked*), menandakan rusaknya instrumen atau prasyarat pengujian.
5. **Independensi Klasifikasi UNVERIFIED:** Status `UNVERIFIED` bukan `PASS` dan bukan `FAIL`. UNVERIFIED tidak menambah nilai $F_{\text{category}}$ dan tidak mereset nilai $F_{\text{category}}$ yang sedang terakumulasi.
6. **Penanganan Seketika Fatal Violation:** Pelanggaran keamanan fatal (nonce reuse, ciphertext termodifikasi lolos verifikasi GCM, kebocoran plaintext parsial, recovery memakai CEK lama) **selalu menghentikan seluruh test runner seketika (*emergency hard stop*)**, tanpa menunggu ambang counter.
7. **Penghitungan Berbasis Skenario Unik:** Satu skenario unik hanya dihitung tepat satu kali sebagai `FAIL` definitif dalam akumulasi $F_{\text{category}}$.
8. **Invariansi Retry Terhadap Identitas Skenario:** Percobaan ulang transien terhadap skenario yang sama tidak menciptakan skenario baru dan tidak menambah counter ganda.
9. **Keterulangan Deterministik (*Deterministic Reproducibility*):** Urutan hasil masukan yang sama wajib menghasilkan keluaran status circuit breaker yang identik secara matematis.

### 5.3. Pembuktian Lima Contoh Urutan Transisi Counter
Untuk membuktikan ketiadaan ambiguitas logika transisi, diuraikan simulasi pada skenario konkret:

#### Contoh 1: Urutan `FAIL` $\rightarrow$ `UNVERIFIED`
- Kondisi Awal: $F = 0, U = 0$.
- Skenario 1 menghasilkan `FAIL Definitif`: $F \leftarrow 0 + 1 = 1$, $U \leftarrow 0$.
- Skenario 2 menghasilkan `UNVERIFIED`: $F \leftarrow 1$ (tetap!), $U \leftarrow 0 + 1 = 1$.
- **Hasil Akhir:** $F = 1, U = 1$. Pengujian dilanjutkan ke skenario 3 karena $F < 3$ dan $U < 2$. Counter FAIL beruntun sebelumnya tidak hilang.

#### Contoh 2: Urutan `UNVERIFIED` $\rightarrow$ `FAIL`
- Kondisi Awal (melanjutkan Contoh 1): $F = 1, U = 1$.
- Skenario 3 menghasilkan `FAIL Definitif`: $F \leftarrow 1 + 1 = 2$, $U \leftarrow 0$ (direset ke 0!).
- **Hasil Akhir:** $F = 2, U = 0$. Keberhasilan mengeksekusi uji (meskipun hasilnya FAIL) memutus rantai UNVERIFIED, sehingga $U$ kembali ke 0.

#### Contoh 3: Urutan `FAIL` $\rightarrow$ `FAIL` $\rightarrow$ `PASS`
- Kondisi Awal: $F = 0, U = 0$.
- Skenario 1 menghasilkan `FAIL Definitif`: $F = 1, U = 0$.
- Skenario 2 menghasilkan `FAIL Definitif`: $F = 2, U = 0$.
- Skenario 3 menghasilkan `PASS`: $F \leftarrow 0, U \leftarrow 0$ (kedua counter di-reset total).
- **Hasil Akhir:** $F = 0, U = 0$. Kelulusan skenario 3 membuktikan logika modul telah berfungsi, mereset akumulasi kegagalan beruntun.

#### Contoh 4: Urutan `UNVERIFIED` $\rightarrow$ `UNVERIFIED`
- Kondisi Awal: $F = 0, U = 0$.
- Skenario 1 menghasilkan `UNVERIFIED`: $F = 0, U = 1$.
- Skenario 2 menghasilkan `UNVERIFIED`: $F = 0, U = 2$.
- **Hasil Akhir:** $U = 2 \ge 2 \rightarrow$ **CIRCUIT BREAKER: CATEGORY BLOCKED**. Seluruh skenario sisa di kelompok ini ditahan otomatis.

#### Contoh 5: Isolasi Lintas Kategori (PASS Kategori A saat Kategori B memiliki Counter Non-Zero)
- Misalkan Kategori B (Integritas AAD) saat ini berada pada kondisi: $F_{\text{catB}} = 2, U_{\text{catB}} = 0$.
- Suatu skenario pada Kategori A (Siklus Normal) dijalankan dan menghasilkan `PASS`:  
  $$F_{\text{catA}} \leftarrow 0, \quad U_{\text{catA}} \leftarrow 0$$
- Kondisi pada Kategori B setelah kejadian tersebut:  
  $$F_{\text{catB}} = 2\ (\text{TETAP!}), \quad U_{\text{catB}} = 0\ (\text{TETAP!})$$
- **Analisis:** Terbukti bahwa hasil PASS pada Kategori A tidak mereset counter Kategori B (*strict category isolation*).

---

## 6. KOREKSI D — BATAS RETRY TRANSIEN DAN REALITAS BUKTI PERSISTENSI

### 6.1. Klasifikasi Jenis Kegagalan Operasional
Untuk menghindari perlakuan keliru di mana insiden keamanan diubah menjadi percobaan ulang, ditetapkan pemisahan empat kelas kegagalan:
1. **Kegagalan I/O Transien Lokal yang Boleh Dicoba Ulang (*Transient Retryable I/O Error*):**  
   Galat penguncian berkas sementara oleh sistem operasi Windows atau proses background seperti antivirus/file indexer (`EBUSY`, `EPERM` transien pada saat penulisan atau read-back awal). Skenario diizinkan mencoba 1 kali lagi pada artefak yang sama.
2. **Kegagalan Definitif Skenario (*Scenario-Level Definitve Failure*):**  
   Kesalahan struktur skema, pelanggaran rentang parameter kuota, atau hash digest tidak cocok setelah penulisan berkas selesai bebas galat. Skenario langsung dinyatakan `FAIL Definitif` ($F_{\text{category}} \leftarrow F_{\text{category}} + 1$).
3. **Kegagalan Keamanan / Autentikasi yang DILARANG DICOBAL ULANG:**  
   Deteksi penggunaan ulang nonce pada CEK yang sama, ketidakcocokan field AAD kanonikal, atau kegagalan verifikasi Authentication Tag 128-bit (`OperationError`). Kasus ini langsung memicu penolakan seketika (*fail-closed*) atau emergency hard stop; **DILARANG KERAS diubah menjadi retry transien**.
4. **Status Persistensi Tidak Pasti yang Mewajibkan Session Abandonment:**  
   Proses crash mendadak di tengah penulisan sebelum handle ditutup, write timeout, atau disk media terputus. Status persistensi dinyatakan tidak pasti dan sesi unggah **WAJIB DIBATALKAN SEUTUHNYA (*FAIL-CLOSED SESSION ABANDONMENT*)**.

### 6.2. Batas Lingkup Retry Transien ($K_{\text{io\_retry}} = 1$)
Ditetapkan batasan lingkup operasional retry:
- **Lingkup Eksklusif I/O Lokal:** Batas $K_{\text{io\_retry}} = 1$ berlaku **secara eksklusif pada level operasi penulisan berkas artefak yang sama** dari buffer ciphertext di RAM yang telah dihasilkan oleh fungsi enkripsi.
- **Larangan Enkripsi Ulang (No Re-Encryption):** Percobaan ulang penulisan I/O **TIDAK BOLEH memanggil ulang fungsi enkripsi Web Crypto** dengan pasangan $(\text{CEK}, \text{Nonce})$ yang sama.
- **Transisi ke FAIL Definitif:** Jika setelah 1 kali percobaan ulang operasi I/O tetap gagal, skenario tersebut resmi dinyatakan `FAIL Definitif` dan menambah counter kategori $+1$. Tidak ada retry tak terbatas (*no unbounded retry loop*).
- **Karakter Rekomendasi Prototipe:** Ketentuan batas $K_{\text{io\_retry}} = 1$ ditegaskan sebagai **rekomendasi operasional khusus prototipe Node-only EXP-02**, bukan standar universal untuk seluruh platform.

### 6.3. Sembilan Aturan Sekuensial Bukti Persistensi dan Keterbatasan Fisik Media
Operasi penyimpanan dan verifikasi lokal wajib mematuhi sekuens 7 tahapan berikut:
1. *Pre-Write Digest:* Hitung expected SHA-256 ($H_{\text{exp}}$) atas buffer Format A di RAM sebelum penulisan disk.
2. *I/O Write:* Tuliskan aliran byte ciphertext ke media penyimpanan lokal.
3. *File Close:* Tutup handle berkas dan verifikasi konfirmasi sukses bebas galat dari sistem operasi.
4. *Read-Back:* Buka kembali berkas dari media disk dan baca seluruh byte ke dalam buffer RAM baru.
5. *Framing Validation:* Verifikasi panjang byte aktual tepat sama dengan $L_i + 28\text{ byte}$.
6. *Digest Comparison:* Hitung digest aktual ($H_{\text{act}}$) atas buffer hasil pembacaan ulang dan cocokkan 100% terhadap $H_{\text{exp}}$ ($H_{\text{act}} == H_{\text{exp}}$).
7. *Session Consistency Check:* Verifikasi 9 parameter metadata sesi cocok 100% dengan sesi aktif.

#### Keterbatasan Faktual Fisik Media yang Wajib Diakui:
- **Read-Back Bukan Bukti `fsync`:** Keberhasilan penutupan berkas (`close`) dan pembacaan ulang (`read-back`) pada runtime Node.js **BUKAN bukti bahwa data telah tersinkronisasi secara fisik ke piringan disk (*hardware fsync*)**. Sistem operasi Windows dan kontroler drive dapat mempertahankan data pada cache memori internal.
- **Ketiadaan Klaim Power-Loss Durability:** Pembacaan ulang yang sukses tidak membuktikan ketahanan terhadap pemadaman daya perangkat keras mendadak (*abrupt hardware power cut*). **Dilarang keras mengklaim durabilitas terhadap crash fisik tanpa pengujian empiris khusus**.
- **Kewajiban Abandonment Pasca-Restart:** Apabila proses aplikasi terhenti atau restart sehingga menyebabkan status buffer $H_{\text{exp}}$ di RAM hilang atau status sesi tidak dapat diverifikasi secara tepercaya, sesi lama **WAJIB DITINGGALKAN SEUTUHNYA (*SESSION ABANDONMENT*)**. Pengunggahan baru wajib membangkitkan CEK baru, UploadSessionID baru, dan UploadSessionSalt baru.

---

## 7. MATRIKS KEPUTUSAN DESAIN DAN STATUS PERSETUJUAN

Tabel matriks berikut memisahkan secara transparan antara ketentuan lama yang dipertahankan, koreksi teknis baru hasil amendemen B-CHAT-08D.2, serta status persetujuannya:

```
+--------------------------------------------------------------------------------------------------------------------------------------------------+
|                                    MATRIKS KEPUTUSAN DESAIN PRASYARAT PRE-EKSEKUSI EXP-02 (AMENDEMEN B-08D.2)                                    |
+----+-----------------------+-----------------------+------------------------------------------+------------------------------+-------------------+
| No | Isu Desain            | Ketentuan Sebelumnya  | Koreksi Teknis Baru (B-CHAT-08D.2)       | Alasan Teknis & Dampak       | Status Persetujuan|
|    |                       | (B-08D & B-08D.1)     |                                          | Arsitektural                 |                   |
+----+-----------------------+-----------------------+------------------------------------------+------------------------------+-------------------+
| 1  | Alasan Penghapusan    | CEK Binding Identifier| Identifier != nilai CEK; identifier tidak| Koreksi argumen teknis: iden-| FINAL TECHNICAL   |
|    | CEK Binding Identifier| dihapus karena risiko | otomatis bocorkan kunci, tidak buktikan  | tifier menambah kompleksitas | REVIEW REQUIRED   |
|    |                       | kebocoran kunci B-08D1| kebenaran CEK, & tambah ilusi keamanan.  | tanpa nilai autentikasi.     |                   |
+----+-----------------------+-----------------------+------------------------------------------+------------------------------+-------------------+
| 2  | Pemisahan Formal Lima | 4 tingkatan validasi  | Pemulihan formal 5 lapisan keabsahan:    | Mengembalikan L-5 assembly;  | FINAL TECHNICAL   |
|    | Lapisan Keabsahan Data| V-1 s.d. V-4 pada     | L-1 (Framing), L-2 (Sesi), L-3 (Storage),| tegaskan sum(Li)==S bukan    | REVIEW REQUIRED   |
|    |                       | B-08D.1 Bab 3.5.      | L-4 (AEAD Crypto), L-5 (Manifest/Assembly| bukti autentikasi manifest.  |                   |
+----+-----------------------+-----------------------+------------------------------------------+------------------------------+-------------------+
| 3  | State Machine Counter | Counter per kategori, | Formulasi tabel transisi deterministik   | Menghilangkan ambiguitas;    | FINAL TECHNICAL   |
|    | Circuit Breaker       | aturan reset umum pada| 2 counter terpisah (F_cat dan U_cat)     | keputusan deterministik &    | REVIEW REQUIRED   |
|    |                       | B-08D.1 Bab 5.        | beserta pembuktian 5 contoh urutan hasil.| reproducible matematis.      |                   |
+----+-----------------------+-----------------------+------------------------------------------+------------------------------+-------------------+
| 4  | Batas Retry Transien  | Batas 1 retry tanpa   | Retry K_io_retry = 1 eksklusif pada I/O  | Mencegah perlakuan keliru    | FINAL TECHNICAL   |
|    | dan Lingkup Operasi   | klasifikasi kegagalan | lokal berkas sama; pisahkan 4 kelas galat| atas galat keamanan; larang  | REVIEW REQUIRED   |
|    |                       | detail B-08D.1 §5.1.  | larang retry pada galat keamanan/crypto. | re-encrypt pasangan kunci.   |                   |
+----+-----------------------+-----------------------+------------------------------------------+------------------------------+-------------------+
| 5  | Keterbatasan Bukti    | Konsep read-back pada | Read-back bukan bukti fsync hardware;    | Mengakui batasan fisik OS;   | FINAL TECHNICAL   |
|    | Persistensi Media     | B-08D.1 Bab 4.2.      | larang klaim power-loss durability;      | session abandonment mutlak   | REVIEW REQUIRED   |
|    |                       |                       | restart mewajibkan session abandonment.  | saat status sesi tidak pasti.|                   |
+----+-----------------------+-----------------------+------------------------------------------+------------------------------+-------------------+
```

---

## 8. REKONSILIASI CHECKLIST 12 BUTIR PRE-EKSEKUSI

Dokumen B-CHAT-08D.2 mengevaluasi kembali dampak terhadap butir-butir checklist pre-eksekusi terkait:

```
+--------------------------------------------------------------------------------------------------------------------------------------------------+
|                                           TABEL REKONSILIASI CHECKLIST 12 BUTIR PRE-EKSEKUSI EXP-02                                              |
+----+------------------------------------+-----------------------+-----------------------+---------------------+-------------------+--------------+
| No | Prasyarat Kesiapan                 | Status B-CHAT-08D.1   | Status B-CHAT-08D.2   | Bukti Dokumentatif  | Bukti Empiris yang| Status Pasca-|
|    |                                    | (Sebelum Koreksi)     | (Saat Ini)            | yang Tersedia       | Masih Diperlukan  | Persetujuan  |
+----+------------------------------------+-----------------------+-----------------------+---------------------+-------------------+--------------+
| 1  | Parameter teknis kanonikal telah   | VERIFIED DOCUMENTALLY | VERIFIED DOCUMENTALLY | Tabel 14 parameter  | Belum ada eksekusi| VERIFIED     |
|    | direkonsiliasi tanpa pertentangan  |                       |                       | terkunci di B-08A.5 | pengujian PoC.    | DOCUMENTALLY |
+----+------------------------------------+-----------------------+-----------------------+---------------------+-------------------+--------------+
| 2  | Hierarki dokumen tidak menyisakan  | VERIFIED DOCUMENTALLY | VERIFIED DOCUMENTALLY | Hierarki 3 tingkat  | N/A (Ketentuan    | VERIFIED     |
|    | konflik spesifikasi operasional    |                       |                       | di B-08B.1 & B-08C  | tata kelola).     | DOCUMENTALLY |
+----+------------------------------------+-----------------------+-----------------------+---------------------+-------------------+--------------+
| 3  | Fixture manifest sintetis tepercaya| PARTIALLY VERIFIED    | PARTIALLY VERIFIED    | Skema JSON 6 elemen | Pembuatan berkas  | VERIFIED     |
|    | telah ditentukan koridornya        | [Menunggu Persetujuan]| [Koreksi Independensi | & batas independensi| fisik di sandbox  | DOCUMENTALLY |
|    |                                    |                       |  Selesai Diperhalus]  | disempurnakan.      | saat PoC dimulai. | (DESIGN SPEC)|
+----+------------------------------------+-----------------------+-----------------------+---------------------+-------------------+--------------+
| 4  | Batas persistensi dan aturan crash | PARTIALLY VERIFIED    | PARTIALLY VERIFIED    | 7 langkah persistens| Pengujian I/O disk| VERIFIED     |
|    | recovery telah ditetapkan          | [Menunggu Persetujuan]| [Koreksi Alasan CEK & | & batasan fisik fsyn| & simulasi crash; | DOCUMENTALLY |
|    |                                    |                       |  fsync Diperjelas]    | c telah ditegaskan. | fsync = UNVERIFIED| (DESIGN SPEC)|
+----+------------------------------------+-----------------------+-----------------------+---------------------+-------------------+--------------+
| 5  | Batas klaim cleanup memori         | VERIFIED DOCUMENTALLY | VERIFIED DOCUMENTALLY | 3 fase pembersihan &| Pengukuran alokasi| VERIFIED     |
|    | (best-effort) telah ditetapkan     |                       |                       | batasan runtime V8  | buffer di Node.js.| DOCUMENTALLY |
+----+------------------------------------+-----------------------+-----------------------+---------------------+-------------------+--------------+
| 6  | Desain application-level nonce     | PARTIALLY VERIFIED    | PARTIALLY VERIFIED    | Desain in-memory    | Implementasi modul| PARTIALLY    |
|    | ledger telah ditetapkan            |                       |                       | ledger diformalkan  | & uji anti-reuse  | VERIFIED     |
|    | operasionalnya                     |                       |                       | di B-08B.1 & B-08C  | empiris di sandbox| (Menunggu PoC|
+----+------------------------------------+-----------------------+-----------------------+---------------------+-------------------+--------------+
| 7  | Matriks skenario telah             | VERIFIED DOCUMENTALLY | VERIFIED DOCUMENTALLY | Rekonsiliasi 35     | Eksekusi 35 uji   | VERIFIED     |
|    | direkonsiliasi tuntas (35 skenario)|                       |                       | skenario granular OK| pada test runner. | DOCUMENTALLY |
+----+------------------------------------+-----------------------+-----------------------+---------------------+-------------------+--------------+
| 8  | Lingkungan eksekusi workstation    | UNVERIFIED            | UNVERIFIED            | Data disalin dari   | Audit empiris host| UNVERIFIED   |
|    | aktual telah diverifikasi faktual  | [Tetap Terpisah]      | [Tetap Terpisah]      | laporan EXP-01      | saat ini di mesin | (Wajib pada  |
|    |                                    |                       |                       | (belum audit kini). | workstation.      | Prasyarat 1) |
+----+------------------------------------+-----------------------+-----------------------+---------------------+-------------------+--------------+
| 9  | Direktori sandbox terisolasi telah | VERIFIED DOCUMENTALLY | VERIFIED DOCUMENTALLY | Koridor sandbox di  | Pembuatan folder  | VERIFIED     |
|    | ditentukan koridor batasannya      |                       |                       | scratch/bchat_proto | fisik di disk.    | DOCUMENTALLY |
+----+------------------------------------+-----------------------+-----------------------+---------------------+-------------------+--------------+
| 10 | Batas berkas yang boleh dibuat saat| VERIFIED DOCUMENTALLY | VERIFIED DOCUMENTALLY | Daftar berkas diizin| Pengecekan daftar | VERIFIED     |
|    | otorisasi terbit telah jelas       |                       |                       | kan terkunci ketat  | berkas di sandbox.| DOCUMENTALLY |
+----+------------------------------------+-----------------------+-----------------------+---------------------+-------------------+--------------+
| 11 | Kriteria evaluasi PASS, FAIL, dan  | PARTIALLY VERIFIED    | PARTIALLY VERIFIED    | Tabel transisi state| Evaluasi kelulusan| VERIFIED     |
|    | UNVERIFIED telah didefinisikan     | [Menunggu Persetujuan]| [Tabel Transisi State | F_cat & U_cat difor-| skenario pada test| DOCUMENTALLY |
|    |                                    |                       |  Deterministik Selesai| malkan tuntas.      | runner prototipe. | (DESIGN SPEC)|
+----+------------------------------------+-----------------------+-----------------------+---------------------+-------------------+--------------+
| 12 | Kondisi henti darurat (circuit     | PARTIALLY VERIFIED    | PARTIALLY VERIFIED    | Ambang F>=3, U>=2,  | Pengujian modul   | VERIFIED     |
|    | breaker / fail-closed) telah jelas | [Menunggu Persetujuan]| [Simulasi 5 Kasus     | isolasi kategori &  | henti darurat dgn | DOCUMENTALLY |
|    |                                    |                       |  Transisi Selesai]    | resume disempurnakan.injeksi kesalahan.| (DESIGN SPEC)|
+----+------------------------------------+-----------------------+-----------------------+---------------------+-------------------+--------------+
```

### Penegasan Disiplin Checklist:
- **Larangan Kenaikan Status Sepihak:** Status Butir 3, 4, 11, dan 12 **TIDAK DINAIKKAN** secara prematur menjadi `VERIFIED DOCUMENTALLY` hanya karena dokumen amendemen B-CHAT-08D.2 telah diterbitkan. Status tetap berada pada **`PARTIALLY VERIFIED`** hingga Pemilik Proyek menerbitkan persetujuan tertulis resmi atas paket dokumen desain ini.
- **Butir 8 Tetap `UNVERIFIED`:** Audit empiris lingkungan workstation aktual tetap merupakan prasyarat empiris terpisah yang tidak dieksekusi dalam mandat dokumentasi ini.
- **Status Eksperimen Tetap `NOT AUTHORIZED`:** Eksperimen EXP-02 belum diotorisasi.

---

## 9. RISIKO, ASUMSI, DAN KETERBATASAN YANG MASIH TERSISA

Auditor dan tim pengembang wajib mempertahankan pengakuan transparan atas risiko serta keterbatasan teknis yang melekat:
1. **Risiko Kesamaan Asumsi Logika Serialisasi pada Oracle Utilitas:**  
   Meskipun menggunakan skrip pembangkit ground truth terpisah, risiko kesamaan asumsi logika serialisasi biner (seperti *endianness* atau format padding biner) tetap dapat terjadi jika pengembang yang sama menulis kedua modul tersebut. Pencegahan terbaik adalah membandingkan keluaran serialisasi terhadap vektor uji teks RFC resmi.
2. **Keterbatasan Kinerja Runtime Node.js CLI vs Peramban Nyata:**  
   Pengujian memori dan streaming pada lingkungan Node.js CLI tidak merefleksikan sepenuhnya karakteristik alokasi thread, sandbox origin, dan keterbatasan heap Web Worker pada peramban web nyata (Chromium/Gecko/WebKit).
3. **Ketiadaan Pengujian Crash Fisik Media Penyimpanan (`fsync`):**  
   Pengujian logika persistensi pada EXP-02 menguji antarmuka sistem berkas Node.js, bukan ketahanan media terhadap pemutusan daya perangkat keras mendadak (*abrupt hardware power cut*).
4. **Kelemahan Inheren Unkeyed Digest Lokal:**  
   Digest SHA-256 lokal pada `.meta.json` tidak melindungi berkas dari manipulasi simultan oleh penyerang dengan hak akses sistem operasi lokal (*local filesystem access*).

---

## 10. STATUS GERBANG AKHIR DAN PENYATAAN OTORISASI

### 10.1. Penetapan Status Evaluasi Desain
Berdasarkan hasil penyempurnaan presisi teknis final atas penghapusan CEK Binding Identifier, pemulihan 5 lapisan keabsahan data, determinisme counter circuit breaker, dan batas retry persistensi media, status gerbang pre-eksekusi EXP-02 secara resmi ditetapkan sebagai:

$$\mathbf{STATUS\ GERBANG:\ FINAL\ TECHNICAL\ REVIEW\ REQUIRED\ —\ EXP\text{-}02\ NOT\ AUTHORIZED}$$

**Alasan Penetapan Status:**
1. Seluruh empat temuan teknis hasil peninjauan Pemilik Proyek terhadap dokumen B-CHAT-08D.1 telah disempurnakan secara presisi, deterministik, dan bebas konflik arsitektural tanpa mengubah satu pun parameter teknis kanonikal.
2. Seluruh rekomendasi teknis disajikan secara transparan di bawah status usulan (*Final Technical Review Required*) dan tidak diposisikan secara sepihak sebagai kebijakan yang telah disetujui.
3. Prasyarat empiris (Audit Lingkungan Workstation) dan keputusan otorisasi eksekusi EXP-02 tetap merupakan wewenang manajerial terpisah Pemilik Proyek yang belum diterbitkan.

---

### 10.2. Komitmen Kepatuhan Nol Eksekusi (Zero Execution Compliance)
Seluruh kegiatan penyusunan dokumen amendemen B-CHAT-08D.2 dilaksanakan dengan kepatuhan penuh terhadap komitmen operasional mutlak:
- **Zero Prototype Code:** Direktori sandbox `scratch/bchat_prototype/exp02_chunking_aad/` **belum dibuat** dan tidak ada kode prototipe yang ditulis.
- **Zero Fixture & Hash Files:** Tidak ada berkas fixture manifest JSON fisik, berkas biner `.bin`, maupun berkas hash yang dibuat di disk.
- **Zero Test & Benchmark:** Tidak ada pengujian kriptografi, simulasi I/O, atau pengukuran memori yang dijalankan.
- **Zero Dependency Installation:** Tidak ada paket npm yang dipasang atau diperbarui.
- **Zero Runtime Execution:** Tidak ada audit runtime host, skrip CLI, proses Node.js, atau server aplikasi yang dijalankan.
- **Zero Main Codebase Changes:** Direktori aplikasi Laravel, Next.js, dan database MySQL tidak disentuh sama sekali.
- **Zero Git Operations:** Tidak ada perintah Git yang dijalankan.
- **Zero Historical Overwrite:** Seluruh dokumen historis repositori tetap tersimpan utuh tanpa penimpaan.

---

### 10.3. Pernyataan Eksplisit Penutup Status Otorisasi EXP-02

$$\mathbf{STATUS\ FINAL\ OPERASIONAL:\ EXP\text{-}02\ NOT\ AUTHORIZED}$$

> [!CAUTION]
> **PERINGATAN OPERASIONAL PENUTUP:**  
> Dokumen amendemen **B-CHAT-08D.2** ini secara resmi menutup seluruh penyempurnaan presisi teknis final desain prasyarat EXP-02. **TIDAK ADA OTORISASI OTOMATIS** untuk memulai pembuatan direktori sandbox, instalasi dependensi, pembuatan berkas fixture fisik, atau penulisan kode prototipe EXP-02. Seluruh pekerjaan rekayasa perangkat lunak berada dalam status siaga penuh (*standby*) menunggu peninjauan teknis final dan penerbitan persetujuan tertulis eksplisit terpisah dari Pemilik Proyek.

---
*(Dokumen B-CHAT-08D.2 selesai disusun secara paripurna sebagai amendemen koreksi presisi teknis final desain prasyarat EXP-02, siap ditinjau oleh Pemilik Proyek).*

