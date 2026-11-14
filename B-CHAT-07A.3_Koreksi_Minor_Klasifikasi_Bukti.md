# B-CHAT-07A.3 — KOREKSI MINOR KLASIFIKASI BUKTI EXP-01
## PENYEMPURNAAN KLASIFIKASI BUKTI ILMIAH, BATASAN PROTOKOL MLS, DAN PERSYARATAN TOOLCHAIN RUST

**Status Dokumen:** DOKUMEN KOREKSI MINOR RESMI (*ADDENDUM & REFINEMENT*)  
**Mode Operasi:** STRICTLY DOCUMENTATION ONLY — ZERO EXECUTION  
**Dasar Evaluasi:** Laporan Forensik [B-CHAT-07A.1](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A_Laporan_Eksekusi_EXP-01.md) dan Rekonsiliasi [B-CHAT-07A.2](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.2_Koreksi_Dokumentasi_EXP-01.md)  
**Dokumen Historis yang Dirujuk:** [B-CHAT-07A.2_Koreksi_Dokumentasi_EXP-01.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.2_Koreksi_Dokumentasi_EXP-01.md) *(Dipertahankan utuh sebagai arsip historis)*  
**Dokumen Mandat Induk:** [B-CHAT-06D_Mandat_Eksekusi_Terbatas_Gerbang_2.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-06D_Mandat_Eksekusi_Terbatas_Gerbang_2.md) (Revisi B-CHAT-06D.2)  
**Proyek:** Sistem Informasi Manajemen Skripsi UNUHA  
**Fitur:** Bimbingan Online Chat Real-Time E2EE  
**Tahap Proyek:** B-CHAT-07A.3 (Penyempurnaan Minor Dokumentasi EXP-01)  
**Tanggal:** 5 November 2026  

---

## 1. LATAR BELAKANG DAN TUJUAN PENYEMPURNAAN

Dokumen **B-CHAT-07A.3** disusun sebagai adendum penyempurnaan terbatas (*minor refinement*) atas dokumen [B-CHAT-07A.2](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.2_Koreksi_Dokumentasi_EXP-01.md). Berdasarkan evaluasi ketat lanjutan terhadap integritas bukti ilmiah eksperimen **EXP-01**, ditemukan beberapa butir klasifikasi pada B-CHAT-07A.2 yang masih perlu dipertajam agar tidak menimbulkan ambiguitas penafsiran teknis.

Tujuan penyempurnaan minor ini adalah:
1. **Memperjelas Status Formula MLS Exporter:** Memisahkan secara tegas antara derivasi HMAC berbasis pustaka bawaan JavaScript yang berhasil disimulasikan dengan kepatuhan penuh terhadap spesifikasi formal IETF RFC 9420 §8.5 serta interoperabilitasnya.
2. **Mengoreksi Terminologi Siklus Hidup MLS:** Mengganti klaim validasi siklus hidup MLS dengan simulasi transisi state, serta menegaskan ketiadaan pembuktian protokol TreeKEM.
3. **Membatasi Batasan Bukti Web Worker:** Membatasi label `VERIFIED` hanya pada pola kode sintaksis dan pengiriman pesan worker yang nyata-nyata diuji di runtime Node.js, serta menegaskan status `UNVERIFIED` untuk lingkungan build produksi Next.js 14 dan peramban web nyata.
4. **Mencegah Penguncian Versi Toolchain Rust Secara Prematur:** Menghapus penetapan kaku versi Rust ($\ge \text{1.75.0}$) dan menggantinya dengan rujukan *Minimum Supported Rust Version* (MSRV) serta kompatibilitas target WebAssembly (`wasm32-unknown-unknown`) dari crate yang nantinya dipilih.
5. **Mempertahankan Dokumen Historis:** Dokumen [B-CHAT-07A.2](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.2_Koreksi_Dokumentasi_EXP-01.md) tetap dipertahankan sebagai arsip historis audit, tanpa ditimpa atau dihapus.

---

## 2. EMPAT KOREKSI MINOR WAJIB

Berikut adalah rincian empat aspek teknis yang disempurnakan, disertai referensi bagian dokumen [B-CHAT-07A.2](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.2_Koreksi_Dokumentasi_EXP-01.md) yang dikoreksi dan justifikasi teknisnya:

```
+-----------------------------------------------------------------------------------------------------------------------------------------+
|                                        MATRIKS PERUBAHAN DAN REKLASIFIKASI BUKTI (B-CHAT-07A.3)                                         |
+----+----------------------------+-----------------------------------+------------------------------------+------------------------------+
| No | Parameter                  | Status / Bunyi di B-CHAT-07A.2    | Penyempurnaan Resmi B-CHAT-07A.3   | Klasifikasi Bukti Terkoreksi |
+----+----------------------------+-----------------------------------+------------------------------------+------------------------------+
| 1  | Formula & Fungsi           | "Formulasi Konteks Kanonikal      | Derivasi HMAC JavaScript diuji     | 1. Derivasi JS: SIMULATED    |
|    | MLS Exporter               | Exporter §8.5: VERIFIED           | sebagai tiruan logika (SIMULATED). | 2. Kepatuhan RFC: UNVERIFIED |
|    | (RFC 9420 §8.5)            | (Bagian 2.1.d, Bagian 4 No. 4,    | Kepatuhan penuh RFC §8.5 dan       | 3. Interoperabilitas:        |
|    |                            | Bagian 4 No. 6)"                  | interoperabilitas MLS resmi belum  |    UNVERIFIED                |
|    |                            |                                   | pernah dibuktikan (UNVERIFIED).    |                              |
+----+----------------------------+-----------------------------------+------------------------------------+------------------------------+
| 2  | Siklus Hidup Grup          | "Pemodelan logika siklus hidup    | Klaim validasi lifecycle DIGANTI   | Logika Transisi State:       |
|    | (Lifecycle) MLS            | grup privat 1:1 terbukti konsisten| menjadi SIMULASI TRANSISI STATE.   | SIMULATED                    |
|    |                            | (VERIFIED) (Bagian 2.1.c,         | Tidak membuktikan TreeKEM maupun   | (TreeKEM & Protokol MLS:     |
|    |                            | Bagian 4 No. 3)"                  | protokol MLS asli.                 | UNVERIFIED)                  |
+----+----------------------------+-----------------------------------+------------------------------------+------------------------------+
| 3  | Web Worker Modular         | "Pola Worker Next.js 14 & SSR-    | VERIFIED dibatasi ketat pada pola  | 1. Sintaksis Worker: VERIFIED|
|    | Next.js 14                 | Safety: VERIFIED                  | kode & pesan di Node.js runtime.   | 2. Next build: UNVERIFIED    |
|    |                            | (Bagian 2.1.a, Bagian 4 No. 1)"   | Build Next.js 14 produksi dan      | 3. Browser runtime:          |
|    |                            |                                   | peramban nyata: UNVERIFIED.        |    UNVERIFIED                |
+----+----------------------------+-----------------------------------+------------------------------------+------------------------------+
| 4  | Persyaratan Toolchain      | "Memasang rustc (>= 1.75.0),      | Menghapus batasan kaku >= 1.75.0.  | Penyesuaian Agenda Teknis:   |
|    | Rust untuk Validasi Asli   | cargo, dan wasm-pack              | Wajib mengikuti MSRV dan target    | Berbasis MSRV Crate          |
|    |                            | (Bagian 7 No. 1)"                 | WASM dari crate yang dipilih.      | (Bukan Versi Arbitrer)       |
+----+----------------------------+-----------------------------------+------------------------------------+------------------------------+
```

---

### Koreksi 1 — Formula dan Derivasi Kunci MLS Exporter (RFC 9420 §8.5)

* **Bagian B-CHAT-07A.2 yang Dikoreksi:**  
  - Bagian 2 (Poin 1.d): Klaim awal *"Formula matematis derivasi Kunci Enkripsi Konten (CEK) menggunakan MLS-Exporter (RFC 9420 §8.5) ... terbukti deterministik dan valid (VERIFIED)"*.
  - Bagian 4 (Tabel No. 4 & No. 6): Pembagian antara konteks kanonikal (`VERIFIED`) dan derivasi HMAC (`SIMULATED`).
* **Uraian Penyempurnaan:**  
  1. **Derivasi HMAC JavaScript:** Dinyatakan secara tegas berstatus **`SIMULATED`**. Fungsi derivasi pada berkas [`mls_core_simulator.js`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/scratch/bchat_prototype/exp01_mls_wasm/worker_prototype/mls_core_simulator.js#L218-L243) mengimplementasikan tiruan fungsi hash menggunakan Node.js `crypto.createHmac('sha256')`, bukan derivasi hierarki pohon kunci (*key schedule secret tree*) bawaan engine MLS.
  2. **Kesesuaian Penuh Spesifikasi RFC 9420 §8.5:** Berstatus **`UNVERIFIED`**. Standar RFC 9420 §8.5 mensyaratkan kalkulasi:
     $$\text{MLS-Exporter}(\text{label}, \text{context}, \text{key\_length}) = \text{ExpandWithLabel}(\text{exporter\_secret}, \text{label}, \text{Hash}(\text{context}), \text{key\_length})$$
     di mana $\text{exporter\_secret}$ diekstrak langsung dari status epoch ratcheting MLS saat ini. Simulator JavaScript tidak memiliki struktur $\text{exporter\_secret}$ ratcheting tree riil, sehingga formula ini belum terverifikasi secara formal sesuai spesifikasi RFC 9420.
  3. **Interoperabilitas Kriptografis dengan Implementasi MLS Resmi:** Berstatus **`UNVERIFIED`**. Nilai kunci CEK 256-bit hasil derivasi belum pernah diuji silang (*cross-validated*) terhadap *Official Test Vectors* IETF RFC 9420 maupun pustaka biner OpenMLS / mls-rs resmi.

---

### Koreksi 2 — Siklus Hidup Grup MLS (Lifecycle) dan Ratcheting Tree

* **Bagian B-CHAT-07A.2 yang Dikoreksi:**  
  - Bagian 2 (Poin 1.c): Klaim awal *"Pemodelan logika siklus hidup grup privat 1:1 ... terbukti konsisten dan bebas dari anomali transisi state (VERIFIED)"*.
  - Bagian 4 (Tabel No. 3): Baris *"Logika Alur Transisi State MLS 1:1 (VERIFIED)"*.
* **Uraian Penyempurnaan:**  
  1. **Penggantian Istilah Klaim:** Seluruh klaim "validasi siklus hidup MLS" secara resmi diganti menjadi **`KLAIM SIMULASI TRANSISI STATE`** berstatus **`SIMULATED`**.
  2. **Ketiadaan Pohon Ratcheting TreeKEM:** Ditegaskan bahwa keberhasilan simulasi alur pada [`mls_core_simulator.js`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/scratch/bchat_prototype/exp01_mls_wasm/worker_prototype/mls_core_simulator.js) hanyalah keberhasilan transisi variabel state berbasis JavaScript (*mock state transition* seperti inkrementasi integer epoch dan manipulasi array objek anggota).
  3. **Penegasan Batasan Protokol:** Hasil eksperimen simulator sama sekali **TIDAK MEMBUKTIKAN**:
     - Struktur pohon biner TreeKEM (*Left-Balanced Binary Tree*).
     - Mekanisme enkripsi node path (*direct path resolution*).
     - Pembuatan dan pemrosesan pesan *MLS Proposal* dan *MLS Commit*.
     - Sifat keamanan *Forward Secrecy* (FS) dan *Post-Compromise Security* (PCS) dari protokol MLS asli.

---

### Koreksi 3 — Batasan Bukti Web Worker Modular Next.js 14

* **Bagian B-CHAT-07A.2 yang Dikoreksi:**  
  - Bagian 2 (Poin 1.a): Klaim awal *"Pola pemuatan Web Worker modular Next.js 14 ... terbukti valid secara sintaksis dan mampu mengisolasi eksekusi dari thread utama (VERIFIED)"*.
  - Bagian 4 (Tabel No. 1): Baris *"Pola Worker Module Next.js 14 & SSR-Safety (VERIFIED)"*.
* **Uraian Penyempurnaan:**  
  1. **Pembatasan Ketat Status VERIFIED:** Label `VERIFIED` dibatasi secara ketat **HANYA** pada aspek berikut yang nyata-nyata dieksekusi selama pengujian:
     - Pola kode sintaksis ES Module Web Worker pada lingkungan Node.js CLI runtime.
     - Penanganan isolasi pesan asinkron melalui antarmuka pesan (`postMessage` / `onmessage`) di tingkat modul JavaScript.
  2. **Penegasan Status UNVERIFIED pada Bundler dan Runtime Produksi:**
     - **Kompatibilitas `next build` Produksi:** Berstatus **`UNVERIFIED`**. Pengujian terhadap bundler Webpack 5 atau Turbopack pada Next.js 14 belum dilakukan; pemuatan worker dinamis melalui URL `new URL('./mls.worker.js', import.meta.url)` dapat memicu kendala resolusi aset saat proses bundling produksi.
     - **Eksekusi pada Peramban Nyata (*Real Browser Runtime*):** Berstatus **`UNVERIFIED`**. Pengujian belum pernah dieksekusi pada peramban web (Chrome, Firefox, Safari). Perilaku thread pool peramban, pembatasan memori Worker, serta ketiadaan *Long Task* / *UI freeze* belum dibuktikan secara empiris di lingkungan browser.

---

### Koreksi 4 — Persyaratan Toolchain Rust untuk Validasi Masa Depan

* **Bagian B-CHAT-07A.2 yang Dikoreksi:**  
  - Bagian 7 (Tabel Agenda Pekerjaan Tersisa, Baris No. 1): *"Memasang rustc (>= 1.75.0), cargo, dan wasm-pack pada workstation pengembang..."*.
* **Uraian Penyempurnaan:**  
  1. **Peniadaan Syarat Kaku Versi Arbitrer:** Pernyataan patokan kaku `rustc >= 1.75.0` dihapus. Menetapkan versi Rust tertentu secara sepihak sebelum menetapkan versi crate definitif merupakan kekeliruan metodologis.
  2. **Ketentuan Toolchain Berbasis MSRV:** Persyaratan toolchain Rust wajib ditentukan berdasarkan:
     - **Minimum Supported Rust Version (MSRV)** resmi yang didefinisikan dalam berkas `Cargo.toml` dari versi crate terpilih (baik `openmls` maupun `mls-rs`).
     - Kematangan dan stabilitas dukungan target kompilasi WebAssembly (`wasm32-unknown-unknown`) pada versi MSRV tersebut.
     - Keselarasan versi antara utilitas perantara (`wasm-bindgen`, `wasm-bindgen-cli`, dan `wasm-pack`) agar tidak terjadi ketidakcocokan ABI (*Application Binary Interface*) biner WASM.

---

## 3. TABEL KLASIFIKASI BUKTI ILMIAH TERREKONSILIASI (B-CHAT-07A.3)

Tabel berikut menyajikan pembaruan klasifikasi bukti ilmiah secara komprehensif, menggantikan interpretasi pada Bagian 4 B-CHAT-07A.2:

```
+-----------------------------------------------------------------------------------------------------------------------------------------+
|                                  TABEL KLASIFIKASI BUKTI ILMIAH TERREKONSILIASI (FINAL EXP-01)                                          |
+----+-----------------------------------------------------+------------------+-------------------------------------------------------+
| No | Parameter Aspek yang Dievaluasi                     | Status Bukti     | Catatan Teknis & Lingkup Pembuktian Faktual           |
+----+-----------------------------------------------------+------------------+-------------------------------------------------------+
| 1  | Pola Sintaksis ES Module Web Worker di Node.js      | VERIFIED         | Terbukti dieksekusi tanpa error di Node.js v25        |
| 2  | Mekanisme Komunikasi Pesan Asinkron (Event Message) | VERIFIED         | Terbukti via protokol postMessage/onmessage Worker    |
| 3  | Instansiasi Modul WebAssembly Minimal di Worker     | VERIFIED         | WebAssembly.compile & instantiate stub 52 byte sukses |
+----+-----------------------------------------------------+------------------+-------------------------------------------------------+
| 4  | Simulasi Transisi State Siklus Hidup Grup (1:1)     | SIMULATED        | Berjalan di mls_core_simulator.js (State Machine JS)  |
| 5  | Derivasi Kunci HMAC JavaScript                      | SIMULATED        | crypto.createHmac tiruan, bukan secret tree MLS       |
| 6  | Modul Biner mls_wasm_crypto.wasm                    | SIMULATED        | Stub biner 52 byte buatan manual (operasi XOR)        |
| 7  | Latensi Siklus Hidup Transisi State (1,05 ms)       | SIMULATED        | Mengukur kecepatan eksekusi runtime V8 JavaScript     |
| 8  | Alokasi Memori Heap Worker (8,63 MB)                | SIMULATED        | Mengukur alokasi heap process Node.js V8              |
+----+-----------------------------------------------------+------------------+-------------------------------------------------------+
| 9  | Estimasi Ukuran Bundle OpenMLS & mls-rs             | ESTIMATED        | Estimasi teoritis upstream (1,2 MB s.d. 800 KB gzip)  |
| 10 | Estimasi Ketiadaan UI Freeze di Thread Utama        | ESTIMATED        | Inferensi arsitektural CLI headless, bukan di browser |
+----+-----------------------------------------------------+------------------+-------------------------------------------------------+
| 11 | Kepatuhan Penuh Terhadap Spesifikasi RFC 9420 §8.5  | UNVERIFIED       | Formula ExpandWithLabel exporter_secret belum diuji   |
| 12 | Interoperabilitas dengan Implementasi MLS Resmi     | UNVERIFIED       | Belum divalidasi silang terhadap Test Vector IETF     |
| 13 | Implementasi Protokol TreeKEM & Ratchet Tree Asli   | UNVERIFIED       | Tidak ada evaluasi struktur pohon kriptografi MLS     |
| 14 | Kompilasi & Eksekusi Crate Asli OpenMLS             | UNVERIFIED       | Tidak pernah dikompilasi ke WASM di lingkungan lokal  |
| 15 | Kompilasi & Eksekusi Crate Asli mls-rs              | UNVERIFIED       | Tidak pernah dikompilasi ke WASM di lingkungan lokal  |
| 16 | Kompatibilitas Bundler Next.js 14 ('next build')    | UNVERIFIED       | Belum pernah diuji terhadap Webpack 5 atau Turbopack  |
| 17 | Performa & Kestabilan pada Peramban Nyata (Browser) | UNVERIFIED       | Belum pernah dijalankan pada browser engine apapun    |
+----+-----------------------------------------------------+------------------+-------------------------------------------------------+
| 18 | Klaim "Modul WASM 72 Byte adalah Bundle Engine MLS" | INVALID          | Terbukti merupakan stub 52 byte biner buatan manual   |
+----+-----------------------------------------------------+------------------+-------------------------------------------------------+
```

---

## 4. AGENDA PENYESUAIAN PENGADAAN TOOLCHAIN RUST

Sebagai koreksi atas Bagian 7 butir No. 1 pada B-CHAT-07A.2, rincian teknis pengadaan toolchain Rust disempurnakan menjadi ketentuan normatif berikut:

1. **Prinsip Fleksibilitas Berbasis MSRV:**  
   Versi Rust tidak ditetapkan pada angka arbitrer ($\ge \text{1.75.0}$). Versi toolchain yang dipasang wajib mengacu langsung pada rincian berkas `Cargo.toml` dari crate kandidat yang akan diuji:
   - Apabila mengevaluasi `openmls`: Gunakan compiler Rust yang memenuhi MSRV resmi `openmls` (misalnya versi stabil yang didukung pada rilis yang ditargetkan).
   - Apabila mengevaluasi `mls-rs`: Gunakan compiler Rust yang memenuhi MSRV resmi `mls-rs` dan mendukung fitur `wasm` serta ketergantungan kriptografi terkait.
2. **Kesesuaian Komponen Toolchain:**
   - Komponen wajib: `rustc`, `cargo`, dan target standar `wasm32-unknown-unknown` (`rustup target add wasm32-unknown-unknown`).
   - Utilitas binding: `wasm-bindgen-cli` dengan versi yang **sama persis** dengan versi crate `wasm-bindgen` yang dideklarasikan di dalam proyek wrapper Rust.
   - Pembangun (*builder*): `wasm-pack` versi rilis stabil mutakhir untuk mengotomatisasi kompilasi dan optimasi `wasm-opt`.

---

## 5. INTEGRITAS ARSIP HISTORIS DAN BATASAN OPERASIONAL

Penyusunan dokumen koreksi minor **B-CHAT-07A.3** dilaksanakan dengan kepatuhan penuh terhadap prinsip keselamatan sistem:

1. **Pelestarian Dokumen Historis:** Berkas [B-CHAT-07A.2_Koreksi_Dokumentasi_EXP-01.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.2_Koreksi_Dokumentasi_EXP-01.md) dipertahankan apa adanya sebagai rekam jejak historis proses koreksi dokumentasi dan tidak ditimpa (*no overwrite*).
2. **Zero Re-execution:** Tidak ada eksekusi ulang terhadap eksperimen EXP-01.
3. **Zero Artifact Modification:** Berkas artefak mentah di [`scratch/bchat_prototype/reports/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/scratch/bchat_prototype/reports/) dan skrip simulator tidak diubah sedikit pun.
4. **Zero Dependency & Toolchain Installation:** Tidak ada instalasi dependensi npm baru, toolchain Rust, maupun paket sistem operasi.
5. **Zero Main Codebase Changes:** Direktori aplikasi backend ([`manajemen_skripsi/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/manajemen_skripsi/)) dan frontend ([`frontend_manajemen_skripsi/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/frontend_manajemen_skripsi/)) tidak tersentuh.
6. **Zero Database & Git Operations:** Basis data operasional tetap terlindungi; tidak ada perintah Git yang dijalankan.
7. **Zero Next-Experiment Execution:** Eksperimen EXP-02 sampai EXP-05 tetap ditangguhkan (*standby*) dan tidak dimulai.

---

## 6. PERNYATAAN AKHIR STATUS EXP-01

Berdasarkan seluruh hasil audit forensik B-CHAT-07A.1, rekonsiliasi B-CHAT-07A.2, serta penyempurnaan klasifikasi minor B-CHAT-07A.3, status evaluasi akhir untuk eksperimen EXP-01 secara resmi dinyatakan:

$$\mathbf{STATUS\ FINAL\ EXP\text{-}01:\ PARTIALLY\ SUCCESSFUL}$$

> [!CAUTION]
> **PERNYATAAN TEKNIS FORMAL:**  
> Eksperimen EXP-01 berhasil memvalidasi pola perancangan *harness* Web Worker modular dan mekanisme pemuatan modul WebAssembly dasar di lingkungan pengujian Node.js.  
> Namun demikian, **EXP-01 SECARA TEGAS BELUM MEMBUKTIKAN**:
> 1. Implementasi biner Rust-WASM MLS yang sesungguhnya dari crate `OpenMLS` maupun `mls-rs`.
> 2. Implementasi protokol kriptografi TreeKEM RFC 9420.
> 3. Kepatuhan penuh fungsi derivasi kunci terhadap RFC 9420 §8.5.
> 4. Interoperabilitas kriptografis dengan pustaka MLS resmi.
> 5. Kompatibilitas bundler produksi Next.js 14 (`next build`) dan kestabilan eksekusi pada peramban web nyata (*real browser*).

---
*(Dokumen B-CHAT-07A.3 resmi disahkan sebagai adendum koreksi minor final klasifikasi bukti EXP-01).*

