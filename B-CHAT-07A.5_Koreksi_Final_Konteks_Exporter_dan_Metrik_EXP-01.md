# B-CHAT-07A.5 — KOREKSI FINAL KONTEKS MLS-EXPORTER DAN KLASIFIKASI METRIK EXP-01
## PENETAPAN FORMAL ASAL EKSPO-SEKRET, STRUKTUR KONTEKS 48-BYTE, PEMISAHAN AAD 83-BYTE, DAN DISIPLIN METRIK RUNTIME

**Status Dokumen:** ADENDUM KOREKSI FINAL RESMI (*STRICTLY DOCUMENTATION ONLY*)  
**Mode Operasi:** ZERO EXECUTION — STRICTLY READ-ONLY  
**Dokumen Acuan:** [B-CHAT-07A.4_Koreksi_Presisi_RFC_dan_Klasifikasi_Bukti_EXP-01.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.4_Koreksi_Presisi_RFC_dan_Klasifikasi_Bukti_EXP-01.md)  
**Dokumen Historis Berjenjang (Dipertahankan Utuh Tanpa Overwrite):**
- [B-CHAT-07A.3_Koreksi_Minor_Klasifikasi_Bukti.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.3_Koreksi_Minor_Klasifikasi_Bukti.md)
- [B-CHAT-07A.2_Koreksi_Dokumentasi_EXP-01.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.2_Koreksi_Dokumentasi_EXP-01.md)
- [B-CHAT-07A.1_Audit_Forensik_EXP-01.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A_Laporan_Eksekusi_EXP-01.md)
- [B-CHAT-07A_Laporan_Eksekusi_EXP-01.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A_Laporan_Eksekusi_EXP-01.md)
- [B-CHAT-06D_Mandat_Eksekusi_Terbatas_Gerbang_2.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-06D_Mandat_Eksekusi_Terbatas_Gerbang_2.md) (Revisi B-CHAT-06D.2)  
**Proyek:** Sistem Informasi Manajemen Skripsi UNUHA  
**Fitur:** Bimbingan Online Chat Real-Time E2EE  
**Tahap Proyek:** B-CHAT-07A.5 (Koreksi Final Konteks Exporter & Metrik EXP-01)  
**Tanggal:** 5 November 2026  

---

## 1. IDENTITAS DAN TUJUAN KOREKSI

Dokumen **B-CHAT-07A.5** diterbitkan sebagai adendum penyempurnaan final atas dokumen [B-CHAT-07A.4](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.4_Koreksi_Presisi_RFC_dan_Klasifikasi_Bukti_EXP-01.md). Berdasarkan evaluasi teknis tingkat lanjut terhadap formulasi kriptografis dan matriks bukti EXP-01, dokumen ini menetapkan empat klarifikasi dan koreksi esensial:

1. **Menetapkan Hubungan Derivasi Eksplisit $\text{exporter\_secret}$:** Menuliskan secara presisi rantai penurunan rahasia $\text{exporter\_secret} = \operatorname{DeriveSecret}(\text{epoch\_secret}, \text{"exporter"})$ sesuai RFC 9420 §8, serta menegaskan bahwa rahasia ini bersumber dari *MLS Key Schedule*, bukan diambil langsung dari simpul *ratchet tree*.
2. **Mengoreksi Representasi dan Panjang Konteks MLS-Exporter:** Meluruskan representasi tiga UUID pengikat menjadi representasi biner mentah 16-byte ($16 + 16 + 16 = 48\text{ byte}$), serta menegaskan pemisahan mutlak antara **Konteks Exporter (48 byte)** dan **AAD Lampiran Kanonikal (83 byte)**.
3. **Memperjelas Klasifikasi Bukti Web Worker:** Membedakan secara tegas telaah statis kode TypeScript (`STATICALLY REVIEWED`) dari eksekusi nyata thread Node.js (`VERIFIED`), serta menetapkan status `UNVERIFIED` untuk peramban web (*browser runtime*) dan proses bundler produksi Next.js 14 (`next build`).
4. **Menyempurnakan Klasifikasi Metrik Runtime:** Membedakan angka pengukuran yang benar-benar diperoleh dari simulator JavaScript (`MEASURED — JS SIMULATOR`) dan alokasi proses Node.js (`MEASURED — NODE.JS PROCESS`) dari implementasi biner MLS asli dan memori Web Worker peramban (`UNVERIFIED`), serta menetapkan UI freeze sebagai **`NOT MEASURED`**.
5. **Menjaga Integritas Arsip Historis:** Dokumen B-CHAT-07A.1 sampai B-CHAT-07A.4 tetap dilestarikan apa adanya sebagai catatan audit berjenjang tanpa penimpaan (*zero overwrite*).

---

## 2. MATRIKS EMPAT KOREKSI WAJIB (B-CHAT-07A.5)

Tabel berikut merangkum empat perbaikan utama yang ditetapkan dalam dokumen **B-CHAT-07A.5**:

```
+-----------------------------------------------------------------------------------------------------------------------------------------+
|                                           MATRIKS KOREKSI TEKNIS FINAL (B-CHAT-07A.5)                                                   |
+---+----------------------+----------------------------------------+---------------------------------------+-----------------------------+
| No| Aspek Koreksi        | Deskripsi pada Dokumen Sebelumnya      | Koreksi Final Resmi (B-CHAT-07A.5)    | Klasifikasi Bukti Terkoreksi|
+---+----------------------+----------------------------------------+---------------------------------------+-----------------------------+
| A | Asal Derivasi        | Disebut berasal dari key schedule,     | Dituliskan hubungan derivasi formal:  | Formula RFC 9420 §8.5:      |
|   | exporter_secret      | tetapi belum mencantumkan formula      | exporter_secret =                     |   SPESIFIKASI REFERENSI     |
|   |                      | derivasi epoch_secret secara eksplisit.|   DeriveSecret(epoch_secret,"exporter")| (Implementasi Simulator:    |
|   |                      |                                        | Diturunkan via Key Schedule RFC 9420. |   SIMULATED / UNVERIFIED)   |
+---+----------------------+----------------------------------------+---------------------------------------+-----------------------------+
| B | Representasi Konteks | Disebut Context = RoomUUID (36 B) ||   | Setiap UUID direpresentasikan sebagai | Konteks Exporter: 48 Byte   |
|   | Exporter & Pemisahan | MessageUUID (36 B) || AttachmentUUID   | 16 byte biner mentah (Total = 48 B).  | AAD Lampiran: 83 Byte       |
|   | AAD Kanonikal        | (11 B) = 83 B (rancu dengan AAD).      | Terpisah mutlak dari AAD 83 byte.     | (STRUKTUR BERBEDA MUTLAK)   |
+---+----------------------+----------------------------------------+---------------------------------------+-----------------------------+
| C | Klasifikasi Bukti    | Pola sintaksis ES Module Web Worker    | Telaah pola kode pada mls_interface.ts| 1. Pola Kode: STATICALLY    |
|   | Web Worker Modular   | pada mls_interface.ts dilabeli         | diberi status STATICALLY REVIEWED.    |    REVIEWED                 |
|   |                      | VERIFIED (berpotensi bias browser).    | Eksekusi Node.js worker_threads tetap | 2. Worker Node.js: VERIFIED |
|   |                      |                                        | VERIFIED. Browser: UNVERIFIED.        | 3. Browser Worker:          |
|   |                      |                                        |                                       |    UNVERIFIED               |
+---+----------------------+----------------------------------------+---------------------------------------+-----------------------------+
| D | Klasifikasi Metrik   | Latensi 1,05 ms & Heap 8,63 MB         | Dipisahkan objek pengukurannya:       | 1. Latensi: MEASURED —      |
|   | Runtime Pengujian    | dilabeli SIMULATED (menutupi bahwa ada | Latensi berasal dari simulator JS,    |    JS SIMULATOR             |
|   |                      | pengukuran runtime riil terhadap JS).  | Heap berasal dari proses V8 Node.js.  | 2. Heap: MEASURED —         |
|   |                      |                                        | Bukan performa MLS biner / browser.   |    NODE.JS PROCESS          |
+---+----------------------+----------------------------------------+---------------------------------------+-----------------------------+
```

---

## 3. PENJELASAN TEKNIS MASING-MASING KOREKSI

### Koreksi A — Hubungan Derivasi Eksplisit $\text{exporter\_secret}$

Dalam spesifikasi Message Layer Security (RFC 9420 Bagian 8 mengenai *Key Schedule*), posisi rahasia eksportir diatur secara hierarkis:
1. **Derivasi dari $\text{epoch\_secret}$:** Pada setiap transisi epoch (baik akibat penambahan anggota, pembaruan kunci *commit*, maupun penghapusan anggota), grup MLS menghasilkan rahasia epoch utama ($\text{epoch\_secret}$). Dari rahasia inilah seluruh rahasia operasional diturunkan, termasuk $\text{exporter\_secret}$:
   $$\text{exporter\_secret} = \operatorname{DeriveSecret}(\text{epoch\_secret}, \text{"exporter"})$$
2. **Pembedaan Pohon Ratchet vs Key Schedule:** Mekanisme pohon biner TreeKEM dan pohon ratchet (*ratchet tree*) memproses kunci publik anggota dan pembaharuan jalur langsung (*direct path*) untuk menghasilkan rahasia pembaruan bersama (*commit secret*). *Commit secret* tersebut kemudian dimasukkan ke dalam fungsi ekstrak HKDF untuk memperbarui $\text{epoch\_secret}$. Oleh karena itu, $\text{exporter\_secret}$ **tidak diambil langsung dari simpul pohon ratchet**, melainkan dihasilkan pada tahap turunan *Key Schedule*.
3. **Penegasan Spesifikasi Referensi:** Formula MLS-Exporter RFC 9420 §8.5 yang tercantum pada dokumen ini adalah **spesifikasi referensi baku**, bukan bukti bahwa simulator JavaScript telah mengimplementasikan rantai KDF pohon rahasia RFC 9420 secara lengkap.

---

### Koreksi B — Representasi dan Panjang Konteks Exporter (48 Byte vs 83 Byte)

Terdapat koreksi mendasar terhadap Bagian 4 dokumen B-CHAT-07A.4 yang menyamakan formulasi konteks dengan ukuran AAD:
1. **Representasi Biner Mentah Tiga UUID (48 Byte):**  
   Dalam rancangan kriptografis yang aman dan efisien, pengikat konteks eksportir yang terdiri dari tiga UUID ditransmisikan dalam representasi **biner mentah 16-byte (RFC 4122 / 128-bit)**, bukan teks string ASCII/UTF-8 36-karakter:
   - $\text{RoomUUID}$: 16 byte biner.
   - $\text{MessageUUID}$: 16 byte biner.
   - $\text{AttachmentUUID}$: 16 byte biner.
   
   Dengan demikian, panjang total konteks eksportir adalah:
   $$\text{Context Length} = 16\text{ byte} + 16\text{ byte} + 16\text{ byte} = 48\text{ byte}$$

2. **Pemisahan Tegas Konteks Exporter dari AAD Lampiran Kanonikal (83 Byte):**  
   Harus ditegaskan bahwa **Konteks MLS-Exporter** dan **AAD Lampiran Kanonikal** adalah dua entitas kriptografis yang terpisah secara struktur, fungsi, dan ukuran:
   - **Konteks MLS-Exporter ($48\text{ byte}$):** Digunakan secara eksklusif sebagai parameter $\text{Context}$ pada fungsi eksportir MLS untuk menurunkan Kunci Enkripsi Konten (*Content Encryption Key* / CEK) 256-bit yang terikat unik pada satu lampiran tertentu.
   - **AAD Lampiran Kanonikal ($83\text{ byte}$):** Merupakan data autentikasi tambahan (*Additional Authenticated Data*) yang diumpankan ke algoritma AEAD AES-256-GCM pada saat enkripsi/dekripsi chunk berkas untuk memproteksi metadata file (UUID lampiran, ukuran berkas, tipe MIME, hash master plaintext, dsb.) terhadap manipulasi di server penyimpanan perantara (*untrusted storage*).
   - **Ketentuan Desain:** Spesifikasi AAD 83 byte yang telah disahkan pada B-CHAT-05, B-CHAT-06C.1, dan B-CHAT-06D **tetap dipertahankan secara utuh dan tidak diubah**. Seluruh AAD tidak dijadikan konteks exporter karena keduanya berada pada domain kriptografi yang berbeda.

---

### Koreksi C — Perjelas Klasifikasi Bukti Web Worker

Pada dokumen sebelumnya, penelaahan pola kode Next.js 14 Web Worker berpotensi disalahartikan sebagai verifikasi runtime peramban:
1. **`STATICALLY REVIEWED` untuk Kode TypeScript:** Pemeriksaan pola kode pada berkas [`mls_interface.ts`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/scratch/bchat_prototype/exp01_mls_wasm/worker_prototype/mls_interface.ts) (pola `new Worker(new URL('./mls.worker.js', import.meta.url))` dan pelindung SSR `typeof window !== 'undefined'`) berstatus **`STATICALLY REVIEWED`**. Pemeriksaan statis kode membuktikan keabsahan sintaksis dan pola desain, tetapi **tidak membuktikan** eksekusi di lingkungan peramban.
2. **`VERIFIED` untuk Node.js `worker_threads`:** Keberhasilan instansiasi thread dan pengiriman pesan asinkron (`postMessage` / `onmessage`) yang benar-benar dieksekusi pada pengujian [`test_runner.js`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/scratch/bchat_prototype/exp01_mls_wasm/worker_prototype/test_runner.js) adalah **eksekusi modul `node:worker_threads`**.
3. **`UNVERIFIED` untuk Lingkungan Browser dan Bundler Next.js 14:** Eksekusi pada Web Worker peramban web nyata (*real browser runtime*), integrasi rendering Next.js SSR, serta kelulusan proses pemaketan produksi (`next build` via Webpack/Turbopack) berstatus **`UNVERIFIED`**.

---

### Koreksi D — Reklasifikasi Presisi Metrik Runtime Pengujian

Untuk membedakan secara adil antara ketersediaan data pengukuran empiris dengan validitas pembuktian protokol MLS:
1. **Latensi Siklus Hidup ($1,05\text{ ms}$):** Diklasifikasikan sebagai **`MEASURED — JS SIMULATOR`**. Angka $1,05\text{ ms}$ benar-benar diperoleh dari pengukuran runtime `performance.now()` selama 50 iterasi pengujian di [`test_runner.js`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/scratch/bchat_prototype/reports/EXP01_raw_measurements.json), namun objek yang diukur adalah fungsi transisi state JavaScript pada simulator V8, bukan performa eksekusi biner Rust-WASM MLS.
2. **Konsumsi Memori Heap ($8,63\text{ MB}$):** Diklasifikasikan sebagai **`MEASURED — NODE.JS PROCESS`**. Angka $8,63\text{ MB}$ benar-benar tercatat melalui API `process.memoryUsage().heapUsed` pada Node.js Worker, namun objek yang diukur adalah alokasi memori proses runtime Node.js V8, bukan memori linear WebAssembly atau konsumsi Web Worker peramban.
3. **Ketiadaan Pengukuran UI Freeze Browser:** Diklasifikasikan secara tegas sebagai **`NOT MEASURED`** karena CLI headless Node.js tidak memiliki subsistem grafis DOM untuk mengukur *browser long task*.
4. **Performa Implementasi MLS Asli & Memori Browser Worker:** Berstatus **`UNVERIFIED`** karena pustaka biner belum dikompilasi dan belum dijalankan pada peramban nyata.

---

## 4. FORMULA DAN STRUKTUR KONTEKS EXPORTER TERKOREKSI

Berdasarkan koreksi di atas, konstruksi formal derivasi Kunci Enkripsi Konten (CEK) untuk fitur lampiran bimbingan skripsi UNUHA ditetapkan sebagai berikut:

### 1. Rantai Derivasi Kunci Sesuai RFC 9420 (§8 & §8.5)

Tahap 1 — Penurunan Rahasia Eksportir dari Kunci Epoch:
$$\text{exporter\_secret} = \operatorname{DeriveSecret}(\text{epoch\_secret}, \text{"exporter"})$$

Tahap 2 — Penurunan Rahasia Berlabel (*Labeled Secret*):
$$\text{secret} = \operatorname{DeriveSecret}(\text{exporter\_secret}, \text{Label})$$

Tahap 3 — Ekspansi Kunci Eksportir dengan Konteks:
$$\operatorname{MLS\text{-}Exporter}(\text{Label}, \text{Context}, \text{Length}) = \operatorname{ExpandWithLabel}(\text{secret}, \text{"exported"}, \operatorname{Hash}(\text{Context}), \text{Length})$$

Bentuk Konseptual Gabungan:
\[
\begin{aligned}
&\operatorname{MLS\text{-}Exporter}(\text{Label},\text{Context},\text{Length})\\
&=\operatorname{ExpandWithLabel}(
\operatorname{DeriveSecret}(\operatorname{DeriveSecret}(\text{epoch\_secret}, \text{"exporter"}),\text{Label}),\\
&\qquad\qquad\quad \text{"exported"},\operatorname{Hash}(\text{Context}),\text{Length})
\end{aligned}
\]

### 2. Struktur Konteks MLS-Exporter ($48\text{ Byte}$)

Untuk derivasi CEK lampiran bimbingan skripsi, parameter $\text{Label} = \text{"bchat\_attachment\_cek"}$, panjang luaran $\text{Length} = 32\text{ byte}$ (AES-256), dan $\text{Context}$ dibentuk secara kanonikal:

$$\text{Context} = \text{RoomUUID}\ (16\text{ B}) \parallel \text{MessageUUID}\ (16\text{ B}) \parallel \text{AttachmentUUID}\ (16\text{ B})$$

$$\text{Panjang Konteks} = 16 + 16 + 16 = 48\text{ byte}$$

Setiap UUID berada dalam bentuk biner mentah (*raw 128-bit big-endian binary*).

### 3. Matriks Komparasi Konteks Exporter vs AAD Lampiran Kanonikal

```
+-------------------------------------------------------------------------------------------------------------+
|                     KOMPARASI TEKNIS KONTEKS MLS-EXPORTER VS AAD LAMPIRAN KANONIKAL                         |
+---------------------+---------------------------------------+-----------------------------------------------+
| Parameter           | Konteks MLS-Exporter                  | AAD Lampiran Kanonikal (Proyek UNUHA)         |
+---------------------+---------------------------------------+-----------------------------------------------+
| Tujuan Kriptografis | Pengikat konteks derivasi kunci CEK   | Autentikasi integritas metadata lampiran AEAD |
| Lapisan Protokol    | Lapisan MLS Key Schedule (RFC §8.5)   | Lapisan Enkripsi Payload AEAD (AES-256-GCM)   |
| Ukuran Data         | 48 Byte (Tepat)                       | 83 Byte (Tepat)                               |
| Struktur Rinci      | - RoomUUID: 16 B (biner mentah)       | - Header Version: 1 B                         |
|                     | - MessageUUID: 16 B (biner mentah)    | - RoomUUID: 16 B (biner mentah)               |
|                     | - AttachmentUUID: 16 B (biner mentah) | - MessageUUID: 16 B (biner mentah)            |
|                     |                                       | - AttachmentUUID: 16 B (biner mentah)         |
|                     |                                       | - MIME Type Enum: 2 B                         |
|                     |                                       | - Original File Size: 8 B (uint64)            |
|                     |                                       | - SHA-256 Plaintext Hash: 24 B (trunc/prefix) |
| Algoritma Penerima  | Hash(Context) -> HKDF-Expand-Label    | Associated Data -> AES-256-GCM Auth Tag       |
| Status Dokumen      | Ditetapkan pada B-CHAT-07A.5          | Ditetapkan pada B-CHAT-05 & B-CHAT-06D.2      |
+---------------------+---------------------------------------+-----------------------------------------------+
```

---

## 5. TABEL KLASIFIKASI BUKTI ILMIAH EXP-01 DIPERBARUI (FINAL B-CHAT-07A.5)

Tabel berikut menyajikan pembaruan klasifikasi bukti ilmiah yang telah disempurnakan secara presisi:

```
+-----------------------------------------------------------------------------------------------------------------------------------------+
|                                  TABEL KLASIFIKASI BUKTI ILMIAH TERREKONSILIASI (B-CHAT-07A.5)                                          |
+----+-----------------------------------------------------+---------------------------+--------------------------------------------------+
| No | Parameter Aspek yang Dievaluasi                     | Status Klasifikasi Bukti  | Catatan Faktual dan Batasan Pembuktian           |
+----+-----------------------------------------------------+---------------------------+--------------------------------------------------+
| 1  | Mekanisme Worker Node.js ('worker_threads')         | VERIFIED                  | Terbukti dieksekusi via modul node:worker_threads|
| 2  | Komunikasi Pesan Asinkron (postMessage/onmessage)   | VERIFIED                  | Terbukti saling bertukar pesan antar thread Node |
| 3  | Pemuatan & Instansiasi Modul WebAssembly di Worker  | VERIFIED                  | WebAssembly.compile & instantiate stub 52 B sukses|
| 4  | Pemanggilan Primitif Kriptografi HMAC-SHA-256       | VERIFIED CALL             | crypto.createHmac benar-benar dipanggil di Node  |
+----+-----------------------------------------------------+---------------------------+--------------------------------------------------+
| 5  | Pemeriksaan Pola Sintaksis Kode pada mls_interface  | STATICALLY REVIEWED       | Pola kode valid, belum membuktikan runtime browser|
+----+-----------------------------------------------------+---------------------------+--------------------------------------------------+
| 6  | Latensi Siklus Hidup Transisi State (1,05 ms)       | MEASURED — JS SIMULATOR   | Terukur nyata di runtime V8 simulator JavaScript |
| 7  | Alokasi Memori Heap Node.js Worker (8,63 MB)        | MEASURED — NODE.JS PROCESS| Terukur nyata via process.memoryUsage() V8 Node  |
+----+-----------------------------------------------------+---------------------------+--------------------------------------------------+
| 8  | Konstruksi Derivasi Kunci CEK Simulator             | SIMULATED                 | Skema derivasi buatan sendiri, BUKAN algoritma MLS|
| 9  | Simulasi Transisi State Siklus Hidup Grup (1:1)     | SIMULATED                 | State machine array JS di mls_core_simulator.js  |
| 10 | Modul Biner mls_wasm_crypto.wasm                    | SIMULATED                 | Stub biner 52 byte (operasi XOR), bukan engine MLS|
+----+-----------------------------------------------------+---------------------------+--------------------------------------------------+
| 11 | Estimasi Ukuran Bundle OpenMLS (1,2–1,6 MB gzip)    | ESTIMATED                 | Estimasi teoritis literatur upstream crate       |
| 12 | Estimasi Ukuran Bundle mls-rs (650–900 KB gzip)     | ESTIMATED                 | Estimasi teoritis literatur upstream crate       |
+----+-----------------------------------------------------+---------------------------+--------------------------------------------------+
| 13 | Pengukuran Pemblokiran Antarmuka (UI Freeze Browser)| NOT MEASURED              | Headless Node.js CLI tidak mengukur long task DOM|
+----+-----------------------------------------------------+---------------------------+--------------------------------------------------+
| 14 | Kepatuhan Penuh Terhadap Spesifikasi RFC 9420 §8.5  | UNVERIFIED                | Rantai ExpandWithLabel exporter_secret belum ada |
| 15 | Interoperabilitas dengan Implementasi MLS Resmi     | UNVERIFIED                | Belum pernah diuji silang terhadap Test Vector   |
| 16 | Implementasi Pohon Kriptografi TreeKEM & Ratchet    | UNVERIFIED                | Tidak ada evaluasi terhadap pohon MLS biner asli |
| 17 | Performa Implementasi Biner MLS Rust-WASM Asli      | UNVERIFIED                | Crate OpenMLS & mls-rs belum pernah dikompilasi  |
| 18 | Konsumsi Memori Linear Web Worker Browser           | UNVERIFIED                | Belum pernah dijalankan pada browser engine apapun|
| 19 | Kompatibilitas Bundler Next.js 14 ('next build')    | UNVERIFIED                | Belum pernah diuji terhadap Webpack 5/Turbopack  |
| 20 | Integrasi Web Worker pada Peramban Nyata (Browser)  | UNVERIFIED                | Belum pernah dieksekusi di browser runtime nyata |
+----+-----------------------------------------------------+---------------------------+--------------------------------------------------+
| 21 | Klaim "Modul WASM 72 Byte adalah Bundle Engine MLS" | INVALID                   | Bertentangan dengan kode fisik generate_wasm.js  |
| 22 | Klaim "UI Freeze 0 ms Membuktikan Browser Tidak Lag"| INVALID                   | Nilai hardcoded tanpa instrumen pengukur peramban|
+----+-----------------------------------------------------+---------------------------+--------------------------------------------------+
```

---

## 6. PERNYATAAN PELESTARIAN ARSIP HISTORIS

Dokumen **B-CHAT-07A.5** merupakan adendum koreksi final dokumentasi. Dokumen-dokumen historis berikut:
- [`B-CHAT-07A_Laporan_Eksekusi_EXP-01.md`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A_Laporan_Eksekusi_EXP-01.md)
- [`B-CHAT-07A.1_Audit_Forensik_EXP-01.md`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A_Laporan_Eksekusi_EXP-01.md)
- [`B-CHAT-07A.2_Koreksi_Dokumentasi_EXP-01.md`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.2_Koreksi_Dokumentasi_EXP-01.md)
- [`B-CHAT-07A.3_Koreksi_Minor_Klasifikasi_Bukti.md`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.3_Koreksi_Minor_Klasifikasi_Bukti.md)
- [`B-CHAT-07A.4_Koreksi_Presisi_RFC_dan_Klasifikasi_Bukti_EXP-01.md`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.4_Koreksi_Presisi_RFC_dan_Klasifikasi_Bukti_EXP-01.md)

**tetap dipertahankan seutuhnya di repositori proyek tanpa diubah, ditimpa, atau dihapus**. Dokumen-dokumen tersebut berfungsi sebagai rekonsiliasi berjenjang yang merekam seluruh evolusi ketelitian analisis ilmiah dan forensik proyek Bimbingan Chat E2EE UNUHA.

---

## 7. PERNYATAAN KEPATUHAN BATAS OPERASIONAL

Penyusunan dokumen B-CHAT-07A.5 diselesaikan dengan kepatuhan penuh terhadap batas operasional mutlak:

1. **Zero Re-execution:** Eksperimen EXP-01 sama sekali tidak dijalankan ulang.
2. **Zero Next Experiments:** Eksperimen lanjutan EXP-02 sampai EXP-05 tidak dimulai.
3. **Zero Compilation, Benchmark, & Testing:** Tidak ada kode sumber yang dikompilasi, tidak ada benchmark yang dijalankan, dan tidak ada unit test yang dieksekusi.
4. **Zero Toolchain & Dependency Installation:** Tidak ada instalasi Rust, Cargo, wasm-pack, paket npm, atau paket dependensi baru.
5. **Zero File Changes to Prototypes:** Kode simulator, skrip pengujian, stub WASM, dan berkas telemetri mentah di [`scratch/bchat_prototype/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/scratch/bchat_prototype/) tidak dimodifikasi.
6. **Zero Application & Database Changes:** Kode aplikasi backend Laravel ([`manajemen_skripsi/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/manajemen_skripsi/)), frontend Next.js ([`frontend_manajemen_skripsi/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/frontend_manajemen_skripsi/)), skema database, dan data tabel tidak disentuh.
7. **Zero Git Operations:** Tidak ada satu pun perintah Git (`git status`, `git diff`, `git commit`, `git checkout`, `git push`, dsb.) yang dijalankan.

---

## 8. KESIMPULAN STATUS EXP-01

Berdasarkan seluruh hasil audit forensik, rekonsiliasi, penyempurnaan, serta koreksi final B-CHAT-07A.5, status resmi eksperimen EXP-01 secara final ditegaskan:

$$\mathbf{STATUS\ RESMI\ EXP\text{-}01:\ PARTIALLY\ SUCCESSFUL}$$

> [!CAUTION]
> **KESIMPULAN AKHIR:**  
> Dokumen **B-CHAT-07A.5** merupakan adendum koreksi final dokumentasi, bukan eksperimen baru dan bukan bukti tambahan keberhasilan implementasi MLS asli.  
> Eksperimen EXP-01 berhasil memvalidasi isolasi pengujian Node.js Worker (`worker_threads`), pengiriman pesan asinkron, dan instansiasi modul WebAssembly dasar (stub 52 byte).  
> Namun demikian, **EXP-01 SECARA TEGAS BELUM MEMBUKTIKAN**:
> 1. Implementasi biner Rust-WASM MLS asli (`OpenMLS` atau `mls-rs`).
> 2. Protokol kriptografi TreeKEM RFC 9420.
> 3. Kepatuhan implementasi fungsi derivasi terhadap spesifikasi penuh $\operatorname{MLS\text{-}Exporter}$ RFC 9420 §8.5.
> 4. Interoperabilitas kriptografis terhadap implementasi MLS resmi.
> 5. Kinerja, konsumsi memori, dan ketiadaan UI freeze pada peramban web nyata (*real browser*).
> 6. Kompatibilitas terhadap bundler produksi Next.js 14 (`next build`).

---
*(Dokumen B-CHAT-07A.5 selesai disusun dan disahkan sebagai koreksi final konteks exporter dan klasifikasi metrik EXP-01).*

