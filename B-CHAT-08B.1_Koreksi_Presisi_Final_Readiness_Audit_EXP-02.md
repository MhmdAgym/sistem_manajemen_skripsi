# B-CHAT-08B.1 — KOREKSI PRESISI FINAL READINESS AUDIT EXP-02
## AMENDEMEN AUDIT KESIAPAN PRE-OTORISASI: REKONSILIASI 35 SKENARIO, KONDISIONALITAS KEUNIKAN NONCE, PROBABILITAS CEK, HIERARKI DOKUMEN, DAN DISIPLIN STATISTIK

**Status Dokumen:** DOKUMEN AMENDEMEN AUDIT PRESISI FINAL (*CONTROLLED AUDIT AMENDMENT*)  
**Mode Operasi:** STRICTLY DOCUMENTATION ONLY — ZERO EXECUTION  
**Status Otorisasi EXP-02:** **EXP-02 NOT AUTHORIZED** *(Eksperimen EXP-02 belum diotorisasi)*  
**Dokumen Sumber yang Diamandemen:** [B-CHAT-08B.0 (Laporan Audit Kesiapan Pre-Otorisasi EXP-02)](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.5_Final_Editorial_Precision_Closure_EXP-02.md)  
**Dokumen Acuan Evaluasi:**
1. [B-CHAT-08A.5_Final_Editorial_Precision_Closure_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.5_Final_Editorial_Precision_Closure_EXP-02.md)
2. [B-CHAT-08A.4_Koreksi_Teknis_Final_Mandat_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.4_Koreksi_Teknis_Final_Mandat_EXP-02.md)
3. [B-CHAT-08A.1_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.1_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md)
4. [B-CHAT-06D_Mandat_Eksekusi_Terbatas_Gerbang_2.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-06D_Mandat_Eksekusi_Terbatas_Gerbang_2.md) (Revisi B-CHAT-06D.2 §3.2)
5. [B-CHAT-05_Spesifikasi_Final_E2EE.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-05_Spesifikasi_Final_E2EE.md) dan [B-CHAT-05A_Rekonsiliasi_Temuan_Audit.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-05A_Rekonsiliasi_Temuan_Audit.md)  
**Proyek:** Sistem Informasi Manajemen Skripsi UNUHA  
**Fitur:** Bimbingan Online Chat Real-Time E2EE  
**Tahap Proyek:** Gerbang 2 — Finalisasi Evaluasi Kesiapan Pre-Otorisasi EXP-02  
**Tanggal:** 5 November 2026  

---

> [!CAUTION]
> **PENEGASAN STATUS OPERASIONAL MUTLAK:**  
> Dokumen **B-CHAT-08B.1** ini berstatus **STRICTLY DOCUMENTATION ONLY — ZERO EXECUTION — EXP-02 NOT AUTHORIZED**. Dokumen ini merupakan koreksi presisi terhadap laporan audit B-CHAT-08B.0, bukan audit ulang menyeluruh dan **BUKAN** merupakan mandat atau izin eksekusi eksperimen fisik di sandbox. Tidak ada berkas kode sumber yang dibuat, tidak ada sandbox yang dibuat, tidak ada dependensi atau toolchain yang dipasang, tidak ada benchmark/pengujian yang dijalankan, dan tidak ada operasi Git. Seluruh dokumen historis tetap dilestarikan tanpa penimpaan (*zero overwrite*).

---

## 1. IDENTITAS DAN TUJUAN AMENDEMEN

Dokumen **B-CHAT-08B.1** diterbitkan sebagai instrumen koreksi presisi formal atas laporan audit kesiapan **B-CHAT-08B.0** (*Readiness Review and Pre-Authorization Gate*). Evaluasi mendalam terhadap laporan B-CHAT-08B.0 mengidentifikasi adanya ketidaktelitian aritmetika pada penghitungan total skenario serta sejumlah penyederhanaan konseptual yang berpotensi menimbulkan salah tafsir teknis jika tidak diperbaiki sebelum pengajuan ke Pemilik Proyek.

Tujuan spesifik dari amendemen B-CHAT-08B.1 meliputi:
1. **Rekonsiliasi Aritmetika Jumlah Skenario Uji:** Mengoreksi inkonsistensi internal narasi B-CHAT-08B.0 yang menyebut "34 skenario" padahal tabel faktualnya memuat 35 baris skenario, serta menyajikan pembuktian matematis pemetaan skenario sumber ([B-CHAT-08A.1](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.1_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md)) terhadap hasil pemecahan granular ([B-CHAT-08A.4](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.4_Koreksi_Teknis_Final_Mandat_EXP-02.md) / [B-CHAT-08A.5](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.5_Final_Editorial_Precision_Closure_EXP-02.md)).
2. **Kondisionalitas Keunikan Nonce AES-GCM:** Menghapus klaim simplistis bahwa counter monotonik saja menjamin keunikan nonce secara absolut; merumuskan lima prasyarat operasional yang wajib dipenuhi agar keunikan nonce terjamin dalam domain kunci yang sama; dan menegaskan prinsip utama: **Tidak boleh terjadi penggunaan ulang pasangan $(\text{CEK}, \text{Nonce})$ untuk operasi enkripsi yang berbeda**.
3. **Presisi Probabilitas Tabrakan CEK 256-Bit:** Menolak penyamaan rumus probabilitas sepasang kunci ($2^{-256}$) sebagai probabilitas umum untuk $m$ kunci; menetapkan formula aproksimasi *birthday bound* $P \approx \frac{m(m-1)}{2^{257}}$ untuk $m$ CEK independen; serta membedakannya dari tabrakan salt 64-bit.
4. **Hierarki Spesifikasi Dokumen Historis:** Menegaskan tata urutan rujukan teknis antara mandat induk Gerbang 2 ([B-CHAT-06D](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-06D_Mandat_Eksekusi_Terbatas_Gerbang_2.md)) dengan dokumen konsolidasi teknis terbaru ([B-CHAT-08A.5](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.5_Final_Editorial_Precision_Closure_EXP-02.md)) tanpa mengklaim dokumen historis telah diubah.
5. **Klasifikasi Statistik dan Batasan Bukti Telemetri:** Menegaskan bahwa 5–10 iterasi merupakan pengujian eksploratif awal dan bukan klaim statistik performa kuat; memisahkan hasil Node.js dari peramban nyata; serta menolak klaim penangkapan *peak memory* mutlak pada sampling periodik.

---

## 2. DAFTAR SUMBER YANG BENAR-BENAR DIBACA DAN DIUJI SILANG

Audit amendemen ini didasarkan pada pembacaan langsung dan uji silang teks terhadap 6 berkas otoritatif:
1. **Laporan Audit B-CHAT-08B.0:** Bagian 3 (Rekonsiliasi Spesifikasi), Bagian 8 (Matriks Skenario), Bagian 9 (Temuan), dan Bagian 10 (Kesiapan Lingkungan).
2. **Dokumen Mandat Penutup [B-CHAT-08A.5](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.5_Final_Editorial_Precision_Closure_EXP-02.md):** Bagian 3 (Koreksi `external`), Bagian 4 (Koreksi `OperationError`), dan Bagian 5 (Konsistensi Spesifikasi Kanonikal).
3. **Dokumen Konsolidasi Teknis [B-CHAT-08A.4](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.4_Koreksi_Teknis_Final_Mandat_EXP-02.md):** Bagian 3 (Enam Koreksi Teknis Wajib), Bagian 4 (Tiga Penyempurnaan Kecil), dan Bagian 5 (Matriks 15 Skenario Terdampak).
4. **Dokumen Matriks Komprehensif [B-CHAT-08A.1](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.1_Koreksi_Teknis_Terbatas_Mandat_EXP-02.md):** Bagian 3 (Matriks Pengujian Terkoreksi Resmi 32 Skenario: UJI-A sampai UJI-F2).
5. **Dokumen Mandat Induk Gerbang 2 [B-CHAT-06D](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-06D_Mandat_Eksekusi_Terbatas_Gerbang_2.md):** Revisi Teknis B-CHAT-06D.2 Bagian 3.2 (Spesifikasi Awal EXP-02) dan Bagian 3.3 (EXP-03 OD-17).
6. **Dokumen Fondasi Arsitektur [B-CHAT-05](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-05_Spesifikasi_Final_E2EE.md) & [B-CHAT-05A](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-05A_Rekonsiliasi_Temuan_Audit.md):** Keputusan Desain OD-02 (Batas Kuota) dan OD-17 (Kunci Lampiran).

---

## 3. TEMUAN KOREKSI TERHADAP B-CHAT-08B.0

Berdasarkan analisis komparatif, ditemukan lima butir inkonsistensi dan kelemahan narasi pada dokumen B-CHAT-08B.0:

1. **Inkonsistensi Aritmetika Tabel vs Narasi pada Bagian 8 B-CHAT-08B.0:**  
   Teks narasi Bagian 8 dan Kesimpulan Bagian 8 B-CHAT-08B.0 menyebutkan total skenario adalah *"Tepat 34 skenario pengujian"*. Namun, tabel matriks pada bagian yang sama mendaftarkan baris nomor 1 sampai nomor 35. Terdapat kontradiksi internal sebanyak 1 skenario akibat kesalahan klerikal saat menjumlahkan skenario hasil pemecahan.
2. **Klaim Keunikan Nonce yang Terlalu Mutlak pada Bagian 3 Poin 3 B-CHAT-08B.0:**  
   B-CHAT-08B.0 menyatakan bahwa ketiadaan *nonce reuse* *"terjamin secara operasional karena counter bersifat monotonik naik"*. Pernyataan ini tidak lengkap karena counter monotonik hanya menjamin keunikan *di dalam satu sesi tunggal*. Jika CEK digunakan ulang, atau jika terjadi crash dan pengulangan enkripsi dengan salt yang sama, tabrakan nonce tetap dapat terjadi.
3. **Penyederhanaan Probabilitas Tabrakan CEK:**  
   B-CHAT-08B.0 mengutip $P = 2^{-256}$ seolah-olah probabilitas tabrakan kunci selalu bernilai demikian untuk sembarang kumpulan sesi unggah. Angka $2^{-256}$ hanya berlaku untuk peluang tabrakan antara tepat dua kunci tertentu. Untuk sistem operasional dengan $m$ sesi, probabilitas akumulatif mengikuti hukum *birthday paradox*.
4. **Status Hierarkis Dokumen B-CHAT-06D vs B-CHAT-08A.5:**  
   B-CHAT-08B.0 mengidentifikasi perbedaan layout AAD dan nonce antara B-CHAT-06D §3.2 dan B-CHAT-08A.5, namun belum menyusun tata urutan hierarki formal yang mengikat tim teknis.
5. **Klaim Kekuatan Statistik Sampel 5–10 Iterasi:**  
   Penyebutan sampling 5–10 iterasi pada Bagian 10 butir 5 B-CHAT-08B.0 perlu ditegaskan batasannya sebagai eksplorasi awal, bukan bukti performa tergeneralisasi untuk sistem produksi.

---

## 4. REKONSILIASI JUMLAH SKENARIO YANG DAPAT DIBUKTIKAN (35 SKENARIO GRANULAR)

Audit melakukan rekonsiliasi aritmetika ketat untuk membuktikan jumlah skenario yang sah:

```
+-------------------------------------------------------------------------------------------------------------+
|                                    REKONSILIASI ARITMETIKA SKENARIO EXP-02                                  |
+----+-----------------------------------------------------+---------------+----------------------------------+
| No | Komponen Matriks Skenario                           | Jumlah        | Dasar Pembuktian & Rujukan       |
+----+-----------------------------------------------------+---------------+----------------------------------+
| 1  | Skenario Induk Sumber Komprehensif                  | 32 Skenario   | B-CHAT-08A.1 Bagian 3 (Tabel)    |
|    | (UJI-A, UJI-B1..B5, UJI-C1..C7, UJI-D1..D13,        |               |                                  |
|    |  UJI-E1..E4, UJI-F1..F2)                            |               |                                  |
+----+-----------------------------------------------------+---------------+----------------------------------+
| 2  | Skenario Induk yang Dipecah (*Split*):              |               |                                  |
|    | - UJI-D9 (PlaintextLength) dipecah -> D9A & D9B     | +1 Bersih     | B-CHAT-08A.4 §3.D (1 jadi 2)     |
|    | - UJI-D10 (Indeks) dipecah -> D10A, D10B, & D10C    | +2 Bersih     | B-CHAT-08A.4 §3.D (1 jadi 3)     |
+----+-----------------------------------------------------+---------------+----------------------------------+
| 3  | Total Pertambahan Bersih dari Pemecahan             | +3 Skenario   | (2 - 1) + (3 - 1) = +3           |
+----+-----------------------------------------------------+---------------+----------------------------------+
| 4  | TOTAL SKENARIO GRANULAR AKTUAL                      | 35 SKENARIO   | 32 + 3 = 35 Skenario Granular    |
+----+-----------------------------------------------------+---------------+----------------------------------+
| 5  | Skenario Terdampak Koreksi Presisi (Matriks Ringkas)| 15 Skenario   | B-CHAT-08A.4 §5 & B-08A.5 §5     |
| 6  | Skenario Utuh Tanpa Perubahan dari B-CHAT-08A.1     | 20 Skenario   | 35 - 15 = 20 Skenario Utuh       |
+----+-----------------------------------------------------+---------------+----------------------------------+
```

### Rincian 20 Skenario Utuh yang Dilengkapi oleh 15 Skenario Terdampak:
- **Kategori B (Variasi Ukuran File Sah):** UJI-B1, UJI-B2, UJI-B3, UJI-B4, UJI-B5 ($5\text{ skenario}$).
- **Kategori C (Penegakan Batas Kuota):** UJI-C1, UJI-C2, UJI-C3, UJI-C4, UJI-C5, UJI-C6, UJI-C7 ($7\text{ skenario}$).
- **Kategori D (Integritas Kriptografis AAD Lainnya):** UJI-D1, UJI-D2, UJI-D3, UJI-D4, UJI-D5 ($5\text{ skenario}$).
- **Kategori D (Integritas Perakitan Assembly):** UJI-D6, UJI-D7, UJI-D11 ($3\text{ skenario}$).
- **Total Utuh:** $5 + 7 + 5 + 3 = 20\text{ skenario}$.

### Rincian 15 Skenario Terdampak Koreksi Presisi:
- **Siklus Normal:** UJI-A ($1\text{ skenario}$).
- **Pecahan UJI-D9:** UJI-D9A dan UJI-D9B ($2\text{ skenario}$).
- **Pecahan UJI-D10:** UJI-D10A, UJI-D10B, dan UJI-D10C ($3\text{ skenario}$).
- **Transport Reordering:** UJI-D8 dan UJI-D12 ($2\text{ skenario}$).
- **Lifecycle Nonce & Key Safety:** UJI-E1, UJI-E2, UJI-E3, dan UJI-E4 ($4\text{ skenario}$).
- **Staging Cleanup:** UJI-D13 ($1\text{ skenario}$).
- **Evaluasi Memori:** UJI-F1 dan UJI-F2 ($2\text{ skenario}$).
- **Total Terdampak:** $1 + 2 + 3 + 2 + 4 + 1 + 2 = 15\text{ skenario}$.

$$\mathbf{TOTAL\ SKENARIO\ PARIPURNA:\ 20\ (Utuh)\ +\ 15\ (Terdampak)\ =\ 35\ SKENARIO\ GRANULAR}$$

> [!IMPORTANT]
> **Koreksi Terhadap B-CHAT-08B.0:**  
> Angka "34 skenario" pada teks narasi B-CHAT-08B.0 dinyatakan sebagai kesalahan klerikal (*typing error*). Jumlah skenario resmi yang dapat dibuktikan secara matematis pada dokumen mandat EXP-02 adalah **35 skenario granular**. Tidak ada skenario yang hilang dan tidak ada skenario yang terhitung ganda.

---

## 5. KOREKSI JAMINAN KEUNIKAN NONCE DAN PROBABILITAS TABRAKAN CEK

### 5.1. Kondisionalitas Keunikan Nonce AES-GCM (§3.B)
Struktur Nonce Opsi 1:
$$\text{Nonce} = \text{UploadSessionSalt}\ (8\text{ byte}) \parallel \text{uint32be}(\text{chunk\_index})\ (4\text{ byte})$$

Counter monotonik `uint32be(chunk_index)` ($0 \le \text{chunk\_index} < N$, dengan $N \le 10$) **HANYA** menjamin keunikan nonce di dalam satu sesi di bawah kunci yang sama apabila lima kondisi keselamatan operasional berikut terpenuhi secara simultan:
1. **Independensi Kunci per Sesi:** CEK tidak pernah digunakan kembali untuk sesi enkripsi baru.
2. **Kepatuhan Siklus Salt dan Counter:** Setiap sesi unggah menghasilkan `UploadSessionSalt` acak baru 64-bit via CSPRNG, dan counter dinaikkan secara sekuensial monotonik.
3. **Larangan Enkripsi Ulang Indeks:** Setiap indeks chunk ($i$) hanya boleh dienkripsi tepat satu kali untuk pasangan $(\text{CEK}, \text{Nonce})$ tersebut.
4. **Aturan Pengiriman Ulang (Retry):** Percobaan ulang akibat gangguan transmisi jaringan hanya boleh dilakukan dengan **mengirimkan ulang ciphertext yang telah terbukti tersimpan utuh di media penyimpanan lokal**, tanpa memicu enkripsi ulang.
5. **Aturan Pembatalan Sesi (Session Abandonment):** Apabila aplikasi mengalami crash, proses terhenti mendadak, atau status keberhasilan persistensi ciphertext tidak dapat dipastikan, sesi lama **wajib ditinggalkan seutuhnya**. Sesi unggah baru wajib membangkitkan **tiga entitas baru: CEK baru, UploadSessionID baru, dan UploadSessionSalt baru**.

> [!CAUTION]
> **PRINSIP KESELAMATAN KRIPTOGRAFIS MUTLAK:**  
> **Tidak boleh terjadi penggunaan ulang pasangan $(\text{CEK}, \text{Nonce})$ untuk operasi enkripsi yang berbeda.**  
> Generator salt 64-bit **TIDAK** memberikan jaminan matematis absolut bebas tabrakan secara global. Keamanan skema ini bergantung pada non-reuse pasangan kunci-nonce dan independensi CEK baru per sesi.

---

### 5.2. Presisi Probabilitas Tabrakan CEK 256-Bit (§3.C)
Untuk menghindari kerancuan teori probabilitas:
1. **Peluang Tabrakan Dua Kunci Tertentu:**  
   Peluang bahwa dua kunci acak 256-bit yang dibangkitkan secara seragam (*uniform*) dari ruang kunci $2^{256}$ bernilai identik adalah:
   $$P_{\text{pair\_collision}} = 2^{-256} \approx 8{,}636 \times 10^{-78}$$
2. **Peluang Tabrakan pada Kumpulan $m$ Kunci (Birthday Bound):**  
   Untuk suatu sistem yang mengoperasikan $m$ sesi unggah independen, probabilitas terjadinya *setidaknya satu pasang* kunci yang bertabrakan mengikuti aproksimasi *birthday paradox*:
   $$P_{\text{collision}}(m) \approx \frac{m(m - 1)}{2 \times 2^{256}} = \frac{m(m - 1)}{2^{257}}$$
   *(Catatan: Formula ini merupakan pendekatan probabilistik teoretis, bukan jaminan kemustahilan fisik).*
3. **Pembedaan Terhadap Tabrakan Salt 64-Bit:**  
   Probabilitas tabrakan salt 64-bit pada $m$ sesi unggah independen adalah:
   $$P_{\text{salt\_collision}}(m) \approx \frac{m(m - 1)}{2 \times 2^{64}} = \frac{m(m - 1)}{2^{65}}$$
   Karena ruang salt ($2^{64}$) jauh lebih kecil daripada ruang kunci ($2^{256}$), probabilitas tabrakan salt pada $m = 10^6$ sesi adalah $P \approx 2{,}71 \times 10^{-8}$. Namun, tabrakan salt antar-sesi tidak merusak keamanan cipher AES-GCM selama kedua sesi tersebut menggunakan CEK yang berbeda.

---

## 6. HIERARKI SPESIFIKASI DOKUMEN HISTORIS

Untuk mencegah kebingungan akibat perbedaan formulasi antara dokumen mandat induk Gerbang 2 ([B-CHAT-06D](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-06D_Mandat_Eksekusi_Terbatas_Gerbang_2.md)) dengan dokumen amendemen teknis terbaru ([B-CHAT-08A.5](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.5_Final_Editorial_Precision_Closure_EXP-02.md)), ditetapkan **Hierarki Spesifikasi Resmi EXP-02**:

```
+-------------------------------------------------------------------------------------------------------------+
|                                    HIERARKI SPESIFIKASI OPERASIONAL EXP-02                                  |
+----+----------------------------+------------------------------------------+--------------------------------+
| No | Tingkat Hierarki Dokumen   | Status Kedudukan Dokumen                 | Fungsi & Batas Otoritas Teknis |
+----+----------------------------+------------------------------------------+--------------------------------+
| 1  | B-CHAT-08A.5               | ACUAN TEKNIS TERTINGGI (Spesifikasi Final)| Menetapkan parameter operasional|
|    | (didukung B-08A.4 & B-08A.3)| Dokumen Koreksi Mandat EXP-02            | yang wajib dieksekusi prototipe|
+----+----------------------------+------------------------------------------+--------------------------------+
| 2  | B-CHAT-08A.1               | ACUAN SUITE PENGUJIAN KOMPREHENSIF       | Menjadi sumber 32 skenario induk|
|    |                            | Matriks Skenario Lengkap                 | yang dilengkapi oleh B-08A.4   |
+----+----------------------------+------------------------------------------+--------------------------------+
| 3  | B-CHAT-06D (Revisi 06D.2)  | MANDAT INDUK GERBANG 2                   | Mengatur batas koridor sandbox,|
|    |                            | Dokumen Otoritas Induk Persetujuan       | isolasi, dan tujuan eksperimen |
+----+----------------------------+------------------------------------------+--------------------------------+
| 4  | B-CHAT-05 & B-CHAT-05A     | FONDASI ARSITEKTUR SISTEM                | Mengatur keputusan induk OD-02 |
|    |                            | Dokumen Desain Sistem E2EE UNUHA         | (kuota) dan OD-17 (kunci)      |
+----+----------------------------+------------------------------------------+--------------------------------+
```

### Ketentuan Resolusi Konflik Historis:
1. **Layout AAD:** Draf awal B-CHAT-06D §3.2 butir 5 (`AAD_VERSION`, `CIPHER_SUITE`, dsb.) **dinyatakan usang (*superseded*)** oleh layout AAD Kanonikal 83-byte pada B-CHAT-08A.5 Bagian 2 (`Magic UNH1`, `Protocol Version 0x0001`, `Domain Separator 0x02`, `RoomUUID`, `MessageUUID`, `AttachmentUUID`, `UploadSessionID`, `ChunkIndex`, `TotalChunks`, `PlaintextLength`). Dokumen B-CHAT-06D tetap utuh di repositori sebagai arsip historis tanpa diubah.
2. **Struktur Nonce:** Draf awal B-CHAT-06D §3.2 butir 4 (nonce nol statis $0000000000000000_{16} \parallel \text{uint32be}$) **dinyatakan usang** dan digantikan secara mutlak oleh Nonce Opsi 1 pada B-CHAT-08A.5 ($\text{UploadSessionSalt } 8\text{ B} \parallel \text{uint32be } 4\text{ B}$).
3. **Prinsip Non-Destruktif:** Dokumen historis B-CHAT-06D dan B-CHAT-08A tidak dimodifikasi secara retrospektif (*zero overwrite*). Penyesuaian teknis berlaku melalui rantai dokumen amendemen formal.
4. **Penanganan Konflik Baru:** Apabila di kemudian hari ditemukan konflik lain yang belum diatur dalam rantai B-CHAT-08A.1 s.d. B-CHAT-08A.5, konflik tersebut **wajib ditandai `DESIGN DECISION REQUIRED`** dan diajukan kepada Pemilik Proyek, bukan diasumsikan selesai sepihak.

---

## 7. KLASIFIKASI STATISTIK DAN BATASAN PENGUKURAN MEMORI

### 7.1. Klasifikasi Disiplin Statistik Eksperimen (§3.E)
Pengujian performa pada EXP-02 (UJI-F1 dan UJI-F2) wajib mematuhi pemisahan tingkatan bukti ilmiah:
1. **Pengulangan 5–10 Iterasi sebagai Observasi Awal:**  
   Jumlah pengulangan 5 sampai 10 kali pada lingkungan sandbox Node.js CLI merupakan **observasi eksploratif awal (*early exploratory observation*)**. Angka pengulangan ini **TIDAK OTOMATIS menjadi ukuran sampel yang memadai untuk membuat klaim performa yang tergeneralisasi secara universal**.
2. **Kewajiban Pelaporan Statistik Deskriptif:**  
   Setiap pelaporan metrik (latensi enkripsi/dekripsi, lonjakan alokasi buffer) wajib mencantumkan:
   - Jumlah sampel pengulangan aktual ($N$).
   - Metode perhitungan: Median, $p95$ (*95th percentile*), Mean (rata-rata), Minimum, Maksimum, dan Standar Deviasi.
   - Dilarang keras mengarang angka sampel atau mengekstrapolasi data yang tidak diukur langsung.
3. **Pemisahan Bukti Node.js vs Web Worker Browser:**  
   Bukti efisiensi memori yang diperoleh dari proses Node.js CLI **tidak boleh diklaim sebagai bukti performa Web Worker di peramban web nyata (Chromium/Gecko/WebKit)**.

---

### 7.2. Presisi Metrik Memori Node.js (`process.memoryUsage()`)
1. **Metrik Primer yang Diamati:**  
   $$\Delta\text{arrayBuffers} = \text{arrayBuffers}_{\text{peak}} - \text{arrayBuffers}_{\text{baseline}}$$  
   Mengukur alokasi memori biner murni pada *backing store* `ArrayBuffer` dan Node.js `Buffer`.
2. **Metrik Pendukung:**  
   $$\Delta\text{external} = \text{external}_{\text{peak}} - \text{external}_{\text{baseline}}$$  
   Mencatat memori C++ native yang terikat pada objek JavaScript menurut mekanisme pelaporan Node.js.
3. **Larangan Mutlak Penjumlahan Redundan:**  
   Karena alokasi `arrayBuffers` sudah termasuk di dalam angka pelaporan `external`, **DILARANG KERAS menjumlahkan $\text{external} + \text{arrayBuffers}$**.
4. **Metrik Konteks:**  
   - $\text{rss}$ (*Resident Set Size*): Konteks konsumsi memori fisik proses Node.js secara keseluruhan (bukan ukuran khusus buffer berkas).
   - $\text{heapUsed}$: Konteks alokasi objek JavaScript murni pada heap V8.
5. **Keterbatasan Snapshot Sampling Periodik:**  
   Pengambilan sampel memori berbasis jeda waktu (*interval sampling*) dapat melewatkan lonjakan memori transien (*transient memory spikes*) yang berlangsung sangat singkat di antara siklus baca-tulis chunk.
6. **Status Ambang Batas Usulan:**  
   Target lonjakan $\Delta\text{arrayBuffers} \le 20\text{ MB}$ pada pemrosesan berkas $10.000.000\text{ byte}$ tetap berstatus **`PROPOSED`**, bukan standar universal dan bukan jaminan batas total konsumsi memori proses (`rss`).

---

## 8. KETENTUAN TAMBAHAN EVALUASI PENGGUNAAN ULANG NONCE (UJI-E1)

Untuk mencegah kekeliruan dalam interpretasi pengujian keamanan nonce:
1. **Objek Pengujian UJI-E1:**  
   Skenario UJI-E1 dirancang secara spesifik untuk menguji **keandalan *in-memory nonce-use ledger* pada lapisan prototipe aplikasi klien UNUHA**.
2. **Ketiadaan Fitur Bawaan Web Crypto API:**  
   Standar Web Cryptography API W3C bersifat *stateless* dan **tidak memiliki fitur bawaan untuk mendeteksi atau menolak penggunaan ulang nonce**. Dilarang mengklaim bahwa Web Crypto API secara otomatis mencegah *nonce reuse*.
3. **Mekanisme Ledger yang Diuji:**  
   Ledger prototipe wajib mencatat setiap pasangan $(\text{CEK\_ID}, \text{Nonce})$ yang mulai digunakan untuk enkripsi, dan menolak secara *fail-closed* setiap upaya enkripsi baru yang menggunakan pasangan yang sama sebelum operasi `crypto.subtle.encrypt` dipanggil.
4. **Batasan Bukti UJI-E1:**  
   Kelulusan pengujian UJI-E1 hanya membuktikan bahwa kode logika ledger prototipe bekerja dengan benar di lingkungan pengujian; **kelulusan ini tidak membuktikan keselamatan nonce di seluruh browser peramban nyata atau sistem produksi multi-tab**.

---

## 9. DAFTAR ISU BERSTATUS UNVERIFIED, PROPOSED, ATAU DESIGN DECISION REQUIRED

Sebelum otorisasi pelaksanaan fisik dipertimbangkan, isu-isu berikut dicatat secara transparan sesuai status keilmuan yang jujur:

```
+-------------------------------------------------------------------------------------------------------------+
|                        DAFTAR ISU UNVERIFIED / PROPOSED / DESIGN DECISION REQUIRED                          |
+----+-----------------------------------------------------+---------------------------+----------------------+
| No | Aspek Teknis / Isu Evaluasi                         | Status Klasifikasi        | Keterangan Batasan   |
+----+-----------------------------------------------------+---------------------------+----------------------+
| 1  | Keutuhan Plaintext Byte-by-Byte (UJI-A & UJI-B)     | UNVERIFIED                | Menunggu eksekusi PoC|
| 2  | Penolakan Kriptografis GCM terhadap Tampering AAD   | UNVERIFIED                | Menunggu eksekusi PoC|
| 3  | Toleransi Perakitan Transport Reordering (D8 & D12) | UNVERIFIED                | Menunggu eksekusi PoC|
| 4  | Penegakan Batas Kuota Maksimum 10MB / 25MB / 5 File | UNVERIFIED                | Menunggu eksekusi PoC|
| 5  | Keandalan Pencegahan Nonce Reuse via Ledger (UJI-E1)| UNVERIFIED                | Menunggu eksekusi PoC|
| 6  | Mitigasi Fail-Closed Saat CSPRNG Error (UJI-E2)     | UNVERIFIED                | Menunggu eksekusi PoC|
| 7  | Efektivitas Best-Effort Cleanup Staging (UJI-D13)   | UNVERIFIED                | Menunggu eksekusi PoC|
| 8  | Mekanisme Autentikasi dan Integritas Manifest Berkas| DESIGN DECISION REQUIRED  | Memerlukan kanal E2EE|
| 9  | Ambang Lonjakan Buffer Streaming <= 20 MB (UJI-F1)  | PROPOSED                  | Target desain usulan |
| 10 | Evaluasi Memori Full-Buffer In-Memory (UJI-F2)      | OBSERVATIONAL             | Baseline kontrol V8  |
| 11 | Kinerja Web Crypto API pada Peramban Nyata          | UNVERIFIED                | Di luar lingkup Node |
| 12 | Ketiadaan UI Freeze / Long Task di Peramban Web     | NOT MEASURED              | Tidak diukur di Node |
| 13 | Status Pelaksanaan Eksperimen Fisik EXP-02          | EXP-02 NOT AUTHORIZED     | Menunggu izin tertulis|
+----+-----------------------------------------------------+---------------------------+----------------------+
```

---

## 10. KESIMPULAN KESIAPAN DAN REKOMENDASI UNTUK PEMILIK PROYEK

Dokumen amendemen **B-CHAT-08B.1** telah berhasil mengoreksi seluruh ketidakakuratan aritmetika, meluruskan jaminan keunikan nonce, menetapkan hierarki dokumen, serta mendisiplinkan metodologi statistik dan metrik memori.

Dengan selesainya koreksi presisi ini, kesimpulan kesiapan dokumentasi mandat EXP-02 dikukuhkan sebagai:

$$\mathbf{STATUS\ KESIAPAN:\ READY\ FOR\ OWNER\ AUTHORIZATION\ REVIEW}$$

### Justifikasi Kelayakan untuk Ditinjau Pemilik Proyek:
1. **Total Skenario Terbukti:** Sebanyak **35 skenario granular** telah direkonsiliasi dan dipetakan secara matematis tanpa ambiguitas.
2. **Kondisionalitas Nonce Presisi:** Aturan *crash-safety*, mitigasi kegagalan persistensi, dan prinsip pelarangan penggunaan ulang pasangan $(\text{CEK}, \text{Nonce})$ telah ditetapkan secara operasional.
3. **Hierarki Spesifikasi Jelas:** B-CHAT-08A.5 ditetapkan sebagai rujukan teknis tertinggi yang mengesampingkan parameter draf lama pada B-CHAT-06D §3.2 tanpa merusak arsip historis.
4. **Kejujuran Bukti & Metrik Memori:** Penjumlahan redundan dilarang mutlak, metrik primer $\Delta\text{arrayBuffers}$ ditetapkan, ambang $20\text{ MB}$ berstatus `PROPOSED`, dan keterbatasan Node.js vs browser nyata diakui secara transparan.

---

## 11. PENEGASAN STATUS OTORISASI EXP-02

$$\mathbf{STATUS\ FINAL\ OTORISASI\ EXP\text{-}02:\ EXP\text{-}02\ NOT\ AUTHORIZED}$$

> [!CAUTION]
> **PERINGATAN OPERASIONAL MUTLAK:**  
> Penerbitan dokumen amendemen **B-CHAT-08B.1** ini **TIDAK MENGOTORISASI** eksekusi prototipe EXP-02. Direktori sandbox `scratch/bchat_prototype/exp02_chunking_aad/` **BELUM DIBUAT** dan kode prototipe **BELUM DITULIS**. Pelaksanaan fisik di sandbox hanya dapat dimulai setelah Pemilik Proyek memeriksa laporan kesiapan ini dan memberikan **persetujuan tertulis eksplisit terpisah**.

---
*(Dokumen B-CHAT-08B.1 selesai disusun secara paripurna sebagai koreksi presisi final audit kesiapan EXP-02, siap ditinjau oleh Pemilik Proyek).*

