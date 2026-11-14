# B-CHAT-07A.4 — MANDAT KOREKSI PRESISI FORMULA RFC DAN KLASIFIKASI BUKTI EXP-01
## REKONSILIASI KONSEPTUAL SPESIFIKASI RFC 9420 §8.5, PRIMITIVE KRIPTOGRAFI, ISOLASI RUNTIME WORKER, DAN DISIPLIN BUKTI ILMIAH

**Status Dokumen:** ADENDUM KOREKSI PRESISI RESMI (*STRICTLY DOCUMENTATION ONLY*)  
**Mode Operasi:** ZERO EXECUTION — STRICTLY READ-ONLY  
**Dokumen yang Disempurnakan:** [B-CHAT-07A.3_Koreksi_Minor_Klasifikasi_Bukti.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.3_Koreksi_Minor_Klasifikasi_Bukti.md)  
**Dokumen Historis Berjenjang:**
- [B-CHAT-07A.2_Koreksi_Dokumentasi_EXP-01.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.2_Koreksi_Dokumentasi_EXP-01.md)
- [B-CHAT-07A.1_Audit_Forensik_EXP-01.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A_Laporan_Eksekusi_EXP-01.md)
- [B-CHAT-07A_Laporan_Eksekusi_EXP-01.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A_Laporan_Eksekusi_EXP-01.md)
- [B-CHAT-06D_Mandat_Eksekusi_Terbatas_Gerbang_2.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-06D_Mandat_Eksekusi_Terbatas_Gerbang_2.md) (Revisi B-CHAT-06D.2)  
**Proyek:** Sistem Informasi Manajemen Skripsi UNUHA  
**Fitur:** Bimbingan Online Chat Real-Time E2EE  
**Tahap Proyek:** B-CHAT-07A.4 (Koreksi Presisi Lanjutan EXP-01)  
**Tanggal:** 5 November 2026  

---

## 1. IDENTITAS DAN TUJUAN KOREKSI

Dokumen **B-CHAT-07A.4** diterbitkan sebagai mandat adendum koreksi presisi lanjutan atas dokumen [B-CHAT-07A.3](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.3_Koreksi_Minor_Klasifikasi_Bukti.md). Berdasarkan evaluasi teknis yang mendalam terhadap dokumen B-CHAT-07A.3 dan berkas artefak mentah EXP-01, ditemukan enam aspek yang memerlukan penajaman presisi konseptual, kriptografis, dan metodologis.

Tujuan utama penyusunan dokumen ini adalah:
1. **Memperbaiki Formula Matematis MLS-Exporter Sesuai RFC 9420 §8.5:** Menampilkan struktur formulasi formal dua tingkat ($\operatorname{DeriveSecret}$ dan $\operatorname{ExpandWithLabel}$) serta memperjelas kedudukan asal rahasia $\text{exporter\_secret}$.
2. **Membedakan Primitive Kriptografi dari Konstruksi Derivasi Kunci Simulator:** Memisahkan pemanggilan fungsi primitif `HMAC-SHA-256` bawaan runtime Node.js dari konstruksi derivasi kunci mandiri (*home-grown key derivation*) yang disimulasikan dalam skrip JavaScript.
3. **Menegaskan Batasan Nyata Runtime Worker Node.js vs Web Worker Browser:** Menyelaraskan klaim pengujian dengan bukti faktual pada kode pengujian yang menggunakan modul `worker_threads` di Node.js, bukan Web Worker peramban web.
4. **Mengkoreksi Klasifikasi Pemblokiran Antarmuka (UI Freeze):** Mengubah klasifikasi UI freeze menjadi **`NOT MEASURED`** karena lingkungan headless Node.js CLI sama sekali tidak mengukur *Long Tasks* pada antarmuka peramban.
5. **Merekonsiliasi Konsistensi Angka Estimasi Bundle:** Menyajikan estimasi ukuran biner gzip OpenMLS ($1,2\text{--}1,6\text{ MB}$) dan mls-rs ($650\text{--}900\text{ KB}$) secara konsisten dan menandai keterbatasan pembuktiannya.
6. **Menyempurnakan Agenda Pengadaan Toolchain Rust:** Mengeliminasi asumsi prematur pada versi `rustc`, `wasm-bindgen-cli`, `wasm-pack`, dan `wasm-opt`.
7. **Mempertahankan Dokumen Historis Berjenjang:** Menjaga keutuhan dokumen B-CHAT-07A.1, B-CHAT-07A.2, dan B-CHAT-07A.3 tanpa menimpa atau menghapusnya.

---

## 2. MATRIKS ENAM KOREKSI PRESISI (B-CHAT-07A.4)

Tabel berikut menyajikan ringkasan perbandingan antara substansi pada [B-CHAT-07A.3](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.3_Koreksi_Minor_Klasifikasi_Bukti.md) dan koreksi presisi resmi yang ditetapkan dalam **B-CHAT-07A.4**:

```
+-----------------------------------------------------------------------------------------------------------------------------------------+
|                                           MATRIKS KOREKSI PRESISI TEKNIS (B-CHAT-07A.4)                                                 |
+---+----------------------+----------------------------------------+---------------------------------------+-----------------------------+
| No| Aspek Koreksi        | Rumusan pada B-CHAT-07A.3              | Rumusan Presisi Resmi (B-CHAT-07A.4)  | Klasifikasi Bukti Terkoreksi|
+---+----------------------+----------------------------------------+---------------------------------------+-----------------------------+
| A | Formula MLS-Exporter | Formula satu tingkat; klaim rahasia    | Menggunakan formula dua tingkat resmi | Formula HMAC JS: SIMULATED  |
|   | & exporter_secret    | diekstrak langsung dari ratchet tree.  | RFC 9420 §8.5; exporter_secret berasal| Kepatuhan RFC §8.5:         |
|   |                      |                                        | dari MLS key schedule epoch terkait.  |   UNVERIFIED                |
|   |                      |                                        |                                       | Interoperabilitas:          |
|   |                      |                                        |                                       |   UNVERIFIED                |
+---+----------------------+----------------------------------------+---------------------------------------+-----------------------------+
| B | Primitive HMAC vs    | Disebut sebagai "tiruan fungsi hash".  | Primitive HMAC-SHA-256 adalah API riil| Primitive HMAC: VALID CALL  |
|   | Konstruksi Derivasi  | Tidak membedakan primitive dan skema.  | Node.js; konstruksi derivasi CEK buatan| Skema Derivasi CEK:         |
|   | CEK Simulator        |                                        | sendiri adalah simulasi (bukan MLS).  |   SIMULATED (BUKAN MLS)     |
+---+----------------------+----------------------------------------+---------------------------------------+-----------------------------+
| C | Batas Lingkup        | Pola Web Worker Next.js 14 diverifikasi| Pengujian empiris HANYA menggunakan   | 1. Worker Node.js: VERIFIED |
|   | Runtime Worker       | di Node.js (klaim menyentuh browser).  | modul 'worker_threads' Node.js. Web   | 2. Pesan Asinkron: VERIFIED |
|   |                      |                                        | Worker browser & 'next build' belum   | 3. Web Worker Browser:      |
|   |                      |                                        | pernah diuji.                         |    UNVERIFIED               |
|   |                      |                                        |                                       | 4. Next.js build: UNVERIFIED|
+---+----------------------+----------------------------------------+---------------------------------------+-----------------------------+
| D | Pengukuran           | Ketiadaan UI Freeze (0 ms)             | CLI Node.js tidak mengukur UI long task| UI Freeze Main-Thread:      |
|   | UI Freeze            | diklasifikasikan sebagai ESTIMATED.    | browser; metrik 0 ms adalah hardcoded |   NOT MEASURED              |
|   |                      |                                        | asumsi, bukan data pengukuran riil.   |                             |
+---+----------------------+----------------------------------------+---------------------------------------+-----------------------------+
| E | Estimasi Ukuran      | Terdapat tipografi inkonsistensi       | Diselaraskan menjadi: OpenMLS 1,2–1,6 | Estimasi Bundle:            |
|   | Bundle Biner WASM    | "1,2 MB s.d. 800 KB".                  | MB gzip; mls-rs 650–900 KB gzip.      |   ESTIMATED                 |
|   |                      |                                        | Tetap berstatus kajian literatur.     | (Belum Terverifikasi Lokal) |
+---+----------------------+----------------------------------------+---------------------------------------+-----------------------------+
| F | Agenda Toolchain     | Mengatur rustc fleksibel, tetapi       | Seluruh komponen (rustc, wasm-bindgen, | Agenda Normatif:            |
|   | Rust Masa Depan      | mengasumsikan wasm-pack dan wasm-opt   | wasm-pack, wasm-opt) wajib menunggu   | Menunggu Pemilihan Crate    |
|   |                      | mutakhir otomatis.                     | pemilihan crate definitif & MSRV.     | & Dependensi Definitif      |
+---+----------------------+----------------------------------------+---------------------------------------+-----------------------------+
```

---

## 3. PENJELASAN TEKNIS SETIAP KOREKSI

### Koreksi A — Penegasan Asal `exporter_secret` dan Hierarki Kunci MLS

Pada dokumen B-CHAT-07A.3 Bagian 2 (Koreksi 1), terdapat pernyataan bahwa $\text{exporter\_secret}$ *"diekstrak langsung dari status epoch ratcheting MLS saat ini"*. Pernyataan ini secara teknis tidak akurat dan perlu diluruskan:
1. **Asal Rahasia $\text{exporter\_secret}$:** Dalam arsitektur Message Layer Security (RFC 9420 §8), $\text{exporter\_secret}$ merupakan salah satu *epoch secret* turunan yang dihasilkan melalui **MLS Key Schedule** pada setiap transisi epoch.
2. **Peran Pohon Ratchet dan TreeKEM:** Struktur pohon biner TreeKEM dan *ratchet tree* berfungsi untuk menghasilkan rahasia pembaruan grup (*commit secret* dan *epoch secret* utama). Pohon ini berkontribusi terhadap evolusi state grup secara menyeluruh, tetapi $\text{exporter\_secret}$ **tidak diekstrak secara langsung dari simpul pohon ratchet**, melainkan diturunkan melalui rantai ekstrak-ekspansi HKDF pada *key schedule*.
3. **Kedudukan Formula:** Formula RFC 9420 §8.5 merupakan spesifikasi referensi standar IETF yang menjadi acuan desain teoritis sistem bimbingan skripsi UNUHA, **bukan bukti** bahwa simulator JavaScript pada EXP-01 telah mengimplementasikannya secara patuh.

---

### Koreksi B — Pembedaan Primitif Kriptografi HMAC dari Konstruksi Derivasi CEK

Pada dokumen B-CHAT-07A.3 baris 69, pemanggilan `crypto.createHmac('sha256')` sempat diistilahkan sebagai "tiruan fungsi hash". Hal ini menimbulkan kerancuan terminologis:
1. **Primitif Kriptografi Asli:** Pemanggilan fungsi `crypto.createHmac('sha256')` pada modul [`mls_core_simulator.js`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/scratch/bchat_prototype/exp01_mls_wasm/worker_prototype/mls_core_simulator.js#L228-L236) adalah eksekusi fungsi primitif kriptografi HMAC-SHA-256 yang sah dan nyata melalui API internal Node.js.
2. **Konstruksi Derivasi Kunci Mandiri (Simulasi):** Konstruksi yang menyatukan pemanggilan HMAC dua tingkat tersebut untuk menghasilkan Kunci Enkripsi Konten (*Content Encryption Key* / CEK) adalah skema buatan sendiri (*ad-hoc simulation*) yang dirancang di JavaScript.
3. **Batas Bukti:** Keberhasilan eksekusi HMAC di lingkungan Node.js **sama sekali tidak membuktikan** implementasi primitif resmi MLS seperti $\operatorname{DeriveSecret}$, $\operatorname{ExpandWithLabel}$, pembentukan *secret tree*, maupun fungsi $\operatorname{MLS\text{-}Exporter}$ standar RFC 9420. Dilarang mengklaim fungsi primitif HMAC runtime sebagai implementasi protokol MLS.

---

### Koreksi C — Batasan Nyata Pengujian Worker (Node.js `worker_threads` vs Browser Web Worker)

Penelusuran terhadap berkas kode sumber pengujian empiris membuktikan bahwa:
1. Skrip pengujian [`test_runner.js`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/scratch/bchat_prototype/exp01_mls_wasm/worker_prototype/test_runner.js#L5) mengimpor Worker melalui:
   ```javascript
   import { Worker } from 'worker_threads';
   ```
   dan berkas target [`mls.worker.js`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/scratch/bchat_prototype/exp01_mls_wasm/worker_prototype/mls.worker.js#L4) mengimpor:
   ```javascript
   import { parentPort } from 'worker_threads';
   ```
2. **Klasifikasi Pembuktian:**
   - Mekanisme thread worker yang benar-benar diuji adalah **Worker Node.js (`worker_threads`)**, berstatus **`VERIFIED`**.
   - Protokol komunikasi pesan asinkron yang benar-benar dieksekusi antara thread induk dan worker adalah `parentPort.postMessage` / `worker.on('message')`, berstatus **`VERIFIED`**.
   - Pola kode sintaksis ES Module Web Worker yang ditulis pada [`mls_interface.ts`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/scratch/bchat_prototype/exp01_mls_wasm/worker_prototype/mls_interface.ts#L53-L56) berstatus **`VERIFIED` sebatas telaah sintaksis kode**.
   - Web Worker peramban web (*browser runtime*), integrasi rendering Next.js SSR, serta kelulusan bundler produksi Next.js 14 (`next build` via Webpack/Turbopack) berstatus **`UNVERIFIED`** karena belum pernah diuji secara fisik.

---

### Koreksi D — Reklasifikasi Metrik UI Freeze Menjadi `NOT MEASURED`

Pada laporan awal EXP-01 dan ringkasan metrik [`EXP01_summary.json`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/scratch/bchat_prototype/reports/EXP01_summary.json#L194), angka `observedMaxMs: 0` pada aspek UI Freeze dicantumkan dengan komentar kode:
```javascript
observedMaxMs: 0, // completely offloaded to worker thread
```
Fakta ini menunjukkan bahwa angka $0\text{ ms}$ bukanlah hasil pengukuran instrumen pengujian, melainkan asumsi teoritis *off-main-thread*:
1. Lingkungan eksekusi Node.js CLI bersifat *headless* dan tidak memiliki subsistem perenderan grafis (*rendering engine*), antarmuka DOM (*Document Object Model*), maupun *browser main-thread event loop*.
2. Node.js CLI tidak dapat mengukur pemblokiran antarmuka pengguna (*browser long tasks* $> 50\text{ ms}$).
3. Pengukuran alokasi heap V8 Node.js ($8,63\text{ MB}$) tidak merepresentasikan konsumsi memori Web Worker di lingkungan peramban.
4. Oleh karena itu, metrik ketiadaan pemblokiran antarmuka (UI Freeze) secara resmi diklasifikasikan sebagai **`NOT MEASURED`**. Angka $0\text{ ms}$ pada pengujian CLI tidak boleh dipresentasikan sebagai bukti empiris bahwa peramban tidak akan mengalami freeze.

---

### Koreksi E — Rekonsiliasi Konsistensi Angka Estimasi Ukuran Bundle

Untuk menghilangkan inkonsistensi tipografi dan menegaskan batasan data pada B-CHAT-07A.3:
1. Angka estimasi teoritis ukuran biner terkompresi gzip yang sah dan bersumber dari kajian literatur upstream rujukan Gerbang 2 (B-CHAT-06D §2) adalah:
   - **Kandidat OpenMLS:** estimasi biner terkompresi sekitar **$1,2\text{--}1,6\text{ MB}$ gzip**.
   - **Kandidat mls-rs:** estimasi biner terkompresi sekitar **$650\text{--}900\text{ KB}$ gzip**.
2. **Batasan Bukti:**
   - Kedua angka di atas tetap berstatus **`ESTIMATED`**.
   - Angka tersebut **bukan** hasil pengukuran kompilasi lokal dan **bukan** ukuran bundel biner final.
   - Karena keterbatasan ketiadaan compiler Rust di workstation pengembang lokal saat ini, angka estimasi upstream tersebut belum dapat diverifikasi atau dibuktikan secara independen. Tidak ada pengunduhan paket dependensi eksternal yang diizinkan selama tahap ini.

---

### Koreksi F — Penyempurnaan Rencana Kerja Toolchain Rust

Agenda pengadaan toolchain Rust pada tahap masa depan disempurnakan dengan lima prinsip disiplin:
1. **Versi `rustc` Fleksibel Sesuai Crate:** Versi compiler Rust tidak boleh dipatok pada angka arbitrer ($\ge \text{1.75.0}$). Versi compiler wajib mengikuti *Minimum Supported Rust Version* (MSRV) resmi dari crate MLS yang nantinya dipilih serta kestabilan target `wasm32-unknown-unknown`.
2. **Sinkronisasi Wajib `wasm-bindgen-cli`:** Versi utilitas baris perintah `wasm-bindgen-cli` harus diselaraskan secara eksak dengan versi pustaka `wasm-bindgen` yang dideklarasikan pada *crate wrapper* Rust lokal untuk mencegah inkompatibilitas skema antarmuka WebAssembly.
3. **Pemilihan `wasm-pack` yang Terverifikasi:** Versi `wasm-pack` tidak boleh diasumsikan sebagai "versi mutakhir otomatis", melainkan harus dievaluasi kompatibilitasnya terhadap versi `rustc` dan crate yang digunakan.
4. **Alat Optimasi `wasm-opt` Terpisah:** Alat optimasi ukuran biner `wasm-opt` (bagian dari *Binaryen*) merupakan utilitas terpisah. Keberadaan biner, versi, dan kecocokannya terhadap instruksi target WebAssembly harus diverifikasi secara khusus ketika eksperimen kompilasi diotorisasi.
5. **Peniadaan Toolchain Definitif Prematur:** Tidak ada pemasangan atau penguncian konfigurasi toolchain yang dilakukan sebelum pemilihan crate kandidat definitif disetujui secara tertulis oleh Pemilik Proyek.

---

## 4. FORMULA PRESISI MLS-EXPORTER (RFC 9420 §8.5)

Berdasarkan spesifikasi resmi IETF RFC 9420 Bagian 8.5 (*Key Schedule: Exporter*), formula presisi fungsi eksportir kunci didefinisikan sebagai konstruksi dua tahap:

$$\text{secret} = \operatorname{DeriveSecret}(\text{exporter\_secret}, \text{Label})$$

$$\operatorname{MLS\text{-}Exporter}(\text{Label}, \text{Context}, \text{Length}) = \operatorname{ExpandWithLabel}(\text{secret}, \text{"exported"}, \operatorname{Hash}(\text{Context}), \text{Length})$$

Atau dalam bentuk konseptual gabungan:

\[
\begin{aligned}
&\operatorname{MLS\text{-}Exporter}(\text{Label},\text{Context},\text{Length})\\
&=\operatorname{ExpandWithLabel}(
\operatorname{DeriveSecret}(\text{exporter\_secret},\text{Label}),\\
&\qquad\qquad\quad \text{"exported"},\operatorname{Hash}(\text{Context}),\text{Length})
\end{aligned}
\]

### Rincian Komponen Kriptografis:
1. **$\text{exporter\_secret}$:** Kunci rahasia master tingkat epoch yang diturunkan melalui rantai ekstrak-ekspansi *MLS Key Schedule* (RFC 9420 §8) untuk epoch grup yang aktif. Rahasia ini **bukan** rahasia yang diekstrak langsung dari simpul *ratchet tree*.
2. **$\operatorname{DeriveSecret}(\text{Secret}, \text{Label})$:** Fungsi penurunan rahasia internal MLS berbasis HKDF-Expand-Label:
   $$\operatorname{DeriveSecret}(\text{Secret}, \text{Label}) = \operatorname{ExpandWithLabel}(\text{Secret}, \text{Label}, \text{""}, \text{Hash.length})$$
3. **$\text{"exported"}$:** String literal label baku yang diamanatkan oleh RFC 9420 §8.5 sebagai pemisah domain kontekstual eksportir.
4. **$\operatorname{Hash}(\text{Context})$:** Digest kriptografis dari konteks kanonikal yang diberikan. Untuk enkripsi lampiran bimbingan skripsi UNUHA, konteks kanonikal berukuran 83 byte dibentuk melalui konkatena:
   $$\text{Context} = \text{RoomUUID}\ (36\text{ B}) \parallel \text{MessageUUID}\ (36\text{ B}) \parallel \text{AttachmentUUID}\ (11\text{ B})$$
5. **$\text{Length}$:** Panjang kunci luaran yang diminta dalam satuan byte (misalnya $32\text{ byte}$ untuk Content Encryption Key / CEK berbasis AES-256-GCM).

> [!NOTE]
> **STATUS BUKTI DAN ATRIBUSI:**  
> - Formula matematis di atas merupakan **spesifikasi referensi konseptual** RFC 9420 §8.5.
> - Formula HMAC JavaScript yang dijalankan pada [`mls_core_simulator.js`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/scratch/bchat_prototype/exp01_mls_wasm/worker_prototype/mls_core_simulator.js) berstatus **`SIMULATED`** (hanya tiruan simulasi mandiri).
> - Kepatuhan implementasi simulator terhadap fungsi penuh $\operatorname{MLS\text{-}Exporter}$ RFC 9420 §8.5 berstatus **`UNVERIFIED`**.
> - Interoperabilitas luaran kunci terhadap implementasi biner resmi OpenMLS / mls-rs dan *Official Test Vectors* IETF berstatus **`UNVERIFIED`**.

---

## 5. TABEL KLASIFIKASI BUKTI ILMIAH EXP-01 TERREKONSILIASI (FINAL)

Berikut adalah tabel klasifikasi bukti ilmiah yang telah direkonsiliasi penuh secara faktual, menggantikan seluruh matriks pembuktian sebelumnya:

```
+-----------------------------------------------------------------------------------------------------------------------------------------+
|                                    TABEL KLASIFIKASI BUKTI ILMIAH TERREKONSILIASI (B-CHAT-07A.4)                                        |
+----+-----------------------------------------------------+------------------+-------------------------------------------------------+
| No | Parameter Aspek yang Dievaluasi                     | Status Bukti     | Catatan Faktual dan Batasan Pengujian                 |
+----+-----------------------------------------------------+------------------+-------------------------------------------------------+
| 1  | Mekanisme Worker Node.js ('worker_threads')         | VERIFIED         | Terbukti dieksekusi via modul node:worker_threads     |
| 2  | Komunikasi Pesan Asinkron (postMessage / onmessage) | VERIFIED         | Terbukti saling bertukar pesan antar thread Node.js   |
| 3  | Pemuatan & Instansiasi Modul WebAssembly di Worker  | VERIFIED         | WebAssembly.compile & instantiate stub 52 byte sukses |
| 4  | Pola Kode Sintaksis ES Module Web Worker            | VERIFIED         | Pola sintaksis kode pada mls_interface.ts valid       |
+----+-----------------------------------------------------+------------------+-------------------------------------------------------+
| 5  | Pemanggilan Primitif Kriptografi HMAC-SHA-256       | VERIFIED CALL    | crypto.createHmac benar-benar dipanggil di Node.js    |
| 6  | Konstruksi Derivasi Kunci CEK Simulator             | SIMULATED        | Skema derivasi mandiri di JS, BUKAN algoritma MLS     |
| 7  | Simulasi Transisi State Siklus Hidup Grup (1:1)     | SIMULATED        | Transisi state array JS di mls_core_simulator.js      |
| 8  | Modul Biner mls_wasm_crypto.wasm                    | SIMULATED        | Stub biner 52 byte (operasi XOR), bukan engine MLS    |
| 9  | Latensi Siklus Hidup Simulator (1,05 ms)            | SIMULATED        | Mengukur kecepatan eksekusi runtime V8 JavaScript     |
| 10 | Alokasi Memori Heap Process Node.js (8,63 MB)       | SIMULATED        | Mengukur alokasi heap process V8, bukan memori Worker |
+----+-----------------------------------------------------+------------------+-------------------------------------------------------+
| 11 | Estimasi Ukuran Bundle OpenMLS (1,2–1,6 MB gzip)    | ESTIMATED        | Estimasi teoritis dari literatur upstream crate       |
| 12 | Estimasi Ukuran Bundle mls-rs (650–900 KB gzip)     | ESTIMATED        | Estimasi teoritis dari literatur upstream crate       |
+----+-----------------------------------------------------+------------------+-------------------------------------------------------+
| 13 | Pengukuran Pemblokiran Antarmuka (UI Freeze)        | NOT MEASURED     | Headless Node.js CLI tidak mengukur long task browser |
+----+-----------------------------------------------------+------------------+-------------------------------------------------------+
| 14 | Kepatuhan Penuh Terhadap Spesifikasi RFC 9420 §8.5  | UNVERIFIED       | Implementasi ExpandWithLabel exporter_secret belum ada|
| 15 | Interoperabilitas dengan Implementasi MLS Resmi     | UNVERIFIED       | Belum pernah diuji silang terhadap Test Vector IETF   |
| 16 | Implementasi Pohon Kriptografi TreeKEM & Ratchet    | UNVERIFIED       | Tidak ada evaluasi terhadap struktur pohon MLS asli   |
| 17 | Kompilasi & Eksekusi Crate Asli OpenMLS             | UNVERIFIED       | Tidak pernah dikompilasi ke WASM di lingkungan lokal  |
| 18 | Kompilasi & Eksekusi Crate Asli mls-rs              | UNVERIFIED       | Tidak pernah dikompilasi ke WASM di lingkungan lokal  |
| 19 | Kompatibilitas Bundler Next.js 14 ('next build')    | UNVERIFIED       | Belum pernah diuji terhadap Webpack 5 atau Turbopack  |
| 20 | Integrasi Web Worker pada Peramban Nyata (Browser)  | UNVERIFIED       | Belum pernah dijalankan pada browser engine apapun    |
+----+-----------------------------------------------------+------------------+-------------------------------------------------------+
| 21 | Klaim "Modul WASM 72 Byte adalah Bundle Engine MLS" | INVALID          | Bertentangan dengan kode fisik generate_wasm.js       |
| 22 | Klaim "UI Freeze 0 ms Membuktikan Browser Tidak Lag"| INVALID          | Asumsi tidak berdasar tanpa instrumen browser nyata   |
+----+-----------------------------------------------------+------------------+-------------------------------------------------------+
```

---

## 6. AGENDA TOOLCHAIN RUST YANG TELAH DIKOREKSI

Sebagai panduan teknis yang presisi untuk tahap implementasi validasi biner masa depan (apabila kelak diotorisasi tertulis oleh Pemilik Proyek), agenda toolchain Rust disempurnakan menjadi:

```
+-----------------------------------------------------------------------------------------------------------------------------------------+
|                                    AGENDA NORMATIF PERSIAPAN TOOLCHAIN RUST (MASA DEPAN)                                                |
+----+----------------------------+-------------------------------------------------------------------------------------------------------+
| No | Komponen Toolchain         | Ketentuan dan Prinsip Penyelarasan Teknis                                                             |
+----+----------------------------+-------------------------------------------------------------------------------------------------------+
| 1  | Kompiler Rust (rustc) &    | Versi rustc WAJIB mengikuti MSRV (Minimum Supported Rust Version) resmi dari crate terpilih           |
|    | Manajer Paket (cargo)      | (OpenMLS atau mls-rs) dan memiliki dukungan stabil terhadap target 'wasm32-unknown-unknown'.          |
|    |                            | Tidak boleh dipatok secara kaku sebelum rilis crate diputuskan.                                       |
+----+----------------------------+-------------------------------------------------------------------------------------------------------+
| 2  | Target WebAssembly         | Wajib menambahkan target resmi: 'rustup target add wasm32-unknown-unknown'.                           |
+----+----------------------------+-------------------------------------------------------------------------------------------------------+
| 3  | Utilitas Binding Baris     | Versi 'wasm-bindgen-cli' WAJIB identik secara eksak dengan versi crate 'wasm-bindgen' yang didefinisikan|
|    | Perintah (wasm-bindgen-cli)| di dalam berkas Cargo.toml pembungkus (wrapper) untuk mencegah kegagalan ABI biner.                   |
+----+----------------------------+-------------------------------------------------------------------------------------------------------+
| 4  | Pembangun Paket            | Versi 'wasm-pack' tidak boleh ditetapkan sebagai rilis mutakhir otomatis, melainkan harus diverifikasi|
|    | (wasm-pack)                | kecocokannya terhadap versi rustc, cargo, dan crate dependensi yang digunakan.                        |
+----+----------------------------+-------------------------------------------------------------------------------------------------------+
| 5  | Pengoptimal Ukuran Biner   | 'wasm-opt' (bagian dari Binaryen) merupakan utilitas terpisah. Keberadaan dan kompatibilitasnya       |
|    | (wasm-opt)                 | terhadap fitur instruksi WebAssembly (misalnya sign-extension, mutable-globals) wajib divalidasi.     |
+----+----------------------------+-------------------------------------------------------------------------------------------------------+
| 6  | Larangan Penguncian Dini   | DILARANG menetapkan versi toolchain definitif sebelum crate dan arsitektur pembungkus dipilih secara   |
|    | (No Early Lock-in)         | resmi melalui keputusan Gerbang 2 / Gerbang 3.                                                        |
+----+----------------------------+-------------------------------------------------------------------------------------------------------+
```

---

## 7. DAFTAR KETERBATASAN DAN TEMUAN YANG BELUM DAPAT DIVERIFIKASI

Secara transparan dan bertanggung jawab, aspek-aspek teknis berikut dicatat sebagai keterbatasan yang **belum dapat diverifikasi** dari bukti fisik yang tersedia saat ini:
1. **Asumsi Ukuran Bundel Biner Upstream:**  
   Angka estimasi $1,2\text{--}1,6\text{ MB}$ (OpenMLS) dan $650\text{--}900\text{ KB}$ (mls-rs) berasal dari klaim literatur upstream dan analisis statis konfigurasi `Cargo.toml`. Karena toolchain Rust belum terpasang di workstation pengembang lokal, ukuran biner riil setelah kompilasi `wasm32-unknown-unknown` dan optimasi `wasm-opt -Oz` belum dapat diverifikasi secara faktual.
2. **Kesesuaian dengan Bundler Produksi Next.js 14:**  
   Pemuatan dinamis worker menggunakan `new URL('./mls.worker.js', import.meta.url)` baru diuji secara statis pada berkas antarmuka TypeScript. Apakah mekanisme ini dapat dipaketkan (*bundled*) tanpa galat oleh Webpack 5 atau Turbopack pada perintah `next build` belum dapat dipastikan.
3. **Perilaku Memori dan Threading pada Peramban Nyata:**  
   Pengujian telemetri yang tersedia sepenuhnya berjalan di atas mesin virtual Node.js v25. Perilaku isolasi memori linear WebAssembly (`WebAssembly.Memory`), alokasi memori peramban (Chrome V8 vs Firefox SpiderMonkey vs Safari JavaScriptCore), dan ketiadaan *Long Tasks* pada antarmuka pengguna belum pernah diuji pada peramban web nyata.
4. **Interoperabilitas Kunci Eksportir MLS:**  
   Keluaran kunci CEK 256-bit hasil derivasi simulator belum pernah diuji interoperabilitasnya dengan implementasi MLS pihak ketiga maupun divalidasi terhadap *Official Test Vectors* IETF RFC 9420.

---

## 8. PERNYATAAN INTEGRITAS ARSIP DAN KEPATUHAN BATAS OPERASIONAL

Penyusunan dokumen koreksi presisi **B-CHAT-07A.4** dilaksanakan dengan kepatuhan mutlak terhadap prinsip keselamatan sistem:

1. **Pelestarian Arsip Historis Berjenjang:** Dokumen [B-CHAT-07A.1](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A_Laporan_Eksekusi_EXP-01.md), [B-CHAT-07A.2](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.2_Koreksi_Dokumentasi_EXP-01.md), dan [B-CHAT-07A.3](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.3_Koreksi_Minor_Klasifikasi_Bukti.md) tetap dipertahankan apa adanya di repositori, tidak diubah, dan tidak ditimpa (*no overwrite*).
2. **Zero Re-execution:** Eksperimen EXP-01 sama sekali tidak dijalankan ulang.
3. **Zero Next Experiments:** Eksperimen lanjutan EXP-02, EXP-03, EXP-04, dan EXP-05 **tidak dimulai**.
4. **Zero Compilation, Benchmark, & Testing:** Tidak ada kode sumber yang dikompilasi, tidak ada benchmark yang dijalankan, dan tidak ada unit test yang dieksekusi.
5. **Zero Toolchain & Dependency Installation:** Tidak ada instalasi Rust, Cargo, wasm-pack, paket npm, atau paket sistem operasi lainnya.
6. **Zero Code Changes:** Berkas skrip simulator, stub biner WebAssembly, berkas pengujian, maupun berkas artefak mentah di [`scratch/bchat_prototype/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/scratch/bchat_prototype/) tidak diubah atau disentuh.
7. **Zero Application & Database Changes:** Kode aplikasi Laravel backend ([`manajemen_skripsi/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/manajemen_skripsi/)), frontend Next.js ([`frontend_manajemen_skripsi/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/frontend_manajemen_skripsi/)), skema basis data, dan data tabel tidak disentuh. Tidak ada migrasi basis data yang dijalankan.
8. **Zero Git Operations:** Sesuai mandat larangan operasional mutlak, **tidak ada satu pun perintah Git** (`git status`, `git diff`, `git commit`, `git checkout`, `git push`, dsb.) yang dijalankan selama pelaksanaan tugas ini.

---

## 9. KESIMPULAN STATUS EXP-01

Berdasarkan seluruh rangkaian audit forensik, rekonsiliasi klaim, penyempurnaan minor, serta koreksi presisi B-CHAT-07A.4, status resmi eksperimen EXP-01 secara final ditegaskan:

$$\mathbf{STATUS\ FINAL\ EXP\text{-}01:\ PARTIALLY\ SUCCESSFUL}$$

> [!CAUTION]
> **KESIMPULAN TEKNIS AKHIR:**  
> Dokumen **B-CHAT-07A.4** merupakan addendum koreksi dokumentasi, bukan laporan eksperimen baru dan bukan bukti tambahan hasil pengujian.  
> Eksperimen EXP-01 berhasil membuktikan mekanisme pengujian Worker Node.js (`worker_threads`), pengiriman pesan asinkron, dan instansiasi modul WebAssembly minimal (stub 52 byte).  
> Namun demikian, **EXP-01 SECARA TEGAS BELUM MEMBUKTIKAN**:
> 1. Implementasi biner Rust-WASM MLS asli (`OpenMLS` atau `mls-rs`).
> 2. Protokol kriptografi TreeKEM RFC 9420.
> 3. Kepatuhan implementasi fungsi derivasi terhadap spesifikasi $\operatorname{MLS\text{-}Exporter}$ RFC 9420 §8.5.
> 4. Interoperabilitas kriptografis terhadap implementasi MLS resmi.
> 5. Kinerja, kestabilan memori, dan ketiadaan pemblokiran antarmuka (*UI freeze*) pada peramban web nyata (*real browser runtime*).
> 6. Kompatibilitas bundler produksi Next.js 14 (`next build`).

---
*(Dokumen B-CHAT-07A.4 selesai disusun dan disahkan sebagai addendum koreksi presisi RFC dan klasifikasi bukti ilmiah EXP-01).*

