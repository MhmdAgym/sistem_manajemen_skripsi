# B-CHAT-07A.7 — MANDAT KOREKSI EDITORIAL DAN KLASIFIKASI BUKTI FINAL EXP-01
## PENAJAMAN TERMINOLOGI UUID, DISTRIBUSI SAMPEL STATISTIK, ISOLASI KOMUNIKASI WORKER NODE.JS, DAN PRESISI STATUS RFC 9420 §8.5

**Status Dokumen:** ADENDUM KOREKSI EDITORIAL & KLASIFIKASI BUKTI RESMI (*STRICTLY DOCUMENTATION ONLY*)  
**Mode Operasi:** ZERO EXECUTION — STRICTLY READ-ONLY  
**Dokumen Acuan:** [B-CHAT-07A.6_Koreksi_Final_AAD_dan_Iterasi_EXP-01.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.6_Koreksi_Final_AAD_dan_Iterasi_EXP-01.md)  
**Dokumen Historis Berjenjang (Dipertahankan Utuh Tanpa Overwrite):**
- [B-CHAT-07A.6_Koreksi_Final_AAD_dan_Iterasi_EXP-01.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.6_Koreksi_Final_AAD_dan_Iterasi_EXP-01.md)
- [B-CHAT-07A.5_Koreksi_Final_Konteks_Exporter_dan_Metrik_EXP-01.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.5_Koreksi_Final_Konteks_Exporter_dan_Metrik_EXP-01.md)
- [B-CHAT-07A.4_Koreksi_Presisi_RFC_dan_Klasifikasi_Bukti_EXP-01.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.4_Koreksi_Presisi_RFC_dan_Klasifikasi_Bukti_EXP-01.md)
- [B-CHAT-07A.3_Koreksi_Minor_Klasifikasi_Bukti.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.3_Koreksi_Minor_Klasifikasi_Bukti.md)
- [B-CHAT-07A.2_Koreksi_Dokumentasi_EXP-01.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.2_Koreksi_Dokumentasi_EXP-01.md)
- [B-CHAT-07A.1_Audit_Forensik_EXP-01.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A_Laporan_Eksekusi_EXP-01.md)
- [B-CHAT-07A_Laporan_Eksekusi_EXP-01.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A_Laporan_Eksekusi_EXP-01.md)
- [B-CHAT-06D_Mandat_Eksekusi_Terbatas_Gerbang_2.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-06D_Mandat_Eksekusi_Terbatas_Gerbang_2.md) (Revisi B-CHAT-06D.2)  
**Proyek:** Sistem Informasi Manajemen Skripsi UNUHA  
**Fitur:** Bimbingan Online Chat Real-Time E2EE  
**Tahap Proyek:** B-CHAT-07A.7 (Koreksi Editorial & Klasifikasi Bukti Final EXP-01)  
**Tanggal:** 5 November 2026  

---

## 1. IDENTITAS DAN TUJUAN DOKUMEN

Dokumen **B-CHAT-07A.7** diterbitkan sebagai mandat adendum penyempurnaan editorial dan klasifikasi bukti ilmiah atas dokumen [B-CHAT-07A.6](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.6_Koreksi_Final_AAD_dan_Iterasi_EXP-01.md). Berdasarkan peninjauan mendalam terhadap ketepatan peristilahan standar dan pembuktian telemetri pada berkas artefak mentah EXP-01, dokumen ini menetapkan koreksi formal pada lima aspek:

1. **Presisi Terminologi UUID dan Spesifikasi RFC 4122:** Menjelaskan secara proporsional bahwa standar RFC 4122 menetapkan representasi 128-bit (16 oktet), sedangkan frasa *raw 16-byte UUID binary representation* merupakan konvensi terminologi dokumentasi proyek UNUHA, tanpa menyimpulkan urutan byte (*endianness*) yang tidak diatur spesifikasi proyek.
2. **Klarifikasi Pemisahan Operasi Alur Kerja vs Jumlah Sampel Statistik:** Menegaskan pembedaan antara 10 kali pengulangan benchmark (*iterations*), 6 operasi perulangan alur kerja (*operations per iteration*), dan jumlah sampel statistik independen ($N = 10$ untuk latensi siklus hidup dan eksportir, $N = 11$ untuk heap memori). Dilarang menjumlahkan operasi alur kerja menjadi 60 sampel latensi.
3. **Penetapan Batasan Bukti Komunikasi Pesan Worker:** Mengklasifikasikan pertukaran pesan antar-thread yang benar-benar dieksekusi sebagai **`VERIFIED (NODE.JS RUNTIME ONLY)`** berdasarkan keberadaan bukti faktual pertukaran pesan pada `worker_threads`, serta menegaskan status `STATICALLY REVIEWED` untuk kode antarmuka browser dan `UNVERIFIED` untuk runtime peramban nyata.
4. **Presisi Status Ketidakpatuhan Terhadap RFC 9420 §8.5:** Mempertegas alasan penetapan status **`UNVERIFIED`** pada rantai eksportir, yaitu simulator JavaScript tidak mengimplementasikan fungsi $\operatorname{DeriveSecret}$, label baku `"exported"`, ekspansi $\operatorname{ExpandWithLabel}$, maupun hubungan dengan $\text{exporter\_secret}$ dari *MLS Key Schedule*.
5. **Koreksi Editorial Jumlah Butir Koreksi B-CHAT-07A.6:** Memperbaiki inkonsistensi narasi Bagian 1 pada B-CHAT-07A.6 yang menyebut "tiga koreksi teknis presisi" padahal mendaftar empat butir koreksi substantif.
6. **Pelestarian Arsip Historis Berjenjang:** Mempertahankan keutuhan berkas dokumen B-CHAT-07A.1 sampai B-CHAT-07A.6 tanpa penimpaan (*zero overwrite*).

---

## 2. MATRIKS KOREKSI EDITORIAL DAN KLASIFIKASI BUKTI (B-CHAT-07A.7)

Tabel berikut menyajikan ringkasan perbandingan rumusan pada [B-CHAT-07A.6](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.6_Koreksi_Final_AAD_dan_Iterasi_EXP-01.md) terhadap penyempurnaan resmi yang ditetapkan dalam **B-CHAT-07A.7**:

```
+-----------------------------------------------------------------------------------------------------------------------------------------+
|                                        MATRIKS KOREKSI DAN PENAJAMAN DOKUMEN (B-CHAT-07A.7)                                             |
+---+----------------------+----------------------------------------+---------------------------------------+-----------------------------+
| No| Aspek yang Dikoreksi | Rumusan pada B-CHAT-07A.6              | Rumusan Presisi Resmi (B-CHAT-07A.7)  | Dasar Penajaman Teknis      |
+---+----------------------+----------------------------------------+---------------------------------------+-----------------------------+
| A | Terminologi UUID dan | Menyiratkan frasa "raw 16-byte UUID    | RFC 4122 menetapkan 128-bit/16 oktet. | Presisi Konvensi Dokumen:   |
|   | Standar RFC 4122     | binary representation" sebagai istilah | Frasa tersebut adalah terminologi     | Terminologi Proyek UNUHA,   |
|   |                      | baku yang diwajibkan oleh RFC 4122.    | dokumentasi proyek UNUHA. Endianness  | Bukan Dikte Normatif RFC    |
|   |                      |                                        | tidak disimpulkan sembarangan.        |                             |
+---+----------------------+----------------------------------------+---------------------------------------+-----------------------------+
| B | Operasi per Iterasi  | Menyebutkan 6 operasi dan 10 iterasi,  | Ditegaskan pembedaan mutlak: 10 iterasi| Disiplin Statistik:         |
|   | vs Sampel Statistik  | namun belum menegaskan larangan        | pengulangan != 60 sampel latensi.     | N = 10 untuk Siklus Hidup;  |
|   |                      | agregasi operasi menjadi sampel data.  | Sampel N = 10 untuk lifecycle & export| N = 11 untuk Heap Memori;   |
|   |                      |                                        | dan N = 11 untuk heap memori.         | 6 Operasi adalah Alur Kerja |
+---+----------------------+----------------------------------------+---------------------------------------+-----------------------------+
| C | Klasifikasi Bukti    | Komunikasi pesan dilabeli VERIFIED     | Dibatasi secara eksplisit menjadi:    | Pemisahan Runtime Faktual:  |
|   | Komunikasi Worker    | umum tanpa pembatasan lingkungan yang  | VERIFIED (NODE.JS RUNTIME ONLY).      | Node.js threads terbukti;   |
|   |                      | tegas terhadap Node.js worker_threads. | Browser Web Worker & Next.js 14 build | Browser & Bundler Next.js 14|
|   |                      |                                        | tetap berstatus UNVERIFIED.           | tetap UNVERIFIED            |
+---+----------------------+----------------------------------------+---------------------------------------+-----------------------------+
| D | Presisi Status       | Status UNVERIFIED dicatat singkat      | Dijelaskan secara rinci bahwa simulator| Transparansi Kriptografi:   |
|   | RFC 9420 §8.5        | tanpa merinci kekosongan rantai        | tidak menguji DeriveSecret, label     | Ketiadaan Rantai KDF Penuh  |
|   |                      | derivasi formal MLS-Exporter.          | "exported", ExpandWithLabel, maupun   | RFC 9420 §8.5               |
|   |                      |                                        | rahasia epoch_secret key schedule.    |                             |
+---+----------------------+----------------------------------------+---------------------------------------+-----------------------------+
| E | Inkonsistensi Jumlah | Narasi Bagian 1 menyebutkan "tiga      | Secara formal dikoreksi bahwa Bagian 1| Ketelitian Editorial:       |
|   | Butir Koreksi        | koreksi teknis presisi", namun daftar  | memuat EMPAT butir koreksi substantif.| 4 Butir Koreksi Substantif  |
|   | Naratif Bagian 1     | memuat empat butir bernomor.           |                                       | Resmi Ditetapkan            |
+---+----------------------+----------------------------------------+---------------------------------------+-----------------------------+
```

---

## 3. PENJELASAN TEKNIS DAN EDITORIAL KOREKSI

### Koreksi A — Presisi Terminologi UUID dan Spesifikasi RFC 4122

Pada dokumen B-CHAT-07A.6 Bagian 5, terdapat narasi yang dapat ditafsirkan seolah-olah frasa *raw 16-byte UUID binary representation* merupakan terminologi normatif yang diatur secara spesifik dalam RFC 4122. Hal ini diluruskan sebagai berikut:
1. **Ketentuan Spesifikasi RFC 4122:** Standar IETF RFC 4122 (*A Universally Unique IDentifier URN Namespace*) menetapkan bahwa sebuah UUID terdiri dari ruang data **128-bit (16 oktet)**.
2. **Kedudukan Istilah Proyek:** Frasa `raw 16-byte UUID binary representation` merupakan **terminologi dokumentasi internal proyek Sistem Informasi Manajemen Skripsi UNUHA** yang dipilih untuk menegaskan bahwa field UUID pada AAD dan Konteks Exporter disimpan dalam format 16 oktet biner mentah, bukan berupa format representasi teks heksadesimal 36-karakter (*canonical textual representation* dengan tanda hubung seperti `xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx`).
3. **Penegasan Endianness:** Dokumentasi proyek tidak menyimpulkan urutan byte (*endianness*) internal pada oktet UUID selain dari apa yang secara eksplisit diatur oleh rancangan serialisasi biner proyek.

---

### Koreksi B — Pembedaan Operasi Alur Kerja per Iterasi dari Sampel Statistik

Untuk mencegah kesalahpahaman dalam interpretasi pengolahan data statistik hasil benchmark EXP-01:
1. **Jumlah Pengulangan Benchmark (*Benchmark Iterations*):** Tepat **10 kali pengulangan** ($i = 1 \dots 10$). Setiap iterasi mengeksekusi satu siklus grup privat 1:1 dari awal hingga akhir.
2. **Jumlah Operasi per Iterasi (*Workflow Operations per Iteration*):** Setiap iterasi pengujian melibatkan runtutan **enam operasi/tahapan alur kerja**:
   - (1) `generateKeyPackages`, (2) `createGroup`, (3) `addMember`, (4) `updateEpoch`, (5) `exportSecret`, (6) `removeDevice`.
3. **Distribusi Sampel Statistik Independen:**
   - **Durasi Siklus Hidup Keseluruhan (*Lifecycle Duration*):** Setiap pengulangan mencatat satu kali durasi total eksekusi siklus di dalam Worker (`res.totalRunDurationMs`). Dengan demikian, jumlah sampel statistik independen adalah **$N = 10\text{ sampel}$**. Nilai median $1,05\text{ ms}$ ($1,0456\text{ ms}$) dan p95 $6,88\text{ ms}$ dihitung dari 10 sampel ini. **Dilarang menjumlahkan enam operasi menjadi 60 sampel latensi** karena instrumen pengujian tidak mencatat 60 titik sampel independen terdistribusi.
   - **Durasi MLS Exporter (*Exporter Latency*):** Setiap pengulangan mencatat satu kali pengukuran durasi fungsi ekspor (`res.timings.exportSecretMs`), menghasilkan tepat **$N = 10\text{ sampel}$** (median $0,07\text{ ms}$, p95 $0,32\text{ ms}$).
   - **Konsumsi Memori Heap Worker (*Heap Memory*):** Terdiri dari 1 sampel awal saat *cold start initialization* dan 10 sampel di akhir setiap iterasi, menghasilkan total **$N = 11\text{ sampel}$** (median $7,66\text{ MB}$, puncak $8,63\text{ MB}$).
4. **Atribusi Runtime:** Seluruh metrik di atas tetap diklasifikasikan sebagai performa simulator JavaScript pada runtime V8 Node.js, bukan performa implementasi biner Rust-WASM MLS asli maupun Web Worker peramban.

---

### Koreksi C — Batasan Bukti Komunikasi Antar-Thread Worker

Pemeriksaan statis terhadap berkas kode sumber [`test_runner.js`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/scratch/bchat_prototype/exp01_mls_wasm/worker_prototype/test_runner.js), [`mls.worker.js`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/scratch/bchat_prototype/exp01_mls_wasm/worker_prototype/mls.worker.js), dan berkas catatan telemetri mentah [`EXP01_raw_measurements.json`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/scratch/bchat_prototype/reports/EXP01_raw_measurements.json) membuktikan bahwa:
1. **Bukti Eksekusi Nyata pada Node.js:** Skrip pengujian `test_runner.js` menginisialisasi thread pekerja menggunakan modul internal Node.js (`new Worker(...)` dari pustaka bawaan `worker_threads`). Pengiriman pesan dari thread utama melalui `worker.postMessage` dan penerimaan balasan dari worker melalui `parentPort.postMessage` terbukti berhasil dieksekusi secara faktual, dibuktikan dengan tersimpannya data telemetri luaran untuk inisialisasi cold start dan seluruh iterasi 1 sampai 10.
2. **Batasan Status `VERIFIED (NODE.JS RUNTIME ONLY)`:** Label pembuktian untuk komunikasi pesan worker secara ketat dibatasi pada lingkungan **Node.js `worker_threads`**.
3. **Penegasan Ketidakterbuktian pada Browser:**
   - Pola kode Web Worker browser pada [`mls_interface.ts`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/scratch/bchat_prototype/exp01_mls_wasm/worker_prototype/mls_interface.ts) berstatus **`STATICALLY REVIEWED`**.
   - Kompatibilitas terhadap pemaketan produksi Next.js 14 (`next build` via Webpack 5 atau Turbopack) dan perilaku eksekusi pesan pada peramban web nyata (*real browser runtime*) tetap berstatus **`UNVERIFIED`**. Dilarang memperluas klaim keberhasilan Node.js menjadi klaim keberhasilan browser.

---

### Koreksi D — Presisi Alasan Status `UNVERIFIED` pada Formula RFC 9420 §8.5

Untuk mencegah klaim kepatuhan semu terhadap standar IETF RFC 9420 Bagian 8.5, status **`UNVERIFIED`** ditegaskan dengan dasar teknis berikut:
1. **Ketiadaan Implementasi $\operatorname{DeriveSecret}$:** Simulator [`mls_core_simulator.js`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/scratch/bchat_prototype/exp01_mls_wasm/worker_prototype/mls_core_simulator.js#L226-L237) tidak mengimplementasikan fungsi ekspansi rahasia formal $\operatorname{DeriveSecret}(\text{Secret}, \text{Label}) = \operatorname{ExpandWithLabel}(\text{Secret}, \text{Label}, \text{""}, \text{Hash.length})$.
2. **Ketiadaan Label Baku $\text{"exported"}$:** Simulator tidak menyisipkan label kontekstual resmi $\text{"exported"}$ yang diwajibkan oleh RFC 9420 §8.5 pada tahap ekspansi kunci kedua.
3. **Ketiadaan Fungsi $\operatorname{ExpandWithLabel}$:** Simulator menggunakan panggilan primitif mandiri `crypto.createHmac('sha256')` dengan penambahan byte `0x01` manual, bukan struktur serialisasi pesan biner $\operatorname{ExpandWithLabel}$ standar MLS.
4. **Ketiadaan Rantai dengan $\text{exporter\_secret}$ Asli:** Nilai rahasia masukan pada simulator tidak diturunkan dari struktur pohon rahasia *MLS Key Schedule* yang mengikat status $\text{epoch\_secret}$ grup riil.
5. **Kesimpulan Kriptografis:** Simulator EXP-01 sama sekali tidak mengimplementasikan dan tidak membuktikan kepatuhan terhadap rantai derivasi eksportir RFC 9420 §8.5. Kepatuhan formal maupun interoperabilitasnya terhadap implementasi MLS resmi berstatus mutlak **`UNVERIFIED`**.

---

### Koreksi E — Koreksi Editorial Jumlah Butir Koreksi B-CHAT-07A.6

Pada dokumen acuan [B-CHAT-07A.6](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.6_Koreksi_Final_AAD_dan_Iterasi_EXP-01.md) Bagian 1, kalimat pengantar tertulis:
> *"Berdasarkan evaluasi forensik terhadap konsistensi arsitektur kriptografis dan berkas artefak mentah EXP-01, dokumen ini menetapkan **tiga koreksi teknis presisi**: (1) Pemulihan Struktur AAD..., (2) Verifikasi Dokumenter..., (3) Penajaman Terminologi..., (4) Pemisahan Tegas Konteks Exporter..."*

Terdapat ketidakcocokan numerik antara kata "tiga" dan daftar yang memuat empat butir. Melalui dokumen B-CHAT-07A.7, ketidakcocokan editorial tersebut secara resmi dikoreksi menjadi **empat butir koreksi substantif**.

---

## 4. TABEL KLASIFIKASI BUKTI ILMIAH TERREKONSILIASI FINAL (B-CHAT-07A.7)

Berikut adalah tabel klasifikasi bukti ilmiah EXP-01 yang telah direkonsiliasi secara paripurna dan bebas dari ambiguitas runtime maupun protokol:

```
+-----------------------------------------------------------------------------------------------------------------------------------------+
|                                    TABEL KLASIFIKASI BUKTI ILMIAH FINAL EXP-01 (B-CHAT-07A.7)                                           |
+----+-----------------------------------------------------+--------------------------------+---------------------------------------------+
| No | Parameter Aspek yang Dievaluasi                     | Status Klasifikasi Bukti       | Catatan Faktual dan Batasan Pembuktian      |
+----+-----------------------------------------------------+--------------------------------+---------------------------------------------+
| 1  | Mekanisme Worker Node.js ('worker_threads')         | VERIFIED (NODE.JS ONLY)        | Terbukti dieksekusi via modul worker_threads|
| 2  | Komunikasi Pesan Asinkron (postMessage/onmessage)   | VERIFIED (NODE.JS ONLY)        | Terbukti saling bertukar pesan di Node.js   |
| 3  | Pemuatan & Instansiasi Modul WebAssembly di Worker  | VERIFIED (NODE.JS ONLY)        | WebAssembly compile & instantiate stub 52 B |
| 4  | Pemanggilan Primitif Kriptografi HMAC-SHA-256       | VERIFIED CALL (NODE.JS ONLY)   | crypto.createHmac benar dipanggil di Node.js|
+----+-----------------------------------------------------+--------------------------------+---------------------------------------------+
| 5  | Pemeriksaan Pola Sintaksis Kode pada mls_interface  | STATICALLY REVIEWED            | Pola kode valid, belum teruji di browser    |
+----+-----------------------------------------------------+--------------------------------+---------------------------------------------+
| 6  | Latensi Siklus Hidup Transisi State (1,05 ms)       | MEASURED — JS SIMULATOR        | Terukur nyata dari N=10 sampel simulator JS |
| 7  | Latensi MLS Exporter Derivasi Mandiri (0,07 ms)     | MEASURED — JS SIMULATOR        | Terukur nyata dari N=10 sampel simulator JS |
| 8  | Alokasi Memori Heap Node.js Worker (8,63 MB)        | MEASURED — NODE.JS PROCESS     | Terukur nyata dari N=11 sampel heap Node.js |
+----+-----------------------------------------------------+--------------------------------+---------------------------------------------+
| 9  | Konstruksi Derivasi Kunci CEK Simulator             | SIMULATED                      | Skema derivasi mandiri JS, BUKAN formula MLS|
| 10 | Simulasi Transisi State Siklus Hidup Grup (1:1)     | SIMULATED                      | State machine array JS di mls_core_sim.js   |
| 11 | Modul Biner mls_wasm_crypto.wasm                    | SIMULATED                      | Stub biner 52 byte (operasi XOR semata)     |
+----+-----------------------------------------------------+--------------------------------+---------------------------------------------+
| 12 | Estimasi Ukuran Bundle OpenMLS (1,2–1,6 MB gzip)    | ESTIMATED                      | Estimasi teoritis literatur upstream crate  |
| 13 | Estimasi Ukuran Bundle mls-rs (650–900 KB gzip)     | ESTIMATED                      | Estimasi teoritis literatur upstream crate  |
+----+-----------------------------------------------------+--------------------------------+---------------------------------------------+
| 14 | Pengukuran Pemblokiran Antarmuka (UI Freeze Browser)| NOT MEASURED                   | Headless Node.js CLI tidak mengukur DOM UI  |
+----+-----------------------------------------------------+--------------------------------+---------------------------------------------+
| 15 | Kepatuhan Penuh Rantai KDF RFC 9420 §8.5            | UNVERIFIED                     | Rantai ExpandWithLabel exporter_secret nihil|
| 16 | Interoperabilitas dengan Implementasi MLS Resmi     | UNVERIFIED                     | Belum diuji silang terhadap Test Vector IETF|
| 17 | Implementasi Pohon Kriptografi TreeKEM & Ratchet    | UNVERIFIED                     | Tidak ada evaluasi terhadap pohon MLS asli  |
| 18 | Performa Implementasi Biner MLS Rust-WASM Asli      | UNVERIFIED                     | Pustaka OpenMLS & mls-rs belum dikompilasi  |
| 19 | Konsumsi Memori Linear Web Worker Browser           | UNVERIFIED                     | Belum pernah dijalankan pada browser engine |
| 20 | Kompatibilitas Bundler Next.js 14 ('next build')    | UNVERIFIED                     | Belum pernah diuji terhadap Webpack 5/Turbo |
| 21 | Integrasi Web Worker pada Peramban Nyata (Browser)  | UNVERIFIED                     | Belum pernah dieksekusi di browser runtime  |
+----+-----------------------------------------------------+--------------------------------+---------------------------------------------+
| 22 | Klaim "Modul WASM 72 Byte adalah Bundle Engine MLS" | INVALID                        | Bertentangan dengan kode fisik generate_wasm|
| 23 | Klaim "UI Freeze 0 ms Membuktikan Browser Tidak Lag"| INVALID                        | Nilai asumsi tanpa instrumen browser nyata  |
+----+-----------------------------------------------------+--------------------------------+---------------------------------------------+
```

---

## 5. BATASAN BUKTI DAN HAL YANG BELUM DAPAT DIVERIFIKASI

Secara eksplisit dan bertanggung jawab, batasan pembuktian eksperimen EXP-01 dicatat sebagai berikut:
1. **Tidak Ada Eksekusi Pustaka MLS Asli:** Crate `openmls` maupun `mls-rs` belum pernah dikompilasi ke target WebAssembly maupun dieksekusi di lingkungan lokal pengembang.
2. **Tidak Ada Validasi Kriptografi Formal RFC 9420:** Mekanisme pembentukan grup, penambahan anggota, pembaruan epoch, dan eksportir kunci pada EXP-01 adalah simulasi JavaScript tingkat aplikasi, bukan pembuktian atas algoritma kriptografi TreeKEM atau Key Schedule RFC 9420.
3. **Ketiadaan Bukti Lingkungan Peramban Web (*Browser Runtime*):** Seluruh data telemetri dihasilkan dari lingkungan *headless* Node.js v25. Pengaruh eksekusi terhadap *event loop* antarmuka peramban, konsumsi memori Web Worker nyata, serta proses bundling produksi Next.js 14 (`next build`) belum pernah diuji secara fisik.
4. **Estimasi Ukuran Bundel Belum Terverifikasi Lokal:** Angka ukuran bundel terkompresi $1,2\text{--}1,6\text{ MB}$ (OpenMLS) dan $650\text{--}900\text{ KB}$ (mls-rs) berstatus estimasi teoritis dari kajian upstream dan belum diverifikasi melalui kompilasi mandiri.

---

## 6. PERNYATAAN PELESTARIAN ARSIP HISTORIS

Dokumen **B-CHAT-07A.7** merupakan adendum koreksi final dokumentasi. Dokumen-dokumen historis terdahulu:
- [`B-CHAT-07A_Laporan_Eksekusi_EXP-01.md`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A_Laporan_Eksekusi_EXP-01.md)
- [`B-CHAT-07A.1_Audit_Forensik_EXP-01.md`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A_Laporan_Eksekusi_EXP-01.md)
- [`B-CHAT-07A.2_Koreksi_Dokumentasi_EXP-01.md`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.2_Koreksi_Dokumentasi_EXP-01.md)
- [`B-CHAT-07A.3_Koreksi_Minor_Klasifikasi_Bukti.md`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.3_Koreksi_Minor_Klasifikasi_Bukti.md)
- [`B-CHAT-07A.4_Koreksi_Presisi_RFC_dan_Klasifikasi_Bukti_EXP-01.md`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.4_Koreksi_Presisi_RFC_dan_Klasifikasi_Bukti_EXP-01.md)
- [`B-CHAT-07A.5_Koreksi_Final_Konteks_Exporter_dan_Metrik_EXP-01.md`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.5_Koreksi_Final_Konteks_Exporter_dan_Metrik_EXP-01.md)
- [`B-CHAT-07A.6_Koreksi_Final_AAD_dan_Iterasi_EXP-01.md`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.6_Koreksi_Final_AAD_dan_Iterasi_EXP-01.md)

**tetap dipertahankan seutuhnya di repositori proyek tanpa diubah, ditimpa (*zero overwrite*), atau dihapus**. Dokumen-dokumen ini membentuk rantai audit ilmiah yang transparan dan dapat ditelusuri.

---

## 7. PERNYATAAN KEPATUHAN BATAS OPERASIONAL

Penyusunan dokumen B-CHAT-07A.7 diselesaikan dengan kepatuhan penuh terhadap batas operasional mutlak:

1. **Zero Re-execution:** Eksperimen EXP-01 sama sekali tidak dijalankan ulang.
2. **Zero Next Experiments:** Eksperimen lanjutan EXP-02 sampai EXP-05 tidak dimulai.
3. **Zero Compilation, Benchmark, & Testing:** Tidak ada kode sumber yang dikompilasi, tidak ada tolok ukur yang dijalankan ulang, dan tidak ada unit test yang dieksekusi.
4. **Zero Toolchain & Dependency Installation:** Tidak ada instalasi Rust, Cargo, wasm-pack, paket npm, atau paket dependensi baru.
5. **Zero File Changes to Prototypes:** Kode simulator, skrip pengujian, stub WASM, dan berkas telemetri mentah di [`scratch/bchat_prototype/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/scratch/bchat_prototype/) tidak dimodifikasi.
6. **Zero Application & Database Changes:** Kode aplikasi backend Laravel ([`manajemen_skripsi/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/manajemen_skripsi/)), frontend Next.js ([`frontend_manajemen_skripsi/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/frontend_manajemen_skripsi/)), skema database, dan data tabel tidak disentuh.
7. **Zero Git Operations:** Tidak ada satu pun perintah Git (`git status`, `git diff`, `git commit`, `git checkout`, `git push`, dsb.) yang dijalankan.

---

## 8. KESIMPULAN STATUS EXP-01

Berdasarkan seluruh hasil audit forensik, rekonsiliasi berjenjang, dan penajaman klasifikasi B-CHAT-07A.7, status resmi eksperimen EXP-01 secara final ditegaskan:

$$\mathbf{STATUS\ RESMI\ EXP\text{-}01:\ PARTIALLY\ SUCCESSFUL}$$

> [!CAUTION]
> **KESIMPULAN AKHIR:**  
> Dokumen **B-CHAT-07A.7** merupakan adendum koreksi dokumentasi, bukan eksperimen baru dan bukan bukti tambahan keberhasilan implementasi MLS asli.  
> Eksperimen EXP-01 berhasil memvalidasi isolasi pengujian Node.js Worker (`worker_threads`), pengiriman pesan asinkron, dan instansiasi modul WebAssembly dasar (stub 52 byte).  
> Namun demikian, **EXP-01 SECARA TEGAS BELUM MEMBUKTIKAN**:
> 1. Implementasi biner Rust-WASM MLS asli (`OpenMLS` atau `mls-rs`).
> 2. Protokol kriptografi TreeKEM RFC 9420.
> 3. Kepatuhan implementasi fungsi derivasi terhadap spesifikasi penuh $\operatorname{MLS\text{-}Exporter}$ RFC 9420 §8.5.
> 4. Interoperabilitas kriptografis terhadap implementasi MLS resmi.
> 5. Kinerja, konsumsi memori, dan ketiadaan UI freeze pada peramban web nyata (*real browser*).
> 6. Kompatibilitas terhadap bundler produksi Next.js 14 (`next build`).

---
*(Dokumen B-CHAT-07A.7 selesai disusun dan disahkan sebagai koreksi editorial dan klasifikasi bukti final EXP-01).*

