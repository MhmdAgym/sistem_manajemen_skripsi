# B-CHAT-08A.5 — FINAL EDITORIAL PRECISION CLOSURE
## PENYEMPURNAAN TERBATAS METRIK MEMORI NODE.JS DAN KLASIFIKASI OPERATIONERROR

**Status Dokumen:** DOKUMEN PENUTUP AMENDEMEN EDITORIAL-TEKNIS FINAL (*FINAL EDITORIAL PRECISION CLOSURE*)  
**Mode Operasi:** STRICTLY READ-ONLY TERHADAP IMPLEMENTASI — DOCUMENTATION ONLY  
**Status Otorisasi EXP-02:** **EXP-02 NOT AUTHORIZED** *(Eksperimen EXP-02 belum diotorisasi)*  
**Dokumen Sumber yang Diamandemen:** [B-CHAT-08A.4_Koreksi_Teknis_Final_Mandat_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.4_Koreksi_Teknis_Final_Mandat_EXP-02.md)  
**Dokumen Acuan Historis:**
- [B-CHAT-08A.3_Koreksi_Presisi_Final_Mandat_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.3_Koreksi_Presisi_Final_Mandat_EXP-02.md)
- [B-CHAT-08A.2_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.2_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md)
- [B-CHAT-08A.1_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.1_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md)
- [B-CHAT-08A_Rencana_dan_Mandat_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A_Rencana_dan_Mandat_EXP-02.md)
- [B-CHAT-06D_Mandat_Eksekusi_Terbatas_Gerbang_2.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-06D_Mandat_Eksekusi_Terbatas_Gerbang_2.md) (Revisi B-CHAT-06D.2 §3.2)
- [B-CHAT-05_Spesifikasi_Final_E2EE.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-05_Spesifikasi_Final_E2EE.md) (Keputusan Desain OD-02 & OD-17)
- Rangkaian Dokumen Forensik EXP-01: [B-CHAT-07A.1](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A_Laporan_Eksekusi_EXP-01.md) s.d. [B-CHAT-07A.7](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.7_Koreksi_Editorial_dan_Klasifikasi_Bukti_Final_EXP-01.md)  
**Proyek:** Sistem Informasi Manajemen Skripsi UNUHA  
**Fitur:** Bimbingan Online Chat Real-Time E2EE  
**Tahap:** Penutupan Final Perencanaan Gerbang 2 (Finalisasi Mandat EXP-02)  
**Tanggal:** 5 November 2026  

---

> [!CAUTION]
> **PENEGASAN STATUS OPERASIONAL MUTLAK:**  
> Dokumen **B-CHAT-08A.5** ini berstatus **STRICTLY DOCUMENTATION ONLY — ZERO EXECUTION — EXP-02 NOT AUTHORIZED**. Penerbitan dokumen ini **BUKAN** merupakan izin atau otorisasi untuk memulai eksekusi prototipe EXP-02 di sandbox. Persetujuan Gerbang 2 sebelumnya tidak memberikan kewenangan otomatis untuk menjalankan EXP-02. Pelaksanaan fisik di sandbox hanya dapat dimulai setelah Pemilik Proyek memberikan persetujuan tertulis eksplisit terpisah atas dokumen mandat final ini. Selama penyusunan dokumen ini, tidak ada kode sumber yang dibuat, tidak ada direktori sandbox yang dibuat, tidak ada dependensi atau toolchain yang dipasang, tidak ada benchmark/pengujian yang dijalankan, dan tidak ada operasi Git yang dilakukan. Seluruh dokumen historis ([B-CHAT-08A](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A_Rencana_dan_Mandat_EXP-02.md), [B-CHAT-08A.1](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.1_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md), [B-CHAT-08A.2](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.2_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md), [B-CHAT-08A.3](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.3_Koreksi_Presisi_Final_Mandat_EXP-02.md), dan [B-CHAT-08A.4](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.4_Koreksi_Teknis_Final_Mandat_EXP-02.md)) tetap dipertahankan utuh di repositori tanpa diubah atau ditimpa (*zero overwrite*).

---

## 1. IDENTITAS DAN BATAS MANDAT OPERASIONAL

Dokumen **B-CHAT-08A.5** diterbitkan sebagai catatan amendemen editorial-teknis penutup (*final editorial precision closure*) terhadap dokumen acuan [B-CHAT-08A.4_Koreksi_Teknis_Final_Mandat_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.4_Koreksi_Teknis_Final_Mandat_EXP-02.md). Dokumen ini berfungsi mandiri untuk mendokumentasikan secara presisi dua penyempurnaan redaksional terbatas yang disyaratkan oleh Pemilik Proyek, sekaligus menegaskan stabilitas seluruh parameter teknis dan keputusan arsitektural yang telah dicapai pada B-CHAT-08A.4.

### Batas Mandat Operasional Mutlak:
- **Dokumentasi Saja:** Bersifat *strictly documentation only*, tanpa pembuatan prototipe, skrip uji, atau direktori sandbox.
- **Zero Code Execution:** Tidak ada pengujian Web Crypto, benchmark memori, simulasi, build, atau eksekusi runtime apa pun.
- **Zero Main Codebase Changes:** Direktori backend Laravel ([`manajemen_skripsi/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/manajemen_skripsi/)) dan frontend Next.js ([`frontend_manajemen_skripsi/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/frontend_manajemen_skripsi/)) tidak disentuh.
- **Zero Database Changes:** Tidak ada migrasi basis data, perubahan skema, atau manipulasi data.
- **Zero Git Operations:** Tidak ada perintah Git (`git status`, `git diff`, `git commit`, `git checkout`, `git push`, dsb.) yang dijalankan.
- **Status Otorisasi:** Status eksperimen tetap **EXP-02 NOT AUTHORIZED**.

---

## 2. LATAR BELAKANG DAN TUJUAN AMENDEMEN EDITORIAL

Pada peninjauan akhir dokumen [B-CHAT-08A.4](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.4_Koreksi_Teknis_Final_Mandat_EXP-02.md), Pemilik Proyek menemukan dua klaim tekstual yang masih mengandung asumsi teknis berlebih atau spekulasi kausalitas yang tidak terukur secara empiris:
1. **Klaim Mekanisme Internal V8 pada Metrik `external`:** Teks B-CHAT-08A.4 §3.C poin 2 menyebutkan fungsi internal V8 spesifik (`Isolate::AdjustAmountOfExternalAllocatedMemory`) sebagai mekanisme yang menentukan apakah alokasi native tercatat pada metrik `external`. Penyebutan fungsi internal V8 yang tidak diukur secara langsung dalam prototipe ini berpotensi menjadi spekulasi implementasi.
2. **Klaim Kausal Motif Desain AEAD pada `OperationError`:** Teks B-CHAT-08A.4 §3.E poin 4 memuat pernyataan bahwa cipher AEAD (AES-256-GCM) *secara sengaja didesain untuk tidak memberikan informasi diagnostik guna mencegah serangan oracle side-channel*. Pernyataan mengenai niat/tujuan desain teoretis tersebut bersifat spekulatif dan tidak diperlukan dalam konteks spesifikasi pengujian unit/integrasi.

Tujuan dokumen **B-CHAT-08A.5** adalah mengganti kedua rumusan tersebut dengan pernyataan berbasis fakta empiris dan perilaku antarmuka teramati (*observable interface behavior*), tanpa mengubah substansi teknis lainnya.

---

## 3. KOREKSI PERTAMA — PENYEMPURNAAN RUMUSAN METRIK `external`

### Lokasi Sumber yang Dikoreksi:
Dokumen [B-CHAT-08A.4](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.4_Koreksi_Teknis_Final_Mandat_EXP-02.md) Bagian 3, Sub-bagian **Koreksi C — Batas Cakupan Metrik Memori `external` (§3.G)**, Poin 2.

### Teks Sebelumnya (B-CHAT-08A.4 §3.C Poin 2):
> *"2. **Ketiadaan Jaminan Cakupan Total OpenSSL:**  
> Nilai `external` **tidak menjamin bahwa seluruh alokasi internal native Web Crypto maupun OpenSSL tercakup secara komprehensif**. Alokasi memori internal di dalam pustaka native C/C++ yang tidak didaftarkan ke engine V8 melalui mekanisme pelaporan alokasi eksternal (`Isolate::AdjustAmountOfExternalAllocatedMemory`) tidak akan terefleksi pada nilai `external`."*

### Teks Pengganti Resmi (B-CHAT-08A.5):
> *"2. **Ketiadaan Jaminan Cakupan Total OpenSSL:**  
> Nilai `external` tidak menjamin seluruh alokasi memori native yang berkaitan dengan proses Web Crypto maupun OpenSSL tercakup dalam pelaporan. Oleh karena itu, metrik ini tidak dapat digunakan sebagai ukuran menyeluruh konsumsi memori native."*

### Penjelasan dan Ketentuan Editorial yang Ditegakkan:
1. **Peniadaan Penyebutan API Internal:** Frasa `Isolate::AdjustAmountOfExternalAllocatedMemory` secara resmi dihapus seutuhnya. Tidak ada referensi ke simbol API internal V8 atau implementasi internal runtime yang tidak diukur.
2. **Ketiadaan Klaim Tambahan:** Tidak ada penambahan klaim baru mengenai cara kerja internal V8, Node.js, atau OpenSSL.
3. **Pelestarian Hubungan Subkategori `arrayBuffers`:** Tetap ditegaskan bahwa alokasi `arrayBuffers` merupakan bagian (*subset*) dari angka pelaporan `external` menurut konteks dokumentasi resmi Node.js.
4. **Pelestarian Larangan Penjumlahan Redundan:** Tetap ditegaskan larangan mutlak menjumlahkan $\text{external} + \text{arrayBuffers}$.
5. **Pelestarian Hierarki Metrik Memori:**
   - **Metrik Primer:** $\Delta\text{arrayBuffers}$ (alokasi memori backing store buffer biner per-chunk streaming).
   - **Metrik Pendukung:** $\Delta\text{external}$ (memori C++ native yang terikat pada objek JavaScript menurut pelaporan Node.js).
   - **Metrik Konteks Proses:** $\text{rss}$ (total alokasi memori fisik proses keseluruhan).
   - **Informasi Heap JavaScript:** $\text{heapUsed}$ (objek JavaScript murni pada heap V8).

---

## 4. KOREKSI KEDUA — PENYEMPURNAAN RUMUSAN KEGAGALAN AUTENTIKASI AES-GCM

### Lokasi Sumber yang Dikoreksi:
Dokumen [B-CHAT-08A.4](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.4_Koreksi_Teknis_Final_Mandat_EXP-02.md) Bagian 3, Sub-bagian **Koreksi E — Presisi Penanganan Galat Pengecualian `OperationError` (§3.F)**, Poin 4.

### Teks Sebelumnya (B-CHAT-08A.4 §3.E Poin 4):
> *"4. **Atribusi Penyebab Berdasarkan Test Setup:**  
> Cipher AEAD (AES-256-GCM) secara sengaja didesain untuk tidak memberikan informasi diagnostik mengenai elemen mana yang rusak (ciphertext, auth tag, IV, atau field AAD) guna mencegah serangan *oracle side-channel*. Oleh karena itu, **atribusi penyebab kegagalan murni didasarkan pada kondisi injeksi kesalahan yang sengaja disiapkan pada lingkungan pengujian (*test setup*)**, bukan dari kemampuan runtime Web Crypto API untuk mendiagnosis komponen yang dimodifikasi."*

### Teks Pengganti Resmi (B-CHAT-08A.5):
> *"4. **Atribusi Penyebab Berdasarkan Test Setup:**  
> Operasi dekripsi AES-GCM yang gagal dalam autentikasi tidak menyediakan diagnostik terperinci yang dapat diandalkan untuk menentukan elemen mana yang berubah (ciphertext, authentication tag, IV, atau field AAD). Oleh karena itu, atribusi penyebab kegagalan dalam pengujian didasarkan pada kondisi injeksi kesalahan yang sengaja disiapkan dalam test setup, bukan pada kemampuan runtime Web Crypto API untuk mendiagnosis komponen yang dimodifikasi."*

### Penjelasan dan Ketentuan Editorial yang Ditegakkan:
1. **Peniadaan Klaim Kausalitas Desain:** Klaim mengenai tujuan desain cipher AEAD atau motif pencegahan serangan oracle secara resmi dihapus. Pernyataan difokuskan secara obyektif pada keterbatasan informasi faktual yang disediakan oleh Web Crypto API saat terjadi kegagalan autentikasi.
2. **Ketiadaan Asumsi Motif Runtime:** Tidak ada klaim spekulatif mengenai motif arsitektural implementasi vendor peramban atau runtime engine.
3. **Pelestarian Klasifikasi `OperationError`:** Tetap ditegaskan bahwa kegagalan autentikasi AES-GCM pada operasi dekripsi dengan parameter lain yang valid selalu menghasilkan exception `OperationError`.
4. **Pelestarian Assertion Berbasis Tipe Exception:** Pengujian memverifikasi nama tipe pengecualian (`OperationError`), bukan mencocokkan teks string pesan galat.
5. **Pelestarian Metodologi Atribusi Test Setup:** Validitas pengujian integritas (UJI-D1 s.d. UJI-D5, UJI-D9A, UJI-D10C) bertumpu pada kontrol variabel injeksi kesalahan pada lingkungan pengujian (*test setup*).

---

## 5. PERNYATAAN KONSISTENSI SPESIFIKASI TEKNIS KANONIKAL

Penerbitan dokumen B-CHAT-08A.5 **TIDAK MENGUBAH SATU PUN** parameter teknis kanonikal, formula kriptografis, atau batasan arsitektural yang telah disahkan pada B-CHAT-08A.4:

```
+-------------------------------------------------------------------------------------------------------------+
|                                    SPESIFIKASI KANONIKAL YANG TETAP BERLAKU PENUH                           |
+--------------------------+----------------------------------------------------------------------------------+
| Parameter                | Nilai Resmi yang Wajib Dipatuhi                                                  |
+--------------------------+----------------------------------------------------------------------------------+
| Kuota Berkas Maksimum    | Maksimum 10.000.000 byte (desimal, kuota 10 MB per file)                         |
| Kuota Pesan Gabungan     | Maksimum 25.000.000 byte (desimal, kuota total 25 MB per pesan chat)             |
| Batas Jumlah Berkas      | Maksimum 5 berkas per pesan chat tunggal                                         |
| Ukuran Chunk Standar     | Tepat 1.048.576 byte (1 MiB biner, 2^20 byte; kecuali chunk terakhir)            |
| Kunci Simetris (CEK)     | AES-256 (256-bit / 32 byte), dibangkitkan per sesi unggah via CSPRNG             |
| Nonce / IV AES-GCM       | 96-bit (12 byte): UploadSessionSalt (8 B) || uint32be(chunk_index) (4 B)         |
| Tag Autentikasi AES-GCM  | 128-bit (16 byte), di luar kuota plaintext                                       |
| Total Ukuran AAD         | Tepat 83 byte (10 field kanonikal terstruktur)                                   |
| Konteks MLS Exporter     | Tepat 48 byte (RoomUUID || MessageUUID || AttachmentUUID, biner mentah)          |
| Format Framing Simpan    | Format A: Nonce (12 B) || Ciphertext (L_i B) || AuthTag (16 B) = (L_i + 28 B)    |
| Formula Jumlah Chunk     | N = ceil(S / C), berkas 0 byte ditolak mutlak (1 <= N <= 10)                     |
| Formula Chunk Terakhir   | L_last = S mod C (jika S mod C != 0), atau C (jika S mod C == 0)                 |
| Aturan Validasi Indeks   | chunk_index >= N ditolak sebelum serialisasi AAD (0 <= chunk_index < N valid)    |
| Formula Tabrakan CEK     | P = 2^-256 (sangat kecil/negligible, namun bukan nol matematis mutlak)           |
| Formula Tabrakan Salt    | P_salt_collision ≈ m^2 / 2^65 (birthday bound salt 64-bit)                       |
| Aturan Crash Recovery    | Sesi gagal ditinggalkan; sesi baru wajib CEK baru, UploadSessionID baru, & salt  |
| Ambang Lonjakan Buffer   | PROPOSED: ΔarrayBuffers <= 20 MB (Δexternal sebagai metrik pendukung)            |
| Matriks Skenario         | 15 skenario terdampak sebagai pelengkap 32 skenario komprehensif B-CHAT-08A.1   |
| Status Otorisasi EXP-02  | EXP-02 NOT AUTHORIZED (Menunggu persetujuan tertulis Pemilik Proyek)            |
+--------------------------+----------------------------------------------------------------------------------+
```

---

## 6. PERNYATAAN INTEGRITAS ARSIP HISTORIS

Dokumen **B-CHAT-08A.5** merupakan dokumen amendemen independen baru. Dokumen ini **TIDAK MENGUBAH DAN TIDAK MENIMPA (*ZERO OVERWRITE*)** dokumen-dokumen historis yang telah terbit sebelumnya:
1. [B-CHAT-08A_Rencana_dan_Mandat_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A_Rencana_dan_Mandat_EXP-02.md) (Dokumen rencana awal EXP-02).
2. [B-CHAT-08A.1_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.1_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md) (32 skenario komprehensif).
3. [B-CHAT-08A.2_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.2_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md) (Pemisahan error manifest & staging).
4. [B-CHAT-08A.3_Koreksi_Presisi_Final_Mandat_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.3_Koreksi_Presisi_Final_Mandat_EXP-02.md) (Presisi CEK collision & metrik memori).
5. [B-CHAT-08A.4_Koreksi_Teknis_Final_Mandat_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.4_Koreksi_Teknis_Final_Mandat_EXP-02.md) (Generalisasi formula indeks & unifikasi error).
6. Seluruh dokumen forensik EXP-01 ([B-CHAT-07A.1](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A_Laporan_Eksekusi_EXP-01.md) s.d. [B-CHAT-07A.7](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-07A.7_Koreksi_Editorial_dan_Klasifikasi_Bukti_Final_EXP-01.md)).

Seluruh berkas di atas tetap tersimpan utuh di repositori sebagai rekaman jejak audit forensik dan evolusi spesifikasi teknis yang transparan dan dapat diverifikasi (*tamper-evident audit trail*).

---

## 7. KEPATUHAN BATAS OPERASIONAL MUTLAK

Penyusunan dokumen B-CHAT-08A.5 telah mematuhi seluruh batasan mandat operasional secara sempurna:
- **Zero Prototype Code:** Direktori sandbox `scratch/bchat_prototype/exp02_chunking_aad/` belum dibuat, dan tidak ada baris kode program atau skrip prototipe yang dibuat.
- **Zero Execution, Benchmark, & Testing:** Tidak ada uji coba eksekusi, simulasi Web Crypto API, benchmark memori Node.js, maupun pengujian fungsional yang dijalankan.
- **Zero Dependency & Toolchain Installation:** Tidak ada dependensi npm, compiler Rust, target WASM, atau paket sistem yang dipasang.
- **Zero Codebase Changes:** Direktori aplikasi Laravel ([`manajemen_skripsi/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/manajemen_skripsi/)) dan Next.js ([`frontend_manajemen_skripsi/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/frontend_manajemen_skripsi/)) tidak dimodifikasi.
- **Zero Database Changes:** Tidak ada migrasi basis data, perubahan struktur tabel, atau manipulasi record.
- **Zero Git Operations:** Tidak ada perintah Git (`git status`, `git diff`, `git commit`, `git checkout`, `git push`, dsb.) yang dijalankan.

---

## 8. PERNYATAAN EKSPLISIT STATUS OTORISASI EXP-02

$$\mathbf{STATUS\ OTORISASI\ EKSEKUSI\ EXP\text{-}02:\ EXP\text{-}02\ NOT\ AUTHORIZED}$$

> [!CAUTION]
> **PERINGATAN RESMI:**  
> Dokumen **B-CHAT-08A.5** adalah dokumen amendemen editorial penutup (*editorial precision closure — documentation only*). Penerbitan dokumen ini **TIDAK MENGOTORISASI** eksekusi fisik eksperimen EXP-02. Eksekusi fisik prototipe di sandbox hanya dapat dimulai setelah Pemilik Proyek memeriksa rangkaian dokumen perencanaan final ini dan memberikan **persetujuan tertulis eksplisit terpisah**.

---
*(Dokumen B-CHAT-08A.5 selesai disusun secara paripurna sebagai penutup amendemen editorial-teknis mandat EXP-02, siap ditinjau oleh Pemilik Proyek).*

