# B-CHAT-07A.2 — KOREKSI DOKUMENTASI HASIL EXP-01
## REKONSILIASI KLAIM ILMIAH DAN KLASIFIKASI BUKTI EKSEKUSI MLS RUST-WASM

**Status Dokumen:** LAPORAN KOREKSI DOKUMENTASI RESMI  
**Mode Operasi:** CONTROLLED DOCUMENTATION REVISION (STRICTLY READ-ONLY — ZERO EXECUTION)  
**Dasar Evaluasi:** Laporan Audit Forensik [B-CHAT-07A.1](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A_Laporan_Eksekusi_EXP-01.md)  
**Dokumen yang Dikoreksi:** [B-CHAT-07A_Laporan_Eksekusi_EXP-01.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A_Laporan_Eksekusi_EXP-01.md)  
**Dokumen Acuan:** [B-CHAT-06D_Mandat_Eksekusi_Terbatas_Gerbang_2.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-06D_Mandat_Eksekusi_Terbatas_Gerbang_2.md) (Revisi B-CHAT-06D.2)  
**Proyek:** Sistem Informasi Manajemen Skripsi UNUHA  
**Fitur:** Bimbingan Online Chat Real-Time E2EE  
**Tahap Proyek:** B-CHAT-07A.2 (Koreksi Dokumentasi EXP-01)  
**Tanggal:** 5 November 2026  

---

## 1. TUJUAN DAN LATAR BELAKANG KOREKSI

Dokumen ini disusun sebagai instrumen koreksi formal atas laporan **B-CHAT-07A** berdasarkan temuan audit forensik independen **B-CHAT-07A.1**. Tujuan utama koreksi ini adalah:
1. Menyelaraskan seluruh klaim teknis dengan bukti faktual yang benar-benar tersedia di dalam sandbox `scratch/bchat_prototype/`.
2. Mengeliminasi klaim kelulusan mutlak yang mengasumsikan simulator JavaScript atau stub WebAssembly sebagai implementasi asli pustaka `OpenMLS` atau `mls-rs`.
3. Mengklasifikasikan bukti secara transparan ke dalam kategori: `VERIFIED`, `SIMULATED`, `ESTIMATED`, `UNVERIFIED`, dan `INVALID`.
4. Merumuskan status akhir EXP-01 secara proporsional sebagai **`PARTIALLY SUCCESSFUL`** (berhasil pada aspek pengujian jalur/harness dan pemodelan semantik, namun belum menguji biner Rust asli).
5. Memetakan daftar pekerjaan tersisa yang belum selesai untuk mencapai validasi MLS biner asli di masa mendatang.

> [!IMPORTANT]
> **INTEGRITAS DATA DAN BATASAN OPERASI MUTLAK:**  
> Seluruh artefak mentah hasil pengujian EXP-01 (termasuk [`EXP01_raw_measurements.json`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/scratch/bchat_prototype/reports/EXP01_raw_measurements.json) dan [`EXP01_summary.json`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/scratch/bchat_prototype/reports/EXP01_summary.json)) **dipertahankan secara utuh dan tidak diubah atau dihapus**. Tidak ada pengujian ulang yang dijalankan, tidak ada kode sumber yang dimodifikasi, dan tidak ada dependensi atau compiler baru yang dipasang.

---

## 2. STATUS AKHIR HASIL EXP-01: PARTIALLY SUCCESSFUL

Status evaluasi akhir pelaksanaan eksperimen EXP-01 secara resmi direkonsiliasi menjadi:

$$\mathbf{STATUS\ RESMI\ EXP\text{-}01:\ PARTIALLY\ SUCCESSFUL\ (BERHASIL\ SEBAGIAN)}$$

### Justifikasi Objektif:
1. **Aspek yang Berhasil (VERIFIED):**
   - Pola pemuatan Web Worker modular Next.js 14 (`new Worker(new URL('./mls.worker.js', import.meta.url), { type: 'module' })`) dengan pelindung SSR (`typeof window !== 'undefined'`) terbukti valid secara sintaksis dan mampu mengisolasi eksekusi dari thread utama.
   - Pemuatan, kompilasi, dan instansiasi modul WebAssembly di dalam Web Worker berhasil dijalankan oleh engine V8 Node.js.
   - Pemodelan logika siklus hidup grup privat 1:1 (Inisialisasi KeyPackage $\rightarrow$ Create Group Epoch 0 $\rightarrow$ Add Member Epoch 1 $\rightarrow$ Rekeying Commit Epoch 2 $\rightarrow$ Remove Member Epoch 3) terbukti konsisten dan bebas dari anomali transisi state.
   - Formula matematis derivasi Kunci Enkripsi Konten (*Content Encryption Key* / CEK) menggunakan `MLS-Exporter` (RFC 9420 §8.5) dengan konteks kanonikal terikat ID berkas (`RoomUUID || MessageUUID || AttachmentUUID`) terbukti deterministik dan valid.
2. **Aspek yang Belum Berhasil / Ditangguhkan (UNVERIFIED / SIMULATED):**
   - Crate asli `OpenMLS` dan `mls-rs` **belum dikompilasi maupun dieksekusi** karena ketiadaan compiler Rust (`rustc`, `cargo`, `wasm-pack`) pada workstation lokal pengembang.
   - Modul WebAssembly yang dieksekusi adalah **stub minimal 52-byte**, bukan bundel biner kriptografi MLS riil.
   - Pengukuran telemetri latensi, alokasi memori heap, dan durasi inisialisasi adalah performa dari **simulator JavaScript dan stub WASM**, bukan performa kompilasi biner Rust-WASM sesungguhnya.

---

## 3. TABEL KOMPARASI KLAIM SEBELUM DAN SESUDAH KOREKSI

Berikut adalah matriks rekonsiliasi klaim antara laporan awal (B-CHAT-07A) dan laporan koreksi resmi (B-CHAT-07A.2):

```
+-----------------------------------------------------------------------------------------------------------------------------------------+
|                                           MATRIKS REKONSILIASI KLAIM TEKNIS EXP-01                                                      |
+----+----------------------------+--------------------------------------------+----------------------------------------------------------+
| No | Parameter Klaim            | Klaim Awal (B-CHAT-07A)                    | Koreksi Resmi Berdasarkan Fakta (B-CHAT-07A.2)          |
+----+----------------------------+--------------------------------------------+----------------------------------------------------------+
| 1  | Implementasi Pustaka MLS   | "OpenMLS dan mls-rs dievaluasi secara      | Crate OpenMLS dan mls-rs BELUM DIKOMPILASI MAUPUN        |
|    | Asli (OpenMLS & mls-rs)    | empiris melalui prototipe sandbox"         | DIEKSEKUSI di lokal karena ketiadaan toolchain Rust.     |
|    |                            |                                            | Evaluasi keduanya berstatus kajian teoritis upstream.    |
+----+----------------------------+--------------------------------------------+----------------------------------------------------------+
| 2  | Hakikat Modul WebAssembly  | "Modul biner WASM tervalidasi sebesar      | Berkas mls_wasm_crypto.wasm adalah STUB XOR MINIMAL      |
|    | (mls_wasm_crypto.wasm)     | 52 Byte (72 Byte gzip) memenuhi target"    | berukuran 52 byte (72 byte gzip) yang dibuat manual via  |
|    |                            |                                            | array byte JS. BUKAN implementasi engine MLS Rust.       |
+----+----------------------------+--------------------------------------------+----------------------------------------------------------+
| 3  | Siklus Hidup Grup MLS 1:1  | "Siklus hidup MLS 1:1 berhasil divalidasi  | Siklus hidup 1:1 dijalankan melalui SIMULATOR JAVASCRIPT |
|    | (KeyPackage s.d. Epoch 3)  | dengan latensi median 1,05 ms"             | (mls_core_simulator.js), bukan pohon TreeKEM biner asli. |
|    |                            |                                            | Latensi 1,05 ms adalah kecepatan runtime JS V8.          |
+----+----------------------------+--------------------------------------------+----------------------------------------------------------+
| 4  | Ketersediaan & Eksekusi    | "Fungsi MLS Exporter RFC §8.5 berhasil     | Penurunan kunci dieksekusi oleh FUNGSI DERIVASI BUATAN   |
|    | MLS Exporter (RFC §8.5)    | menurunkan CEK 256-bit ba8339dce9...       | SENDIRI di JavaScript berbasis crypto.createHmac, bukan  |
|    |                            | dengan latensi 0,07 ms"                    | pemanggilan binding API Rust OpenMLS / mls-rs asli.      |
+----+----------------------------+--------------------------------------------+----------------------------------------------------------+
| 5  | Waktu Inisialisasi MLS     | "Cold start 60,12 ms memenuhi target       | 60,12 ms adalah waktu spawn Node Worker thread dan       |
|    | (Cold Start)               | <= 300 ms"                                 | kompilasi stub WASM 52-byte. BUKAN waktu inisialisasi    |
|    |                            |                                            | engine MLS Rust-WASM biner sesungguhnya (2 - 4 MB).      |
+----+----------------------------+--------------------------------------------+----------------------------------------------------------+
| 6  | Konsumsi Memori Heap       | "Worker heap peak 8,63 MB memenuhi target  | 8,63 MB adalah alokasi HEAP MEMORI NODE.JS WORKER V8,    |
|    |                            | <= 50 MB"                                  | bukan konsumsi memori linear WebAssembly dari engine MLS.|
+----+----------------------------+--------------------------------------------+----------------------------------------------------------+
| 7  | Pemblokiran UI Main Thread | "UI Main Thread Freeze 0,00 ms memenuhi    | BELUM TERVERIFIKASI DI BROWSER PERAMBAN NYATA. Angka     |
|    | (UI Freeze)                | target <= 50 ms"                           | 0 ms diukur di CLI Node.js headless tanpa DOM render.    |
+----+----------------------------+--------------------------------------------+----------------------------------------------------------+
| 8  | Kompatibilitas Next.js 14  | "Next.js 14 Web Worker terbukti kompatibel | Pola kode terbukti valid, namun KOMPATIBILITAS PENUH     |
|    | App Router                 | dan siap digunakan"                        | BELUM TERBUKTI sampai diuji pada proses 'next build'     |
|    |                            |                                            | dan eksekusi peramban nyata yang sebenarnya.             |
+----+----------------------------+--------------------------------------------+----------------------------------------------------------+
| 9  | Ukuran Bundle Produksi     | "Bundle WASM OpenMLS (1,2 MB) dan mls-rs   | Angka 1,2 MB dan 800 KB berstatus ESTIMASI TEORITIS      |
|    |                            | (800 KB) memenuhi target <= 2,5 MB"        | berdasarkan literatur upstream, BUKAN hasil kompilasi.   |
+----+----------------------------+--------------------------------------------+----------------------------------------------------------+
```

---

## 4. MATRIKS KLASIFIKASI BUKTI ILMIAH EXP-01

Setiap aspek temuan diklasifikasikan secara ketat berdasarkan metodologi audit forensik:

```
+-------------------------------------------------------------------------------------------------------------+
|                                    KLASIFIKASI BUKTI ILMIAH ARTEFAK EXP-01                                  |
+----+---------------------------------------------+--------------+-------------------------------------------+
| No | Parameter Pembuktian                        | Klasifikasi  | Keterangan dan Dasar Pembuktian           |
+----+---------------------------------------------+--------------+-------------------------------------------+
| 1  | Pola Worker Module Next.js 14 & SSR-Safety  | VERIFIED     | Kode mls_interface.ts & mls.worker.js     |
| 2  | Instansiasi Modul WebAssembly di Worker     | VERIFIED     | WebAssembly.compile & instantiate sukses  |
| 3  | Logika Alur Transisi State MLS 1:1          | VERIFIED     | State machine 4 epoch berjalan konsisten  |
| 4  | Formulasi Konteks Kanonikal Exporter §8.5   | VERIFIED     | Label & context string terbukti presisi   |
+----+---------------------------------------------+--------------+-------------------------------------------+
| 5  | Eksekusi Lifecycle via Simulator JS         | SIMULATED    | Berjalan di mls_core_simulator.js         |
| 6  | Derivasi Kunci Exporter via HMAC JS         | SIMULATED    | Replikasi HKDF buatan sendiri di Node.js  |
| 7  | Modul WebAssembly mls_wasm_crypto.wasm      | SIMULATED    | Stub biner 52 byte (operasi XOR semata)   |
| 8  | Pengukuran Latensi Lifecycle (1,05 ms)      | SIMULATED    | Mengukur fungsi JavaScript V8             |
| 9  | Pengukuran Memori Heap Worker (8,63 MB)     | SIMULATED    | Mengukur heap process V8 Worker           |
+----+---------------------------------------------+--------------+-------------------------------------------+
| 10 | Estimasi Ukuran Bundle OpenMLS & mls-rs     | ESTIMATED    | Perkiraan teoritis dari literatur crates  |
| 11 | Estimasi Ketiadaan UI Freeze (0,00 ms)      | ESTIMATED    | Inferensi isolasi thread CLI headless     |
+----+---------------------------------------------+--------------+-------------------------------------------+
| 12 | Eksekusi Biner Crate Asli OpenMLS           | UNVERIFIED   | Belum pernah dikompilasi di lokal         |
| 13 | Eksekusi Biner Crate Asli mls-rs            | UNVERIFIED   | Belum pernah dikompilasi di lokal         |
| 14 | Kinerja di Peramban Nyata (Browser Engine)  | UNVERIFIED   | Baru diuji di Node.js CLI runtime         |
| 15 | Kompatibilitas Bundler Next.js 'next build' | UNVERIFIED   | Belum diuji terhadap Webpack/Turbopack    |
+----+---------------------------------------------+--------------+-------------------------------------------+
| 16 | Klaim "WASM 72 Byte adalah Bundle MLS"      | INVALID      | Bertentangan dengan kode generate_wasm.js |
+----+---------------------------------------------+--------------+-------------------------------------------+
```

---

## 5. BUKTI FISIK DAN LOKASI BERKAS SUMBER (TRACEABILITY)

Seluruh temuan di atas didasarkan pada penelusuran berkas fisik di dalam direktori `scratch/bchat_prototype/`:

1. **Ketiadaan Toolchain Rust:**  
   Berkas: [`scratch/bchat_prototype/toolchain_check.json`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/scratch/bchat_prototype/toolchain_check.json)  
   *Bukti:* Nilai `rustc_installed: false`, `cargo_installed: false`, dan `wasm_pack_installed: false`.
2. **Modul WASM Stub Buatan Manual:**  
   Berkas: [`scratch/bchat_prototype/exp01_mls_wasm/worker_prototype/generate_wasm.js`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/scratch/bchat_prototype/exp01_mls_wasm/worker_prototype/generate_wasm.js#L8-L25)  
   *Bukti:* Array byte biner langsung dikodekan di JavaScript (magic `00 61 73 6d`, export `mls_ct_compare`, opcode `i32.xor`). Modul mentah berukuran tepat 52 byte ([`mls_wasm_crypto.wasm`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/scratch/bchat_prototype/exp01_mls_wasm/worker_prototype/mls_wasm_crypto.wasm)).
3. **Simulator Logika MLS Buatan Sendiri:**  
   Berkas: [`scratch/bchat_prototype/exp01_mls_wasm/worker_prototype/mls_core_simulator.js`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/scratch/bchat_prototype/exp01_mls_wasm/worker_prototype/mls_core_simulator.js)  
   *Bukti:*  
   - Pembangkitan kunci P-256 via Web Crypto native (Baris 18–25).
   - Pergeseran epoch manual via `crypto.createHash('sha256')` (Baris 108–115).
   - Derivasi kunci Exporter manual via `crypto.createHmac('sha256')` (Baris 218–243).
4. **Data Telemetri Mentah yang Dipertahankan:**  
   Berkas: [`scratch/bchat_prototype/reports/EXP01_raw_measurements.json`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/scratch/bchat_prototype/reports/EXP01_raw_measurements.json) dan [`scratch/bchat_prototype/reports/EXP01_summary.json`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/scratch/bchat_prototype/reports/EXP01_summary.json).  
   *Bukti:* Menunjukkan nilai nyata inisialisasi cold start ($60,12\text{ ms}$) dan heap peak ($8,63\text{ MB}$) dari simulator Node.js.

---

## 6. KETERBATASAN EKSPERIMEN

Pelaksanaan EXP-01 memiliki keterbatasan mendasar yang wajib dicatat secara transparan:
1. **Workstation Pengembang Tidak Memiliki Compiler Rust:**  
   Tanpa `rustc`, `cargo`, dan `wasm-pack`, pengujian kompilasi nyata terhadap crates `openmls` atau `mls-rs` tidak dapat dieksekusi di workstation lokal saat ini.
2. **Ketiadaan Paket NPM Resmi Siap Pakai:**  
   Baik OpenMLS maupun mls-rs tidak menyediakan paket npm resmi yang langsung dapat diimpor ke aplikasi Next.js tanpa kompilasi Rust perantara.
3. **Pengujian Belum Melibatkan Browser Nyata:**  
   Pengujian telemetri dijalankan pada lingkungan headless Node.js CLI v25. Perilaku browser sesungguhnya (Chrome, Firefox, Safari) dalam mengelola alokasi memori linear WASM dan event loop UI belum teramati secara langsung.
4. **Pengujian 'next build' Belum Dijalankan:**  
   Pola worker Next.js 14 baru diverifikasi secara sintaksis dan isolasi thread, belum diuji terhadap proses bundler optimasi produksi Next.js (`next build`).

---

## 7. DAFTAR PEKERJAAN TERSISA UNTUK VALIDASI MLS ASLI

Untuk membuktikan kelayakan implementasi biner MLS asli di masa mendatang (sebelum melangkah ke tahap implementasi produksi Gerbang 3), langkah-langkah kerja berikut wajib diselesaikan:

```
+-------------------------------------------------------------------------------------------------------------+
|                               AGENDA PEKERJAAN TERSISA UNTUK VALIDASI MLS ASLI                              |
+----+--------------------------------+-----------------------------------------------------------------------+
| No | Item Pekerjaan Tersisa         | Rincian Tindakan Teknis yang Wajib Dilakukan                          |
+----+--------------------------------+-----------------------------------------------------------------------+
| 1  | Pengadaan Toolchain Rust       | Memasang rustc (>= 1.75.0), cargo, dan wasm-pack pada workstation     |
|    | atau Build Server Terpusat     | pengembang ATAU menyiapkan pipeline build CI/CD cross-compilation.    |
+----+--------------------------------+-----------------------------------------------------------------------+
| 2  | Penyusunan Wrapper Crate       | Menulis crate Rust perantara (wasm-bindgen) yang membungkus tipe data |
|    | wasm-bindgen untuk MLS         | OpenMLS (MlsGroup) atau mls-rs (Group) ke antarmuka JavaScript.       |
+----+--------------------------------+-----------------------------------------------------------------------+
| 3  | Kompilasi Biner WASM Asli      | Menjalankan kompilasi nyata ke target wasm32-unknown-unknown dengan   |
|    |                                | optimasi ukuran (opt-level = 'z', lto = true) dan mengukur biner riil.|
+----+--------------------------------+-----------------------------------------------------------------------+
| 4  | Pengujian Bundler Next.js 14   | Mengintegrasikan biner .wasm hasil kompilasi ke dalam proyek Next.js  |
|    | Terhadap 'next build'          | dan memverifikasi kelulusan proses kompilasi Webpack & Turbopack.     |
+----+--------------------------------+-----------------------------------------------------------------------+
| 5  | Pengujian di Peramban Nyata    | Menjalankan worker di peramban nyata (Chromium/Gecko) dan mengukur    |
|    | (Browser UI Thread Test)       | ada/tidaknya Long Task (> 50 ms) via PerformanceObserver API.         |
+----+--------------------------------+-----------------------------------------------------------------------+
| 6  | Validasi Silang Output         | Memvalidasi keluaran kunci MLS Exporter terhadap Test Vectors resmi   |
|    | Terhadap Test Vectors RFC 9420 | IETF MLS RFC 9420 untuk membuktikan interoperabilitas kriptografis.   |
+----+--------------------------------+-----------------------------------------------------------------------+
```

---

## 8. PERNYATAAN INTEGRITAS SISTEM DAN KEPATUHAN BATASAN OPERASI

Dengan ini dinyatakan dan ditegaskan secara transparan bahwa selama pelaksanaan penyusunan laporan **B-CHAT-07A.2**:
1. **Zero Re-execution:** Eksperimen EXP-01 tidak dijalankan ulang.
2. **Zero Code Changes:** Tidak ada satu pun baris kode di `scratch/bchat_prototype/` maupun di aplikasi utama (`manajemen_skripsi/` dan `frontend_manajemen_skripsi/`) yang diubah, ditimpa, atau dimodifikasi.
3. **Zero Artifact Deletion:** Berkas mentah pengujian ([`EXP01_raw_measurements.json`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/scratch/bchat_prototype/reports/EXP01_raw_measurements.json) dan [`EXP01_summary.json`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/scratch/bchat_prototype/reports/EXP01_summary.json)) tetap tersimpan utuh dan terjaga integritasnya.
4. **Zero Dependency Changes:** Tidak ada dependensi atau compiler baru yang dipasang pada sistem.
5. **Zero Database Changes:** Basis data operasional MySQL dan staging tetap 100% terlindungi tanpa migrasi atau query.
6. **Zero Git Operations:** Tidak ada perintah Git (`commit`, `push`, `checkout`, `branch`, `stash`, `merge`) yang dijalankan.
7. **Kepatuhan Protokol Eksperimen:** Eksperimen lanjutan (EXP-02 sampai EXP-05) **secara mutlak tidak dimulai** sebelum dokumen koreksi ini disetujui secara tertulis oleh Pemilik Proyek.

---
*(Dokumen B-CHAT-07A.2 selesai disusun secara paripurna sebagai rekonsiliasi resmi atas laporan eksekusi EXP-01).*

