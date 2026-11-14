# B-CHAT-08A.1 — KOREKSI TEKNIS TERBATAS MANDAT EXP-02
## REKONSILIASI PRESISI HIPOTESIS KEAMANAN, STRATEGI NONCE TERKOREKSI, PENGELOLAAN MEMORI BUFFER STREAMING, PENANGANAN TRANSPORT REORDERING, DAN PEMISAHAN BUKTI LINGKUNGAN

**Status Dokumen:** DOKUMEN KOREKSI TEKNIS RESMI (*CONTROLLED SPECIFICATION AMENDMENT*)  
**Mode Operasi:** STRICTLY READ-ONLY TERHADAP APLIKASI — DOCUMENTATION ONLY  
**Otorisasi Pelaksanaan EXP-02:** **BELUM DIBERIKAN** *(Eksperimen EXP-02 belum diotorisasi)*  
**Dokumen Sumber yang Dikoreksi:** [B-CHAT-08A_Rencana_dan_Mandat_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A_Rencana_dan_Mandat_EXP-02.md)  
**Dokumen Acuan Induk:**
- [B-CHAT-06D_Mandat_Eksekusi_Terbatas_Gerbang_2.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-06D_Mandat_Eksekusi_Terbatas_Gerbang_2.md) (Revisi B-CHAT-06D.2 §3.2)
- [B-CHAT-05_Spesifikasi_Final_E2EE.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-05_Spesifikasi_Final_E2EE.md) (Keputusan Desain OD-02 & OD-17)
- Rangkaian Dokumen Forensik EXP-01: [B-CHAT-07A.1](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A_Laporan_Eksekusi_EXP-01.md) s.d. [B-CHAT-07A.7](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.7_Koreksi_Editorial_dan_Klasifikasi_Bukti_Final_EXP-01.md)  
**Proyek:** Sistem Informasi Manajemen Skripsi UNUHA  
**Fitur:** Bimbingan Online Chat Real-Time E2EE  
**Tahap:** Peninjauan Mandat Gerbang 2 (Persiapan EXP-02)  
**Tanggal:** 5 November 2026  

---

> [!CAUTION]
> **PENEGASAN OTORISASI OPERASIONAL MUTLAK:**  
> Penerbitan dokumen koreksi **B-CHAT-08A.1** ini **BUKAN** merupakan izin atau otorisasi untuk memulai eksekusi prototipe EXP-02. Persetujuan Gerbang 2 sebelumnya tidak boleh ditafsirkan sebagai persetujuan pelaksanaan EXP-02 secara otomatis. Pelaksanaan fisik di sandbox hanya dapat dimulai setelah Pemilik Proyek memberikan persetujuan eksplisit tertulis atas mandat final ini. Selama proses penyusunan, tidak ada berkas kode yang dibuat, tidak ada dependensi yang dipasang, tidak ada pengujian/benchmark yang dijalankan, dan tidak ada operasi Git. Dokumen sumber [B-CHAT-08A](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A_Rencana_dan_Mandat_EXP-02.md) tetap dipertahankan utuh sebagai arsip historis.

---

## 1. TUJUAN DAN RINGKASAN PERUBAHAN TEKNIS

Dokumen **B-CHAT-08A.1** diterbitkan untuk melakukan koreksi teknis terbatas atas dokumen perencanaan [B-CHAT-08A](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A_Rencana_dan_Mandat_EXP-02.md) berdasarkan hasil peninjauan kritis Pemilik Proyek. Seluruh struktur dasar, ketentuan kuota resmi OD-02, spesifikasi AAD kanonikal 83-byte, dan ruang lingkup isolasi tetap dipertahankan.

### Ringkasan 7 Area Perbaikan Teknis Utama:
1. **Penyempurnaan Hipotesis & Batasan Keamanan (§1):** Mengoreksi istilah "deterministik" (yang deterministik dan identik adalah plaintext hasil dekripsi, bukan ciphertext dari dua enkripsi dengan nonce berbeda); membatasi cakupan H2 pada autentikasi kriptografis AES-GCM; menegaskan AAD tidak menggantikan autentikasi pengguna/otorisasi room/distribusi CEK; serta mengeluarkan klaim bahwa EXP-02 membuktikan pencegahan *session hijacking* secara menyeluruh.
2. **Penajaman Strategi Nonce & Probabilitas Salt (§5.2):** Menetapkan 7 ketentuan wajib Opsi 1 (1 CEK baru per sesi unggah, 1 salt 64-bit per sesi, chunk_index dipakai 1 kali, retry menggunakan ciphertext tersimpan tanpa re-enkripsi, sesi baru wajib CEK & salt baru, larangan penggunaan ulang CEK-salt, keunikan nonce bersyarat); menjelaskan probabilitas tabrakan salt 64-bit; dan meluruskan parameter $q$ pada *birthday bound* Opsi 2 sebagai total seluruh operasi enkripsi di bawah kunci yang sama.
3. **Koreksi Kriteria Kegagalan CSPRNG (§5.3):** Menghapus ketentuan keliru bahwa buffer acak bernilai nol otomatis dianggap kegagalan entropi; menggantinya dengan evaluasi ketiadaan fungsi `getRandomValues` atau pelemparan exception; penegasan mitigasi *fail-closed*; serta verifikasi kuota ukuran pemanggilan Web Crypto ($12\text{ B} \ll 65.536\text{ B}$).
4. **Pemisahan Transport Reordering dari Tampering & Penambahan 9 Uji Batas (§6):** Memisahkan *transport reordering* (dapat disusun kembali berdasarkan indeks terautentikasi), *index tampering* (modifikasi indeks AAD memicu penolakan GCM), dan *assembly validation* (chunk hilang/duplikat ditolak); mengoreksi UJI-D8; serta menambahkan 9 skenario pengujian batas dan anomali baru.
5. **Protokol Staging Buffer & Pencegahan Kebocoran Plaintext Parsial (§3 & §7):** Menegaskan bahwa keberhasilan satu chunk tidak membuktikan keabsahan file; hasil dekripsi wajib disimpan sementara (*staging*) dan baru dipublikasikan setelah seluruh validasi (chunk, indeks, panjang, manifest) lulus; serta menandai perlindungan plaintext parsial tingkat file sebagai `UNVERIFIED` jika belum diuji.
6. **Klarifikasi Framing Ciphertext Web Crypto vs Format Aplikasi (§3 & §12):** Menjelaskan bahwa keluaran `crypto.subtle.encrypt()` adalah `Ciphertext || AuthTag` ($L_i + 16\text{ byte}$), sedangkan serialisasi penyimpanan aplikasi (Format A) adalah `Nonce (12 B) || Ciphertext (L_i B) || AuthTag (16 B)` ($L_i + 28\text{ byte}$).
7. **Hakikat Streaming Aplikasi & Metrik Memori Multi-Dimensi (§1, §7, §8, §10):** Menegaskan `crypto.subtle` bukan API streaming melainkan chunk-by-chunk di lapisan aplikasi; memperluas metrik Node.js ke `heapUsed`, `external`, `arrayBuffers`, dan `rss`; memisahkan bukti browser dari Node.js; mempertahankan target buffer $\le 20\text{ MB}$ sebagai `PROPOSED`; serta menjadikan UJI-F2 full-buffer sebagai kontrol observasional tanpa syarat mutlak $> 30\text{ MB}$.

---

## 2. BAGIAN SEBELUM DAN SESUDAH KOREKSI UNTUK SETIAP ISU

Berikut adalah perbandingan tekstual mendalam (*before vs after*) untuk ketujuh isu teknis yang dikoreksi:

```
+-----------------------------------------------------------------------------------------------------------------------------------------+
|                                           MATRIKS PERBANDINGAN SEBELUM DAN SESUDAH KOREKSI                                              |
+---+----------------------------+------------------------------------------+-------------------------------------------------------------+
| No| Aspek yang Dikoreksi       | Rumusan Sebelum (B-CHAT-08A)             | Rumusan Terkoreksi Resmi (B-CHAT-08A.1)                     |
+---+----------------------------+------------------------------------------+-------------------------------------------------------------+
| 1 | Hipotesis H1 & H2 (§1.2)   | "mampu mengeksekusi enkripsi & dekripsi  | "mampu mendekripsi ciphertext menjadi plaintext yang 100%   |
|   |                            | secara deterministik... mencegah         | identik bit-demi-bit (SHA-256 match). Menolak modifikasi bit|
|   |                            | session hijacking secara menyeluruh"     | pada cipher/AAD. Tidak membuktikan anti-session hijacking". |
+---+----------------------------+------------------------------------------+-------------------------------------------------------------+
| 2 | Strategi Nonce Opsi 1 & 2  | "Jaminan matematis 100% bebas tabrakan...| "Keunikan nonce bersyarat pada: 1 CEK per sesi, 1 salt CSPRNG|
|   | (§5.2)                     | q adalah jumlah chunk dalam 1 file"      | per sesi, retry pakai ciphertext lama, sesi baru pakai CEK  |
|   |                            |                                          | baru. q adalah TOTAL operasi di bawah kunci yang sama".     |
+---+----------------------------+------------------------------------------+-------------------------------------------------------------+
| 3 | Evaluasi CSPRNG (§5.3)     | "jika buffer bernilai nol seluruhnya     | "Dibatalkan jika getRandomValues() tidak tersedia atau      |
|   |                            | dianggap kegagalan entropi"              | melempar exception. Buffer nol bukan indikator gagal entropi|
|   |                            |                                          | Permintaan 12 B jauh di bawah kuota 65.536 B".              |
+---+----------------------------+------------------------------------------+-------------------------------------------------------------+
| 4 | Reordering & Uji Integritas| UJI-D8 menganggap chunk tiba tidak       | Dipisahkan: Transport reordering (bisa disusun ulang),      |
|   | (§6)                       | berurutan sebagai AssemblyError          | Index tampering (gagal GCM), Assembly validation (manifest).|
|   |                            |                                          | Ditambahkan 9 skenario uji batas & anomali baru.            |
+---+----------------------------+------------------------------------------+-------------------------------------------------------------+
| 5 | Staging Plaintext Parsial  | Belum mengatur penahanan buffer parsial  | Hasil dekripsi chunk disimpan sementara (staging); file     |
|   | (§3 & §7)                  | sebelum seluruh file tervalidasi         | final hanya dipublikasikan setelah seluruh validasi lulus.  |
|   |                            |                                          | Jika belum diuji, status jaminan: UNVERIFIED.               |
+---+----------------------------+------------------------------------------+-------------------------------------------------------------+
| 6 | Framing Ciphertext         | Format A (L + 28 B) bercampur dengan     | Keluaran Web Crypto: Ciphertext || Tag (L + 16 B).          |
|   | (§3 & §12)                 | keluaran native Web Crypto API           | Format Framing Aplikasi: Nonce || Cipher || Tag (L + 28 B). |
+---+----------------------------+------------------------------------------+-------------------------------------------------------------+
| 7 | Streaming & Metrik Memori  | Mengukur heapUsed saja; UJI-F2 mewajibkan| crypto.subtle bukan API streaming. Mengukur heapUsed,       |
|   | (§1, §7, §8, §10)          | heap > 30 MB; UI freeze seolah terukur   | external, arrayBuffers, rss. UJI-F2 kontrol observasional.  |
|   |                            |                                          | Peak buffer <= 20 MB status: PROPOSED. UI freeze: NOT MEAS. |
+---+----------------------------+------------------------------------------+-------------------------------------------------------------+
```

---

### Isu 1 — Hipotesis dan Batasan Klaim Keamanan (§1)

#### Rumusan Sebelum (B-CHAT-08A §1.2 & §1.1 butir 5):
> *"Hipotesis H1: Web Crypto API native mampu mengeksekusi enkripsi dan dekripsi chunk AES-256-GCM dengan AAD kanonikal 83-byte secara deterministik, menghasilkan plaintext yang identik bit-demi-bit antara proses enkripsi dan dekripsi."*  
> *"Tujuan 5: Menguji ketahanan dekripsi terhadap berbagai skenario serangan manipulasi data (tampering, bit-flip, chunk reordering, truncation, dan session hijacking)."*

#### Rumusan Terkoreksi Sesudah (B-CHAT-08A.1):
1. **Koreksi Deterministik:** Istilah deterministik diluruskan. Operasi enkripsi AES-GCM dengan nonce acak/berbeda menghasilkan ciphertext yang probabilistik (berbeda pada setiap enkripsi). Yang bersifat deterministik adalah **proses dekripsi**: untuk kunci ($K$), nonce ($IV$), ciphertext ($C$), dan AAD yang sama, dekripsi selalu menghasilkan plaintext ($P$) yang identik bit-demi-bit ($100\%$ SHA-256 match).
2. **Batasan Otentikasi H2:** Hipotesis H2 dibatasi secara ketat pada autentikasi integritas data kriptografis yang diproteksi oleh AES-GCM (ciphertext, auth tag, nonce, dan 83-byte AAD). Kegagalan autentikasi kriptografis GCM (`OperationError`) dipisahkan secara tegas dari kegagalan validasi struktur data atau ketidaksesuaian manifest perakitan berkas.
3. **Batas Domain AAD:** Ditegaskan bahwa AAD kanonikal 83-byte **tidak menggantikan** sistem autentikasi identitas pengguna, mekanisme otorisasi hak akses ruang bimbingan (*room authorization*), maupun protokol pertukaran kunci aman (*secure CEK distribution*).
4. **Peniadaan Klaim Anti-Session Hijacking:** Pernyataan bahwa EXP-02 membuktikan pencegahan *session hijacking* secara menyeluruh **dikeluarkan**. EXP-02 hanya menguji pengikatan konteks kriptografis (*cryptographic context binding*) pada field `Upload Session ID` di dalam AAD. Pencegahan pembajakan sesi secara sistemik berada di luar lingkup eksperimen komponen kriptografi chunk ini.

---

### Isu 2 — Strategi Pengelolaan Nonce dan Batasan Keunikan (§5.2)

#### Rumusan Sebelum (B-CHAT-08A §5.2 Opsi 1 & Opsi 2):
> *"Opsi 1 Keunggulan: Jaminan matematis 100% bahwa tidak akan pernah terjadi tabrakan nonce antar-chunk dalam satu sesi unggah berkas yang sama... Opsi 2: Untuk satu berkas lampiran dengan maksimal 10 chunk (q = 10): P_collision = 6,3 x 10^-28."*

#### Rumusan Terkoreksi Sesudah (B-CHAT-08A.1):
1. **Tujuh Ketentuan Wajib Opsi 1 (Deterministik Counter Terstruktur):**  
   Struktur: $\text{Nonce} = \text{UploadSessionSalt}\ (8\text{ B}) \parallel \text{uint32be}(\text{chunk\_index})\ (4\text{ B})$.  
   Keunikan nonce Opsi 1 **bukan jaminan global tanpa syarat**, melainkan hanya berlaku apabila sistem mematuhi **tujuh aturan operasional mutlak**:
   - *Aturan 1:* Satu Kunci Enkripsi Konten (CEK) sintetis baru dibangkitkan khusus untuk setiap sesi unggah berkas lampiran.
   - *Aturan 2:* Satu `UploadSessionSalt` 64-bit dibangkitkan secara acak menggunakan CSPRNG (`crypto.getRandomValues`) pada saat sesi unggah diinisialisasi.
   - *Aturan 3:* Nilai `chunk_index` ($0 \dots N-1$) hanya boleh digunakan tepat satu kali untuk operasi enkripsi di bawah pasangan $(\text{CEK}, \text{UploadSessionSalt})$ tersebut.
   - *Aturan 4:* Mekanisme percobaan ulang pengiriman (*network retry*) **wajib menggunakan kembali ciphertext chunk yang sudah dihasilkan dan disimpan di media lokal**, dilarang melakukan enkripsi ulang (*re-encryption*) dengan nonce yang sama.
   - *Aturan 5:* Apabila sesi unggah gagal total dan harus dimulai ulang dari awal (*restart session* yang memerlukan enkripsi ulang), sistem **wajib membangkitkan CEK baru dan identitas sesi baru**.
   - *Aturan 6:* Dilarang keras menggunakan ulang pasangan $(\text{CEK}, \text{UploadSessionSalt})$ yang sama untuk mengenkripsi berkas lampiran lain atau sesi baru.
   - *Aturan 7:* Klaim keunikan nonce hanya sah di dalam domain operasional satu sesi unggah lampiran yang mematuhi Aturan 1 sampai 6.
2. **Evaluasi Probabilitas Tabrakan Salt 64-Bit:**  
   Penting dicatat bahwa `UploadSessionSalt` berukuran 64-bit ($8\text{ byte}$). Apabila suatu implementasi keliru menggunakan kunci CEK yang sama untuk banyak sesi unggah ($m$ sesi), probabilitas tabrakan salt acak 64-bit adalah $P_{\text{salt\_collision}} \approx \frac{m^2}{2 \times 2^{64}}$. Oleh karena itu, salt 64-bit **tidak memberikan jaminan keunikan absolut lintas sesi**. Jaminan keamanan hanya tercipta karena Aturan 1 mewajibkan **satu CEK unik per sesi unggah berkas**.
3. **Koreksi Parameter $q$ pada Formula Birthday Bound Opsi 2:**  
   Pada Opsi 2 (Random Nonce 96-bit), parameter $q$ pada formula $P_{\text{collision}} \approx \frac{q^2}{2^{97}}$ didefinisikan secara presisi sebagai **jumlah seluruh operasi enkripsi yang dilakukan menggunakan kunci CEK yang sama**, bukan hanya jumlah chunk dalam satu file. Jika CEK digunakan untuk beberapa berkas atau pesan, nilai $q$ meningkat secara akumulatif.

---

### Isu 3 — Evaluasi dan Penanganan Kegagalan CSPRNG (§5.3)

#### Rumusan Sebelum (B-CHAT-08A §5.3):
> *"jika pemanggilan API entropi crypto.getRandomValues gagal (misalnya melempar pengecualian QuotaExceededError atau menghasilkan nilai buffer nol seluruhnya), operasi enkripsi harus segera dibatalkan seketika (fail-closed)..."*

#### Rumusan Terkoreksi Sesudah (B-CHAT-08A.1):
1. **Penghapusan Pemeriksaan Nilai Buffer Nol:**  
   Ketentuan bahwa buffer acak yang bernilai nol seluruhnya (`all-zeros`) otomatis dianggap sebagai kegagalan entropi **resmi dihapus**. Secara matematis dan kriptografis, rangkaian byte nol adalah salah satu keluaran sah dari ruang kemungkinan acak (meskipun probabilitasnya $2^{-96}$). Memeriksa nilai nol adalah asumsi heuristik yang keliru dan tidak mencerminkan standar pengujian CSPRNG.
2. **Kriteria Kegagalan CSPRNG yang Sah:**  
   Operasi enkripsi dibatalkan dengan status *fail-closed* **hanya apabila**:
   - Antarmuka `globalThis.crypto.getRandomValues` tidak tersedia di lingkungan runtime.
   - Pemanggilan `crypto.getRandomValues()` melempar pengecualian runtime (*exception/error*).
3. **Metode Simulasi Pengujian:**  
   Pengujian kegagalan CSPRNG pada EXP-02 (UJI-E2) diimplementasikan melalui teknik *mocking* yang sengaja menginjeksikan kegagalan/pelemparan pengecualian pada fungsi pembangkit entropi, untuk memastikan bahwa sistem bereaksi secara aman: **tidak menghasilkan ciphertext, tidak menyimpan data, dan tidak melakukan transmisi**.
4. **Kepatuhan Kuota Ukuran Web Crypto:**  
   Permintaan nonce $12\text{ byte}$ atau salt $8\text{ byte}$ berada sangat jauh di bawah batas maksimum pemanggilan tunggal `crypto.getRandomValues()` yang ditetapkan oleh spesifikasi W3C ($65.536\text{ byte}$).

---

### Isu 4 — Penanganan Reordering, Integritas Manifest, dan Penambahan Uji Batas (§6)

#### Rumusan Sebelum (B-CHAT-08A §6 UJI-D8):
> *"UJI-D8: Integritas: Chunk Tertukar Urutan (Chunk 1 dan Chunk 2 ditukar posisi) -> REJECT: AssemblyError (OutOfOrderChunkIndex)"*

#### Rumusan Terkoreksi Sesudah (B-CHAT-08A.1):
1. **Pemisahan Tiga Kategori Penanganan Data:**
   - **Kategori 1 — Transport Reordering (Toleransi Jaringan):** Apabila chunk-chunk ciphertext tiba di sisi penerima tidak berurutan karena karakteristik protokol jaringan (misalnya chunk 2 tiba sebelum chunk 1), setiap chunk **tetap dapat didekripsi secara valid** karena masing-masing chunk membawa nonce dan AAD tersendiri dengan `Chunk Index` yang sah. Lapisan aplikasi penerima bertugas menata ulang (*reassemble*) potongan plaintext ke posisi yang tepat berdasarkan indeksnya. **Transport reordering bukan kegagalan autentikasi.**
   - **Kategori 2 — Index Tampering (Serangan Manipulasi):** Apabila penyerang secara sengaja mengubah field `Chunk Index` pada data AAD tanpa mengubah ciphertext, proses dekripsi AES-GCM **wajib gagal seketika melempar `OperationError`** karena integritas tag autentikasi terganggu.
   - **Kategori 3 — Assembly Validation (Validasi Perakitan):** Validasi integritas tingkat file yang memastikan seluruh chunk $0 \dots N-1$ hadir lengkap, tidak ada chunk duplikat, tidak ada indeks di luar rentang, dan jumlah chunk sesuai manifest berkas.
2. **Revisi Skenario UJI-D8:**  
   UJI-D8 disempurnakan: kedatangan chunk secara acak/tidak berurutan harus berhasil disusun kembali menjadi berkas plaintext yang utuh dan valid (SHA-256 cocok).
3. **Penambahan 9 Skenario Pengujian Baru pada Matriks Pengujian:**
   - `UJI-C5 (Batas Presisi Kuota Pesan)`: Total ukuran gabungan tepat $25.000.000\text{ byte}$ $\rightarrow$ **Wajib DITERIMA (ACCEPT)**.
   - `UJI-C6 (Pelanggaran Presisi Kuota Pesan)`: Total ukuran gabungan $25.000.001\text{ byte}$ $\rightarrow$ **Wajib DITOLAK (REJECT: TotalQuotaExceededError)**.
   - `UJI-C7 (Batas Maksimum Berkas)`: Pengiriman tepat 5 berkas valid ($\le 25\text{ MB}$) $\rightarrow$ **Wajib DITERIMA (ACCEPT)**.
   - `UJI-D10 (Indeks di Luar Rentang)`: Chunk membawa indeks $\ge N$ atau nilai negatif pada AAD $\rightarrow$ **Wajib DITOLAK saat perakitan (REJECT: IndexOutOfRangeError)**.
   - `UJI-D11 (Chunk Terakhir Hilang)`: Chunk $0 \dots N-2$ lengkap namun chunk $N-1$ hilang $\rightarrow$ **Wajib DITOLAK saat validasi manifest (REJECT: TruncatedFileError)**.
   - `UJI-D12 (Ketahanan Transport Reordering Penuh)`: Seluruh $N$ chunk diterima dalam urutan acak sempurna $\rightarrow$ **Wajib BERHASIL direkonstruksi (PASS: SHA-256 match)**.
   - `UJI-E3 (Verifikasi Skenario Retry Aman)`: Pengiriman ulang chunk menggunakan ciphertext yang tersimpan di media lokal $\rightarrow$ **Wajib BERHASIL tanpa enkripsi ulang (PASS: Nonce reuse avoided)**.
   - `UJI-E4 (Verifikasi Skenario Restart Sesi)`: Pembuatan sesi baru setelah kegagalan fatal $\rightarrow$ **Wajib menggunakan CEK baru dan SessionID baru (PASS: Fresh key state)**.
   - `UJI-D13 (Isolasi Kegagalan Chunk Akhir)`: Chunk $N-1$ gagal diautentikasi $\rightarrow$ **Wajib GAGAL total dan staging buffer dibersihkan tanpa publikasi berkas final (PASS: No partial file leakage)**.

---

### Isu 5 — Protokol Staging Buffer dan Pencegahan Kebocoran Plaintext Parsial (§3 & §7)

#### Rumusan Terkoreksi Resmi (B-CHAT-08A.1):
1. **Prinsip Validasi Menyeluruh Sebelum Publikasi (*All-or-Nothing Assembly*):**  
   Keberhasilan autentikasi dan dekripsi satu chunk individual **sama sekali tidak menyatakan bahwa seluruh lampiran telah aman dan sah**. Penyerang dapat mengirimkan chunk awal yang sah namun memotong chunk akhir atau merusak metadata file.
2. **Kewajiban Penggunaan Staging Buffer (Penyimpanan Sementara):**  
   Selama proses dekripsi berlangsung, potongan-potongan plaintext chunk yang berhasil didekripsi **wajib ditahan di dalam area penyimpanan sementara (*staging buffer / temporary storage*)** dan dilarang diserahkan ke antarmuka pengguna (UI chat), dilarang disimpan ke direktori unduhan permanen, dan dilarang diserahkan ke komponen pembaca dokumen (PDF viewer/image renderer).
3. **Syarat Rilis Berkas Final:** Berkas lampiran final hanya boleh dipublikasikan (*committed/released*) setelah seluruh kondisi berikut terpenuhi secara kumulatif:
   - Setiap chunk dari $0$ hingga $N-1$ lolos verifikasi autentikasi AES-GCM tanpa galat.
   - Seluruh chunk lengkap tanpa ada indeks yang hilang atau duplikat.
   - Ukuran plaintext dari masing-masing chunk cocok secara presisi dengan field `Plaintext Length` pada AAD dan formula piecewise modulo.
   - Hash plaintext gabungan seluruh berkas cocok dengan manifest lampiran.
4. **Penanganan Kegagalan Dekripsi:** Jika salah satu chunk gagal diautentikasi atau validasi perakitan gagal, seluruh data sementara di staging buffer **wajib segera dihapus dan dibersihkan dari memori (*purged/wiped*)**.
5. **Klasifikasi Bukti:** Jaminan pencegahan kebocoran plaintext parsial pada tingkat integrasi aplikasi peramban diberi status **`UNVERIFIED`** sampai mekanisme staging buffer diuji secara fisik di lingkungan runtime.

---

### Isu 6 — Penegasan Format Framing Ciphertext Web Crypto vs Format Aplikasi (§3 & §12)

#### Rumusan Terkoreksi Resmi (B-CHAT-08A.1):
Untuk mencegah kerancuan antara spesifikasi API W3C Web Crypto dan protokol framing aplikasi:
1. **Keluaran Native Web Crypto API (`crypto.subtle.encrypt`):**  
   Fungsi standar `crypto.subtle.encrypt({ name: 'AES-GCM', ... })` menghasilkan *ArrayBuffer* yang strukturnya menggabungkan ciphertext dan authentication tag:
   $$\text{Keluaran Web Crypto} = \text{Ciphertext}\ (L_i\text{ byte}) \parallel \text{AuthTag}\ (16\text{ byte})$$
   Panjang biner keluaran API adalah tepat **$L_i + 16\text{ byte}$**. API Web Crypto **tidak menyertakan Nonce (IV) ke dalam buffer keluarannya**.
2. **Format Pembungkusan Aplikasi (Format A Framing):**  
   Aplikasi klien E2EE UNUHA bertugas membungkus keluaran API tersebut bersama nonce ke dalam satu paket biner chunk terenkripsi untuk disimpan atau ditransmisikan:
   $$\text{Format A Chunk} = \text{Nonce}\ (12\text{ B}) \parallel \text{Ciphertext}\ (L_i\text{ B}) \parallel \text{AuthTag}\ (16\text{ B})$$
   Panjang total serialisasi chunk Format A adalah tepat **$L_i + 28\text{ byte}$**.
3. **Proses Unframing pada Dekripsi:**  
   Saat menerima chunk Format A, lapisan aplikasi mengekstrak $12\text{ byte}$ pertama sebagai parameter `iv` untuk diumpankan ke `crypto.subtle.decrypt()`, sedangkan sisa $(L_i + 16)\text{ byte}$ diumpankan sebagai parameter data ciphertext.

---

### Isu 7 — Hakikat Streaming Aplikasi dan Metrik Pengukuran Memori Multi-Dimensi (§1, §7, §8, §10)

#### Rumusan Terkoreksi Resmi (B-CHAT-08A.1):
1. **Karakteristik Non-Streaming dari Web Crypto API:**  
   Ditegaskan bahwa `crypto.subtle.encrypt()` dan `crypto.subtle.decrypt()` **bukanlah antarmuka enkripsi streaming**. Web Crypto API tidak menyediakan fungsi `update()` atau pipa aliran bertahap untuk satu sesi cipher tunggal.
2. **Streaming pada Lapisan Aplikasi:**  
   Konsep *streaming* pada sistem lampiran UNUHA dicapai melalui **arsitektur aplikasi chunk-by-chunk**:
   - Membaca satu potongan plaintext $1\text{ MiB}$ dari sumber berkas (`File.slice()` atau `ReadableStream`).
   - Melakukan enkripsi chunk tersebut secara independen menggunakan Web Crypto API.
   - Menulis/menyimpan paket terenkripsi $L_i + 28\text{ byte}$ ke buffer tujuan atau transmisi.
   - Melepaskan referensi buffer siklus sebelumnya agar dapat dibersihkan oleh Garbage Collector.
   - Membatasi konkurensi: pemrosesan dilakukan secara sekuensial (maksimal 1 chunk aktif dalam pipeline) untuk membatasi konsumsi RAM.
   - **Penegasan:** Membaca seluruh file $10\text{ MB}$ ke dalam satu buffer besar di memori lalu memotongnya menggunakan array slice **bukanlah arsitektur streaming yang membatasi memori**.
3. **Perluasan Metrik Pengukuran Memori Node.js (4 Dimensi):**  
   Pengukuran konsumsi memori pada runtime Node.js tidak boleh hanya mengandalkan `heapUsed`, karena buffer biner Web Crypto (`ArrayBuffer` dan `Uint8Array`) dialokasikan di luar V8 managed heap. Pengujian wajib mencatat empat metrik `process.memoryUsage()`:
   - `heapUsed`: Memori objek JavaScript pada V8 heap engine.
   - `external`: Memori objek C++ yang terikat pada objek JavaScript (termasuk buffer native).
   - `arrayBuffers`: Alokasi memori khusus untuk `ArrayBuffer` dan `SharedArrayBuffer`.
   - `rss` (*Resident Set Size*): Total alokasi memori fisik yang dialokasikan sistem operasi untuk proses pengujian.
4. **Koreksi Skenario UJI-F2 (Full-Buffer Control):**  
   UJI-F2 ditetapkan sebagai **kontrol pembanding observasional**, bukan pengujian dengan syarat mutlak gagal jika heap tidak melebihi $30\text{ MB}$. Nilai heap riil sangat dipengaruhi oleh jadwal *garbage collection* dan optimasi alokasi internal runtime V8.
5. **Status Target Memori Puncak:**  
   Target konsumsi buffer memori $\le 20\text{ MB}$ dipertahankan sebagai **`PROPOSED`** (target usulan desain), bukan jaminan universal mutlak sebelum terbukti di berbagai konfigurasi perangkat keras.
6. **Ketiadaan Klaim UI Freeze Tanpa Browser Nyata:**  
   Metrik UI freeze peramban diberi status **`NOT MEASURED`** pada pengujian Node.js CLI. Tidak ada klaim ketiadaan UI freeze yang diakui sebelum diuji pada *browser main thread* menggunakan `PerformanceObserver` API.

---

## 3. MATRIKS RANCANGAN PENGUJIAN EXP-02 TERREKONSILIASI (32 SKENARIO)

Dengan penambahan 9 skenario baru dan koreksi UJI-D8, matriks pengujian EXP-02 kini mencakup **32 skenario pengujian komprehensif**:

```
+-----------------------------------------------------------------------------------------------------------------------------------------+
|                                        MATRIKS PENGUJIAN TERKOREKSI RESMI EXP-02 (32 SKENARIO)                                          |
+----+--------+---------------------------------------+------------------------------------+----------------------------------------------+
| No | Kode   | Nama Skenario Pengujian               | Parameter Data Uji / Kondisi       | Target Hasil & Klasifikasi Bukti             |
+----+--------+---------------------------------------+------------------------------------+----------------------------------------------+
| 1  | UJI-A  | Enkripsi & Dekripsi Siklus Normal     | File acak 1 MiB (1.048.576 byte)   | PASS: Plaintext 100% SHA-256 match           |
+----+--------+---------------------------------------+------------------------------------+----------------------------------------------+
| 2  | UJI-B1 | Berkas Minimal (1 Byte)               | File 1 byte (N = 1, L_last = 1 B)  | PASS: N=1, L=1 B, Plaintext cocok            |
| 3  | UJI-B2 | Berkas Tepat 1 Chunk (1 MiB)          | File 1.048.576 B (N = 1, L = 1 MiB)| PASS: N=1, L=1.048.576 B, Plaintext cocok    |
| 4  | UJI-B3 | Berkas Sedikit > 1 Chunk              | File 1.048.577 B (N = 2, L2 = 1 B) | PASS: N=2, L1=1 MiB, L2=1 B, cocok           |
| 5  | UJI-B4 | Berkas Multi-Chunk Menengah           | File 5.000.000 B (N = 5 chunk)     | PASS: N=5, 4x1 MiB + 805.696 B, cocok        |
| 6  | UJI-B5 | Berkas Tepat Batas Maksimum File      | File 10.000.000 B (N = 10 chunk)   | PASS: N=10, 9x1 MiB + 562.816 B, cocok       |
+----+--------+---------------------------------------+------------------------------------+----------------------------------------------+
| 7  | UJI-C1 | Validasi File Kosong (0 Byte)         | File 0 byte (S = 0)                | REJECT: Galat 'EmptyFileError'               |
| 8  | UJI-C2 | Validasi File Melebihi Kuota Tunggal  | File 10.000.001 byte               | REJECT: Galat 'FileQuotaExceededError'       |
| 9  | UJI-C3 | Validasi Gabungan Melebihi Kuota Pesan| 3 file x 9.000.000 B = 27.000.000 B| REJECT: Galat 'TotalQuotaExceededError'      |
| 10 | UJI-C4 | Validasi Jumlah File Melebihi Batas   | 6 file valid (@ 100 KB)            | REJECT: Galat 'MaxAttachmentCountExceeded'   |
| 11 | UJI-C5 | Batas Presisi Kuota Pesan Tepat 25 MB | 2x10 MB + 1x5 MB = 25.000.000 B    | ACCEPT / PASS: Kuota tepat dipenuhi          |
| 12 | UJI-C6 | Pelanggaran Presisi Kuota Pesan +1 B  | Total gabungan 25.000.001 byte     | REJECT: Galat 'TotalQuotaExceededError'      |
| 13 | UJI-C7 | Batas Maksimum Berkas Tepat 5 File    | Tepat 5 file valid total <= 25 MB  | ACCEPT / PASS: Diproses tanpa galat          |
+----+--------+---------------------------------------+------------------------------------+----------------------------------------------+
| 14 | UJI-D1 | Integritas: Modifikasi Ciphertext     | 1 bit dibalik pada ciphertext ch-0 | REJECT: OperationError (GCM Tag Mismatch)    |
| 15 | UJI-D2 | Integritas: Modifikasi AAD RoomUUID   | Mengubah 1 byte pada RoomUUID AAD  | REJECT: OperationError (AAD Auth Failed)     |
| 16 | UJI-D3 | Integritas: Modifikasi AAD SessionID  | Mengubah SessionID pada AAD ch-1   | REJECT: OperationError (AAD Auth Failed)     |
| 17 | UJI-D4 | Integritas: Nonce Mismatch            | Menggunakan nonce ch-0 untuk ch-1  | REJECT: OperationError (Auth Failed)         |
| 18 | UJI-D5 | Integritas: Tag Autentikasi Rusak     | Mengubah 1 byte pada auth tag 16 B | REJECT: OperationError (Tag Corrupted)       |
| 19 | UJI-D6 | Integritas: Chunk Hilang              | Chunk 2 dari 5 dihapus             | REJECT: AssemblyError (MissingChunkDetected) |
| 20 | UJI-D7 | Integritas: Chunk Duplikat            | Chunk 0 disisipkan ulang 2 kali    | REJECT: AssemblyError (DuplicateChunkIndex)  |
| 21 | UJI-D8 | Transport: Chunk Tiba Acak / Terbalik | Chunk tiba dalam urutan terbalik   | PASS: Reassembled by Index (SHA-256 match)   |
| 22 | UJI-D9 | Integritas: Pemalsuan PlaintextLength | Field PlaintextLength AAD diubah   | REJECT: OperationError (AAD Tampered)        |
| 23 | UJI-D10| Integritas: Indeks di Luar Rentang    | Chunk membawa ChunkIndex >= N      | REJECT: AssemblyError (IndexOutOfRange)      |
| 24 | UJI-D11| Integritas: Chunk Terakhir Hilang     | Chunk N-1 tidak ada di transmisi   | REJECT: AssemblyError (TruncatedFileError)   |
| 25 | UJI-D12| Transport: Seluruh Chunk Acak Penuh   | Permutasi acak seluruh N chunk     | PASS: Reassembled by Index (SHA-256 match)   |
| 26 | UJI-D13| Staging: Isolasi Gagal Chunk Terakhir | Chunk N-1 gagal tag auth GCM       | REJECT: Staging Purged (No partial file leak)|
+----+--------+---------------------------------------+------------------------------------+----------------------------------------------+
| 27 | UJI-E1 | Deteksi Penggunaan Ulang Nonce        | Simulasi duplikasi nonce pada CEK  | DETECTED / REJECT: NonceReuseViolation       |
| 28 | UJI-E2 | Kegagalan Entropi CSPRNG (Mock Error) | Mock exception pada getRandomValues| FAIL-CLOSED: No ciphertext produced/stored   |
| 29 | UJI-E3 | Verifikasi Skenario Retry Aman        | Retry transmisi pakai cipher lama  | PASS: Tidak memicu enkripsi ulang            |
| 30 | UJI-E4 | Verifikasi Skenario Restart Sesi Baru | Sesi baru setelah gagal total      | PASS: Wajib CEK baru & SessionID baru        |
+----+--------+---------------------------------------+------------------------------------+----------------------------------------------+
| 31 | UJI-F1 | Evaluasi Streaming Buffer Memori      | Pipeline per-chunk 1 MiB sekuensial| PROPOSED: Peak buffer <= 20 MB (heap/ext)    |
| 32 | UJI-F2 | Evaluasi Full-Buffer In-Memory        | Memuat seluruh file 10 MB sekaligus| OBSERVATIONAL: Kontrol komparasi alokasi RAM |
+----+--------+---------------------------------------+------------------------------------+----------------------------------------------+
```

---

## 4. KRITERIA KEBERHASILAN TERREKONSILIASI (PASS / FAIL / UNVERIFIED / PROPOSED)

```
+-----------------------------------------------------------------------------------------------------------------------------------------+
|                                           KRITERIA KEBERHASILAN RESMI TERREKONSILIASI EXP-02                                            |
+----+----------------------------+------------------------------------+------------------------------------+-----------------------------+
| No | Parameter Kriteria         | Kriteria LULUS (PASS)              | Kriteria GAGAL (FAIL)              | Status Klasifikasi Bukti    |
+----+----------------------------+------------------------------------+------------------------------------+-----------------------------+
| 1  | Keutuhan Plaintext         | Plaintext hasil dekripsi 100%      | Hash SHA-256 berbeda, ada bit rusak| UNVERIFIED (Akan diuji)     |
|    | Rekonstruksi (SHA-256)     | identik pada seluruh skenario valid| atau dekripsi melempar galat       |                             |
+----+----------------------------+------------------------------------+------------------------------------+-----------------------------+
| 2  | Ketahanan Autentikasi      | Seluruh manipulasi cipher, tag, AAD| Manipulasi lolos dekripsi, atau    | UNVERIFIED (Akan diuji)     |
|    | Kriptografis AES-GCM       | melempar OperationError seketika   | plaintext parsial bocor            |                             |
+----+----------------------------+------------------------------------+------------------------------------+-----------------------------+
| 3  | Penanganan Transport       | Chunk tiba acak berhasil ditata    | Gagal menyusun urutan chunk yang   | UNVERIFIED (Akan diuji)     |
|    | Reordering Tingkat Jaringan| ulang berdasarkan indeksnya        | tiba tidak berurutan               |                             |
+----+----------------------------+------------------------------------+------------------------------------+-----------------------------+
| 4  | Integritas Assembly        | Chunk hilang, duplikat, indeks     | File berhasil dirakit meskipun     | UNVERIFIED (Akan diuji)     |
|    | & Validasi Manifest        | out-of-range ditolak tegas         | manifest tidak konsisten           |                             |
+----+----------------------------+------------------------------------+------------------------------------+-----------------------------+
| 5  | Penegakan Batas Kuota      | File 0 B ditolak; File > 10 MB     | Kuota dilanggar namun sistem tetap | UNVERIFIED (Akan diuji)     |
|    | Resmi OD-02                | ditolak; Total > 25 MB ditolak;    | memproses enkripsi                 |                             |
|    |                            | Total = 25 MB diterima; > 5 ditolak|                                    |                             |
+----+----------------------------+------------------------------------+------------------------------------+-----------------------------+
| 6  | Pencegahan Nonce Reuse     | Nonce unik untuk setiap operasi di | Terjadi duplikasi pasangan         | UNVERIFIED (Akan diuji)     |
|    | di Bawah CEK yang Sama     | bawah CEK yang sama                | (CEK, Nonce) pada enkripsi baru    |                             |
+----+----------------------------+------------------------------------+------------------------------------+-----------------------------+
| 7  | Konsumsi Memori Buffer     | Alokasi memori buffer streaming    | Buffer memori tidak terkontrol     | PROPOSED (Target Usulan:    |
|    | Streaming (Multi-Metrik)   | terkontrol <= 20 MB (PROPOSED)     | melebihi batas yang wajar          | Peak Buffer <= 20 MB)       |
+----+----------------------------+------------------------------------+------------------------------------+-----------------------------+
| 8  | Ketiadaan Kebocoran        | Staging buffer dibersihkan jika    | File final parsial dipublikasikan  | UNVERIFIED (Tingkat File    |
|    | Plaintext Parsial          | salah satu chunk gagal autentikasi | saat ada chunk yang gagal          | Integrasi Browser)          |
+----+----------------------------+------------------------------------+------------------------------------+-----------------------------+
| 9  | Pemisahan Validasi         | Hasil Node.js dan browser nyata    | Hasil Node.js diklaim otomatis     | MANDATORI (Wajib dipatuhi)  |
|    | Lingkungan Eksekusi        | dicatat secara terpisah            | berlaku untuk browser              |                             |
+----+----------------------------+------------------------------------+------------------------------------+-----------------------------+
```

---

## 5. REKOMENDASI KEPUTUSAN UNTUK PERSETUJUAN PEMILIK PROYEK

Bagian 12 pada dokumen B-CHAT-08A diperbarui secara komprehensif menjadi daftar 6 rekomendasi teknis yang diajukan untuk mendapatkan persetujuan tertulis Pemilik Proyek:

```
+-----------------------------------------------------------------------------------------------------------------------------------------+
|                                    DAFTAR KEPUTUSAN TEKNIS YANG DIAJUKAN KEPADA PEMILIK PROYEK                                          |
+----+----------------------------+---------------------------------------------+---------------------------------------------------------+
| No | Butir Keputusan Teknis     | Rekomendasi Teknis yang Diajukan            | Dasar Pertimbangan & Batasan Teknis                     |
+----+----------------------------+---------------------------------------------+---------------------------------------------------------+
| 1  | Strategi Nonce Utama       | OPSI 1: Deterministik Counter Terstruktur   | 1 CEK baru per sesi; 1 UploadSessionSalt CSPRNG per sesi|
|    | untuk Chunk Enkripsi       | (UploadSessionSalt 8 B || ChunkIndex 4 B)   | chunk_index unik per sesi; retry pakai ciphertext lama. |
|    |                            |                                             | Menghilangkan risiko tabrakan birthday bound.           |
+----+----------------------------+---------------------------------------------+---------------------------------------------------------+
| 2  | Format Pembungkusan        | FORMAT A: Nonce (12 B) diprefiks pada       | Mempermudah dekripsi independen chunk (L_i + 28 B) tanpa|
|    | (Framing) Ciphertext Chunk | setiap ciphertext chunk                     | ketergantungan urutan transmisi jaringan.               |
+----+----------------------------+---------------------------------------------+---------------------------------------------------------+
| 3  | Ambang Memori Puncak       | AMBANG USULAN: Peak buffer memori <= 20 MB  | Status tetap PROPOSED; dicatat melalui 4 metrik Node.js |
|    | Streaming Buffer           | pada pengujian berkas 10.000.000 byte       | (heapUsed, external, arrayBuffers, rss).                |
+----+----------------------------+---------------------------------------------+---------------------------------------------------------+
| 4  | Pengujian Peramban Nyata   | Pengujian Browser Web Crypto API WAJIB      | Hasil Node.js TIDAK BOLEH diklaim membuktikan browser.  |
|    | vs Node.js Environment     | dieksekusi secara terpisah dari Node.js     | UI freeze di peramban berstatus NOT MEASURED di Node.js.|
+----+----------------------------+---------------------------------------------+---------------------------------------------------------+
| 5  | Batasan Bukti Keamanan     | Tidak mengklaim pencegahan session hijacking| EXP-02 murni menguji pengikatan kriptografis konteks    |
|    | Konteks Sesi Kriptografi   | secara menyeluruh                           | AAD (UploadSessionID); bukan keamanan sesi jaringan.    |
+----+----------------------------+---------------------------------------------+---------------------------------------------------------+
| 6  | Otorisasi Pelaksanaan      | Status otorisasi eksekusi EXP-02:           | Sandbox scratch/bchat_prototype/exp02_chunking_aad/     |
|    | Eksperimen EXP-02 Fisik    | BELUM DIBERIKAN (Menunggu persetujuan final)| BELUM DIBUAT dan kode prototipe BELUM DITULIS.          |
+----+----------------------------+---------------------------------------------+---------------------------------------------------------+
```

---

## 6. INVENTARISASI ISU YANG MASIH BERSTATUS `UNVERIFIED` ATAU `PROPOSED`

Secara transparan dan bertanggung jawab, daftar isu berikut dicatat sebagai aspek yang **belum terverifikasi secara empiris** atau masih berstatus **usulan desain**:

```
+-----------------------------------------------------------------------------------------------------------------------------------------+
|                                   DAFTAR ISU BERSTATUS UNVERIFIED / PROPOSED PADA EXP-02                                                |
+----+-----------------------------------------------------+------------------+-------------------------------------------------------+
| No | Isu / Aspek Teknis                                  | Status           | Keterangan dan Alasan Status                          |
+----+-----------------------------------------------------+------------------+-------------------------------------------------------+
| 1  | Keutuhan Plaintext & Dekripsi Bitwise SHA-256       | UNVERIFIED       | Menunggu pelaksanaan fisik eksekusi EXP-02            |
| 2  | Ketahanan Penolakan GCM terhadap 9 Tampering        | UNVERIFIED       | Menunggu pelaksanaan fisik eksekusi EXP-02            |
| 3  | Keberhasilan Rekonstruksi Transport Reordering      | UNVERIFIED       | Menunggu pelaksanaan fisik eksekusi EXP-02            |
| 4  | Penegakan Batas Kuota 10 MB / 25 MB / 5 Berkas      | UNVERIFIED       | Menunggu pelaksanaan fisik eksekusi EXP-02            |
| 5  | Ketiadaan Kebocoran Plaintext Parsial Tingkat File  | UNVERIFIED       | Mekanisme staging buffer belum diuji di aplikasi nyata|
| 6  | Kinerja Browser Web Crypto API pada Peramban Nyata  | UNVERIFIED       | Belum pernah diuji pada Chromium / Gecko browser      |
| 7  | Kompatibilitas Antarmuka File.stream() Peramban     | UNVERIFIED       | Belum pernah diuji pada pipeline browser nyata        |
| 8  | Ambang Batas Konsumsi Memori Puncak <= 20 MB        | PROPOSED         | Merupakan target desain usulan, bukan standar wajib   |
| 9  | Ketiadaan Long Task / UI Freeze di Peramban Web     | NOT MEASURED     | Headless Node.js CLI tidak mengukur event loop DOM    |
+----+-----------------------------------------------------+------------------+-------------------------------------------------------+
```

---

## 7. HASIL PEMERIKSAAN KONSISTENSI STATIS

Pemeriksaan konsistensi statis dokumen **B-CHAT-08A.1** terhadap mandat induk [B-CHAT-06D.2](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-06D_Mandat_Eksekusi_Terbatas_Gerbang_2.md) dan dokumen historis EXP-01 ([B-CHAT-07A.1](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A_Laporan_Eksekusi_EXP-01.md) s.d. [B-CHAT-07A.7](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.7_Koreksi_Editorial_dan_Klasifikasi_Bukti_Final_EXP-01.md)) menunjukkan keselarasan sempurna:
- **Kesesuaian Kuota Byte:** Seluruh batas ukuran ($10.000.000\text{ byte}$ per berkas, $25.000.000\text{ byte}$ per pesan, 5 berkas, dan chunk $1.048.576\text{ byte}$) selaras $100\%$ tanpa ada percampuran antara satuan desimal MB dan biner MiB.
- **Kesesuaian Layout AAD 83-Byte:** Layout AAD 10 komponen kanonikal (`UNH1`, Version, Domain, 4x UUID 16-byte biner, Index, Total, Length uint32 BE) konsisten penuh dengan penetapan final [B-CHAT-07A.6](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.6_Koreksi_Final_AAD_dan_Iterasi_EXP-01.md) dan [B-CHAT-07A.7](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.7_Koreksi_Editorial_dan_Klasifikasi_Bukti_Final_EXP-01.md).
- **Pemisahan Konteks Exporter:** Konteks Exporter $48\text{ byte}$ terbukti tetap terpisah dan tidak tercampur dengan AAD $83\text{ byte}$.
- **Disiplin Istilah UUID:** Menggunakan istilah baku *raw 16-byte UUID binary representation*.
- **Peniadaan Konflik:** Tidak ditemukan konflik teknis antara rekomendasi B-CHAT-08A.1 dengan keputusan-keputusan Gerbang 1 terdahulu.

---

## 8. PERNYATAAN KEPATUHAN BATAS OPERASIONAL MUTLAK

Penyusunan dokumen koreksi **B-CHAT-08A.1** dilaksanakan dengan kepatuhan mutlak pada prinsip kehati-hatian sistem:

1. **Zero Prototype Creation:** Direktori sandbox `scratch/bchat_prototype/exp02_chunking_aad/` **belum dibuat**, dan tidak ada satu pun baris kode program prototipe EXP-02 yang ditulis.
2. **Zero Execution, Benchmark, & Testing:** Tidak ada uji coba eksekusi, simulasi, benchmark kinerja, maupun unit test yang dijalankan.
3. **Zero Toolchain & Dependency Installation:** Tidak ada dependensi npm eksternal, compiler, atau paket sistem yang dipasang.
4. **Zero Main Codebase Changes:** Direktori backend Laravel ([`manajemen_skripsi/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/manajemen_skripsi/)) dan frontend Next.js ([`frontend_manajemen_skripsi/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/frontend_manajemen_skripsi/)) tidak disentuh sama sekali.
5. **Zero Database Changes:** Tidak ada migrasi basis data, modifikasi skema tabel, atau query database yang dijalankan.
6. **Zero Git Operations:** Tidak ada perintah Git (`git status`, `git diff`, `git commit`, `git checkout`, `git push`, dsb.) yang dijalankan selama proses ini.
7. **Pelestarian Arsip Historis:** Dokumen [B-CHAT-08A](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A_Rencana_dan_Mandat_EXP-02.md) dan seluruh dokumen EXP-01 ([B-CHAT-07A.1](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A_Laporan_Eksekusi_EXP-01.md) s.d. [B-CHAT-07A.7](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.7_Koreksi_Editorial_dan_Klasifikasi_Bukti_Final_EXP-01.md)) tetap tersimpan utuh di repositori tanpa diubah atau ditimpa (*zero overwrite*).

---

## 9. PERNYATAAN EKSPLISIT STATUS OTORISASI EXP-02

$$\mathbf{STATUS\ OTORISASI\ EKSEKUSI\ EXP\text{-}02:\ BELUM\ DIBERIKAN}$$

> [!CAUTION]
> **PERINGATAN RESMI:**  
> Dokumen **B-CHAT-08A.1** adalah dokumen adendum koreksi teknis atas mandat perencanaan EXP-02. Penerbitan dokumen ini **TIDAK MEMBERIKAN KEWENANGAN** kepada sistem atau agen untuk mengeksekusi prototipe EXP-02 di sandbox. Pelaksanaan eksperimen EXP-02 secara fisik hanya dapat dimulai setelah Pemilik Proyek memeriksa dokumen koreksi ini dan memberikan **persetujuan tertulis eksplisit** terpisah.

---
*(Dokumen B-CHAT-08A.1 selesai disusun secara paripurna sebagai koreksi teknis terbatas mandat EXP-02, siap ditinjau oleh Pemilik Proyek).*

