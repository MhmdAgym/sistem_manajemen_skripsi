# B-CHAT-06C.1 — LAPORAN KOREKSI TERBATAS GERBANG 1
## PENYEMPURNAAN RENCANA PROTOTIPE KOMPARATIF DAN VALIDASI TEKNIS E2EE UNUHA

**Status Operasi:** STRICTLY READ-ONLY — PLAN ONLY — ZERO EXECUTION  
**Proyek:** Sistem Informasi Manajemen Skripsi UNUHA  
**Fitur:** Bimbingan Online Chat Real-Time E2EE  
**Tahap:** B-CHAT-06C.1 (Revisi Perencanaan Gerbang 1)  
**Dokumen Acuan:** Laporan Perencanaan B-CHAT-06C, Rekonsiliasi B-CHAT-06B.1.3, B-CHAT-01, B-CHAT-04, B-CHAT-05, B-CHAT-05A, dan Keputusan Pemilik Proyek tanggal 5 November 2026.  
**Tanggal Evaluasi:** 5 November 2026  

---

## 1. IDENTITAS DOKUMEN DAN BATASAN OPERASI

Dokumen ini merupakan laporan koreksi terbatas terhadap dokumen perencanaan **B-CHAT-06C (Rencana Prototipe Komparatif dan Validasi Teknis)**. Dokumen ini disusun untuk merekonsiliasi enam temuan teknis yang teridentifikasi selama peninjauan perencanaan Gerbang 1.

### Batasan Operasi Mutlak:
- **Strictly Read-Only — Plan Only — Zero Execution:** Tidak ada kode aplikasi yang dimodifikasi, dibuat, atau dihapus.
- **Tanpa Perubahan Dependensi:** Tidak ada instalasi atau modifikasi pustaka pihak ketiga pada `manajemen_skripsi/composer.json` maupun `frontend_manajemen_skripsi/package.json`.
- **Tanpa Eksekusi Runtime:** Tidak ada pengujian unit, benchmark performa, migrasi basis data, build, prototype execution, atau deployment yang dijalankan.
- **Tanpa Modifikasi Git:** Tidak ada operasi Git checkout, commit, push, stash, atau branching.
- **Pemisahan Gerbang:** Dokumen ini merupakan instrumen penyempurnaan **Gerbang 1 (Persetujuan Rencana)**. Eksekusi eksperimen prototipe di lingkungan terisolasi tetap memerlukan mandat eksplisit terpisah pada **Gerbang 2 (Persetujuan Eksekusi)**.

---

## 2. RINGKASAN HASIL KOREKSI

Evaluasi mendalam terhadap B-CHAT-06C menghasilkan resolusi atas 6 (enam) temuan teknis utama:

1. **Status Pustaka MLS & Cipher Suite:** Menghapus klaim sepihak bahwa OpenMLS atau mls-rs adalah "implementasi resmi RFC 9420". Keduanya diklasifikasikan sebagai "kandidat implementasi berbasis Rust" yang wajib diverifikasi dukungannya pada EXP-01. Memisahkan secara tegas MLS Cipher Suite (misal: Cipher Suite 1 yang menggunakan AES-128-GCM) dari lapisan enkripsi lampiran yang menggunakan AES-256-GCM.
2. **Konteks Web Crypto API & Worker:** Mengoreksi pemanggilan objek kriptografi dari `window.crypto.subtle` menjadi `self.crypto.subtle` (atau `crypto.subtle`) sesuai konteks Web Worker. Mengoreksi instansiasi modul Worker menjadi `new Worker('/workers/mls.js', { type: 'module' })`. Memasukkan integrasi bundler Next.js 14 App Router, pemuatan aset WASM, dan penanganan SSR sebagai item validasi teknis eksplisit.
3. **Objektivitas Semantik Komparasi OD-17:** Menyelaraskan perbandingan Pendekatan A (MLS Exporter) dan Pendekatan B (CSPRNG via Payload MLS) secara netral tanpa menentukan pemenang apriori. Membedakan 3 kondisi akses kunci (menurunkan kunci baru, membaca kunci tersimpan, atau menerima kunci via sinkronisasi). Mengganti klaim "isolasi kriptografis sempurna" menjadi "isolasi kunci per lampiran dengan asumsi keunikan CEK dan pengelolaan nonce yang benar".
4. **Penyelarasan Transactional Outbox (EXP-04):** Mengintegrasikan penyimpanan event Outbox ke dalam transaksi atomik basis data yang sama dengan penyimpanan pesan (`INSERT chat_messages` + `INSERT outbox_events` sebelum commit). Dispatcher memproses event paska commit. Menegaskan pemisahan antara idempotensi aplikasi (UUID/fingerprint) dan anti-replay pada lapisan protokol transport.
5. **Presisi Nonce, Chunk Index, dan Layout AAD 83-Byte:** Menetapkan chunk index berbasis nol (`0..TotalChunks-1`), formula nonce 96-bit deterministik `0000000000000000 || uint32be(chunk_index)` dengan material kunci unik per lampiran, serta merinci layout biner kanonikal AAD 83-byte field-by-field.
6. **Diferensiasi Target Hipotesis vs Circuit Breakers:** Membedakan secara tegas target eksperimen awal (bundle WASM $\le 2,5$ MB gzip, memori $\le 50$ MB, inisialisasi $\le 300$ ms) dari ambang batas penghentian darurat (*circuit breaker*: memori worker $> 100$ MB, UI freeze $> 500$ ms). Merinci parameter lingkungan pengukuran empiris yang wajib dicatat.

---

## 3. ENAM TEMUAN TEKNIS DAN PENJELASAN KOREKSI

### TEMUAN 1 — Status Library MLS dan Pemisahan Lapisan Cipher Suite
- **Kondisi Sebelum:** Dokumen B-CHAT-06C Bagian 3.1 menyebut OpenMLS sebagai "Pustaka resmi arsitektur Rust, implementasi RFC 9420 lengkap, dukungan cipher suite 1" dan mencampuradukkan cipher suite MLS dengan cipher suite lampiran.
- **Koreksi Konseptual:**
  1. Istilah dikoreksi menjadi **"kandidat implementasi MLS berbasis Rust"**. Tidak ada pustaka pihak ketiga yang diakui sebagai "resmi" oleh IETF; seluruh klaim kepatuhan RFC 9420, dukungan cipher suite, stabilitas API exporter, dan kompatibilitas WebAssembly wajib diverifikasi secara empiris terhadap versi rilis pustaka yang dipilih pada eksperimen EXP-01.
  2. Pemisahan lapisan kriptografi ditegaskan:
     - **Lapisan Transport & Protokol MLS (RFC 9420):** Menggunakan cipher suite yang didukung pustaka MLS terpilih (misal: MLS Cipher Suite 1 / `MLS_128_DHKEMX25519_AES128GCM_SHA256_Ed25519` yang berbasis **AES-128-GCM**).
     - **Lapisan Enkripsi Berkas Lampiran:** Menggunakan algoritma simetris berkekuatan tinggi yang terisolasi, yaitu **AES-256-GCM** via Web Crypto API.
  3. Kedua lapisan ini independen: material kunci lampiran tidak diproses oleh cipher suite MLS, melainkan hanya diekspor atau dibungkus di dalamnya.

---

### TEMUAN 2 — Konteks Web Crypto API dan Integrasi Web Worker
- **Kondisi Sebelum:** Bagian 2.1 menyebut `window.crypto.subtle`, dan Bagian 6 menyebut `Worker('/workers/mls.js', ...)` tanpa sintaks konstruktor lengkap, serta mengasumsikan Worker secara otomatis menyelesaikan isolasi bundler Next.js.
- **Koreksi Konseptual:**
  1. Pada lingkungan Web Worker peramban, objek global `window` tidak terdefinisi. Panggilan Web Crypto API dikoreksi menjadi `self.crypto.subtle` atau referensi langsung `crypto.subtle`.
  2. Sintaks pembuatan worker dikoreksi mengikuti standar modul ECMAScript modern:
     ```javascript
     const worker = new Worker(new URL('/workers/mls.worker.js', import.meta.url), { type: 'module' });
     ```
  3. Mengakui secara jujur bahwa pemisahan eksekusi ke Web Worker tidak otomatis menyelesaikan tantangan kompilasi Next.js 14 App Router. Oleh karena itu, EXP-01 diwajibkan memvalidasi:
     - Pemuatan modul biner `.wasm` melalui konfigurasi bundler (Webpack / Turbopack).
     - Penanganan isolasi rendering Server-Side Rendering (SSR) agar modul worker hanya dimuat pada sisi klien (*client-only*).
     - Komunikasi terstruktur data biner (`ArrayBuffer` transferable objects) antara Main UI Thread dan Worker Thread.

---

### TEMUAN 3 — Semantik Perbandingan Kunci Lampiran (OD-17)
- **Kondisi Sebelum:** Bagian 3.3 membuat klaim sepihak bahwa Pendekatan B memiliki "isolasi kriptografis sempurna", sementara Pendekatan A diklaim menyebabkan lampiran historis tidak dapat dibuka saat epoch berubah, serta menggunakan klaim rentan "payload MLS bocor sebelum didekripsi".
- **Koreksi Konseptual:**
  1. Menghilangkan praduga keunggulan salah satu pendekatan. Keduanya dievaluasi secara setara:
     - **Pendekatan A (MLS Exporter):** Kunci diturunkan deterministik dari state pohon MLS via `MLS-Exporter`.
     - **Pendekatan B (CSPRNG via Payload MLS):** Kunci CEK 256-bit dibangkitkan acak oleh pengirim dan didistribusikan terenkripsi di dalam `MLSApplicationMessage`.
  2. Memperjelas 3 kondisi siklus hidup akses kunci:
     - *Kondisi 1 (Menurunkan/Memperoleh Kunci Baru):* Pada Pendekatan A, anggota aktif pada Epoch $E$ dapat langsung menurunkan kunci exporter. Pada Pendekatan B, anggota harus menerima dan mendekripsi payload pesan pembawa CEK.
     - *Kondisi 2 (Menggunakan Kunci Tersimpan):* Kunci yang telah berhasil diperoleh/diturunkan dan disimpan di IndexedDB lokal dapat digunakan kapan saja untuk membuka lampiran tanpa terpengaruh rotasi epoch berikutnya. Rotasi epoch tidak membatalkan kunci yang sudah berada di penyimpanan lokal terenkripsi penerima.
     - *Kondisi 3 (Akses Lampiran Historis oleh Perangkat Baru):* Perangkat baru yang bergabung pada Epoch $E+1$ tidak memegang rahasia Epoch $E$ (*Forward Secrecy*). Baik Pendekatan A maupun B membutuhkan mekanisme transfer riwayat (sinkronisasi antar-perangkat terenkripsi atau impor snapshot cadangan lokal) agar perangkat baru dapat memperoleh kunci lampiran lama.
  3. Mengganti frasa "isolasi kriptografis sempurna" menjadi:
     **"Isolasi kunci per lampiran dengan asumsi keunikan CEK dan pengelolaan nonce yang benar."**
  4. Menghapus klaim spekulatif kegagalan payload bocor; fokus evaluasi kegagalan diarahkan pada:
     - Pada Pendekatan A: kegagalan deserialisasi konteks exporter atau ketiadaan dukungan API exporter pada pustaka WASM.
     - Pada Pendekatan B: risiko penggunaan ulang CEK lintas lampiran atau kegagalan pengikatan identitas CEK ke lampiran yang sah.

---

### TEMUAN 4 — Penyelarasan Transactional Outbox pada EXP-04
- **Kondisi Sebelum:** Bagian 3.4 menyebut transactional outbox pada teks naratif, tetapi diagram alur transaksi hanya mencatat penyimpanan pesan ke basis data dan langsung mengeksekusi broadcast Reverb paska commit.
- **Koreksi Konseptual:**
  1. Alur transaksi pada eksperimen EXP-04 diselaraskan penuh dengan pola *Transactional Outbox*:
     ```
     1. Mulai Transaksi Basis Data (DB::beginTransaction);
     2. Lock baris ruang (SELECT ... FOR UPDATE);
     3. Validasi keunikan UUID & kesesuaian fingerprint payload;
     4. Simpan pesan ciphertext ke tabel `chat_messages`;
     5. Alokasikan dan perbarui sequence_cursor ruang;
     6. Simpan rekaman event ke tabel `outbox_events` dalam transaksi yang sama;
     7. COMMIT Transaksi;
     8. Worker / Dispatcher mengambil event dari `outbox_events` paska commit;
     9. Broadcast WebSocket Reverb dieksekusi secara idempotent;
     10. Tandai status event di `outbox_events` sebagai 'PROCESSED'.
     ```
  2. **Penanganan Skenario Kegagalan Simulatir:**
     - Jika server mati tepat setelah Tahap 7 (Commit berhasil, broadcast belum terjadi), data pesan telah aman tersimpan di basis data dan event tetap tersimpan di tabel `outbox_events`. Saat sistem pulih, dispatcher otomatis memproses ulang pengiriman event tanpa duplikasi pesan.
  3. **Pemisahan Anti-Replay:**
     - Pengecekan `UNIQUE(room_id, client_message_uuid)` dan fingerprint muatan adalah mekanisme idempotensi tingkat aplikasi.
     - Perlindungan terhadap *replay attack* pada lapisan transport/protokol (misal pemancaran ulang paket jabat tangan MLS atau ciphertext lama) diuji secara terpisah melalui mekanisme tracking generasi ratchet tree dan epoch window.

---

### TEMUAN 5 — Presisi Nonce, Chunk Index, dan Layout Biner AAD 83-Byte
- **Kondisi Sebelum:** Definisi pengikatan nonce dan AAD masih memuat narasi umum tanpa layout serialisasi biner yang siap diuji.
- **Koreksi Konseptual:**
  1. **Indeks Chunk:** Ditetapkan sebagai integer berbasis nol (*zero-based indexing*): $0 \le \text{chunk\_index} \le \text{TotalChunks} - 1$.
  2. **Format Nonce 96-bit (12 Byte):**
     $$\text{Nonce}_{96} = \text{0x0000000000000000}\ (8\text{ Byte / 64-bit nol}) \parallel \text{uint32be}(\text{chunk\_index})\ (4\text{ Byte Big-Endian})$$
  3. **Aturan Keunikan Mutlak:** Pasangan `(Key, Nonce)` dilarang keras digunakan ulang. Keunikan dijamin oleh kebijakan bahwa setiap lampiran memiliki material kunci unik (pada Pendekatan A: konteks exporter unik; pada Pendekatan B: CEK acak unik).
  4. **Layout Biner Kanonikal AAD 83-Byte:**
     Struktur biner AAD didefinisikan secara fixed-length dan non-ambigu:

| Urutan | Nama Field | Ukuran Byte | Tipe Data & Encoding | Aturan Serialisasi & Validasi |
|:---:|:---|:---:|:---|:---|
| 1 | Magic Header | 4 Byte | ASCII: `UNH1` (`0x55, 0x4E, 0x48, 0x31`) | Pengenal format kanonikal UNUHA v1. |
| 2 | Protocol Version | 2 Byte | `uint16be` (`0x00, 0x01`) | Versi protokol bimbingan (nilai: 1). |
| 3 | Domain Separator | 1 Byte | `uint8` (`0x02`) | Domain pemisah (`0x02` = Chunk Lampiran). |
| 4 | Room UUID | 16 Byte | Binary UUID (128-bit) | Raw bytes Big-Endian dari UUIDv4/v7 ruang. |
| 5 | Message UUID | 16 Byte | Binary UUID (128-bit) | Raw bytes Big-Endian dari UUIDv4/v7 pesan. |
| 6 | Attachment UUID | 16 Byte | Binary UUID (128-bit) | Raw bytes Big-Endian dari UUIDv4/v7 lampiran.|
| 7 | Upload Session ID | 16 Byte | Binary UUID (128-bit) | Raw bytes Big-Endian dari sesi unggah aktif. |
| 8 | Chunk Index | 4 Byte | `uint32be` | Indeks chunk zero-based ($0 \le \text{idx} < \text{total}$). |
| 9 | Total Chunks | 4 Byte | `uint32be` | Jumlah total chunk untuk berkas ini ($1 \le \text{tot} \le 10$).|
| 10 | Plaintext Length | 4 Byte | `uint32be` | **Panjang plaintext dari chunk ini** ($\le 1.048.576$ B).|
| **Total** | **Panjang Eksak** | **83 Byte** | **ArrayBuffer Kontinu** | **Tolak dekripsi jika buffer AAD $\neq$ 83 byte.** |

- *Verifikasi Ukuran:* $4 + 2 + 1 + 16 + 16 + 16 + 16 + 4 + 4 + 4 = \mathbf{83\text{ Byte}}$.
- *Representasi Plaintext Length:* Chunk 1 s.d. 9 (penuh) bernilai $1.048.576$ (`0x00100000`). Chunk 10 (terakhir desimal) bernilai $562.816$ (`0x00089680`). Ukuran total berkas asli ($10.000.000$ Byte) disimpan di dalam Manifest terenkripsi.

---

### TEMUAN 6 — Target Performa Eksperimen dan Ambang Circuit Breaker
- **Kondisi Sebelum:** Bagian 3.1 dan Bagian 5 mencampur target pengujian awal dengan kriteria penghentian darurat, serta mengasumsikan keterlambatan Web Worker otomatis membekukan UI thread.
- **Koreksi Konseptual:**
  1. Menegaskan pemisahan antara **Target Eksperimen Awal (Hipotesis Kerja)** dan **Ambang Penghentian Darurat (Circuit Breakers)**:

```
+-------------------------------------------------------------------------------------------------------------+
|                               PEMISAHAN AMBANG PENGUKURAN KINERJA (EXP-01)                                 |
+------------------------------+---------------------------------------+--------------------------------------+
| Metrik Pengukuran            | Target Eksperimen Awal (Hipotesis)    | Ambang Penghentian Darurat (Stop)    |
+------------------------------+---------------------------------------+--------------------------------------+
| Ukuran Bundel WASM           | $\le 2,5$ MB (terkompresi gzip)       | $> 5,0$ MB (tidak layak web mobile)  |
| Penggunaan Memori Worker     | $\le 50$ MB alokasi puncak (heap)     | $> 100$ MB (indikasi kebocoran OOM)  |
| Waktu Inisialisasi Modul     | $\le 300$ ms (pemuatan cold-start)    | $> 1.500$ ms (latensi boot kritis)   |
| Durasi UI Thread Freeze      | $0$ ms (komputasi di worker terpisah) | $> 500$ ms (blocking event loop UI)  |
+------------------------------+---------------------------------------+--------------------------------------+
```

  2. **Pemisahan Metrik Memori Worker vs UI Freeze:**
     - Memori Web Worker berada pada heap V8 terisolasi. Peningkatan memori di worker tidak serta-merta membekukan UI thread utama, kecuali terjadi transfer data serialisasi masif yang membebani garbage collection peramban. Kedua metrik ini diukur dan dicatat secara independen.
  3. **Parameter Lingkungan Pengukuran yang Wajib Dicatat:**
     Setiap hasil pengukuran metrik di Gerbang 2 wajib melampirkan konteks eksekusi:
     - Spesifikasi perangkat keras (CPU, RAM).
     - Sistem Operasi dan versi kernel.
     - Vendor peramban dan versi mesin (Chromium / Gecko / WebKit).
     - Jumlah perangkat dan anggota aktif dalam grup MLS.
     - Generasi epoch pohon TreeKEM saat pengujian.
     - Kondisi cache peramban (*cold-start* vs *warm-start*).
     - Kondisi simulasi latensi jaringan (RTT ms, packet drop rate).
     - Metodologi pengulangan pengukuran (minimal 10 kali pengulangan, dilaporkan dalam median dan 95th percentile).

---

## 4. PERBANDINGAN KONDISI SEBELUM DAN SESUDAH KOREKSI

| No. | Temuan Evaluasi | Kondisi Sebelum (B-CHAT-06C) | Koreksi Terbatas (B-CHAT-06C.1) | Dampak pada Rencana Gerbang 1 |
|:---:|:---|:---|:---|:---|
| **1** | **Status Library MLS & Cipher Suite** | OpenMLS diklaim pustaka resmi RFC 9420; cipher suite MLS dicampur dengan AES-256 lampiran. | Dikoreksi menjadi kandidat evaluasi Rust-WASM; cipher suite MLS (AES-128) dipisahkan tegas dari lampiran (AES-256). | Menghilangkan klaim prematur; memperjelas batasan verifikasi EXP-01. |
| **2** | **Konteks Web Crypto & Worker** | Menggunakan `window.crypto.subtle` dan konstruktor worker tidak lengkap; Next.js SSR diabaikan. | Menggunakan `self.crypto.subtle` dan `new Worker(..., { type: 'module' })`; menambahkan validasi bundler Next.js. | Menjamin skrip prototipe valid secara sintaks dan kompatibel dengan App Router. |
| **3** | **Semantik Perbandingan OD-17** | Menetapkan bias "isolasi sempurna" pada Pendekatan B; klaim kaku lampiran lama terkunci di Pendekatan A. | Menetralkan perbandingan; membedakan 3 kondisi akses kunci; mengevaluasi pemulihan historis secara setara. | Menjamin objektivitas evaluasi komparatif tanpa pemenang apriori. |
| **4** | **Pola Transactional Outbox** | Diagram transaksi tidak memuat penyimpanan `outbox_events` sebelum commit basis data. | Alur transaksi diselaraskan penuh dengan pola Outbox: event disimpan sebelum commit, broadcast diproses paska commit. | Mencegah inkonsistensi event hilang jika server mati paska commit pesan. |
| **5** | **Definisi Nonce & Layout AAD** | Nonce dan AAD dijelaskan secara umum; layout byte belum dirinci secara fixed-length. | Menetapkan nonce `00..00 || uint32be(idx)`, chunk zero-based, dan layout kanonikal AAD 83-byte field-by-field. | Memberikan spesifikasi layout deterministik yang siap diuji di EXP-02. |
| **6** | **Target Kinerja vs Circuit Breakers** | Target awal dicampur dengan batas kegagalan fatal; metrik worker disamakan dengan UI freeze. | Memisahkan target eksperimen (hipotesis) dari circuit breaker (stop); memisahkan metrik worker dan UI loop. | Mencegah penghentian prematur dan memperjelas kriteria kegagalan kritis. |

---

## 5. DAMPAK KOREKSI TERHADAP LIMA EKSPERIMEN (EXP-01 S.D. EXP-05)

Penyempurnaan teknis pada dokumen ini mempertajam fokus pengujian masing-masing eksperimen prototipe:

```
+-------------------------------------------------------------------------------------------------------------+
|                                    DAMPAK REVISI TERHADAP RANGKAIAN EKSPERIMEN                              |
+---------------+---------------------------------------------------------------------------------------------+
| ID Eksperimen | Penajaman Lingkup dan Rincian Pengujian                                                     |
+---------------+---------------------------------------------------------------------------------------------+
| **EXP-01**    | Memverifikasi dukungan RFC 9420 dan API exporter pada versi rilis pustaka Rust-WASM terpilih|
|               | serta menguji pemuatan modul WASM di Web Worker Next.js 14 App Router (SSR-safe).           |
+---------------+---------------------------------------------------------------------------------------------+
| **EXP-02**    | Menguji streaming chunking 1 MiB dengan format AAD 83-byte kanonikal di mana PlaintextLength|
|               | merepresentasikan ukuran chunk aktual (termasuk chunk terakhir desimal 562.816 Byte).       |
+---------------+---------------------------------------------------------------------------------------------+
| **EXP-03**    | Menjalankan uji komparatif obyektif antara Pendekatan A dan B berdasarkan 8 kriteria tanpa   |
|               | menentukan pemenang sebelum data empiris konsumsi memori dan latensi terkumpul.             |
+---------------+---------------------------------------------------------------------------------------------+
| **EXP-04**    | Menguji konsistensi transaksi 7 tahap: penulisan atomik pesan dan event outbox sebelum      |
|               | commit, serta verifikasi ketiadaan duplikasi paska lost ACK melalui constraint basis data.  |
+---------------+---------------------------------------------------------------------------------------------+
| **EXP-05**    | Menguji variasi interval rotasi epoch (event-based murni vs hybrid 25, 50, 100 pesan)       |
|               | sebagai bahan penentuan rekomendasi kebijakan produksi kepada Pemilik Proyek.               |
+---------------+---------------------------------------------------------------------------------------------+
```

---

## 6. DAFTAR HAL YANG MASIH MEMBUTUHKAN VERIFIKASI TEKNIS

Hal-hal berikut diidentifikasi belum dapat diverifikasi secara statis dan diklasifikasikan sebagai **agenda pembuktian empiris pada Gerbang 2**:
1. *Kestabilan Binding WASM-TypeScript:* Memverifikasi apakah pustaka Rust-WASM yang dipilih mengekspor struktur data yang dapat diserialisasi secara efisien melalui mekanisme transferable `ArrayBuffer` tanpa *cloning overhead* berlebihan.
2. *Kompatibilitas Web Crypto API pada WebKit Mobile:* Memverifikasi performa algoritma AES-256-GCM pada browser Safari iOS saat memproses 10 chunk file secara sekuensial.
3. *Latensi Pemrosesan Outbox Dispatcher:* Mengukur jeda waktu (*dispatch lag*) antara commit transaksi pesan di MySQL dan tibanya frame broadcast di klien penerima melalui Laravel Reverb.
4. *Ketahanan Terhadap Packet Drop:* Mengukur perilaku antrean offline klien saat kondisi jaringan disimulasikan mengalami kehilangan paket (*packet loss*) sebesar 20%.

---

## 7. DAFTAR KEPUTUSAN YANG MEMBUTUHKAN PERSETUJUAN PEMILIK PROYEK

Dokumen ini menegaskan kembali bahwa batasan keputusan yang telah ditetapkan Pemilik Proyek tetap dihormati dan tidak diubah. Adapun hal-hal yang kelak memerlukan penetapan formal Pemilik Proyek adalah:

1. **Pemilihan Pendekatan Kunci Lampiran Final (OD-17):** Menetapkan apakah menggunakan Pendekatan A (MLS Exporter) atau Pendekatan B (CSPRNG via Payload) untuk fase produksi, yang akan diputuskan **setelah laporan empiris B-CHAT-06C selesai dievaluasi**.
2. **Persetujuan Penerbitan Mandat Eksekusi Gerbang 2:** Memberikan izin tertulis bagi agen/pengembang untuk menyiapkan direktori terisolasi (`scratch/bchat_prototype/`) dan mengeksekusi rangkaian skrip pengujian EXP-01 sampai EXP-05.
3. **Penetapan Regulasi Retensi Akademik (OD-15):** Penerbitan SK Rektor terkait durasi simpan arsip bimbingan online (menjelang fase produksi).

---

## 8. KESIMPULAN KELAYAKAN DOKUMEN (GERBANG 1)

Rencana prototipe komparatif B-CHAT-06C yang disempurnakan melalui laporan koreksi **B-CHAT-06C.1** telah memenuhi seluruh kriteria penerimaan teknis:
- [x] Enam temuan teknis hasil peninjauan telah dikoreksi secara tuntas tanpa meninggalkan ambiguitas.
- [x] Tidak ada klaim teknis yang melampaui bukti yang tersedia (seluruh hipotesis ditandai secara jujur).
- [x] Keputusan Pemilik Proyek terkait OD-01 (Rust-WASM), OD-02 (Batas Desimal), dan OD-17 (Komparasi Objektif) dipertahankan 100%.
- [x] Alur Transactional Outbox telah diselaraskan penuh dengan prinsip ACID transaksi basis data.
- [x] Layout biner kanonikal AAD 83-byte dan indeks nonce telah didefinisikan secara fixed-length dan non-ambigu.
- [x] Target kinerja awal telah dipisahkan secara tegas dari kriteria penghentian darurat (*circuit breakers*).
- [x] Batasan strictly read-only dipatuhi secara mutlak.

### Rekomendasi Status:
$$\mathbf{LAYAK\ DIAJUKAN\ KEMBALI\ UNTUK\ PERSETUJUAN\ GERBANG\ 1}$$

*(Dokumen perencanaan B-CHAT-06C beserta koreksi B-CHAT-06C.1 secara resmi siap diserahkan kepada Pemilik Proyek untuk mendapatkan pengesahan Gerbang 1).*

---

## 9. PERNYATAAN BATAS AUDIT DAN INTEGRITAS SISTEM

1. **Zero File Modification on Production:** Tidak ada satu pun file kode aplikasi, komponen frontend, model backend, konfigurasi, atau skrip yang disentuh di dalam repositori `D:\Aplikasi\laragon\www\sistem_manajamen_skripsi\`. Dokumen ini dibuat murni sebagai berkas dokumentasi perencanaan.
2. **Zero Code / Migration Execution:** Tidak ada migrasi basis data, pengubahan skema, instalasi dependensi (`npm install` / `composer require`), build produksi, benchmark, atau pengujian kode yang dijalankan.
3. **Zero Git Modification:** Status Git repository tetap bersih tanpa ada commit, staging, checkout branch, maupun push.
4. **Kepatuhan Protokol Gerbang:** Tim kerja **tidak melanjutkan ke tahap eksekusi prototipe (Gerbang 2)** sebelum persetujuan resmi Gerbang 1 dan mandat eksekusi Gerbang 2 diterbitkan oleh Pemilik Proyek.

---
*(Laporan B-CHAT-06C.1 selesai disusun secara paripurna).*

