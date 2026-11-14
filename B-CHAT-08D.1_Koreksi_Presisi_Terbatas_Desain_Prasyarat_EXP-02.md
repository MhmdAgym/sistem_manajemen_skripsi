# B-CHAT-08D.1 — KOREKSI PRESISI TERBATAS DESAIN PRASYARAT EXP-02
## AMENDEMEN KOREKSI INDEPENDENSI FIXTURE, PENGIKATAN RETRY, DAN DETERMINISME CIRCUIT BREAKER

**Status Dokumen:** DOKUMEN AMENDEMEN KEPUTUSAN DESAIN PRASYARAT PRE-EKSEKUSI PERTAMA (*FIRST CONTROLLED DESIGN PREREQUISITE AMENDMENT*)  
**Mode Operasi:** STRICTLY DOCUMENTATION-ONLY / ZERO EXECUTION  
**Status Evaluasi Desain:** **DESIGN REVIEW REQUIRED** *(Menunggu peninjauan dan persetujuan tertulis Pemilik Proyek)*  
**Status Otorisasi EXP-02:** **EXP-02 NOT AUTHORIZED** *(Eksperimen EXP-02 belum diotorisasi)*  
**Dokumen Induk:** [B-CHAT-08D_Design_Prerequisite_Resolution_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08D_Design_Prerequisite_Resolution_EXP-02.md)  
**Dokumen Acuan Pre-Eksekusi Terdahulu:**
1. [B-CHAT-08C_EXP-02_Final_Pre-Execution_Gate.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08C_EXP-02_Final_Pre-Execution_Gate.md)
2. [B-CHAT-08C.1_Final_Gate_Precision_Correction_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08C.1_Final_Gate_Precision_Correction_EXP-02.md)
3. [B-CHAT-08C.2_Final_Gate_Evidence_Integrity_Correction_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08C.2_Final_Gate_Evidence_Integrity_Correction_EXP-02.md)
4. [B-CHAT-08C.3_Final_Cryptographic_Binding_Result_Classification_Correction_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08C.3_Final_Cryptographic_Binding_Result_Classification_Correction_EXP-02.md)
5. [B-CHAT-08C.4_Final_Security_Claim_Chunk_Consistency_Checklist_Reconciliation_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08C.4_Final_Security_Claim_Chunk_Consistency_Checklist_Reconciliation_EXP-02.md)  
**Dokumen Acuan Teknis Tertinggi:** [B-CHAT-08A.5_Final_Editorial_Precision_Closure_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.5_Final_Editorial_Precision_Closure_EXP-02.md)  
**Proyek:** Sistem Informasi Manajemen Skripsi UNUHA  
**Fitur:** Bimbingan Online Chat Real-Time E2EE  
**Tahap Proyek:** Penyempurnaan Terbatas Desain Prasyarat Gerbang 2 Pre-Eksekusi EXP-02  
**Tanggal Penerbitan:** 5 November 2026  

---

> [!CAUTION]
> **PENEGASAN STATUS OPERASIONAL MUTLAK:**  
> Dokumen **B-CHAT-08D.1** ini berstatus **STRICTLY DOCUMENTATION-ONLY / ZERO EXECUTION — EXP-02 NOT AUTHORIZED**. Dokumen ini diterbitkan khusus untuk melakukan amendemen koreksi presisi terhadap tiga area desain pada dokumen induk B-CHAT-08D: (1) independensi data fixture manifest dan batasan oracle eksternal, (2) disiplin pengikatan metadata digest pada alur persistensi dan retry, serta (3) formulasi deterministik kebijakan operasional circuit breaker. Penerbitan dokumen ini **BUKAN** merupakan izin atau otorisasi untuk memulai pembuatan direktori sandbox, pembuatan berkas fisik, penulisan kode sumber, atau eksekusi eksperimen kriptografi apa pun. Dokumen induk dan seluruh dokumen historis repositori tetap dilestarikan utuh tanpa penimpaan (*zero overwrite*).

---

## 1. TUJUAN DAN BATAS KOREKSI

### 1.1. Tujuan Koreksi Presisi
Berdasarkan hasil penelaahan dan peninjauan kritis Pemilik Proyek terhadap dokumen desain [B-CHAT-08D](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08D_Design_Prerequisite_Resolution_EXP-02.md), ditemukan tiga area perancangan yang memerlukan penegasan dan pengetatan presisi teknis agar implementasi prototipe kelak tidak mengalami ambiguitas logika:
1. **Presisi Independensi Fixture dan Batas Oracle:** Memperjelas bahwa *seed* konstan hanyalah instrumen reproduksibilitas (bukan bukti independensi), merumuskan batasan independensi jika menggunakan skrip utilitas mandiri atau OpenSSL CLI terhadap potensi kesamaan asumsi logika serialisasi dengan SUT, serta memisahkan secara ketat empat tingkatan validasi data uji.
2. **Presisi Pengikatan Metadata Digest dan Disiplin Retry:** Menegaskan bahwa kelayakan artefak ciphertext untuk transmisi ulang (*retry*) wajib terikat secara eksklusif pada kombinasi identitas sesi dan identitas artefak yang sama, memperjelas sembilan aturan sekuensial verifikasi persistensi dan pengiriman ulang, serta merumuskan skema konseptual metadata digest lokal tanpa menciptakan berkas fisik di disk.
3. **Determinisme Kebijakan Circuit Breaker:** Menghilangkan potensi ketidakkonsistenan penghitungan kegagalan skenario (membedakan percobaan *attempt* vs skenario unik yang gagal), menegakkan aturan reset berbasis isolasi kategori (*per-category counter reset on PASS*), mendefinisikan perlakuan terhadap sekuens hasil `UNVERIFIED`, mengevaluasi ambang numerik prototipe, serta menetapkan rancangan 11 field terstruktur pada log insiden henti darurat.

Koreksi ini bersifat **terbatas dan terfokus**. Dokumen ini tidak menulis ulang keseluruhan dokumen induk B-CHAT-08D dan **TIDAK MENGUBAH SATU PUN** parameter teknis kanonikal EXP-02 yang telah dikunci pada dokumen B-CHAT-08A.5.

### 1.2. Batas Eksekusi Mutlak
Pekerjaan penyusunan dokumen B-CHAT-08D.1 tunduk pada larangan eksekusi mutlak:
- **Zero Prototype Code:** Dilarang membuat direktori sandbox di `scratch/bchat_prototype/exp02_chunking_aad/` dan dilarang menulis modul kode program JavaScript/TypeScript.
- **Zero Fixture & Hash Files:** Dilarang membuat berkas fixture manifest fisik `.json`, berkas biner `.bin`, berkas digest, atau data uji ciphertext nyata di disk.
- **Zero Test & Benchmark:** Dilarang menjalankan pengujian unit, simulasi kegagalan I/O, atau pengukuran lonjakan memori.
- **Zero Dependency Installation:** Dilarang memasang atau memperbarui paket NPM.
- **Zero Runtime Commands:** Dilarang menjalankan perintah Node.js, OpenSSL CLI, Web Worker, framework Next.js, framework Laravel, atau audit runtime workstation host.
- **Zero Main Codebase Changes:** Direktori aplikasi Laravel ([`manajemen_skripsi/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/manajemen_skripsi/)) dan Next.js ([`frontend_manajemen_skripsi/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/frontend_manajemen_skripsi/)) serta basis data MySQL tidak disentuh sama sekali.
- **Zero Git Operations:** Tidak ada perintah Git yang dijalankan (`git commit`, `git checkout`, `git reset`, `git push`, dsb.).
- **Zero Historical Overwrite:** Seluruh dokumen historis repositori tetap terlestarikan utuh tanpa modifikasi retrospektif.

---

## 2. DAFTAR SUMBER YANG DIGUNAKAN DAN RENTANG PEMBACAAN AKTUAL

Penyusunan amendemen ini didasarkan pada penelaahan langsung terhadap dokumen-dokumen rujukan repositori dengan pencatatan baris faktual:

```
+--------------------------------------------------------------------------------------------------------------------------------------------------+
|                                           TABEL REKONSILIASI LOG PEMBACAAN SUMBER DOKUMENTATIF                                                   |
+----+--------------------------------------------+------------------------------------------+-----------------------+-----------------------------+
| No | Dokumen Sumber Otoritatif                  | Rentang Baris yang Benar-Benar Diperiksa | Status Bukti Faktual  | Fokus Verifikasi Teknis     |
+----+--------------------------------------------+------------------------------------------+-----------------------+-----------------------------+
| 1  | B-CHAT-08D_Design_Prerequisite_Resolution_ | Baris 125-140 (Bab 4.1-4.2 Format JSON), | VERIFIED DOCUMENTALLY | Struktur skema fixture,     |
|    | EXP-02.md (Dokumen Induk yang Dikoreksi)   | Baris 200-240 (Bab 4.4-4.6 Anti-circular)| (Bagian kunci desain  | alur persistensi digest, 5  |
|    |                                            | Baris 270-330 (Bab 5.2-5.5 Metadata & Ret|  diperiksa langsung   | lapisan keabsahan, ambang CB|
|    |                                            | Baris 360-430 (Bab 6.2 Parameter CB),    |  secara tekstual)     | dan matriks checklist).     |
|    |                                            | Baris 474-536 (Bab 8 Rekonsiliasi Checkl)|                       |                             |
+----+--------------------------------------------+------------------------------------------+-----------------------+-----------------------------+
| 2  | B-CHAT-08C.4_Final_Security_Claim_Chunk_   | Baris 1-100 (Bab 1-3 Sesi & Batas Klaim),| VERIFIED DOCUMENTALLY | 5 syarat penolakan AES-GCM, |
|    | Consistency_Checklist_Reconciliation_      | Baris 101-250 (Bab 4-8 Taksonomi 3 Kelas | (Seluruh 353 baris    | rekonsiliasi matematis      |
|    | EXP-02.md (Amendemen Keempat Gerbang Final)|  Kegagalan, Konsistensi Chunk & Prasyarat|  dokumen diverifikasi) chunking sum(Li) == S, dan |
|    |                                            | Baris 251-353 (Bab 9-12 Checklist & Gate)|                       | 4 prasyarat pra-prototipe). |
+----+--------------------------------------------+------------------------------------------+-----------------------+-----------------------------+
| 3  | B-CHAT-08C.3_Final_Cryptographic_Binding_  | Baris 55-130 (Bab 3-4 CEK & AAD 83-B),   | VERIFIED DOCUMENTALLY | Penghapusan CEK Identifier, |
|    | Result_Classification_Correction_EXP-02.md | Baris 141-300 (Bab 5-7 Alur 9 Langkah,   | (Pondasi AAD dan alur | alur 9 langkah storage hash,|
|    | (Amendemen Ketiga Gerbang Final)           |  Perlindungan Hash & Kriteria Hasil)     |  hash diverifikasi)   | dan kriteria PASS/FAIL/UNV).|
+----+--------------------------------------------+------------------------------------------+-----------------------+-----------------------------+
| 4  | B-CHAT-08C.2_Final_Gate_Evidence_Integrity | Baris 76-150 (Bab 3-4 5 Lapisan Keabsahan| VERIFIED DOCUMENTALLY | Pemisahan 5 lapisan data,   |
|    | _Correction_EXP-02.md (Amendemen Kedua)    |  dan Pengikatan Sesi & Aturan Retry),    | (Pondasi integritas)  | 9 metadata sesi, dan aturan |
|    |                                            | Baris 164-280 (Bab 6-8 Checklist & CB)   |                       | session abandonment).       |
+----+--------------------------------------------+------------------------------------------+-----------------------+-----------------------------+
| 5  | B-CHAT-08B.1_Koreksi_Presisi_Final_        | Baris 69-160 (Bab 4-5 Rekonsiliasi 35    | VERIFIED DOCUMENTALLY | Rekonsiliasi 35 skenario,   |
|    | Readiness_Audit_EXP-02.md                  |  Skenario & Conditional Nonce Safety),   | (Hierarki dan rincian | birthday bound salt/CEK, dan|
|    | (Koreksi Audit Kesiapan EXP-02)            | Baris 161-236 (Bab 6-8 Hierarki Dokumen, |  skenario diverifikasi| in-memory nonce ledger      |
|    |                                            |  Disiplin Statistik & Ledger Nonce UJI-E1)secara matematis)   | UJI-E1 fail-closed).        |
+----+--------------------------------------------+------------------------------------------+-----------------------+-----------------------------+
| 6  | B-CHAT-08B.0 (Laporan Audit Kesiapan       | Penelusuran struktur direktori repositori| REFERENCE UNVERIFIED  | Berkas fisik tidak ada di   |
|    | Pre-Otorisasi EXP-02)                      | root; berkas fisik lokal TIDAK DITEMUKAN | (Mode NO FILE         | disk karena mode mandat;    |
|    |                                            | di workspace                             |  CREATION mandat lalu)| rekonsiliasi via B-08B.1.   |
+----+--------------------------------------------+------------------------------------------+-----------------------+-----------------------------+
| 7  | B-CHAT-08A.5_Final_Editorial_Precision_    | Baris 101-132 (Bab 5 Konsistensi         | VERIFIED DOCUMENTALLY | Tabel 14 parameter kanonikal|
|    | Closure_EXP-02.md (Acuan Teknis Tertinggi) |  Spesifikasi Teknis Kanonikal Lengkap)   | (Parameter terkunci)  | mutlak yang wajib dipatuhi. |
+----+--------------------------------------------+------------------------------------------+-----------------------+-----------------------------+
```

---

## 3. KOREKSI A — INDEPENDENSI FIXTURE DAN EXPECTED-VALUE GROUND TRUTH

### 3.1. Penegasan Prinsip Anti-Circular Test Oracle
Pada dokumen B-CHAT-08D §4.1 dan §4.4 telah dirumuskan rekomendasi penggunaan format JSON deklaratif statis. Namun, untuk menjamin keketatan metodologis pengujian, ditegaskan prinsip-prinsip mutlak berikut:
1. **Kemandirian Mutlak Nilai Ekspektasi (*Strict SUT Independence*):** Seluruh nilai ekspektasi (*expected values*), termasuk hash plaintext per-chunk, hash berkas akhir, framing buffer AAD 83-byte, dan expected storage hash, **wajib ditentukan secara independen dari fungsi implementasi yang diuji (*System Under Test / SUT*)**.
2. **Larangan Evaluasi Sirkular (*Anti-Circular Mandate*):** **Dilarang keras menghitung expected value menggunakan fungsi pembentukan manifest atau fungsi framing milik modul prototipe SUT, lalu menggunakan hasil keluaran tersebut sebagai oracle pengujian untuk memvalidasi fungsi yang sama**. Jika fungsi SUT memiliki cacat logika (misalnya kesalahan *endianness* pada representasi integer atau kesalahan pemotongan UUID), evaluasi sirkular akan meloloskan cacat tersebut dengan status kelulusan semu (*false positive PASS*).
3. **Dokumentasi Metode dan Kemampuan Reproduksi (*Reproducible Provenance*):** Metode pembentukan setiap nilai ekspektasi wajib terdokumentasi secara lengkap dalam spesifikasi manifest (meliputi formula matematika, algoritma digest, perintah CLI pembangkit, dan lingkungan toolchain) sehingga pihak ketiga atau auditor dapat mereproduksinya secara mandiri tanpa menjalankan prototipe aplikasi.

### 3.2. Batas Independensi Utilitas Eksternal dan Potensi Bias Implementasi
Penggunaan skrip utilitas mandiri terpisah (misalnya skrip baris perintah Node.js di luar direktori SUT) atau utilitas OpenSSL CLI memiliki batasan independensi yang wajib diakui secara transparan:
- **Keterbatasan Skrip Utilitas Terpisah:** Jika skrip pembangkit fixture eksternal ditulis menggunakan modul atau fungsi pembantu serialisasi biner yang disalin (*shared codebase / copy-paste logic*) dari modul SUT, independensi tersebut menjadi gugur. Skrip tersebut akan mewarisi asumsi struktural yang sama persis dengan SUT.
- **Keterbatasan OpenSSL CLI:** Utilitas `openssl dgst -sha256` menjamin independensi algoritma hash murni dari engine JavaScript V8. Namun, OpenSSL hanya memproses aliran byte mentah yang disuapkan kepadanya. Ketepatan tata letak biner AAD 83-byte atau susunan nonce tetap bergantung pada fungsi pembuat aliran byte tersebut.
- **Koreksi Status Seed Generator:** **Ditegaskan bahwa penggunaan seed tetap (*fixed PRNG seed*) semata-mata merupakan parameter penjamin sifat determinisme dan keterulangan (*reproducibility*), BUKAN bukti independensi kriptografis maupun bukti ketiadaan circular bias**.
- **Solusi Rancangan Mandat:** Fixture manifest untuk EXP-02 wajib menggunakan **vektor uji terkomputasi statis (*static pre-computed test vectors*)** di mana aliran byte plaintext bersumber dari pola deterministik baku (misalnya pola byte berulang `0xAA` atau urutan sekuensial terdefinisi), dan seluruh hash ekspektasi dihitung menggunakan utilitas standar bersertifikasi sebelum modul pengujian prototipe dimuat ke memori.

### 3.3. Enam Komponen Wajib Setiap Entri Fixture
Setiap blok skenario pada berkas manifest fixture JSON wajib memuat enam komponen deklaratif:
1. `fixture_id`: Identitas unik string terstandarisasi (misal: `FIX-POS-01-1MIB-EXACT`, `FIX-NEG-05-INDEX-BOUNDS`).
2. `test_objective`: Deskripsi spesifik perilaku logika atau kriteria keamanan yang dibuktikan.
3. `test_inputs`: Parameter masukan lengkap (ukuran berkas $S$, array chunk plaintext atau pola generator, konteks sesi 9 parameter).
4. `expected_results`: Hasil keluaran yang diharapkan (status sukses/penolakan, kode galat/pengecualian, panjang frame, array hash ekspektasi).
5. `expected_value_source_provenance`: Catatan asal-usul nilai ekspektasi (tool utilitas, versi engine, command CLI, dan tanggal kalkulasi independen).
6. `validation_rules`: Aturan pembuktian formal yang memicu klasifikasi PASS, FAIL, atau UNVERIFIED.

### 3.4. Representasi Encoding dan Serialisasi Biner Kanonikal
Untuk mencegah kesalahan interpretasi tipe data saat pengujian membaca fixture JSON:
- **UUID:** Dituliskan sebagai string kanonikal RFC 4122 (36 karakter bertanda hubung, misal: `11111111-1111-4111-8111-111111111111`) di dalam JSON; wajib dikonversi menjadi **16 raw octets** biner mentah saat dimasukkan ke AAD atau konteks exporter MLS.
- **Nonce (12 Byte):** Disusun dari `UploadSessionSalt` (8 raw octets) diikuti `uint32be(chunk_index)` (4 octets big-endian); direpresentasikan sebagai hex string 24 karakter di JSON.
- **AAD Kanonikal (83 Byte):** Disusun persis dari 10 field kanonikal berurutan jaringan (*network byte order / big-endian*) tanpa byte padding liar; direpresentasikan sebagai hex string 166 karakter di JSON sebagai pembanding byte-demi-byte.
- **Ciphertext Frame:** Framing Format A berukuran tepat $L_i + 28\text{ byte}$ ($12\text{ B Nonce} \parallel L_i\text{ B Ciphertext} \parallel 16\text{ B AuthTag}$).
- **Authentication Tag:** Tepat 16 raw octets (128-bit); direpresentasikan sebagai hex string 32 karakter di JSON.
- **Storage Digest:** Hash SHA-256 berukuran 32 raw octets; direpresentasikan sebagai hex string 64 karakter di JSON.

### 3.5. Pemisahan Empat Tingkatan Validasi Data Uji
Modul pengujian prototipe wajib mengeksekusi dan membedakan secara tegas empat tingkatan validasi data:

```
+-------------------------------------------------------------------------------------------------------------+
|                                    EMPAT TINGKATAN VALIDASI DATA UJI EXP-02                                 |
+--------+------------------------------------+-----------------------+---------------------------------------+
| Tingkat| Nama Tingkatan Validasi            | Objek yang Dievaluasi | Karakteristik Bukti & Batas Logika    |
+--------+------------------------------------+-----------------------+---------------------------------------+
| V-1    | Validasi Struktur & Batas Ukuran   | Skema JSON, batas     | Validasi batas pra-enkripsi pada level|
|        | (Schema & Bounds Validation)       | kuota berkas (<= 10MB)| aplikasi; mendeteksi file 0-byte,     |
|        |                                    | kuota pesan (<= 25MB).| format UUID korup, chunk_index >= N.  |
+--------+------------------------------------+-----------------------+---------------------------------------+
| V-2    | Validasi Framing & Konsistensi Berkas Ukuran frame Li+28B, | Validasi kesinambungan urutan chunk;  |
|        | (Framing & Continuity Validation)  | kesinambungan indeks, | membuktikan tidak ada chunk hilang    |
|        |                                    | sum(Li) == S.         | dan seluruh chunk tepat membentuk S.  |
+--------+------------------------------------+-----------------------+---------------------------------------+
| V-3    | Validasi Digest Penyimpanan Lokal  | SHA-256 pre-write vs  | Mendeteksi korupsi media lokal, crash |
|        | (Storage Byte Digest Validation)   | post-write read-back  | penulisan parsial, atau silent flip;  |
|        |                                    | (H_act == H_exp).     | unkeyed hash, BUKAN bukti autentikasi.|
+--------+------------------------------------+-----------------------+---------------------------------------+
| V-4    | Validasi Autentikasi Kriptografis  | crypto.subtle.decrypt | SATU-SATUNYA validasi kriptografis;   |
|        | (Cryptographic AEAD Authentication)| dengan CEK, Nonce, dan| membuktikan keaslian dan integritas   |
|        |                                    | AAD 83-B (Tag 128-bit)| data dari manipulasi pihak ketiga.    |
+--------+------------------------------------+-----------------------+---------------------------------------+
```

### 3.6. Klasifikasi Kegagalan Fixture
Apabila berkas fixture manifest tidak ditemukan, mengalami kegagalan parsing sintaksis JSON, memiliki nilai ekspektasi yang hilang/korup, atau tidak memiliki asal-usul (*provenance*) ground truth yang independen:
- Pengujian yang bersangkutan **DILARANG KERAS DIBERI STATUS PASS**.
- Pengujian tersebut **DILARANG DIJADIKAN DASAR KLAIM KELAYAKAN SISTEM**.
- Seluruh kasus uji yang terdampak **WAJIB DIKLASIFIKASIKAN SEBAGAI `UNVERIFIED / Prerequisite Failure`**.

---

## 4. KOREKSI B — PENGIKATAN METADATA DIGEST DENGAN SESI DAN ARTEFAK SAAT RETRY

### 4.1. Parameter Pengikatan Wajib Sesi dan Artefak
Validitas artefak ciphertext untuk digunakan pada alur pengiriman ulang (*retry*) wajib terikat secara eksklusif pada kombinasi identitas sesi dan identitas artefak yang sama. Parameter pengikatan wajib mencakup:
1. `UploadSessionID`: 16 byte biner mentah (UUID v4 unik per sesi unggah).
2. `UploadSessionSalt`: 8 byte biner mentah acak CSPRNG (garam sesi kanonikal).
3. `RoomUUID`: 16 byte biner mentah (identitas ruang percakapan).
4. `MessageUUID`: 16 byte biner mentah (identitas pesan percakapan).
5. `AttachmentUUID`: 16 byte biner mentah (identitas berkas lampiran).
6. `ChunkIndex`: Integer uint32be ($0 \le \text{chunk\_index} < N$).
7. `TotalChunks`: Integer uint16be ($1 \le N \le 10$).
8. `PlaintextLength`: Integer uint32be ($1 \le L_i \le 1.048.576\text{ byte}$).
9. `ExpectedCiphertextArtifactLength`: Tepat $L_i + 28\text{ byte}$ ($12\text{ B Nonce} + L_i\text{ B Cipher} + 16\text{ B Tag}$).
10. `ExpectedSHA256Digest`: Hash digest 32 byte biner mentah ($H_{\text{exp}}$) dari aliran byte lengkap Format A.

> [!CAUTION]
> **PENEGASAN LARANGAN `CEK BINDING IDENTIFIER`:**  
> Dokumen B-CHAT-08D.1 **MENOLAK SECARA MUTLAK** pengenalan kembali `CEK Binding Identifier` sebagai metadata wajib. Kunci enkripsi simetris (CEK) adalah rahasia 256-bit yang tidak boleh diekspos melalui derivasi parsial dalam metadata penyimpanan lokal. Hubungan antara CEK dan artefak telah terikat secara intrinsik oleh kriptografi AEAD melalui AAD 83-byte dan verifikasi Tag 128-bit.

### 4.2. Sembilan Aturan Sekuensial Persistensi dan Retry
Untuk menjamin determinisme logika penyimpanan lokal dan mencegah pengiriman data korup:
1. **Kalkulasi Pre-Write Expected Digest:** Nilai $H_{\text{exp}}$ wajib dihitung di memori RAM atas buffer ciphertext Format A sesaat setelah operasi `crypto.subtle.encrypt` berhasil, sebelum aliran penulisan ke disk dimulai.
2. **Konfirmasi Penutupan Handle Berkas (I/O Close):** Penulisan berkas fisik ke media disk lokal wajib diselesaikan dan handle sistem berkas dikonfirmasi telah ditutup secara sukses bebas galat I/O (`io_close_confirmed == true`).
3. **Pembacaan Ulang Mandiri (Read-Back):** Setelah handle ditutup, modul penyimpanan wajib membuka dan membaca ulang byte artefak dari media disk ke dalam buffer baru di RAM.
4. **Verifikasi Panjang Framing Aktual:** Panjang byte hasil pembacaan ulang ($S_{\text{act}}$) wajib diperiksa dan tepat sama dengan panjang framing ekspektasi:
   $$S_{\text{act}} = L_i + 28\text{ byte}$$
   Jika $S_{\text{act}} \ne L_i + 28\text{ byte}$, artefak ditolak seketika (*fail-closed*).
5. **Kalkulasi dan Komparasi Digest Aktual:** Hitung digest aktual ($H_{\text{act}}$) atas buffer pembacaan ulang menggunakan SHA-256. Nilai $H_{\text{act}}$ wajib cocok 100% bit-demi-bit terhadap $H_{\text{exp}}$ ($H_{\text{act}} == H_{\text{exp}}$).
6. **Syarat Kelayakan Mutlak Retry Transmisi:** Pengiriman ulang chunk melalui antarmuka jaringan **HANYA DIPERBOLEHKAN** apabila:
   - Verifikasi digest berhasil ($H_{\text{act}} == H_{\text{exp}}$),
   - Panjang framing cocok ($S_{\text{act}} == L_i + 28\text{ B}$), dan
   - Keseluruhan 9 parameter metadata sesi cocok 100% dengan sesi pengunggahan aktif saat ini.
7. **Kewajiban Pembatalan Sesi (Fail-Closed Session Abandonment):** Apabila terjadi galat I/O penulisan, proses crash, pembacaan ulang gagal, atau nilai digest tidak cocok ($H_{\text{act}} \ne H_{\text{exp}}$), status persistensi dinyatakan tidak pasti (*uncertain state*). Sesi pengunggahan tersebut **WAJIB DIBATALKAN SEUTUHNYA**.
8. **Larangan Migrasi Artefak Lintas Sesi:** Sesi baru yang dibangkitkan pasca-abandonment wajib menghasilkan:
   - **CEK baru** via CSPRNG (256-bit),
   - **UploadSessionID baru** (UUID v4), dan
   - **UploadSessionSalt baru** via CSPRNG (64-bit).  
   **Dilarang keras memindahkan, mengaitkan ulang, atau menganggap valid artefak dari sesi lama untuk sesi baru yang baru dibangkitkan**.
9. **Pengakuan Keterbatasan Keamanan Digest:** Nilai hash SHA-256 lokal beroperasi tanpa kunci (*unkeyed digest*). **Dilarang keras mengklaim digest lokal sebagai perlindungan terhadap penyerang lokal berizin tulis sistem berkas yang mampu memodifikasi berkas ciphertext dan metadata `.meta.json` secara bersamaan**. Digest lokal bukan pengganti verifikasi autentikasi AES-256-GCM pada penerima dan bukan pengganti tanda tangan manifest produksi.

### 4.3. Rancangan Konseptual Skema Berkas `.meta.json`
Metadata penyimpanan digest disimpan berdampingan dengan artefak biner dengan nama berkas konseptual:
```text
scratch/bchat_prototype/exp02_chunking_aad/storage/<attachment_uuid>/chunk_<index>.meta.json
```

**Struktur Konseptual Field JSON:**
```json
{
  "$schema": "urn:unuha:e2ee:exp02:storage-meta:v1",
  "meta_version": "1.0.0",
  "artifact_reference": {
    "file_name": "chunk_0.bin",
    "expected_frame_length_bytes": 1048604
  },
  "session_binding": {
    "room_uuid": "11111111-1111-4111-8111-111111111111",
    "message_uuid": "22222222-2222-4222-8222-222222222222",
    "attachment_uuid": "33333333-3333-4333-8333-333333333333",
    "upload_session_id": "44444444-4444-4444-8444-444444444444",
    "upload_session_salt_hex": "0123456789abcdef",
    "chunk_index": 0,
    "total_chunks": 1,
    "plaintext_length": 1048576
  },
  "integrity_lifecycle": {
    "pre_write_sha256_hex": "9c8b7a6d5e4f3a2b1c0d...",
    "pre_write_timestamp_epoch_ms": 1793875200000,
    "io_close_confirmed": true,
    "readback_verified": true,
    "post_write_sha256_hex": "9c8b7a6d5e4f3a2b1c0d...",
    "readback_timestamp_epoch_ms": 1793875200050,
    "integrity_status": "VERIFIED_READY_FOR_RETRY"
  }
}
```

*Catatan Mandat:* Rancangan skema di atas adalah spesifikasi konseptual dokumentatif. **Tidak ada pembuatan berkas `.meta.json` fisik di disk**.

---

## 5. KOREKSI C — FORMULASI DETERMINISTIK KEBIJAKAN CIRCUIT BREAKER

Untuk memastikan implementasi modul henti darurat (*circuit breaker*) pada prototipe EXP-02 kelak menghasilkan keputusan yang deterministik, konsisten, dan dapat direproduksi untuk urutan hasil pengujian yang sama, ditetapkan aturan operasional berikut:

### 5.1. Counter Scenario-Level Failure (Kelas 2)
1. **Penghitungan Berbasis Skenario Unik yang Gagal (*Unique Failed Scenario*):**  
   Penghitung kegagalan beruntun (*consecutive failure counter*) dihitung berdasarkan **skenario unik yang gagal definitif**, BUKAN berdasarkan jumlah percobaan (*attempt*) I/O lokal.
2. **Aturan Retry Transien per-Skenario ($K_{\text{retry}} = 1$):**  
   Untuk mencegah anomali I/O transien lokal (misalnya penguncian berkas sementara oleh OS), setiap skenario uji diizinkan melakukan maksimal 1 kali percobaan ulang transien ($K_{\text{retry}} = 1$).
   - Jika *Attempt 1* gagal tetapi *Attempt 2* sukses: Skenario dinyatakan `PASS` dan counter kegagalan kelompok terkait di-reset ke 0.
   - Jika *Attempt 1* dan *Attempt 2* keduanya gagal: Skenario secara resmi dinyatakan `SCENARIO FAIL` definitif, dan counter kegagalan kelompok bersangkutan bertambah $+1$ ($C \leftarrow C + 1$).
   - Percobaan ulang di dalam skenario yang sama **dilarang menambah counter ganda**.
3. **Isolasi Reset Antar-Kelompok Kategori (*Category-Scoped Reset on PASS*):**  
   Hasil `PASS` definitif pada suatu skenario **HANYA mereset counter pada kelompok kategori yang sama**. Hasil PASS pada Kategori C (Kuota) **SAMA SEKALI TIDAK MERESET** counter kegagalan yang sedang terakumulasi pada Kategori D (Integritas AAD).
4. **Emergency Hard Stop Tanpa Toleransi untuk Fatal Violation (Kelas 1):**  
   Terjadinya pelanggaran keamanan fatal (*Fatal Security Violation*: nonce reuse pada CEK yang sama, ciphertext termodifikasi lolos verifikasi GCM, kebocoran plaintext parsial, atau crash recovery memakai CEK lama) **wajib memicu EMERGENCY HARD STOP seketika** pada saat kejadian terdeteksi, tanpa menunggu pengulangan retry dan tanpa evaluasi ambang batas numerik.

### 5.2. Counter Hasil UNVERIFIED (Kelas 3)
1. **Penghitungan Terpisah per-Kelompok (*Category-Scoped Consecutive UNVERIFIED*):**  
   Hasil `UNVERIFIED` mencerminkan kegagalan instrumen lingkungan atau rusaknya data fixture, bukan kegagalan logika kriptografi aplikasi. Oleh karena itu, hasil `UNVERIFIED` dicatat pada penghitung terpisah ($U_{\text{category}}$) dan **DILARANG MENAMBAH penghitung kegagalan kriptografi Kelas 2 ($C_{\text{category}}$)**.
2. **Pemutusan Urutan UNVERIFIED:**  
   Hasil `PASS` pada skenario berikutnya dalam kelompok yang sama memutus urutan UNVERIFIED dan mereset penghitung $U_{\text{category}} \leftarrow 0$.
3. **Penanganan Prasyarat yang Belum Diperbaiki:**  
   Apabila terjadi 2 kali `UNVERIFIED` berturut-turut pada kelompok kategori yang sama ($U_{\text{category}} \ge 2$), seluruh skenario yang tersisa di dalam kelompok tersebut **wajib ditahan (*execution blocked*)**. Seluruh skenario dependen dalam kelompok tersebut otomatis diklasifikasikan sebagai `UNVERIFIED` tanpa eksekusi, untuk mencegah eksekusi sia-sia pada lingkungan yang rusak.
4. **Larangan Klasifikasi Ganda:**  
   Hasil `UNVERIFIED` dilarang diklasifikasikan sebagai `PASS` dan dilarang diklasifikasikan sebagai `FAIL` tingkat skenario.

### 5.3. Evaluasi Ambang Kuantitatif Circuit Breaker
- **Evaluasi Ambang $M = 3$ (Scenario-Level Failure):**  
  Tiga skenario unik gagal berturut-turut ($M = 3$) pada kelompok kategori yang sama memicu penghentian otomatis (*Category Stop*). Ambang ini dipilih secara rasional: angka $M = 1$ terlalu sensitif terhadap galat lingkungan lokal yang terisolasi, sedangkan $M \ge 5$ membiarkan eksekusi prototipe memboroskan sumber daya pada sistem yang jelas-jelas mengalami kegagalan logika berulang.
- **Evaluasi Ambang $U = 2$ (Consecutive UNVERIFIED):**  
  Dua hasil `UNVERIFIED` berturut-turut ($U = 2$) mengindikasikan masalah prasyarat sistemik (misalnya Web Crypto API tidak tersedia pada runtime atau berkas fixture manifest korup), sehingga kelompok pengujian harus segera dihentikan.
- **Batas Kebijakan Prototipe:**  
  Kedua ambang numerik di atas ($M = 3, U = 2$) ditegaskan sebagai **kebijakan operasional khusus untuk prototipe Node-only EXP-02**, BUKAN standar universal dan bukan jaminan keamanan untuk sistem produksi multi-node.

### 5.4. Prosedur Reset dan Pelanjutan Pengujian (Resume Conditions)
Kelompok skenario pengujian yang dihentikan oleh circuit breaker hanya dapat dilanjutkan apabila memenuhi empat kondisi berikut secara kumulatif:
1. **Identifikasi Akar Penyebab:** Akar penyebab kegagalan (*root cause*) telah diidentifikasi dan didokumentasikan dalam laporan investigasi teknis.
2. **Verifikasi Perbaikan Prasyarat / Kode:** Modul kode yang cacat atau berkas fixture yang rusak telah diperbaiki dan divalidasi ulang kesesuaian formatnya.
3. **Pencatatan Bukti Insiden:** Seluruh data insiden penghentian telah terdokumentasi lengkap dalam log henti darurat.
4. **Persetujuan Dimulainya Ulang:**  
   - Penghentian akibat Kelas 1 (*Fatal Security Violation*) **memerlukan persetujuan tertulis Pemilik Proyek** sebelum pengujian baru dari awal (*fresh run*) dapat dijalankan.
   - Penghentian akibat terlampauinya ambang Kelas 2 ($M = 3$) atau Kelas 3 ($U = 2$) dapat dilanjutkan dari awal kelompok skenario terkait (*clean group restart*) dengan persetujuan tim rekayasa.

### 5.5. Rancangan Konseptual Berkas Log Insiden (`emergency_stop_incident_log.json`)
Modul circuit breaker dirancang mencatat insiden penghentian ke dalam berkas log konseptual dengan format 11 field standar:

```json
{
  "incident_id": "INC-20261105-EXP02-001",
  "timestamp": "2026-11-05T00:00:00.000Z",
  "category": "KATEGORI_D_INTEGRITAS_AAD",
  "scenario_id": "UJI-D10A",
  "attempt_id": 2,
  "result_class": "SCENARIO_LEVEL_FAILURE",
  "counter_before": 2,
  "counter_after": 3,
  "stop_reason": "CONSECUTIVE_SCENARIO_FAILURE_THRESHOLD_EXCEEDED (M=3)",
  "evidence_reference": {
    "expected_behavior": "Reject chunk_index >= TotalChunks with RangeError",
    "actual_behavior": "Function accepted index without throwing expected error",
    "session_context_id": "44444444-4444-4444-8444-444444444444"
  },
  "resume_status": "BLOCKED_PENDING_ROOT_CAUSE_INVESTIGATION"
}
```

*Catatan Mandat:* Field log di atas adalah rancangan konseptual. **Dilarang membuat berkas log fisik di disk**.

---

## 6. MATRIKS KEPUTUSAN DESAIN DAN STATUS PERSETUJUAN

Tabel matriks berikut memisahkan secara transparan antara ketentuan yang telah ditetapkan sebelumnya, rekomendasi teknis baru hasil amendemen B-CHAT-08D.1, serta status persetujuannya:

```
+--------------------------------------------------------------------------------------------------------------------------------------------------+
|                                    MATRIKS KEPUTUSAN DESAIN PRASYARAT PRE-EKSEKUSI EXP-02 (AMENDEMEN B-08D.1)                                    |
+----+-----------------------+-----------------------+------------------------------------------+------------------------------+-------------------+
| No | Isu Desain            | Ketentuan Sebelumnya  | Rekomendasi Teknis Baru (B-CHAT-08D.1)   | Alasan Teknis & Dampak       | Status Persetujuan|
|    |                       | (B-08A.5 s.d. B-08D)  |                                          | Arsitektural                 |                   |
+----+-----------------------+-----------------------+------------------------------------------+------------------------------+-------------------+
| 1  | Metodologi Ground     | Skrip generator mandiri| Pre-computed static test vectors; seed   | Mencegah circular bias; seed | DESIGN REVIEW     |
|    | Truth Fixture         | & seed tetap diajukan;| hanya parameter reproduksi, bukan bukti  | bukan bukti independensi;    | REQUIRED          |
|    |                       | skema JSON B-08D §4.  | independensi; 6 field komponen wajib fixture.| bebas asumsi fungsi SUT. |                   |
+----+-----------------------+-----------------------+------------------------------------------+------------------------------+-------------------+
| 2  | Kategori Validasi     | 5 lapisan keabsahan   | Formalisasi 4 tingkatan validasi:        | Membedakan penolakan sintaks | DESIGN REVIEW     |
|    | Data Uji              | terpisah di B-08D §5.1| V-1 (Batas), V-2 (Framing), V-3 (Storage | lokal dari penolakan tag     | REQUIRED          |
|    |                       |                       | Digest), V-4 (AEAD Authentication).      | autentikasi kriptografis.    |                   |
+----+-----------------------+-----------------------+------------------------------------------+------------------------------+-------------------+
| 3  | Pengikatan Sesi &     | 9 parameter sesi &    | 10 parameter pengikatan eksplisit        | Menjamin isolasi mutlak arte-| DESIGN REVIEW     |
|    | Artefak saat Retry    | larangan CEK Binding  | (termasuk Expected Frame Length & SHA256)| fak; melarang artefak lama   | REQUIRED          |
|    |                       | Identifier B-08D §5.2.| 9 aturan persistensi & session abandonment| dipakai untuk sesi baru.     |                   |
+----+-----------------------+-----------------------+------------------------------------------+------------------------------+-------------------+
| 4  | Skema Berkas Metadata | Konsep digest lokal di| Skema konseptual .meta.json 11 field     | Mendeteksi korupsi I/O lokal | DESIGN REVIEW     |
|    | Digest Penyimpanan    | B-08D §5.2.           | terstandarisasi; zero creation fisik.    | sebelum retry tanpa klaim MAC| REQUIRED          |
+----+-----------------------+-----------------------+------------------------------------------+------------------------------+-------------------+
| 5  | Determinisme Counter  | Ambang M = 3 diajukan;| Counter dihitung per skenario unik gagal | Mencegah runaway count akibat| DESIGN REVIEW     |
|    | Scenario Failure      | basis hitung terbuka. | definitif (maks 1 retry per skenario);   | transient OS I/O lock;       | REQUIRED          |
|    |                       |                       | PASS mereset HANYA kelompok yang sama.   | deterministik & reproducible.|                   |
+----+-----------------------+-----------------------+------------------------------------------+------------------------------+-------------------+
| 6  | Counter Sekuens       | Kategori UNVERIFIED   | Counter UNVERIFIED terpisah per kategori;| Mencegah eksekusi sia-sia    | DESIGN REVIEW     |
|    | UNVERIFIED            | didefinisikan 08C.4.  | >= 2x UNVERIFIED berturut-turut hentikan | pada lingkungan yang rusak;  | REQUIRED          |
|    |                       |                       | kelompok; PASS memutus urutan.           | tidak mencemari counter FAIL.|                   |
+----+-----------------------+-----------------------+------------------------------------------+------------------------------+-------------------+
| 7  | Evaluasi Ambang       | M = 3 diusulkan pada  | Ditegaskan M = 3 dan U = 2 sebagai       | Transparansi batas klaim;    | DESIGN REVIEW     |
|    | Kuantitatif CB        | B-08D §6.2.           | kebijakan proporsional prototipe Node-onlybukan standar universal atau   | REQUIRED          |
|    |                       |                       | EXP-02 (bukan standar universal).        | jaminan multi-node.          |                   |
+----+-----------------------+-----------------------+------------------------------------------+------------------------------+-------------------+
| 8  | Prosedur Resume       | Evaluasi henti terbuka| 4 syarat kumulatif pelanjutan (akar,     | Menjamin audit trail lengkap;| DESIGN REVIEW     |
|    | Pasca-Henti Darurat   | di B-08D §6.2.        | perbaikan, log, persetujuan Owner/tim).  | Kelas 1 wajib izin Owner.    | REQUIRED          |
+----+-----------------------+-----------------------+------------------------------------------+------------------------------+-------------------+
| 9  | Rancangan Log         | Format log darurat    | Spesifikasi konseptual 11 field JSON     | Format log audit forensik    | DESIGN REVIEW     |
|    | Insiden CB            | di B-08D §6.2.        | (emergency_stop_incident_log.json).      | terstandarisasi pra-kode.    | REQUIRED          |
+----+-----------------------+-----------------------+------------------------------------------+------------------------------+-------------------+
```

---

## 7. REKONSILIASI CHECKLIST 12 BUTIR PRE-EKSEKUSI

Dokumen B-CHAT-08D.1 mengevaluasi kembali dampak terhadap butir-butir checklist pre-eksekusi terkait:

```
+--------------------------------------------------------------------------------------------------------------------------------------------------+
|                                           TABEL REKONSILIASI CHECKLIST 12 BUTIR PRE-EKSEKUSI EXP-02                                              |
+----+------------------------------------+-----------------------+-----------------------+---------------------+-------------------+--------------+
| No | Prasyarat Kesiapan                 | Status B-CHAT-08D     | Status B-CHAT-08D.1   | Bukti Dokumentatif  | Bukti Empiris yang| Status Pasca-|
|    |                                    | (Sebelum Koreksi)     | (Saat Ini)            | yang Tersedia       | Masih Diperlukan  | Persetujuan  |
+----+------------------------------------+-----------------------+-----------------------+---------------------+-------------------+--------------+
| 1  | Parameter teknis kanonikal telah   | VERIFIED DOCUMENTALLY | VERIFIED DOCUMENTALLY | Tabel 14 parameter  | Belum ada eksekusi| VERIFIED     |
|    | direkonsiliasi tanpa pertentangan  |                       |                       | terkunci di B-08A.5 | pengujian PoC.    | DOCUMENTALLY |
+----+------------------------------------+-----------------------+-----------------------+---------------------+-------------------+--------------+
| 2  | Hierarki dokumen tidak menyisakan  | VERIFIED DOCUMENTALLY | VERIFIED DOCUMENTALLY | Hierarki 3 tingkat  | N/A (Ketentuan    | VERIFIED     |
|    | konflik spesifikasi operasional    |                       |                       | di B-08B.1 & B-08C  | tata kelola).     | DOCUMENTALLY |
+----+------------------------------------+-----------------------+-----------------------+---------------------+-------------------+--------------+
| 3  | Fixture manifest sintetis tepercaya| PARTIALLY VERIFIED    | PARTIALLY VERIFIED    | Skema JSON 6 elemen | Pembuatan berkas  | VERIFIED     |
|    | telah ditentukan koridornya        | [Rancangan Selesai]   | [Koreksi Presisi      | & batas independensi| fisik di sandbox  | DOCUMENTALLY |
|    |                                    |                       |  Independensi Selesai]| disempurnakan.      | saat PoC dimulai. | (DESIGN SPEC)|
+----+------------------------------------+-----------------------+-----------------------+---------------------+-------------------+--------------+
| 4  | Batas persistensi dan aturan crash | PARTIALLY VERIFIED    | PARTIALLY VERIFIED    | 9 aturan retry &    | Pengujian I/O disk| VERIFIED     |
|    | recovery telah ditetapkan          | [Rancangan Selesai]   | [Koreksi Presisi      | 10 parameter sesi   | & simulasi crash; | DOCUMENTALLY |
|    |                                    |                       |  Pengikatan Selesai]  | diperketat.         | fsync = UNVERIFIED| (DESIGN SPEC)|
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
|    | aktual telah diverifikasi faktual  |                       | [Tetap Terpisah]      | laporan EXP-01      | saat ini di mesin | (Wajib pada  |
|    |                                    |                       |                       | (belum audit kini). | workstation.      | Prasyarat 1) |
+----+------------------------------------+-----------------------+-----------------------+---------------------+-------------------+--------------+
| 9  | Direktori sandbox terisolasi telah | VERIFIED DOCUMENTALLY | VERIFIED DOCUMENTALLY | Koridor sandbox di  | Pembuatan folder  | VERIFIED     |
|    | ditentukan koridor batasannya      |                       |                       | scratch/bchat_proto | fisik di disk.    | DOCUMENTALLY |
+----+------------------------------------+-----------------------+-----------------------+---------------------+-------------------+--------------+
| 10 | Batas berkas yang boleh dibuat saat| VERIFIED DOCUMENTALLY | VERIFIED DOCUMENTALLY | Daftar berkas diizin| Pengecekan daftar | VERIFIED     |
|    | otorisasi terbit telah jelas       |                       |                       | kan terkunci ketat  | berkas di sandbox.| DOCUMENTALLY |
+----+------------------------------------+-----------------------+-----------------------+---------------------+-------------------+--------------+
| 11 | Kriteria evaluasi PASS, FAIL, dan  | PARTIALLY VERIFIED    | PARTIALLY VERIFIED    | Kriteria determinis-| Evaluasi kelulusan| VERIFIED     |
|    | UNVERIFIED telah didefinisikan     | [Rancangan Selesai]   | [Koreksi Determinis-| tik & batas 1 retry | skenario pada test| DOCUMENTALLY |
|    |                                    |                       |  me CB Selesai]       | diformalkan.        | runner prototipe. | (DESIGN SPEC)|
+----+------------------------------------+-----------------------+-----------------------+---------------------+-------------------+--------------+
| 12 | Kondisi henti darurat (circuit     | PARTIALLY VERIFIED    | PARTIALLY VERIFIED    | Kebijakan M=3, U=2, | Pengujian modul   | VERIFIED     |
|    | breaker / fail-closed) telah jelas | [Rancangan Selesai]   | [Koreksi Determinis-| scope per-kategori, | henti darurat dgn | DOCUMENTALLY |
|    |                                    |                       |  me CB Selesai]       | & resume disempurnakan.injeksi kesalahan.| (DESIGN SPEC)|
+----+------------------------------------+-----------------------+-----------------------+---------------------+-------------------+--------------+
```

### Penegasan Disiplin Checklist:
- **Larangan Kenaikan Status Sepihak:** Status Butir 3, 4, 11, dan 12 **TIDAK DINAIKKAN** secara prematur menjadi `VERIFIED DOCUMENTALLY` hanya karena dokumen amendemen B-CHAT-08D.1 telah diterbitkan. Status tetap berada pada **`PARTIALLY VERIFIED`** hingga Pemilik Proyek menerbitkan persetujuan tertulis resmi atas dokumen desain ini.
- **Butir 8 Tetap `UNVERIFIED`:** Audit empiris lingkungan workstation aktual tetap merupakan prasyarat empiris terpisah yang tidak dieksekusi dalam mandat dokumentasi ini.
- **Status Eksperimen Tetap `NOT AUTHORIZED`:** Eksperimen EXP-02 belum diotorisasi.

---

## 8. RISIKO, ASUMSI, DAN KETERBATASAN YANG MASIH TERSISA

Auditor dan tim pengembang wajib mempertahankan pengakuan transparan atas risiko serta keterbatasan teknis yang melekat:
1. **Risiko Kesamaan Asumsi Serialisasi pada Oracle Utilitas:**  
   Meskipun menggunakan skrip pembangkit ground truth terpisah, risiko kesamaan asumsi logika serialisasi biner (seperti *endianness* atau format padding biner) tetap dapat terjadi jika pengembang yang sama menulis kedua modul tersebut. Pencegahan terbaik adalah membandingkan keluaran serialisasi terhadap vektor uji teks RFC resmi.
2. **Keterbatasan Kinerja Runtime Node.js CLI vs Browser Web Worker:**  
   Pengujian memori dan streaming pada lingkungan Node.js CLI tidak merefleksikan sepenuhnya karakteristik alokasi thread, sandbox origin, dan keterbatasan heap Web Worker pada peramban peramban web nyata (Chromium/Gecko/WebKit).
3. **Ketiadaan Pengujian Crash Fisik Media Penyimpanan (`fsync`):**  
   Pengujian logika persistensi pada EXP-02 menguji antarmuka sistem berkas Node.js, bukan ketahanan media terhadap pemutusan daya perangkat keras mendadak (*abrupt hardware power cut*).
4. **Kelemahan Inheren Unkeyed Digest Lokal:**  
   Digest SHA-256 lokal pada `.meta.json` tidak melindungi berkas dari manipulasi simultan oleh penyerang dengan hak akses sistem operasi lokal (*local filesystem access*).

---

## 9. STATUS GERBANG AKHIR DAN PENYATAAN OTORISASI

### 9.1. Penetapan Status Evaluasi Desain
Berdasarkan hasil koreksi presisi terbatas atas independensi fixture, pengikatan retry, dan determinisme circuit breaker, status gerbang pre-eksekusi EXP-02 secara resmi ditetapkan sebagai:

$$\mathbf{STATUS\ GERBANG:\ DESIGN\ REVIEW\ REQUIRED\ —\ EXP\text{-}02\ NOT\ AUTHORIZED}$$

**Alasan Penetapan Status:**
1. Tiga kelemahan presisi desain yang diidentifikasi oleh Pemilik Proyek pada dokumen B-CHAT-08D telah berhasil disempurnakan dan diformalkan secara deterministik tanpa mengubah parameter teknis kanonikal.
2. Seluruh rekomendasi teknis baru disajikan secara transparan di bawah status usulan (*Design Review Required*) dan tidak diposisikan secara sepihak sebagai kebijakan yang telah disetujui.
3. Prasyarat empiris (Audit Lingkungan Workstation) dan keputusan otorisasi eksekusi EXP-02 tetap merupakan wewenang manajerial terpisah Pemilik Proyek yang belum diterbitkan.

---

### 9.2. Komitmen Kepatuhan Nol Eksekusi (Zero Execution Compliance)
Seluruh kegiatan penyusunan dokumen amendemen B-CHAT-08D.1 dilaksanakan dengan kepatuhan penuh terhadap komitmen operasional mutlak:
- **Zero Prototype Code:** Direktori sandbox `scratch/bchat_prototype/exp02_chunking_aad/` **belum dibuat** dan tidak ada kode prototipe yang ditulis.
- **Zero Fixture & Hash Files:** Tidak ada berkas fixture manifest JSON fisik, berkas biner `.bin`, maupun berkas hash yang dibuat di disk.
- **Zero Test & Benchmark:** Tidak ada pengujian kriptografi, simulasi I/O, atau pengukuran memori yang dijalankan.
- **Zero Dependency Installation:** Tidak ada paket npm yang dipasang atau diperbarui.
- **Zero Runtime Execution:** Tidak ada audit runtime host, skrip CLI, proses Node.js, atau server aplikasi yang dijalankan.
- **Zero Main Codebase Changes:** Direktori aplikasi Laravel, Next.js, dan database MySQL tidak disentuh sama sekali.
- **Zero Git Operations:** Tidak ada perintah Git yang dijalankan.
- **Zero Historical Overwrite:** Seluruh dokumen historis repositori tetap tersimpan utuh tanpa penimpaan.

---

### 9.3. Pernyataan Eksplisit Penutup Status Otorisasi EXP-02

$$\mathbf{STATUS\ FINAL\ OPERASIONAL:\ EXP\text{-}02\ NOT\ AUTHORIZED}$$

> [!CAUTION]
> **PERINGATAN OPERASIONAL PENUTUP:**  
> Dokumen amendemen **B-CHAT-08D.1** ini secara resmi menutup seluruh koreksi presisi terbatas desain prasyarat EXP-02. **TIDAK ADA OTORISASI OTOMATIS** untuk memulai pembuatan direktori sandbox, instalasi dependensi, pembuatan berkas fixture fisik, atau penulisan kode prototipe EXP-02. Seluruh pekerjaan rekayasa perangkat lunak berada dalam status siaga penuh (*standby*) menunggu peninjauan desain dan penerbitan persetujuan tertulis eksplisit terpisah dari Pemilik Proyek.

---
*(Dokumen B-CHAT-08D.1 selesai disusun secara paripurna sebagai amendemen koreksi presisi terbatas desain prasyarat EXP-02, siap ditinjau oleh Pemilik Proyek).*

