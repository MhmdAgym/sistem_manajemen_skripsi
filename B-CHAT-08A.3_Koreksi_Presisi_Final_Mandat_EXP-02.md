# B-CHAT-08A.3 — KOREKSI PRESISI FINAL MANDAT EXP-02
## CEK COLLISION, METRIK MEMORI, VALIDASI INDEKS, DAN REKONSILIASI SKENARIO

**Status Dokumen:** DOKUMEN KOREKSI PRESISI RESMI (*FINAL PRECISION SPECIFICATION AMENDMENT*)  
**Mode Operasi:** STRICTLY READ-ONLY TERHADAP IMPLEMENTASI — DOCUMENTATION ONLY  
**Status Otorisasi EXP-02:** **EXP-02 NOT AUTHORIZED** *(Eksperimen EXP-02 belum diotorisasi)*  
**Dokumen Sumber yang Dikoreksi:** [B-CHAT-08A.2_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.2_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md)  
**Dokumen Acuan Historis:**
- [B-CHAT-08A.1_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.1_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md)
- [B-CHAT-08A_Rencana_dan_Mandat_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A_Rencana_dan_Mandat_EXP-02.md)
- [B-CHAT-06D_Mandat_Eksekusi_Terbatas_Gerbang_2.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-06D_Mandat_Eksekusi_Terbatas_Gerbang_2.md) (Revisi B-CHAT-06D.2 §3.2)
- [B-CHAT-05_Spesifikasi_Final_E2EE.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-05_Spesifikasi_Final_E2EE.md) (Keputusan Desain OD-02 & OD-17)
- Rangkaian Dokumen Forensik EXP-01: [B-CHAT-07A.1](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A_Laporan_Eksekusi_EXP-01.md) s.d. [B-CHAT-07A.7](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.7_Koreksi_Editorial_dan_Klasifikasi_Bukti_Final_EXP-01.md)  
**Proyek:** Sistem Informasi Manajemen Skripsi UNUHA  
**Fitur:** Bimbingan Online Chat Real-Time E2EE  
**Tahap:** Peninjauan Mandat Gerbang 2 (Finalisasi Perencanaan EXP-02)  
**Tanggal:** 5 November 2026  

---

> [!CAUTION]
> **PENEGASAN STATUS OPERASIONAL MUTLAK:**  
> Dokumen **B-CHAT-08A.3** ini berstatus **STRICTLY DOCUMENTATION ONLY — EXP-02 NOT AUTHORIZED**. Penerbitan dokumen ini **BUKAN** merupakan izin atau otorisasi untuk memulai eksekusi fisik prototipe EXP-02 di sandbox. Persetujuan Gerbang 2 sebelumnya tidak memberikan kewenangan otomatis untuk menjalankan EXP-02. Pelaksanaan fisik di sandbox hanya dapat dimulai setelah Pemilik Proyek memberikan persetujuan tertulis eksplisit terpisah atas dokumen mandat final ini. Selama penyusunan dokumen ini, tidak ada kode sumber yang dibuat, tidak ada direktori sandbox yang dibuat, tidak ada dependensi atau toolchain yang dipasang, tidak ada benchmark/pengujian yang dijalankan, dan tidak ada operasi Git yang dilakukan. Dokumen sumber [B-CHAT-08A](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A_Rencana_dan_Mandat_EXP-02.md), [B-CHAT-08A.1](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.1_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md), dan [B-CHAT-08A.2](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.2_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md) tetap dipertahankan utuh sebagai arsip historis (*zero overwrite*).

---

## 1. IDENTITAS DAN TUJUAN KOREKSI PRESISI FINAL

Dokumen **B-CHAT-08A.3** diterbitkan sebagai amendemen presisi final (*final precision amendment*) terhadap dokumen [B-CHAT-08A.2](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.2_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md) berdasarkan hasil peninjauan kritis lanjutan Pemilik Proyek. Tujuan utama koreksi ini adalah menyempurnakan aspek-aspek teoretis, kriptografis, arsitektural, dan terminologis yang masih memerlukan presisi tinggi, tanpa mengubah fondasi keputusan desain yang telah benar, tanpa memperluas cakupan mandat ke arah eksekusi prototipe, dan tanpa melanggar status **EXP-02 NOT AUTHORIZED**.

Fokus koreksi presisi B-CHAT-08A.3 meliputi:
1. **Presisi Teoretis Independensi CEK dan Probabilitas Tabrakan Kunci (§3.B):** Menghapus pernyataan mutlak bahwa kunci pasti berbeda ($K_1 \ne K_2$ mutlak); mengakui secara matematis bahwa probabilitas tabrakan kunci acak 256-bit adalah $2^{-256}$ (dapat diabaikan dalam praktik, namun bukan nol); mempertahankan formula *birthday bound* salt 64-bit; serta menegaskan bahwa keamanan AES-GCM bertumpu pada CEK independen per sesi, non-reuse nonce dalam sesi, dan aturan *session abandonment*.
2. **Penetapan Metrik Memori Node.js Primer dan Pendukung (§3.H):** Menetapkan $\Delta\text{arrayBuffers}$ sebagai metrik primer konsumsi buffer native per-chunk, $\Delta\text{external}$ sebagai metrik pendukung, $\text{rss}$ sebagai metrik konteks proses, dan $\text{heapUsed}$ sebagai informasi heap JavaScript; melarang tegas penjumlahan redundan $\text{external} + \text{arrayBuffers}$; serta mempertahankan status ambang $20\text{ MB}$ sebagai `PROPOSED`.
3. **Pemisahan Tiga Lapisan Validasi Indeks (§3.C):** Memisahkan secara tegas pengujian indeks negatif (UJI-D10A: validasi boundary input aplikasi pra-serialisasi), indeks melebihi rentang valid (UJI-D10B: validasi rentang perakitan pra-serialisasi), dan manipulasi byte indeks AAD biner (UJI-D10C: kegagalan autentikasi AES-GCM melempar `OperationError`).
4. **Unifikasi Nama Galat Inkonsistensi Manifest (§3.D & §4):** Menstandarkan nama kelas/tipe galat penolakan panjang berkas manifest menjadi nama konseptual tunggal: `ManifestConsistencyError`.
5. **Koreksi Terminologi Pembatalan Staging Plaintext (§3.F & §4):** Mengganti istilah berlebihan seperti *staging buffer wiped* atau klaim penghapusan fisik menjadi terminologi yang akurat: *staging dibatalkan; referensi buffer sementara dilepas dan buffer Uint8Array yang masih dapat diakses ditimpa nol sebagai best-effort cleanup*.
6. **Klarifikasi Batasan Diagnostik `OperationError` (§3.I):** Mengakui variasi teks pesan generik antar-runtime (Node.js vs peramban web); menegaskan bahwa penyebab kegagalan integritas diketahui dari skenario pengujian (*test setup*), bukan dari kemampuan Web Crypto API mendiagnosis bagian mana yang rusak.
7. **Kelengkapan Tiga Komponen Pemulihan Sesi UJI-E4 (§3.A & §4):** Menegaskan secara eksplisit bahwa sesi baru yang dibentuk setelah insiden crash/kegagalan wajib menggunakan tiga entitas baru: (1) CEK baru, (2) UploadSessionID baru, dan (3) UploadSessionSalt baru.
8. **Presisi Terminologi Batas Kuota Berkas (§2):** Memperbaiki label kuota dari "Tepat" menjadi **"Maksimum"** (Maksimum 10.000.000 byte per berkas, Maksimum 25.000.000 byte per pesan, Maksimum 5 berkas per pesan).
9. **Penyempurnaan Tambahan:** Menyelaraskan rekomendasi memori Bagian 6 dengan metrik primer $\Delta\text{arrayBuffers}$; menegaskan bahwa matriks 15 skenario terdampak melengkapi 32 skenario komprehensif B-CHAT-08A.1; serta menegakkan disiplin taksonomi status bukti ilmiah.

---

## 2. SPESIFIKASI TEKNIS KANONIKAL TERREKONSILIASI

Seluruh parameter spesifikasi kanonikal yang telah disahkan pada Gerbang 1, Gerbang 2, dan disempurnakan pada B-CHAT-08A.2 dirangkum dengan terminologi yang telah disempurnakan:

```
+-------------------------------------------------------------------------------------------------------------+
|                                    SPESIFIKASI KANONIKAL YANG TETAP BERLAKU                                 |
+--------------------------+----------------------------------------------------------------------------------+
| Parameter                | Nilai Resmi yang Wajib Dipatuhi                                                  |
+--------------------------+----------------------------------------------------------------------------------+
| Kuota Berkas Maksimum    | Maksimum 10.000.000 byte (desimal, 10 MB kuota per file)                         |
| Kuota Pesan Gabungan     | Maksimum 25.000.000 byte (desimal, 25 MB kuota total per pesan chat)             |
| Batas Jumlah Berkas      | Maksimum 5 berkas per pesan chat tunggal                                         |
| Ukuran Chunk Plaintext   | Tepat 1.048.576 byte (1 MiB biner, 2^20 byte; kecuali chunk terakhir)            |
| Kunci Simetris (CEK)     | AES-256 (256-bit / 32 byte), di-generate per sesi unggah via CSPRNG              |
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
Data Terotentikasi Tambahan (*Additional Authenticated Data* / AAD) diwajibkan memiliki panjang tepat **83 byte** biner, terdiri dari 10 elemen terurut secara ketat:
1. `Magic Header`: 4 byte ASCII `UNH1` (`0x55 0x4E 0x48 0x31`)
2. `Protocol Version`: 2 byte `uint16be` (`0x0001`)
3. `Domain Separator`: 1 byte `uint8` (`0x02` = ATTACHMENT_CHUNK)
4. `Room UUID`: 16 byte (`raw 16-byte UUID binary representation`)
5. `Message UUID`: 16 byte (`raw 16-byte UUID binary representation`)
6. `Attachment UUID`: 16 byte (`raw 16-byte UUID binary representation`)
7. `Upload Session ID`: 16 byte (`raw 16-byte UUID binary representation`)
8. `Chunk Index`: 4 byte `uint32be` (zero-based: $0 \le \text{chunk\_index} \le N-1$)
9. `Total Chunks`: 4 byte `uint32be` ($1 \le N \le 10$)
10. `Plaintext Length`: 4 byte `uint32be` ($1 \le L_i \le 1.048.576$)

---

## 3. DELAPAN KOREKSI PRESISI FINAL (A–H)

### Koreksi A — Aturan Nonce Crash & Recovery Safety dan Kelengkapan Sesi Baru (§5.2)

1. **Status Penggunaan Nonce Sejak Enkripsi Dimulai:**  
   Suatu kombinasi pasangan $(\text{CEK}, \text{Nonce})$ **dianggap telah terpakai (*consumed*) seketika sejak operasi enkripsi dimulai pada antarmuka Web Crypto API**. Dilarang menganggap bahwa nonce baru terpakai setelah berkas/chunk berhasil tersimpan di media penyimpanan atau basis data.
2. **Penanganan Insiden Crash & Kegagalan Proses:**  
   Jika proses enkripsi terhenti (*abrupt process termination*), sistem crash, daya terputus, atau status persistensi ciphertext tidak dapat dipastikan, nonce yang bersangkutan **tidak boleh digunakan kembali untuk mengenkripsi data baru**.
3. **Ketentuan Pengiriman Ulang (Retry):**  
   Pengiriman ulang chunk yang gagal terkirim ke jaringan hanya diperbolehkan dengan **mengirimkan ulang ciphertext yang telah terbukti tersimpan secara utuh di media penyimpanan lokal**, tanpa menjalankan proses enkripsi ulang.
4. **Kewajiban Pembatalan Sesi (Session Abandonment) & Kelengkapan Tiga Komponen Baru:**  
   Jika ciphertext lokal rusak, tidak lengkap, atau statusnya meragukan, sesi unggah tersebut **wajib ditinggalkan seutuhnya (*abandoned*)**. Proses unggah ulang wajib dimulai dari awal sebagai sesi baru yang menghasilkan tiga komponen kriptografis independen secara lengkap:
   - **CEK baru** (kunci AES-256 baru via CSPRNG),
   - **UploadSessionID baru** (UUID v4 baru 16 byte), dan
   - **UploadSessionSalt baru** (salt acak 64-bit baru via CSPRNG).
5. **Ketiadaan Asumsi Durabilitas Otomatis:**  
   Penyimpanan peramban (IndexedDB/Origin Private File System) tidak boleh diasumsikan tahan terhadap *abrupt crash* tanpa bukti pengujian spesifik di luar EXP-02.

---

### Koreksi B — Presisi Teoretis Independensi CEK dan Probabilitas Tabrakan Kunci (§5.2)

1. **Peniadaan Klaim Kemustahilan Absolut ($K_1 \ne K_2$):**  
   Pernyataan pada dokumen terdahulu yang menyiratkan bahwa dua kunci acak 256-bit dipastikan berbeda secara mutlak ($K_1 \ne K_2$) dikoreksi. Secara matematis, dua pembangkitan kunci 256-bit independen melalui CSPRNG memiliki probabilitas teoretis tabrakan sebesar:
   $$P_{\text{key\_collision}} = 2^{-256} \approx 8{,}636 \times 10^{-78}$$
   Untuk kumpulan $m$ sesi unggah, probabilitas tabrakan kunci mengikuti batas *birthday bound*:
   $$P_{\text{collision}}(m) \approx \frac{m^2}{2 \times 2^{256}} = \frac{m^2}{2^{257}}$$
   Probabilitas ini sangat teramat kecil (*negligible*) sehingga dapat diabaikan untuk seluruh siklus hidup operasional sistem, namun **bukan nol secara absolut matematis**.
2. **Formula Birthday Bound Salt 64-Bit:**  
   Untuk $m$ sesi unggah independen yang masing-masing menghasilkan salt acak 64-bit, probabilitas tabrakan salt antar-sesi tetap mematuhi formula:
   $$P_{\text{salt\_collision}} \approx \frac{m^2}{2 \times 2^{64}} = \frac{m^2}{2^{65}}$$
   Sebagai contoh ilustrasi: pada $m = 10^6$ sesi unggah, probabilitas tabrakan salt adalah $P \approx \frac{10^{12}}{3{,}69 \times 10^{19}} \approx 2{,}71 \times 10^{-8}$.
3. **Pilar Keamanan Nonce AES-GCM:**  
   Mekanisme proteksi terhadap serangan *nonce reuse* pada rancangan lampiran ini bertumpu secara ketat pada tiga pilar pertahanan:
   - **Pilar 1 — Fresh CEK per Session:** Setiap sesi unggah menghasilkan Kunci Enkripsi Konten (CEK) independen baru. Sekalipun terjadi tabrakan salt 64-bit antar-dua sesi berbeda ($S_A = S_B$), hal tersebut tidak menimbulkan kegagalan keamanan AES-GCM karena kunci enkripsinya independen secara praktis ($K_A \ne K_B$).
   - **Pilar 2 — Deterministic Counter Monoton:** Di dalam satu sesi unggah ber-CEK tunggal, counter chunk ($0 \le \text{chunk\_index} \le N-1$, di mana $N \le 10$) menjamin bahwa nonce tidak pernah berulang di antara chunk pada sesi tersebut.
   - **Pilar 3 — Crash & Session Abandonment Rules:** Sesi yang mengalami kegagalan sebelum tuntas wajib dibatalkan dan digantikan oleh sesi baru dengan CEK, UploadSessionID, dan salt baru.

---

### Koreksi C — Pemisahan Tiga Lapisan Validasi Indeks (§4 & §6)

Field `Chunk Index` pada struktur AAD kanonikal adalah tipe data unsigned 32-bit integer (*uint32be*, rentang $0 \dots 4.294.967.295$). Skenario pengujian indeks dipisahkan secara tegas ke dalam tiga lapisan arsitektur:

```
+-------------------------------------------------------------------------------------------------------------+
|                                    PEMISAHAN TIGA LAPISAN VALIDASI INDEKS                                   |
+----------+--------------------------------+--------------------------+--------------------------------------+
| Kode Uji | Skenario Kondisi Uji           | Lapisan Validasi         | Respons Penolakan yang Diharapkan    |
+----------+--------------------------------+--------------------------+--------------------------------------+
| UJI-D10A | Indeks bernilai negatif (< 0)  | Validasi Input Aplikasi  | Ditolak di layer aplikasi sebelum    |
|          | (misal chunk_index = -1)       | (Pra-Serialisasi AAD)    | serialisasi AAD (TypeRangeValidation)|
+----------+--------------------------------+--------------------------+--------------------------------------+
| UJI-D10B | Indeks di luar rentang chunk   | Validasi Perakitan       | Ditolak validasi logika perakitan    |
|          | (chunk_index >= N, misal >=10) | (Pra-Serialisasi AAD)    | sebelum serialisasi (ChunkOutOfRange)|
+----------+--------------------------------+--------------------------+--------------------------------------+
| UJI-D10C | Modifikasi biner byte indeks   | Lapisan Kriptografis     | Ditolak seketika oleh Web Crypto API |
|          | pada AAD saat dekripsi         | AES-256-GCM Decrypt      | melempar OperationError (Auth Failed)|
+----------+--------------------------------+--------------------------+--------------------------------------+
```

- **UJI-D10A (Boundary Input Negatif):** Nilai negatif bukan merupakan tipe data *uint32* yang sah. Layer antarmuka aplikasi wajib menolaknya sebelum data diproses ke pembentuk AAD biner.
- **UJI-D10B (Boundary Rentang Chunk):** Nilai indeks $N \le \text{chunk\_index} \le 9$ (atau $\ge 10$) adalah uint32 valid secara representasi biner, namun melanggar batasan logis ukuran berkas ($N = \lceil S / C \rceil$). Logika aplikasi wajib menolaknya sebelum memanggil enkripsi/dekripsi.
- **UJI-D10C (Integritas Kriptografis AAD):** Jika penyerang memodifikasi 4 byte field `Chunk Index` pada data AAD biner sebelum dekripsi dieksekusi, tag autentikasi AES-GCM wajib menggagalkan operasi dan menghasilkan `OperationError`.

---

### Koreksi D — Unifikasi Nama Galat Inkonsistensi Manifest: `ManifestConsistencyError` (§4 & §6)

Pengujian manipulasi panjang berkas pada manifest aplikasi dipisahkan secara konseptual dari pengujian modifikasi AAD:
1. **UJI-D9A (Modifikasi Field PlaintextLength pada AAD):**  
   Field `Plaintext Length` pada AAD biner diubah nilainya sebelum dekripsi.  
   *Target Hasil:* Lapisan AES-GCM gagal mendekripsi dan melempar `OperationError`.
2. **UJI-D9B (Modifikasi Metadata Panjang Berkas pada Manifest Aplikasi):**  
   Field AAD biner tetap asli dan valid (dekripsi GCM sukses), namun metadata panjang berkas total pada manifest lampiran dipalsukan oleh pihak ketiga sehingga tidak cocok dengan total akumulasi byte plaintext terdekripsi.  
   *Target Hasil:* Lapisan perakitan aplikasi mendeteksi inkonsistensi dan menolak berkas dengan galat tunggal resmi: **`ManifestConsistencyError`**.  
   *Koreksi Terminologi:* Nama galat distandarkan secara seragam di seluruh dokumen dan skenario menjadi `ManifestConsistencyError` (menghapus variasi istilah lain seperti *ConsistencyError* atau *ManifestValidationError*).

---

### Koreksi E — Terminologi Pembatalan Staging Plaintext dan Best-Effort Cleanup (§3, §6, §7)

1. **Prinsip All-or-Nothing Perakitan Berkas:**  
   Plaintext hasil dekripsi chunk individual tidak boleh digabungkan atau diserahkan ke komponen unduhan peramban sebelum seluruh chunk ($0 \dots N-1$) lolos autentikasi GCM dan cocok dengan manifest.
2. **Peniadaan Klaim Secure Wipe / Physical Wipe:**  
   Dokumen ini secara formal **menghapus seluruh klaim bahwa penimpaan buffer nol pada JavaScript menjamin penghapusan memori fisik (*secure memory wipe*)**.
3. **Deskripsi Akurat Perilaku Pembersihan:**  
   Jika salah satu chunk gagal didekripsi (misalnya pada skenario UJI-D13 di mana chunk terakhir $N-1$ disabotase), perilaku yang dipersyaratkan dirumuskan sebagai:  
   *"Staging dibatalkan; referensi buffer sementara dilepas dan buffer Uint8Array yang masih dapat diakses ditimpa nol sebagai best-effort cleanup."*
4. **Alasan Keterbatasan Runtime JavaScript (V8 Engine):**  
   - Garbage Collector (GC) dapat memindahkan buffer di memori selama fase *compaction*.
   - Objek biner dapat disalin (*shallow/deep copy*) secara internal oleh binding C++ Web Crypto tanpa kendali kode JavaScript.
   - Sistem operasi dan runtime dapat mempertahankan jejak memori pada *swap* atau *unallocated memory pages*.
   - Oleh karena itu, tindakan `buffer.fill(0)` dan `buffer = null` diklasifikasikan secara jujur sebagai **mitigasi pembersihan terbaik (*best-effort cleanup*)**, bukan kepastian kriptografis penghapusan memori.

---

### Koreksi F — Presisi Batasan Diagnostik dan Pesan Pengecualian `OperationError` (§6)

1. **Variasi Pesan Antar-Runtime:**  
   Spesifikasi Web Cryptography API W3C menetapkan tipe galat `OperationError` ketika autentikasi tag AES-GCM gagal. Namun demikian, **teks pesan galat (*error message string*) tidak distandarkan secara identik antar-implementasi mesin JavaScript**:
   - Node.js (OpenSSL backend): melempar `OperationError: The operation failed for an operation-specific reason` atau variasi string OpenSSL.
   - Chromium / Blink: melempar `OperationError: Decryption failed`.
   - Mozilla Firefox / Gecko (NSS): melempar `OperationError: The operation failed for an operation-specific reason`.
   - Apple Safari / WebKit: melempar `OperationError: The operation failed`.
2. **Ketiadaan Fitur Diagnostik Kriptografis Otomatis:**  
   Sifat bawaan cipher terautentikasi (AEAD) secara sengaja **tidak memberitahukan field mana yang rusak** untuk mencegah serangan kebocoran informasi (*padding/tampering oracle attack*).
3. **Dasar Pembuktian Hasil Uji:**  
   Pada skenario UJI-D1 sampai UJI-D5, UJI-D9A, dan UJI-D10C, kegagalan autentikasi hanya dapat diatribusikan ke parameter tertentu **berdasarkan kondisi injeksi kesalahan yang sengaja disiapkan pada pengujian (*test setup*)**, bukan karena Web Crypto API mampu mendeteksi elemen spesifik yang dirusak.

---

### Koreksi G — Presisi Metrik Memori Multi-Dimensi Node.js (§8 & §10)

1. **Hierarki Empat Metrik Memori Node.js (`process.memoryUsage()`):**
   - **Metrik Primer (Buffer Native Per-Chunk):**  
     $$\Delta\text{arrayBuffers}$$  
     Mencatat alokasi memori biner murni yang dialokasikan untuk objek `ArrayBuffer` dan `TypedArray` (`Uint8Array`) yang digunakan selama streaming chunking. Ini merupakan indikator utama efisiensi chunking streaming 1 MiB.
   - **Metrik Pendukung (Native Bindings C++):**  
     $$\Delta\text{external}$$  
     Mencatat seluruh memori C++ native yang terikat pada objek V8. Nilai ini mencakup seluruh alokasi `arrayBuffers` ditambah memori native internal yang dialokasikan oleh modul Web Crypto (OpenSSL context).
   - **Metrik Konteks Proses (Total Alokasi RAM):**  
     $$\text{rss}\ (\text{Resident Set Size})$$  
     Mencatat total memori fisik proses Node.js yang dipetakan oleh OS (mencakup executable binary, pustaka bersama, V8 runtime, C++ context, dan heap).
   - **Informasi JavaScript Heap:**  
     $$\text{heapUsed}$$  
     Mencatat penggunaan memori untuk objek JavaScript murni pada heap V8 (closure, variabel, objek metadata, bukan isi biner file).
2. **Larangan Mutlak Penjumlahan Redundan:**  
   **DILARANG KERAS menjumlahkan $\text{external} + \text{arrayBuffers}$**, karena dalam arsitektur modern Node.js/V8, alokasi `arrayBuffers` **merupakan himpunan bagian (*subset*) yang sudah tercakup di dalam `external`**. Penjumlahan keduanya menghasilkan perhitungan ganda (*double counting*) yang menyesatkan.
3. **Status Ambang Batas Konsumsi Memori Puncak:**  
   Target lonjakan memori puncak per-chunk streaming $\le 20\text{ MB}$ berstatus **`PROPOSED`** (target desain usulan, bukan tolok ukur mutlak). Evaluasi ambang ini diukur secara ketat berdasarkan lonjakan buffer native $\Delta\text{arrayBuffers}$ (dan $\Delta\text{external}$ sebagai pendukung) selama pemrosesan berkas uji $10.000.000\text{ byte}$.

---

### Koreksi H — Koreksi Terminologi Batas Kuota Berkas (§2)

Pada tabel Bagian 2 dokumen terdahulu, batas kuota berkas tertulis menggunakan kata "Tepat", yang secara semantik keliru karena menyiratkan bahwa berkas harus berukuran tepat senilai angka tersebut. Istilah dikoreksi secara formal menjadi:
- **Maksimum 10.000.000 byte (desimal):** Berkas dengan ukuran plaintext $1 \le S \le 10.000.000$ byte diterima; berkas $S > 10.000.000$ byte ditolak seketika (`FileSizeExceededError`); berkas 0 byte ditolak seketika (`EmptyFileError`).
- **Maksimum 25.000.000 byte (desimal):** Total ukuran gabungan seluruh lampiran dalam satu pesan chat tidak boleh melebihi $25.000.000$ byte.
- **Maksimum 5 berkas per pesan:** Jumlah lampiran dalam satu pesan chat dibatasi antara $1 \le k \le 5$ berkas.
- **Ukuran Chunk Standar:** Tetap tepat $1.048.576\text{ byte}$ ($1\text{ MiB}$ biner, $2^{20}\text{ byte}$) untuk chunk $0$ sampai $N-2$, sedangkan chunk terakhir $N-1$ berukuran tepat $L_{\text{last}} = S \bmod C$ jika $S \bmod C \ne 0$, atau $C$ jika $S \bmod C = 0$.

---

## 4. MATRIKS 15 SKENARIO TERDAMPAK KOREKSI PRESISI

> [!NOTE]
> **HUBUNGAN DENGAN MATRIKS SKENARIO B-CHAT-08A.1:**  
> Matriks berikut menampilkan **15 skenario pengujian yang secara langsung terdampak oleh koreksi presisi B-CHAT-08A.3** (pemecahan skenario indeks, standarisasi galat manifest, penegasan kelengkapan komponen sesi, pembersihan staging best-effort, dan metrik memori primer).  
> Kelimabelas skenario ini **berfungsi menyempurnakan dan memperjelas, BUKAN menggantikan atau menghapus 32 skenario pengujian komprehensif** yang tercantum pada dokumen [B-CHAT-08A.1](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.1_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md). Seluruh skenario pengujian integritas lainnya pada B-CHAT-08A.1 (seperti UJI-D1 s.d. UJI-D7) tetap berlaku penuh dalam perencanaan EXP-02.

```
+-----------------------------------------------------------------------------------------------------------------------------------------+
|                                    MATRIKS 15 SKENARIO TERDAMPAK KOREKSI PRESISI (B-CHAT-08A.3)                                         |
+----+------------+------------+---------------------------------------+------------------------------------------------------------------+
| No | ID B-08A.1 | ID B-08A.3 | Nama Skenario Pengujian               | Deskripsi Perbaikan Teknis & Target Hasil                        |
+----+------------+------------+---------------------------------------+------------------------------------------------------------------+
| 1  | UJI-A      | UJI-A      | Siklus Enkripsi & Dekripsi Normal     | Bukti utama: Komparasi Byte-by-Byte (SHA-256 checksum pendukung) |
+----+------------+------------+---------------------------------------+------------------------------------------------------------------+
| 2  | UJI-D9     | UJI-D9A    | Modifikasi Field PlaintextLength AAD  | Nilai length AAD saat dekripsi beda -> REJECT: GCM OperationError|
| 3  | (Baru)     | UJI-D9B    | Modifikasi Manifest Panjang Berkas    | AAD asli, manifest dipalsukan -> REJECT: ManifestConsistencyError|
+----+------------+------------+---------------------------------------+------------------------------------------------------------------+
| 4  | (Baru)     | UJI-D10A   | Validasi Input Indeks Negatif (< 0)   | Indeks negatif pada input -> REJECT: TypeRangeValidationError    |
| 5  | UJI-D10    | UJI-D10B   | Validasi Chunk Index Melebihi Batas   | Indeks >= N pada logika chunk -> REJECT: ChunkIndexOutOfRangeErr |
| 6  | (Baru)     | UJI-D10C   | Manipulasi Byte Indeks AAD Dekripsi   | Byte ChunkIndex pada AAD diubah -> REJECT: GCM OperationError    |
+----+------------+------------+---------------------------------------+------------------------------------------------------------------+
| 7  | UJI-D8     | UJI-D8     | Transport Reordering Urutan Terbalik  | Chunk tiba urutan terbalik -> PASS: Dirakit berdasarkan indeks AAD|
| 8  | UJI-D12    | UJI-D12    | Transport Reordering Permutasi Acak   | Chunk tiba urutan acak penuh -> PASS: Dirakit berdasar indeks AAD|
+----+------------+------------+---------------------------------------+------------------------------------------------------------------+
| 9  | UJI-E1     | UJI-E1     | Deteksi Nonce Reuse via Ledger        | Menguji ledger aplikasi penolak duplikasi pasangan (CEK, Nonce)  |
| 10 | UJI-E2     | UJI-E2     | Penanganan Kegagalan CSPRNG           | Mock exception getRandomValues -> FAIL-CLOSED (Tidak ada cipher) |
| 11 | UJI-E3     | UJI-E3     | Skenario Pengiriman Ulang (Retry)     | Mengirim ciphertext lokal tersimpan -> PASS: Tanpa enkripsi ulang|
| 12 | UJI-E4     | UJI-E4     | Skenario Pembatalan Sesi (Crash/Fail) | Sesi crash ditinggalkan -> Wajib (1) CEK baru, (2) UploadSession |
|    |            |            |                                       | ID baru, dan (3) UploadSessionSalt baru                          |
+----+------------+------------+---------------------------------------+------------------------------------------------------------------+
| 13 | UJI-D13    | UJI-D13    | Isolasi Staging Chunk Terakhir Gagal  | Chunk N-1 gagal GCM -> REJECT: Staging dibatalkan; buffer dilepas|
|    |            |            |                                       | dan ditimpa nol sebagai best-effort cleanup                      |
+----+------------+------------+---------------------------------------+------------------------------------------------------------------+
| 14 | UJI-F1     | UJI-F1     | Streaming Pipeline Buffer 1 MiB       | PROPOSED: Lonjakan delta arrayBuffers <= 20 MB (external pendukung|
| 15 | UJI-F2     | UJI-F2     | Full-Buffer Control (In-Memory)       | OBSERVATIONAL: Kontrol komparasi alokasi V8 memory               |
+----+------------+------------+---------------------------------------+------------------------------------------------------------------+
```

---

## 5. KLASIFIKASI BUKTI ILMIAH TERREKONSILIASI (B-CHAT-08A.3)

Untuk menjamin kejujuran akademis dan integritas pelaporan, seluruh parameter keberhasilan EXP-02 diklasifikasikan secara ketat menggunakan taksonomi pembuktian formal:

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
| 9  | Ambang Lonjakan Buffer Streaming <= 20 MB (UJI-F1)  | PROPOSED                  | Target desain usulan diukur dari delta arrayBuff |
| 10 | Evaluasi Memori Full-Buffer In-Memory (UJI-F2)      | OBSERVATIONAL             | Kontrol pembanding observasional alokasi V8 RAM  |
| 11 | Kinerja Web Crypto API pada Peramban Nyata          | UNVERIFIED                | Di luar lingkup pengujian sandbox Node.js        |
| 12 | Ketiadaan UI Freeze / Long Task di Peramban Web     | NOT MEASURED              | Node.js CLI tidak mengukur event loop peramban   |
| 13 | Status Pelaksanaan Eksperimen Fisik EXP-02          | EXP-02 NOT AUTHORIZED     | Belum memperoleh izin tertulis Pemilik Proyek    |
+----+-----------------------------------------------------+---------------------------+--------------------------------------------------+
```

---

## 6. KEPUTUSAN DESAIN YANG MEMERLUKAN PERSETUJUAN PEMILIK PROYEK

Rekomendasi teknis yang disempurnakan diajukan secara formal kepada Pemilik Proyek untuk memperoleh keputusan final sebelum eksperimen fisik dapat dipertimbangkan:

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
| 4  | Ambang Batas Memori Usulan | Menyetujui ambang PROPOSED <= 20 MB dievalu-| Diukur secara primer dari lonjakan delta arrayBuffers   |
|    | pada Pengujian Node.js     | asi berdasarkan delta arrayBuffers Node.js  | (didukung delta external) pada pemrosesan streaming 10MB|
+----+----------------------------+---------------------------------------------+---------------------------------------------------------+
| 5  | Pemisahan Pengujian        | Menyetujui bahwa pengujian browser nyata    | Pengujian EXP-02 di sandbox Node.js tidak diklaim       |
|    | Lingkungan Peramban Web    | merupakan pekerjaan terpisah                | membuktikan kompatibilitas browser nyata.               |
+----+----------------------------+---------------------------------------------+---------------------------------------------------------+
| 6  | Otorisasi Pelaksanaan      | Otorisasi tertulis terpisah untuk membuat   | Eksperimen EXP-02 belum diizinkan dieksekusi sebelum ada|
|    | Fisik Eksperimen EXP-02    | sandbox dan menjalankan skenario pengujian  | persetujuan eksplisit tertulis dari Pemilik Proyek.     |
+----+----------------------------+---------------------------------------------+---------------------------------------------------------+
```

---

## 7. DAFTAR HAL YANG BELUM DIUJI DAN TIDAK BOLEH DIKLAIM

Untuk menjaga batasan keilmuan yang sehat, batasan pembuktian dokumen dicatat secara transparan:
1. **Belum Ada Eksekusi Prototipe Fisik:** Seluruh skenario pengujian (UJI-A sampai UJI-F) berstatus perencanaan dan **belum pernah dieksekusi secara fisik di sandbox**.
2. **Ketiadaan Pembuktian di Peramban Nyata:** Perilaku Web Crypto API pada mesin browser sesungguhnya (Chromium, Gecko, WebKit) belum diuji dalam eksperimen ini.
3. **Ketiadaan Pengujian Integrasi Next.js 14:** Antarmuka pemuatan stream peramban (`File.stream()`) dan eksekusi Web Worker pada Next.js 14 belum diuji pada tahapan ini.
4. **Ketiadaan Jaminan Secure Memory Erasure:** Operasi penimpaan buffer nol (`buffer.fill(0)`) pada JavaScript tidak boleh diklaim sebagai penghapusan memori fisik mutlak (*no physical memory erasure guarantee*).
5. **Ketiadaan Diagnostik Internal pada `OperationError`:** Kegagalan autentikasi AES-GCM tidak menghasilkan rincian komponen mana yang dirusak; atribusi galat hanya berasal dari skenario pengujian.

---

## 8. PERNYATAAN INTEGRITAS ARSIP DAN KEPATUHAN BATAS OPERASIONAL

Penyusunan dokumen koreksi presisi **B-CHAT-08A.3** diselesaikan dengan kepatuhan mutlak terhadap instruksi operasional:

1. **Zero Prototype Creation:** Direktori sandbox `scratch/bchat_prototype/exp02_chunking_aad/` **belum dibuat**, dan tidak ada berkas kode program yang ditulis.
2. **Zero Execution, Benchmark, & Testing:** Tidak ada uji coba eksekusi, simulasi, benchmark memori, maupun pengujian fungsional yang dijalankan.
3. **Zero Toolchain & Dependency Installation:** Tidak ada dependensi npm, compiler Rust, target WASM, atau paket sistem yang diinstal.
4. **Zero Main Codebase Changes:** Direktori backend Laravel ([`manajemen_skripsi/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/manajemen_skripsi/)) dan frontend Next.js ([`frontend_manajemen_skripsi/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/frontend_manajemen_skripsi/)) tidak disentuh sama sekali.
5. **Zero Database Changes:** Tidak ada migrasi basis data, modifikasi skema tabel, atau query database yang dijalankan.
6. **Zero Git Operations:** Tidak ada perintah Git (`git status`, `git diff`, `git commit`, `git checkout`, `git push`, dsb.) yang dijalankan.
7. **Pelestarian Arsip Historis:** Dokumen [B-CHAT-08A](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A_Rencana_dan_Mandat_EXP-02.md), [B-CHAT-08A.1](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.1_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md), [B-CHAT-08A.2](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.2_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md), dan seluruh dokumen forensik EXP-01 ([B-CHAT-07A.1](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A_Laporan_Eksekusi_EXP-01.md) s.d. [B-CHAT-07A.7](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.7_Koreksi_Editorial_dan_Klasifikasi_Bukti_Final_EXP-01.md)) tetap tersimpan utuh di repositori tanpa diubah atau ditimpa (*zero overwrite*).

---

## 9. PERNYATAAN EKSPLISIT STATUS OTORISASI EXP-02

$$\mathbf{STATUS\ OTORISASI\ EKSEKUSI\ EXP\text{-}02:\ EXP\text{-}02\ NOT\ AUTHORIZED}$$

> [!CAUTION]
> **PERINGATAN RESMI:**  
> Dokumen **B-CHAT-08A.3** adalah dokumen perencanaan dan mandat teknis (*documentation only*). Penerbitan dokumen ini **TIDAK MENGOTORISASI** eksekusi eksperimen EXP-02. Eksekusi fisik di sandbox hanya dapat dimulai setelah Pemilik Proyek memeriksa dokumen koreksi presisi final ini dan memberikan **persetujuan tertulis eksplisit terpisah**.

---
*(Dokumen B-CHAT-08A.3 selesai disusun secara paripurna sebagai koreksi presisi final mandat EXP-02, siap ditinjau oleh Pemilik Proyek).*

