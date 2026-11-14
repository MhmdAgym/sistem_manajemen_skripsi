# B-CHAT-08A.2 — KOREKSI TEKNIS TERBATAS MANDAT EXP-02
## NONCE CRASH-SAFETY, INDEPENDENSI CEK, VALIDASI AAD VS MANIFEST, STAGING PLAINTEXT, DAN KLASIFIKASI METRIK MEMORI

**Status Dokumen:** DOKUMEN KOREKSI TEKNIS RESMI (*CONTROLLED SPECIFICATION AMENDMENT*)  
**Mode Operasi:** STRICTLY READ-ONLY TERHADAP IMPLEMENTASI — DOCUMENTATION ONLY  
**Status Otorisasi EXP-02:** **EXP-02 NOT AUTHORIZED** *(Eksperimen EXP-02 belum diotorisasi)*  
**Dokumen Sumber yang Dikoreksi:** [B-CHAT-08A.1_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.1_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md)  
**Dokumen Acuan Historis:**
- [B-CHAT-08A_Rencana_dan_Mandat_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A_Rencana_dan_Mandat_EXP-02.md)
- [B-CHAT-06D_Mandat_Eksekusi_Terbatas_Gerbang_2.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-06D_Mandat_Eksekusi_Terbatas_Gerbang_2.md) (Revisi B-CHAT-06D.2 §3.2)
- [B-CHAT-05_Spesifikasi_Final_E2EE.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-05_Spesifikasi_Final_E2EE.md) (Keputusan Desain OD-02 & OD-17)
- Rangkaian Dokumen Forensik EXP-01: [B-CHAT-07A.1](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A_Laporan_Eksekusi_EXP-01.md) s.d. [B-CHAT-07A.7](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.7_Koreksi_Editorial_dan_Klasifikasi_Bukti_Final_EXP-01.md)  
**Proyek:** Sistem Informasi Manajemen Skripsi UNUHA  
**Fitur:** Bimbingan Online Chat Real-Time E2EE  
**Tahap:** Peninjauan Mandat Gerbang 2 (Persiapan EXP-02)  
**Tanggal:** 5 November 2026  

---

> [!CAUTION]
> **PENEGASAN STATUS OPERASIONAL MUTLAK:**  
> Dokumen **B-CHAT-08A.2** ini berstatus **STRICTLY DOCUMENTATION ONLY — EXP-02 NOT AUTHORIZED**. Penerbitan dokumen ini **BUKAN** merupakan izin atau otorisasi untuk memulai eksekusi prototipe EXP-02 di sandbox. Persetujuan Gerbang 2 sebelumnya tidak memberikan kewenangan otomatis untuk menjalankan EXP-02. Pelaksanaan fisik di sandbox hanya dapat dimulai setelah Pemilik Proyek memberikan persetujuan tertulis eksplisit terpisah atas mandat final ini. Selama penyusunan dokumen ini, tidak ada kode sumber yang dibuat, tidak ada direktori sandbox yang dibuat, tidak ada dependensi atau toolchain yang dipasang, tidak ada benchmark/pengujian yang dijalankan, dan tidak ada operasi Git yang dilakukan. Dokumen sumber [B-CHAT-08A](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A_Rencana_dan_Mandat_EXP-02.md) dan [B-CHAT-08A.1](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.1_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md) tetap dipertahankan utuh sebagai arsip historis.

---

## 1. IDENTITAS DAN TUJUAN KOREKSI

Dokumen **B-CHAT-08A.2** diterbitkan untuk menutup sejumlah ketidakakuratan teknis yang masih tersisa pada dokumen [B-CHAT-08A.1](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.1_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md), tanpa mengubah keputusan desain yang sudah benar, tanpa memperluas ruang lingkup eksperimen, dan tanpa memberikan izin eksekusi EXP-02.

Tujuan utama penyempurnaan ini mencakup:
1. **Nonce Option 1 — Crash & Recovery Safety:** Menetapkan bahwa nonce dianggap telah terpakai sejak operasi enkripsi dimulai; kegagalan proses/crash sebelum persistensi ciphertext mewajibkan pembatalan sesi (*session abandonment*) dengan pembentukan CEK baru dan salt baru.
2. **Klarifikasi Collision Salt dan Independensi CEK:** Menjelaskan bahwa formula *birthday bound* salt 64-bit ($P \approx \frac{m^2}{2^{65}}$) tidak memicu *nonce reuse* selama setiap sesi menggunakan CEK independen yang dihasilkan CSPRNG; keamanan AES-GCM bergantung mutlak pada non-reuse pasangan $(\text{Key}, \text{Nonce})$.
3. **Pemisahan Validasi Indeks dari Serialisasi uint32:** Membedakan indeks negatif (ditolak pada validasi tipe/rentang aplikasi sebelum serialisasi) dari indeks $\ge N$ (ditolak validasi rentang chunk) dan manipulasi byte indeks AAD (memicu penolakan autentikasi GCM).
4. **Pemisahan Modifikasi AAD vs Validasi Manifest:** Memecah pengujian pemalsuan panjang plaintext menjadi dua kasus: Kasus AAD termodifikasi (gagal autentikasi GCM `OperationError`) dan Kasus manifest termodifikasi (gagal konsistensi aplikasi, bukan otomatis `OperationError`).
5. **Presisi Uji Nonce Reuse Ledger (Pemetaan Skenario UJI-E1):** Menegaskan bahwa pengujian memeriksa kontrol aplikasi (*application-level nonce ledger*), bukan fitur deteksi bawaan Web Crypto.
6. **Keterbatasan Staging Plaintext dan Best-Effort Cleanup:** Menegaskan bahwa penimpaan buffer nol pada JavaScript adalah *best-effort cleanup* dan bukan jaminan penghapusan memori fisik (*secure memory erasure*); serta mengoreksi keabsahan hash manifest.
7. **Standar Pembuktian H1:** Menetapkan perbandingan *byte-by-byte* sebagai bukti utama identitas plaintext, sedangkan hash SHA-256 sebagai *checksum* pendukung.
8. **Disiplin Metrik Memori Node.js:** Memperjelas definisi `heapUsed`, `external`, `arrayBuffers`, dan `rss`, serta melarang penjumlahan redundan `external + arrayBuffers`.
9. **Keterbatasan Diagnostik `OperationError`:** Menegaskan bahwa `OperationError` pada Web Crypto bersifat generik dan tidak dapat mendiagnosis komponen mana yang rusak.
10. **Pemisahan Batas Runtime Node.js vs Browser:** Menegaskan pembagian batas verifikasi antara prototipe Node.js dan pengujian peramban nyata.

---

## 2. KONSISTENSI SPESIFIKASI KANONIKAL YANG TETAP BERLAKU

Seluruh ketentuan resmi yang telah disahkan pada Gerbang 1 dan Gerbang 2 dipertahankan tanpa perubahan:

```
+-------------------------------------------------------------------------------------------------------------+
|                                    SPESIFIKASI KANONIKAL YANG TETAP BERLAKU                                 |
+--------------------------+----------------------------------------------------------------------------------+
| Parameter                | Nilai Resmi yang Wajib Dipatuhi                                                  |
+--------------------------+----------------------------------------------------------------------------------+
| Kuota File Maksimum      | Tepat 10.000.000 byte (desimal)                                                  |
| Kuota Pesan Gabungan     | Tepat 25.000.000 byte (desimal)                                                  |
| Jumlah File per Pesan    | Maksimum 5 file                                                                  |
| Ukuran Chunk Plaintext   | Tepat 1.048.576 byte (1 MiB biner, 2^20 byte)                                     |
| Kunci Simetris (CEK)     | AES-256 (256-bit / 32 byte)                                                      |
| Nonce / IV AES-GCM       | 96-bit (12 byte)                                                                 |
| Tag Autentikasi AES-GCM  | 128-bit (16 byte), di luar kuota plaintext                                       |
| Total Ukuran AAD         | Tepat 83 byte (10 field kanonikal)                                               |
| Konteks MLS Exporter     | Tepat 48 byte (RoomUUID || MessageUUID || AttachmentUUID, biner mentah)          |
| Format Framing Simpan    | Format A: Nonce (12 B) || Ciphertext (L_i B) || AuthTag (16 B) = (L_i + 28 B)    |
| Formula Jumlah Chunk     | N = ceil(S / C), berkas 0 byte ditolak mutlak                                    |
| Formula Chunk Terakhir   | L_last = S mod C (jika S mod C != 0), atau C (jika S mod C == 0)                 |
+--------------------------+----------------------------------------------------------------------------------+
```

### Layout Struktur AAD Kanonikal 83-Byte:
1. `Magic Header`: 4 byte ASCII `UNH1` (`0x55 0x4E 0x48 0x31`)
2. `Protocol Version`: 2 byte `uint16be` (`0x0001`)
3. `Domain Separator`: 1 byte `uint8` (`0x02` = ATTACHMENT_CHUNK)
4. `Room UUID`: 16 byte (`raw 16-byte UUID binary representation`)
5. `Message UUID`: 16 byte (`raw 16-byte UUID binary representation`)
6. `Attachment UUID`: 16 byte (`raw 16-byte UUID binary representation`)
7. `Upload Session ID`: 16 byte (`raw 16-byte UUID binary representation`)
8. `Chunk Index`: 4 byte `uint32be` (zero-based: $0 \le \text{index} \le N-1$)
9. `Total Chunks`: 4 byte `uint32be` ($1 \le N \le 10$)
10. `Plaintext Length`: 4 byte `uint32be` ($1 \le L_i \le 1.048.576$)
**Total Panjang: Tepat 83 byte.**

---

## 3. SEPULUH KOREKSI TEKNIS WAJIB (A–J)

### Koreksi A — Nonce Option 1: Aturan Crash dan Recovery Safety (§5.2)

Struktur Nonce Opsi 1 tetap:
$$\text{Nonce} = \text{UploadSessionSalt}\ (8\text{ byte}) \parallel \text{uint32be}(\text{chunk\_index})\ (4\text{ byte})$$

Untuk menjamin ketiadaan risiko *nonce reuse* saat terjadi kegagalan sistem, aturan operasional diperketat:
1. **Status Penggunaan Nonce:** Suatu nilai nonce **dianggap telah digunakan seketika sejak operasi enkripsi dengan pasangan $(\text{CEK}, \text{Nonce})$ tersebut mulai dieksekusi**. Dilarang menyatakan bahwa nonce baru dianggap terpakai setelah ciphertext berhasil dipersistenkan ke media penyimpanan.
2. **Penanganan Kegagalan & Crash:** Jika aplikasi mengalami crash, proses terhenti (*process termination*), atau status keberhasilan enkripsi tidak dapat dipastikan sebelum ciphertext terbukti tersimpan secara lengkap, nonce yang bersangkutan **tidak boleh digunakan kembali** untuk operasi enkripsi baru.
3. **Ketentuan Pengiriman Ulang (Retry):** Percobaan ulang pengiriman chunk ke jaringan hanya diizinkan dengan **mengirimkan ulang ciphertext yang telah terbukti tersimpan secara utuh di media lokal**, tanpa melakukan enkripsi ulang.
4. **Pembatalan Sesi (Session Abandonment):** Jika ciphertext tidak tersedia secara utuh atau status persistensinya di media lokal tidak dapat dibuktikan, sesi unggah tersebut **wajib ditinggalkan (*abandoned*)**. Proses unggah harus dimulai ulang dari awal menggunakan **CEK baru, UploadSessionID baru, dan UploadSessionSalt baru**.
5. **Ketiadaan Asumsi Durabilitas Otomatis:** Dilarang mengasumsikan bahwa penyimpanan lokal peramban (misalnya IndexedDB atau Cache API) secara otomatis bersifat *crash-safe* atau *durable*. Ketahanan penyimpanan terhadap *power loss* atau *abrupt process termination* harus dibuktikan melalui pengujian tersendiri di luar cakupan EXP-02.

---

### Koreksi B — Probabilitas Collision Salt dan Independensi CEK (§5.2)

1. **Formula Birthday Bound Salt 64-Bit:**  
   Untuk $m$ sesi unggah yang menghasilkan salt acak 64-bit independen melalui CSPRNG, probabilitas terjadinya tabrakan nilai salt antar-sesi adalah:
   $$P_{\text{salt\_collision}} \approx \frac{m^2}{2 \times 2^{64}} = \frac{m^2}{2^{65}}$$
2. **Peran Independensi CEK:**  
   Terjadinya tabrakan nilai salt lintas sesi **tidak otomatis menyebabkan *nonce reuse* yang merusak**, karena AES-GCM hanya rentan apabila pasangan $(\text{Kunci}, \text{Nonce})$ yang sama digunakan ulang. Jika setiap sesi unggah menggunakan **CEK independen yang dihasilkan oleh CSPRNG terpercaya**, penggunaan salt yang sama pada dua sesi berbeda tetap aman karena kunci enkripsinya berbeda ($K_1 \ne K_2$).
3. **Larangan Klaim Absolut:**  
   Dilarang mengklaim bahwa tabrakan salt lintas sesi adalah mustahil secara mutlak atau bahwa risiko *birthday bound* hilang seutuhnya. Keamanan strategi ini bergantung pada **independensi dan non-reuse Kunci Enkripsi Konten (CEK)**, bukan pada asumsi bahwa seluruh salt acak 64-bit dijamin unik secara global.

---

### Koreksi C — Pemisahan Validasi Indeks dan Serialisasi uint32 (§4 & §6)

Field `Chunk Index` pada AAD kanonikal adalah tipe data unsigned 32-bit integer (*uint32be*, rentang $0 \dots 4.294.967.295$). Skenario pengujian indeks diluruskan menjadi tiga mekanisme terpisah:
1. **Indeks Negatif pada Input Aplikasi:**  
   Nilai indeks negatif (misalnya `-1`) yang masuk sebagai input dari antarmuka aplikasi **wajib ditolak pada lapisan validasi tipe data dan rentang nilai pra-serialisasi**. Indeks negatif **bukan nilai uint32 yang sah** dan tidak dapat diserialisasikan secara langsung ke field AAD.
2. **Indeks Melebihi Batas Chunk ($\ge N$):**  
   Nilai indeks yang berada di luar rentang valid berkas ($N \le \text{chunk\_index} \le 9$) ditolak oleh fungsi validasi rentang chunk pada lapisan perakitan (*assembly validation*).
3. **Manipulasi Byte Indeks AAD pada Dekripsi:**  
   Jika penyerang memodifikasi byte pada field `Chunk Index` di dalam AAD sebelum didekripsi (misalnya mengubah byte indeks dari `0x00000001` menjadi `0x00000002`), proses dekripsi AES-GCM **wajib gagal seketika melempar `OperationError`** karena tag autentikasi GCM memproteksi data AAD.

---

### Koreksi D — Pemisahan Modifikasi AAD vs Validasi Manifest Aplikasi (§6)

Pengujian perubahan field `Plaintext Length` pada skenario manipulasi secara tegas dipisahkan menjadi dua kasus independen:

```
+-------------------------------------------------------------------------------------------------------------+
|                        PEMISAHAN PENGUJIAN PERUBAHAN PLAINTEXT LENGTH (AAD VS MANIFEST)                     |
+---------------------+---------------------------------------+-----------------------------------------------+
| Parameter           | Kasus 1 — Modifikasi AAD              | Kasus 2 — Modifikasi Manifest Aplikasi        |
+---------------------+---------------------------------------+-----------------------------------------------+
| Kondisi Pengujian   | Field PlaintextLength pada AAD diubah | PlaintextLength pada AAD asli (tidak diubah), |
|                     | saat proses dekripsi dijalankan       | namun metadata pada manifest berkas dipalsukan|
| Lapisan Kegagalan   | Lapisan Kriptografi AES-GCM           | Lapisan Logika Aplikasi (Perakitan Berkas)    |
| Galat yang Diharapkan| OperationError (Web Crypto Exception) | ConsistencyError (Manifest Length Mismatch)   |
| Karakteristik Galat | Otentikasi GCM tag gagal seketika     | Dekripsi GCM sukses, namun assembling ditolak |
+---------------------+---------------------------------------+-----------------------------------------------+
```

---

### Koreksi E — Evaluasi Kontrol Penggunaan Ulang Nonce (Pemetaan Skenario UJI-E1)

1. **Karakteristik Web Crypto API:**  
   Standar Web Crypto API W3C (`crypto.subtle.encrypt`) bersifat *stateless*; API ini **tidak menyimpan riwayat nonce masa lalu** dan **tidak secara otomatis menolak penggunaan ulang nonce**.
2. **Objek Pengujian UJI-E1:**  
   Pengujian skenario deteksi penggunaan ulang nonce (pada dokumen B-CHAT-08A.1 tercantum dengan kode **`UJI-E1`**) dirancang untuk menguji **kontrol aplikasi / *nonce-use ledger*** pada prototipe klien bimbingan skripsi UNUHA.
3. **Batas Bukti:**  
   Bukti kelulusan pengujian UJI-E1 **hanya membuktikan keandalan mekanisme pencatatan (*ledger*) di lapisan prototipe aplikasi**, bukan membuktikan bahwa Web Crypto API memiliki fitur proteksi *nonce-reuse* bawaan.

---

### Koreksi F — Staging Plaintext, Keterbatasan Best-Effort Cleanup, dan Integritas Manifest Hash (§3, §6, §7)

1. **Prinsip All-or-Nothing Assembly:**  
   Plaintext hasil dekripsi chunk **dilarang dipublikasikan sebagai berkas final** sebelum seluruh chunk ($0 \dots N-1$) lolos autentikasi dan tervalidasi terhadap manifest.
2. **Keterbatasan Manajemen Memori JavaScript (Peniadaan Klaim Secure Wipe):**  
   Jika terjadi kegagalan dekripsi pada salah satu chunk, aplikasi wajib membatalkan perakitan berkas, melepaskan referensi buffer sementara, dan menimpa buffer `Uint8Array` yang masih terjangkau dengan nilai nol (`buffer.fill(0)`).  
   Namun demikian, **diakui secara transparan keterbatasan mendasar runtime JavaScript**:
   - Engine JavaScript (V8) tidak menjamin penghapusan fisik data dari RAM.
   - Runtime dapat menduplikasi buffer secara internal saat konversi tipe, memindahkan memori saat proses *compaction*, atau menunda pembersihan memori.
   - Operasi `buffer.fill(0)` adalah tindakan mitigasi pembersihan terbaik (*best-effort cleanup*), **bukan bukti penghapusan memori absolut (*secure memory erasure*)**.
   - Dilarang keras menggunakan istilah mutlak seperti `secure wipe`, `physical wipe`, atau `plaintext dipastikan terhapus dari RAM`.
3. **Keabsahan dan Batasan Hash Manifest:**  
   Pencocokan hash berkas terhadap manifest hanya menjadi bukti integritas yang bermakna **apabila nilai hash manifest berasal dari kanal tepercaya (*trusted origin*) atau dilindungi oleh tanda tangan digital / autentikasi E2EE pesan chat**.  
   - AAD kanonikal 83-byte **tidak memproteksi hash manifest**, karena hash manifest tidak dimasukkan ke dalam komponen AAD.
   - Jika penyerang memiliki kemampuan memodifikasi ciphertext sekaligus memodifikasi manifest di server penyimpanan, pencocokan hash saja tidak membuktikan keaslian berkas.
   - Karena protokol distribusi manifest aman belum diuji pada EXP-02, jaminan keaslian berkas tingkat manifest diberi status **`DESIGN DECISION REQUIRED / UNVERIFIED`**.

---

### Koreksi G — Standar Pembuktian H1: Perbandingan Byte-by-Byte (§1.2)

1. **Metode Verifikasi Utama:**  
   Pemeriksaan bahwa plaintext hasil dekripsi identik dengan plaintext asli sebelum enkripsi wajib menggunakan **perbandingan nilai biner byte-demi-byte (*byte-by-byte direct buffer comparison*)** sebagai pembuktian utama.
2. **Kedudukan Hash SHA-256:**  
   Kalkulasi hash SHA-256 pada kedua berkas digunakan sebagai *checksum* verifikasi integritas pendukung, **bukan bukti matematis absolut**, karena secara teoretis probabilitas tabrakan hash (*hash collision*) tetap ada meskipun sangat kecil.
3. **Penggunaan Frasa Identik:**  
   Frasa *100% identik* pada dokumen ini secara ketat didefinisikan sebagai hasil **kelulusan uji komparasi byte-demi-byte** pada seluruh panjang berkas.

---

### Koreksi H — Presisi Metrik Memori Multi-Dimensi Node.js (§8 & §10)

1. **Definisi Presisi Empat Metrik Memori Node.js:**
   - `heapUsed`: Total memori heap V8 yang sedang digunakan oleh objek JavaScript aktif.
   - `external`: Memori objek C++ native yang terikat pada objek JavaScript (mencakup buffer native dan konteks Web Crypto C++ bindings).
   - `arrayBuffers`: Alokasi memori spesifik untuk `ArrayBuffer` dan `SharedArrayBuffer` (merupakan **subkategori yang berada di dalam alokasi `external`**).
   - `rss` (*Resident Set Size*): Total memori fisik (RAM) yang dipetakan oleh sistem operasi untuk keseluruhan proses Node.js (mencakup kode eksekusi, runtime V8, pustaka C++, heap, dan buffer native).
2. **Larangan Penjumlahan Redundan:**  
   Dilarang keras menjumlahkan `external + arrayBuffers` sebagai dua komponen independen, karena nilai `arrayBuffers` sudah termasuk di dalam nilai `external`.
3. **Keterbatasan Snapshot Sampling:**  
   Pencatatan memori berbasis *snapshot* (`process.memoryUsage()`) memiliki keterbatasan: lonjakan memori puncak (*transient memory peak*) yang berlangsung sangat singkat (dalam fraksi milidetik) dapat terlewat di antara jeda pemanggilan fungsi sampling.
4. **Dasar Evaluasi Ambang 20 MB pada Prototipe Node.js:**  
   Ambang konsumsi memori puncak $\le 20\text{ MB}$ berstatus **`PROPOSED`**. Pada lingkungan Node.js, dasar evaluasi ambang ini diukur secara spesifik berdasarkan **lonjakan alokasi buffer native ($\Delta\text{external}$ atau $\Delta\text{arrayBuffers}$)** selama siklus pemrosesan chunk, **bukan nilai total RSS proses**.
5. **Pemisahan dari Memori Peramban:**  
   Nilai metrik memori proses Node.js CLI tidak mewakili konsumsi memori Web Worker di peramban web nyata.

---

### Koreksi I — Batasan Diagnostik Pengecualian `OperationError` (§6)

1. **Karakteristik Pengecualian Web Crypto API:**  
   Pada antarmuka Web Crypto API W3C, kegagalan autentikasi dekripsi AES-256-GCM selalu menghasilkan pengecualian generik bertipe `OperationError` (dengan pesan standar seperti *The operation failed for an operation-specific reason*).
2. **Ketiadaan Diagnosis Rinci:**  
   API Web Crypto **tidak menyediakan informasi diagnostik spesifik** untuk membedakan apakah kegagalan tersebut dipicu oleh:
   - Ciphertext yang dimodifikasi,
   - Authentication tag yang rusak,
   - Nilai nonce yang tidak cocok, atau
   - Data AAD yang berbeda antara pengirim dan penerima.
3. **Penegasan Metodologi:**  
   Penyebab kegagalan pada pengujian integritas (UJI-D1 s.d. UJI-D5) hanya diketahui secara pasti melalui **skenario injeksi kesalahan yang dirancang pada lingkungan pengujian (*test setup*)**, bukan dari kemampuan runtime API untuk mengidentifikasi komponen yang rusak. Dilarang mengklaim bahwa `OperationError` dapat menunjukkan bagian mana yang dimodifikasi.

---

### Koreksi J — Batas Validasi Runtime Node.js vs Browser (§2, §6, §9)

Untuk menegakkan disiplin integritas bukti ilmiah, batas ruang lingkup pengujian dipisahkan secara tegas ke dalam lima lapisan arsitektur:

```
+-------------------------------------------------------------------------------------------------------------+
|                                  PEMISAHAN LAPISAN VALIDASI LINGKUNGAN PENGUJIAN                            |
+----+--------------------------------+--------------------+--------------------------------------------------+
| No | Lapisan Arsitektur Pengujian   | Status pada EXP-02 | Keterangan Batasan dan Ruang Lingkup             |
+----+--------------------------------+--------------------+--------------------------------------------------+
| 1  | Prototipe Logika di Node.js    | RENCANA EXP-02     | Validasi logika chunking, AAD serializer, framing|
| 2  | Web Crypto API pada Node.js    | RENCANA EXP-02     | Validasi AES-GCM native via globalThis.crypto    |
| 3  | Web Crypto API di Browser Nyata| TERPISAH (STANDBY) | Memerlukan mandat dan pengujian browser terpisah |
| 4  | Integrasi Pipeline Next.js 14  | TERPISAH (STANDBY) | Evaluasi Web Worker & File.stream() Next.js      |
| 5  | Integrasi Aplikasi Utama UNUHA | DI LUAR GERBANG 2  | Implementasi produksi chat bimbingan (Gerbang 3) |
+----+--------------------------------+--------------------+--------------------------------------------------+
```

EXP-02 tidak boleh diperluas secara otomatis menjadi eksperimen peramban web tanpa otorisasi terpisah. Bukti yang diperoleh dari pengujian Node.js hanya berlaku untuk lingkungan Node.js.

---

## 4. MATRIKS SKENARIO PENGUJIAN TERKOREKSI DAN PEMETAAN ID

Berikut adalah pemetaan koreksi terhadap skenario pengujian pada dokumen B-CHAT-08A.1, termasuk pemecahan skenario dan klarifikasi ID pengujian:

```
+-----------------------------------------------------------------------------------------------------------------------------------------+
|                                  PEMETAAN DAN REVISI SKENARIO PENGUJIAN EXP-02 (B-CHAT-08A.2)                                           |
+----+------------+------------+---------------------------------------+------------------------------------------------------------------+
| No | ID B-08A.1 | ID B-08A.2 | Nama Skenario Pengujian               | Deskripsi Perbaikan Teknis & Target Hasil                        |
+----+------------+------------+---------------------------------------+------------------------------------------------------------------+
| 1  | UJI-A      | UJI-A      | Siklus Enkripsi & Dekripsi Normal     | Validasi utama: Byte-by-byte match (SHA-256 checksum pendukung)  |
+----+------------+------------+---------------------------------------+------------------------------------------------------------------+
| 2  | UJI-D9     | UJI-D9A    | Modifikasi Field PlaintextLength AAD  | Nilai length AAD saat dekripsi beda -> REJECT: GCM OperationError|
| 3  | (Baru)     | UJI-D9B    | Modifikasi Manifest Panjang Berkas    | AAD asli, manifest dipalsukan -> REJECT: ManifestConsistencyError|
+----+------------+------------+---------------------------------------+------------------------------------------------------------------+
| 4  | (Baru)     | UJI-D10A   | Validasi Input Indeks Negatif (< 0)   | Indeks negatif pada input -> REJECT: TypeRangeValidationError    |
| 5  | UJI-D10    | UJI-D10B   | Validasi Chunk Index Melebihi Batas   | Indeks >= N pada AAD -> REJECT: ChunkIndexOutOfRangeError        |
| 6  | (Baru)     | UJI-D10C   | Manipulasi Byte Indeks AAD Dekripsi   | Byte ChunkIndex pada AAD diubah -> REJECT: GCM OperationError    |
+----+------------+------------+---------------------------------------+------------------------------------------------------------------+
| 7  | UJI-D8     | UJI-D8     | Transport Reordering Urutan Terbalik  | Chunk tiba urutan terbalik -> PASS: Assembled by authenticated ID|
| 8  | UJI-D12    | UJI-D12    | Transport Reordering Permutasi Acak   | Chunk tiba acak penuh -> PASS: Assembled by authenticated ID     |
+----+------------+------------+---------------------------------------+------------------------------------------------------------------+
| 9  | UJI-E1     | UJI-E1     | Deteksi Nonce Reuse via Ledger        | Menguji ledger aplikasi penolak duplikasi pasangan CEK-nonce     |
| 10 | UJI-E2     | UJI-E2     | Penanganan Kegagalan CSPRNG           | Mock exception getRandomValues -> FAIL-CLOSED (No cipher produced|
| 11 | UJI-E3     | UJI-E3     | Skenario Pengiriman Ulang (Retry)     | Mengirim ciphertext tersimpan -> PASS: Tidak enkripsi ulang      |
| 12 | UJI-E4     | UJI-E4     | Skenario Pembatalan Sesi (Crash/Fail) | Sesi gagal ditinggalkan -> Wajib CEK baru & UploadSessionID baru |
+----+------------+------------+---------------------------------------+------------------------------------------------------------------+
| 13 | UJI-D13    | UJI-D13    | Isolasi Staging Chunk Terakhir Gagal  | Chunk N-1 gagal GCM -> REJECT: Staging buffer wiped (Best-effort)|
+----+------------+------------+---------------------------------------+------------------------------------------------------------------+
| 14 | UJI-F1     | UJI-F1     | Streaming Pipeline Buffer 1 MiB       | PROPOSED: Lonjakan delta arrayBuffers/external <= 20 MB          |
| 15 | UJI-F2     | UJI-F2     | Full-Buffer Control (In-Memory)       | OBSERVATIONAL: Kontrol komparasi observasional V8 memory         |
+----+------------+------------+---------------------------------------+------------------------------------------------------------------+
```

---

## 5. KLASIFIKASI BUKTI ILMIAH TERREKONSILIASI (B-CHAT-08A.2)

Matriks kriteria keberhasilan EXP-02 diselaraskan menggunakan sistem klasifikasi formal yang disiplin:

```
+-----------------------------------------------------------------------------------------------------------------------------------------+
|                                    TABEL KLASIFIKASI KRITERIA KEBERHASILAN DAN STATUS PEMBUKTIAN                                        |
+----+-----------------------------------------------------+---------------------------+--------------------------------------------------+
| No | Parameter Aspek Evaluasi                            | Klasifikasi Status        | Dasar Penentuan dan Batasan Pembuktian           |
+----+-----------------------------------------------------+---------------------------+--------------------------------------------------+
| 1  | Keutuhan Plaintext Byte-by-Byte (UJI-A & UJI-B)     | UNVERIFIED                | Menunggu eksekusi fisik prototipe di sandbox     |
| 2  | Penolakan Kriptografis GCM terhadap 5 Tampering AAD | UNVERIFIED                | Menunggu eksekusi fisik prototipe di sandbox     |
| 3  | Toleransi Perakitan Transport Reordering (D8 & D12) | UNVERIFIED                | Menunggu eksekusi fisik prototipe di sandbox     |
| 4  | Penegakan Batas Kuota 10 MB / 25 MB / 5 Berkas      | UNVERIFIED                | Menunggu eksekusi fisik prototipe di sandbox     |
| 5  | Pencegahan Nonce Reuse via Ledger Aplikasi (UJI-E1) | UNVERIFIED                | Menguji ledger aplikasi, bukan fitur Web Crypto  |
| 6  | Mitigasi Fail-Closed Saat CSPRNG Error (UJI-E2)     | UNVERIFIED                | Menunggu eksekusi pengujian mock exception       |
| 7  | Pembersihan Staging Buffer saat GCM Gagal (UJI-D13) | UNVERIFIED                | Best-effort cleanup (bukan secure memory wipe)   |
| 8  | Integritas Hash Manifest Berkas Lampiran            | DESIGN DECISION REQUIRED  | Memerlukan mekanisme autentikasi kanal manifest  |
| 9  | Ambang Lonjakan Buffer Streaming <= 20 MB (UJI-F1)  | PROPOSED                  | Merupakan target desain usulan, bukan patokan mutlak|
| 10 | Evaluasi Memori Full-Buffer In-Memory (UJI-F2)      | OBSERVATIONAL             | Kontrol pembanding perilaku garbage collector V8 |
| 11 | Kinerja Web Crypto API pada Peramban Nyata          | UNVERIFIED                | Berada di luar ruang lingkup pengujian Node.js   |
| 12 | Ketiadaan UI Freeze / Long Task di Peramban Web     | NOT MEASURED              | Node.js CLI tidak mengukur event loop browser    |
| 13 | Status Pelaksanaan Eksperimen Fisik EXP-02          | EXP-02 NOT AUTHORIZED     | Belum diotorisasi oleh Pemilik Proyek            |
+----+-----------------------------------------------------+---------------------------+--------------------------------------------------+
```

---

## 6. KEPUTUSAN DESAIN YANG MEMERLUKAN PERSETUJUAN PEMILIK PROYEK

Sebelum eksperimen EXP-02 dapat diajukan untuk mendapatkan otorisasi pelaksanaan fisik, butir-butir keputusan teknis berikut diajukan kepada Pemilik Proyek:

```
+-----------------------------------------------------------------------------------------------------------------------------------------+
|                                  DAFTAR KEPUTUSAN TEKNIS YANG MEMERLUKAN PERSETUJUAN PEMILIK PROYEK                                     |
+----+----------------------------+---------------------------------------------+---------------------------------------------------------+
| No | Butir Keputusan Desain     | Rekomendasi Teknis yang Diajukan            | Dasar Pertimbangan & Implikasi Teknis                   |
+----+----------------------------+---------------------------------------------+---------------------------------------------------------+
| 1  | Strategi Nonce Opsi 1 &    | Menyetujui Opsi 1 dengan 5 aturan crash-    | Nonce dianggap terpakai sejak enkripsi dimulai; crash    |
|    | Aturan Crash-Safety        | safety dan ketentuan 1 CEK baru per sesi    | sebelum persistensi mewajibkan pembatalan sesi total.   |
+----+----------------------------+---------------------------------------------+---------------------------------------------------------+
| 2  | Format Pembungkusan        | Menyetujui Format A: Nonce (12 B) diprefiks | Output Web Crypto (L + 16 B) dibungkus aplikasi menjadi |
|    | (Framing) Ciphertext       | pada ciphertext chunk (Total: L + 28 B)     | (L + 28 B) untuk dekripsi mandiri per chunk.            |
+----+----------------------------+---------------------------------------------+---------------------------------------------------------+
| 3  | Mekanisme Autentikasi      | Menetapkan kanal tepercaya atau tanda tangan| AAD 83 byte tidak memproteksi manifest hash; manifest  |
|    | Hash Manifest              | E2EE untuk melindungi manifest lampiran     | memerlukan perlindungan integritas terpisah.            |
+----+----------------------------+---------------------------------------------+---------------------------------------------------------+
| 4  | Ambang Batas Memori Usulan | Menyetujui ambang PROPOSED <= 20 MB dievaluasi| Diukur dari lonjakan delta arrayBuffers/external pada    |
|    | pada Pengujian Node.js     | berdasarkan delta buffer native di Node.js  | streaming per-chunk berkas 10.000.000 byte.             |
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

Secara bertanggung jawab, batasan pembuktian eksperimen dicatat sebagai berikut:
1. **Belum Ada Eksekusi Prototipe:** Seluruh skenario pengujian (UJI-A sampai UJI-F) berstatus rencana dan **belum pernah dijalankan secara fisik**.
2. **Ketiadaan Pembuktian di Peramban Nyata:** Perilaku Web Crypto API pada mesin Chromium, Gecko, dan WebKit belum pernah diuji dalam rangkaian eksperimen ini.
3. **Ketiadaan Pengujian Integrasi Next.js 14:** Antarmuka pemuatan stream peramban (`File.stream()`) dan integrasi Web Worker pada Next.js 14 belum diuji terhadap bundler produksi.
4. **Ketiadaan Jaminan Secure Memory Erasure:** Operasi penimpaan buffer nol (`buffer.fill(0)`) pada JavaScript tidak boleh diklaim sebagai penghapusan memori fisik mutlak.
5. **Ketiadaan Pembuktian Anti-Session Hijacking:** EXP-02 hanya membuktikan pengikatan kriptografis konteks AAD, bukan keamanan sesi jaringan aplikasi secara menyeluruh.

---

## 8. PERNYATAAN INTEGRITAS ARSIP DAN KEPATUHAN BATAS OPERASIONAL

Penyusunan dokumen koreksi **B-CHAT-08A.2** diselesaikan dengan kepatuhan penuh terhadap batas mandat:

1. **Zero Prototype Creation:** Direktori sandbox `scratch/bchat_prototype/exp02_chunking_aad/` **belum dibuat**, dan tidak ada berkas kode program yang ditulis.
2. **Zero Execution, Benchmark, & Testing:** Tidak ada uji coba eksekusi, simulasi, benchmark kinerja, maupun unit test yang dijalankan.
3. **Zero Toolchain & Dependency Installation:** Tidak ada dependensi npm, toolchain Rust/WASM, atau paket sistem yang dipasang.
4. **Zero Main Codebase Changes:** Direktori backend Laravel ([`manajemen_skripsi/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/manajemen_skripsi/)) dan frontend Next.js ([`frontend_manajemen_skripsi/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/frontend_manajemen_skripsi/)) tidak disentuh sama sekali.
5. **Zero Database Changes:** Tidak ada migrasi basis data, modifikasi skema tabel, atau query database yang dijalankan.
6. **Zero Git Operations:** Tidak ada perintah Git (`git status`, `git diff`, `git commit`, `git checkout`, `git push`, dsb.) yang dijalankan.
7. **Pelestarian Arsip Historis:** Dokumen [B-CHAT-08A](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A_Rencana_dan_Mandat_EXP-02.md), [B-CHAT-08A.1](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.1_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md), dan seluruh dokumen EXP-01 ([B-CHAT-07A.1](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A_Laporan_Eksekusi_EXP-01.md) s.d. [B-CHAT-07A.7](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.7_Koreksi_Editorial_dan_Klasifikasi_Bukti_Final_EXP-01.md)) tetap tersimpan utuh di repositori tanpa diubah atau ditimpa (*zero overwrite*).

---

## 9. PERNYATAAN EKSPLISIT STATUS OTORISASI EXP-02

$$\mathbf{STATUS\ OTORISASI\ EKSEKUSI\ EXP\text{-}02:\ EXP\text{-}02\ NOT\ AUTHORIZED}$$

> [!CAUTION]
> **PERINGATAN RESMI:**  
> Dokumen **B-CHAT-08A.2** adalah dokumen koreksi teknis perencanaan (*documentation only*). Penerbitan dokumen ini **TIDAK MENGOTORISASI** eksekusi eksperimen EXP-02. Eksperimen fisik di sandbox hanya dapat dimulai setelah Pemilik Proyek memeriksa dokumen koreksi ini dan memberikan **persetujuan tertulis eksplisit terpisah**.

---
*(Dokumen B-CHAT-08A.2 selesai disusun secara paripurna sebagai koreksi teknis terbatas mandat EXP-02, siap ditinjau oleh Pemilik Proyek).*

