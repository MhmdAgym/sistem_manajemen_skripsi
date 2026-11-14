# B-CHAT-08D — PENYELESAIAN PRASYARAT DESAIN EXP-02
## ANALISIS DAN KEPUTUSAN DESAIN ATAS TIGA PRASYARAT DOKUMENTASI GERBANG PRE-EKSEKUSI EXP-02

**Status Dokumen:** DOKUMEN KEPUTUSAN DESAIN PRASYARAT PRE-EKSEKUSI (*PRE-EXECUTION DESIGN PREREQUISITE RESOLUTION*)  
**Mode Operasi:** STRICTLY DOCUMENTATION ONLY / DESIGN-ONLY — ZERO EXECUTION  
**Status Evaluasi Desain:** **DESIGN REVIEW REQUIRED** *(Menunggu peninjauan dan persetujuan tertulis Pemilik Proyek)*  
**Status Otorisasi EXP-02:** **EXP-02 NOT AUTHORIZED** *(Eksperimen EXP-02 belum diotorisasi)*  
**Dokumen Induk Gerbang Final:** [B-CHAT-08C_EXP-02_Final_Pre-Execution_Gate.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08C_EXP-02_Final_Pre-Execution_Gate.md)  
**Dokumen Rangkaian Amendemen Acuan:**
1. [B-CHAT-08C.1_Final_Gate_Precision_Correction_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08C.1_Final_Gate_Precision_Correction_EXP-02.md)
2. [B-CHAT-08C.2_Final_Gate_Evidence_Integrity_Correction_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08C.2_Final_Gate_Evidence_Integrity_Correction_EXP-02.md)
3. [B-CHAT-08C.3_Final_Cryptographic_Binding_Result_Classification_Correction_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08C.3_Final_Cryptographic_Binding_Result_Classification_Correction_EXP-02.md)
4. [B-CHAT-08C.4_Final_Security_Claim_Chunk_Consistency_Checklist_Reconciliation_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08C.4_Final_Security_Claim_Chunk_Consistency_Checklist_Reconciliation_EXP-02.md)  
**Dokumen Acuan Teknis Tertinggi:** [B-CHAT-08A.5_Final_Editorial_Precision_Closure_EXP-02.md](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08A.5_Final_Editorial_Precision_Closure_EXP-02.md)  
**Proyek:** Sistem Informasi Manajemen Skripsi UNUHA  
**Fitur:** Bimbingan Online Chat Real-Time E2EE  
**Tahap Proyek:** Penuntasan Prasyarat Desain Gerbang 2 Pre-Eksekusi EXP-02  
**Tanggal Penerbitan:** 5 November 2026  

---

> [!CAUTION]
> **PENEGASAN STATUS OPERASIONAL MUTLAK:**  
> Dokumen **B-CHAT-08D** ini berstatus **STRICTLY DESIGN-ONLY / DOCUMENTATION-ONLY — ZERO EXECUTION — EXP-02 NOT AUTHORIZED**. Dokumen ini disusun semata-mata untuk menetapkan spesifikasi desain atas tiga prasyarat dokumentasi yang masih berstatus terbuka pasca-rekonsiliasi B-CHAT-08C.4. Penerbitan dokumen ini **BUKAN** merupakan izin atau otorisasi untuk memulai pembuatan direktori sandbox, pembuatan berkas fixture fisik, penulisan modul kode prototipe, instalasi dependensi, atau eksekusi eksperimen kriptografi apa pun. Seluruh dokumen historis repositori dilestarikan utuh tanpa penimpaan (*zero overwrite*).

---

## 1. TUJUAN DAN BATAS MANDAT OPERASIONAL

### 1.1. Tujuan Mandat B-CHAT-08D
Berdasarkan hasil evaluasi gerbang final pre-eksekusi pada dokumen [B-CHAT-08C.4](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08C.4_Final_Security_Claim_Chunk_Consistency_Checklist_Reconciliation_EXP-02.md), sistem berada pada status `READY WITH EXPLICIT PRE-EXECUTION CONDITIONS`. Status tersebut mengidentifikasi empat prasyarat yang wajib diselesaikan sebelum prototipe kode EXP-02 dapat dibangun di sandbox. Dari empat prasyarat tersebut, satu prasyarat (Prasyarat 1: Audit Verifikasi Lingkungan Workstation) memerlukan eksekusi skrip audit empiris pada lingkungan host sehingga wajib dipisahkan dan ditangguhkan hingga otorisasi eksekusi terbit.

Tiga prasyarat lainnya merupakan ranah perancangan dan spesifikasi konseptual (*design and documentation prerequisites*) yang dapat dan harus dituntaskan secara teoretis-dokumentatif terlebih dahulu agar tidak menyisakan ruang ambiguitas saat otorisasi dipertimbangkan oleh Pemilik Proyek. Mandat B-CHAT-08D bertujuan menuntaskan keputusan desain atas ketiga isu terbuka tersebut:
1. **Prasyarat Desain 1 (Fixture):** Penetapan skema data fixture manifest sintetis dan metodologi *independent expected-value ground truth* yang bebas dari jebakan *circular test oracle*.
2. **Prasyarat Desain 2 (Metadata Digest):** Penetapan skema penyimpanan metadata digest lokal untuk verifikasi integritas fisik artefak ciphertext pada alur persistensi dan retry, dengan pemisahan tegas terhadap lapisan autentikasi AEAD.
3. **Prasyarat Desain 3 (Circuit Breaker):** Penetapan kebijakan operasional modul henti darurat (*circuit breaker*), termasuk formulasi kuantitatif ambang batas kegagalan beruntun, cakupan penghitung, aturan reset, perlakuan terhadap hasil `UNVERIFIED`, dan prosedur pencatatan bukti insiden.

### 1.2. Batas Mandat dan Larangan Mutlak Eksekusi
Pekerjaan ini tunduk pada batas-batas operasional yang tidak dapat dinegosiasikan:
- **Dilarang Menjalankan EXP-02:** Tidak ada eksperimen kriptografi, streaming chunk, atau benchmark performa yang dijalankan.
- **Dilarang Membuat Prototipe Fisik:** Tidak ada direktori sandbox yang dibuat di `scratch/bchat_prototype/exp02_chunking_aad/` dan tidak ada baris kode program JavaScript/TypeScript yang ditulis.
- **Dilarang Membuat Berkas Fixture Aktual:** Tidak ada berkas biner `.bin`, berkas teks `.json`, berkas hash, atau data ciphertext nyata yang dihasilkan di media disk.
- **Dilarang Menjalankan Perintah Runtime:** Tidak ada eksekusi perintah Node.js, CLI, browser, Web Worker, framework Next.js, framework Laravel, atau instalasi paket NPM.
- **Dilarang Mengubah Basis Kode Utama dan Basis Data:** Direktori [`manajemen_skripsi/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/manajemen_skripsi/) dan [`frontend_manajemen_skripsi/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/frontend_manajemen_skripsi/) serta database MySQL tidak disentuh sama sekali.
- **Dilarang Mengubah Git State:** Tidak ada perintah git yang dijalankan (`commit`, `checkout`, `reset`, `push`, dsb.).
- **Dilarang Menimpa Dokumen Historis:** Seluruh dokumen sebelumnya tetap terlestarikan utuh sebagai bukti audit.
- **Dilarang Mengubah Parameter Kanonikal:** Parameter teknis kanonikal (14 parameter kanonikal, AAD 83-byte, Nonce 12-byte Opsi 1, formula chunking piecewise, matriks 35 skenario granular) dilestarikan tanpa perubahan.

---

## 2. DAFTAR SUMBER OTORITATIF DAN RENTANG PEMBACAAN AKTUAL

Penyusunan keputusan desain pada dokumen B-CHAT-08D didasarkan pada verifikasi langsung terhadap sumber-sumber dokumentatif repositori dengan pencatatan baris pembacaan faktual:

```
+--------------------------------------------------------------------------------------------------------------------------------------------------+
|                                           TABEL REKONSILIASI LOG PEMBACAAN SUMBER DOKUMENTATIF                                                   |
+----+--------------------------------------------+------------------------------------------+-----------------------+-----------------------------+
| No | Dokumen Sumber Otoritatif                  | Rentang Baris yang Benar-Benar Diperiksa | Status Bukti Faktual  | Fokus Verifikasi Teknis     |
+----+--------------------------------------------+------------------------------------------+-----------------------+-----------------------------+
| 1  | B-CHAT-08C.4_Final_Security_Claim_Chunk_   | Baris 1-100 (Bab 1-3 Tujuan, Log & Sesi),| VERIFIED DOCUMENTALLY | Batasan klaim sesi, 5 syarat|
|    | Consistency_Checklist_Reconciliation_      | Baris 101-250 (Bab 4-8 AES-GCM, Taksonomi| (Seluruh 353 baris    | AES-GCM, taksonomi 3 kelas, |
|    | EXP-02.md (Amendemen Keempat Gerbang Final)|  Kegagalan, Checklist & 4 Prasyarat),    |  dokumen diperiksa    | formula chunking, dan 4     |
|    |                                            | Baris 251-353 (Bab 9-12 Checklist & Gate)|  secara tuntas)       | prasyarat pre-eksekusi).    |
+----+--------------------------------------------+------------------------------------------+-----------------------+-----------------------------+
| 2  | B-CHAT-08C.3_Final_Cryptographic_Binding_  | Baris 55-130 (Bab 3-4 CEK & AAD 83-B),   | VERIFIED DOCUMENTALLY | Penghapusan CEK Identifier, |
|    | Result_Classification_Correction_EXP-02.md | Baris 141-300 (Bab 5-7 Alur 9 Langkah,   | (Bagian kunci teknis  | alur 9 langkah storage hash,|
|    | (Amendemen Ketiga Gerbang Final)           |  Perlindungan Hash & Kriteria Hasil),    |  diperiksa langsung)  | dan taksonomi 5 ancaman     |
|    |                                            | Baris 301-368 (Bab 8-12 Checklist & Gate)|                       | penyimpanan lokal).         |
+----+--------------------------------------------+------------------------------------------+-----------------------+-----------------------------+
| 3  | B-CHAT-08C.2_Final_Gate_Evidence_Integrity | Baris 76-150 (Bab 3-4 5 Lapisan Keabsahan| VERIFIED DOCUMENTALLY | Pemisahan 5 lapisan data, 9 |
|    | _Correction_EXP-02.md (Amendemen Kedua)    |  dan Pengikatan Sesi & Aturan Retry),    | (Pondasi integritas   | metadata sesi, larangan re- |
|    |                                            | Baris 164-280 (Bab 6-8 Checklist & CB)   |  dan CB ditelaah)     | encrypt, taksonomi fatal CB)|
+----+--------------------------------------------+------------------------------------------+-----------------------+-----------------------------+
| 4  | B-CHAT-08C.1_Final_Gate_Precision_        | Baris 84-165 (Bab 4-5 Independensi Data  | VERIFIED DOCUMENTALLY | Jebakan circular test oracle|
|    | Correction_EXP-02.md (Amendemen Pertama)   |  Fixture dan 4 Milestone Siklus Hidup)   | (Pondasi fixture)     | dan 4 milestone ciphertext. |
+----+--------------------------------------------+------------------------------------------+-----------------------+-----------------------------+
| 5  | B-CHAT-08B.1_Koreksi_Presisi_Final_        | Baris 69-160 (Bab 4-5 Rekonsiliasi 35    | VERIFIED DOCUMENTALLY | Rekonsiliasi 35 skenario,   |
|    | Readiness_Audit_EXP-02.md                  |  Skenario & Conditional Nonce Safety),   | (Hierarki dan rincian | birthday bound salt/CEK, dan|
|    | (Koreksi Audit Kesiapan EXP-02)            | Baris 161-236 (Bab 6-8 Hierarki Dokumen, |  skenario diverifikasi| in-memory nonce ledger      |
|    |                                            |  Disiplin Statistik & Ledger Nonce UJI-E1)secara matematis)   | UJI-E1 fail-closed).        |
+----+--------------------------------------------+------------------------------------------+-----------------------+-----------------------------+
| 6  | B-CHAT-08B.0 (Laporan Audit Kesiapan       | Penelusuran struktur direktori repositori| REFERENCE UNVERIFIED  | Berkas fisik tidak ada di   |
|    | Pre-Otorisasi EXP-02)                      | root; berkas fisik lokal TIDAK DITEMUKAN | (Mode NO FILE         | disk karena mode mandat;    |
|    |                                            | di workspace                             |  CREATION mandat lalu)| rekonsiliasi via B-08B.1.   |
+----+--------------------------------------------+------------------------------------------+-----------------------+-----------------------------+
| 7  | B-CHAT-08A.5_Final_Editorial_Precision_    | Baris 101-132 (Bab 5 Konsistensi         | VERIFIED DOCUMENTALLY | Tabel 14 parameter kanonikal|
|    | Closure_EXP-02.md (Acuan Teknis Tertinggi) |  Spesifikasi Teknis Kanonikal Lengkap)   | (Parameter terkunci)  | mutlak yang wajib dipatuhi. |
+----+--------------------------------------------+------------------------------------------+-----------------------+-----------------------------+
| 8  | B-CHAT-07A_Laporan_Eksekusi_EXP-01.md      | Baris 29-56 (Bab 2 Lingkungan Pengujian  | VERIFIED DOCUMENTALLY | Konteks empiris workstation |
|    | (Laporan Eksekusi Eksperimen EXP-01)       |  Laboratorium dan Profil Workstation)    | (Konteks lingkungan)  | laboratorium historis.      |
+----+--------------------------------------------+------------------------------------------+-----------------------+-----------------------------+
```

---

## 3. RINGKASAN KONDISI AWAL B-CHAT-08C.4 DAN TIGA ISU TERBUKA

Pada dokumen gerbang final [B-CHAT-08C.4](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08C.4_Final_Security_Claim_Chunk_Consistency_Checklist_Reconciliation_EXP-02.md), seluruh pondasi arsitektur dan parameter kanonikal telah berhasil dikunci secara konsisten. Namun, status kesiapan checklist 12 butir menyisakan lima butir berstatus `PARTIALLY VERIFIED` dan satu butir berstatus `UNVERIFIED`. Dari butir-butir tersebut, tiga ranah perancangan dokumentatif diidentifikasi sebagai isu terbuka (*open issues*) yang membutuhkan spesifikasi konkret:

```
+-------------------------------------------------------------------------------------------------------------+
|                                 TIGA ISU TERBUKA DOKUMENTATIF PRA-EKSEKUSI EXP-02                           |
+----+--------------------------+--------------------------+--------------------------------------------------+
| No | Isu Prasyarat Desain     | Butir Checklist Terkait  | Kondisi Awal pada Dokumen B-CHAT-08C.4           |
+----+--------------------------+--------------------------+--------------------------------------------------+
| 1  | Skema Fixture Manifest & | Butir 3                  | Koridor independensi dan prinsip anti-circular   |
|    | Ground Truth Independen  | (PARTIALLY VERIFIED)     | telah ditetapkan, namun struktur skema format    |
|    |                          |                          | detail biner/JSON masih DESIGN DECISION REQUIRED.|
+----+--------------------------+--------------------------+--------------------------------------------------+
| 2  | Skema Metadata Digest    | Butir 4                  | Alur 9 langkah storage hash dan 9 parameter sesi |
|    | Integritas Penyimpanan   | (PARTIALLY VERIFIED)     | telah ditetapkan, namun format penyimpanan lokal |
|    |                          |                          | dan batas klaimnya masih DESIGN DECISION REQUIRED|
+----+--------------------------+--------------------------+--------------------------------------------------+
| 3  | Kebijakan Circuit Breaker| Butir 11 & Butir 12      | Taksonomi 3 kelas kegagalan telah ditetapkan,    |
|    | & Kriteria Henti Darurat | (PARTIALLY VERIFIED)     | namun ambang numerik kegagalan berturut-turut,   |
|    |                          |                          | aturan reset, dan scope = DESIGN DECISION REQ.   |
+----+--------------------------+--------------------------+--------------------------------------------------+
```

Penyelesaian ketiga isu ini secara dokumentatif di dalam dokumen B-CHAT-08D bertujuan mengubah status rancangan dari `DESIGN DECISION REQUIRED` menjadi **rekomendasi spesifikasi desain konkret** yang siap ditinjau oleh Pemilik Proyek (*DESIGN REVIEW REQUIRED*).

---

## 4. KEPUTUSAN DESAIN 1: FIXTURE MANIFEST DAN INDEPENDENT EXPECTED-VALUE GROUND TRUTH

### 4.1. Format Fixture yang Direkomendasikan dan Justifikasi Arsitektural
Untuk memenuhi prinsip independensi pengujian dan mencegah jebakan *circular test oracle*, direkomendasikan penggunaan **Format Berkas Statis JSON Deklaratif Mandiri (*Self-Contained Declarative JSON File*)** yang disimpan pada berkas terpisah:
```text
scratch/bchat_prototype/exp02_chunking_aad/fixtures/exp02_synthetic_manifests.json
```

**Alasan Pemilihan Format JSON Statis:**
1. **Inspeksi Visual & Auditability:** JSON berbasis teks memungkinkan peninjauan kasat mata (*human-readable*) oleh auditor keamanan dan Pemilik Proyek tanpa memerlukan utilitas deserialisasi biner khusus.
2. **Keterpisahan Deklaratif:** Data ekspektasi tersimpan sebagai konstanta statis yang dibekukan (*immutable constants*). Modul pengujian perakitan (*assembly validator*) hanya membaca objek data ini, bukan membangkitkannya saat pengujian berjalan.
3. **Kompatibilitas Native Node.js & Web Crypto:** Struktur JSON dapat langsung dimuat ke dalam runtime Node.js tanpa pustaka pihak ketiga (*zero dependency*), dan mudah dipetakan ke objek `ArrayBuffer` melalui decoding base64 atau hex standard.

### 4.2. Struktur Data dan Field Wajib Fixture Manifest
Struktur skema JSON fixture manifest dirancang mencakup identitas berkas makro, parameter perakitan, dan array granular ekspektasi per-chunk:

```json
{
  "$schema": "urn:unuha:e2ee:exp02:manifest:v1",
  "fixture_version": "1.0.0",
  "generated_at": "2026-11-05T00:00:00Z",
  "generator_provenance": {
    "tool": "external_precompute_script.mjs",
    "runtime": "Node.js v25.3.0 standalone / OpenSSL 3.0",
    "seed_identifier": "UNUHA-EXP02-FIXED-PRNG-SEED-2026",
    "is_sut_independent": true
  },
  "fixtures": [
    {
      "fixture_id": "FIX-POS-01-1MIB-EXACT",
      "category": "POSITIVE_CANONICAL",
      "description": "Pengujian 1 chunk tepat 1 MiB (S = 1.048.576 byte, N = 1)",
      "file_metadata": {
        "file_uuid": "e2a1b3c4-d5e6-4f7a-8b9c-0d1e2f3a4b5c",
        "total_size_bytes": 1048576,
        "total_chunks": 1,
        "chunk_size_standard": 1048576,
        "final_chunk_size": 1048576,
        "expected_file_sha256_hex": "4a5b6c..."
      },
      "session_binding_context": {
        "room_uuid": "11111111-1111-4111-8111-111111111111",
        "message_uuid": "22222222-2222-4222-8222-222222222222",
        "attachment_uuid": "33333333-3333-4333-8333-333333333333",
        "upload_session_id": "44444444-4444-4444-8444-444444444444",
        "upload_session_salt_hex": "0123456789abcdef"
      },
      "chunks": [
        {
          "chunk_index": 0,
          "plaintext_length": 1048576,
          "expected_chunk_plaintext_sha256_hex": "7f8a9b...",
          "framing": {
            "expected_nonce_hex": "0123456789abcdef00000000",
            "expected_aad_hex": "554e4831000102...",
            "expected_aad_length_bytes": 83,
            "expected_ciphertext_length_bytes": 1048576,
            "expected_auth_tag_length_bytes": 16,
            "expected_total_frame_length_bytes": 1048604
          },
          "storage_integrity": {
            "expected_storage_sha256_hex": "9c8b7a..."
          }
        }
      ]
    }
  ]
}
```

### 4.3. Representasi Tipe Data dan Encoding Biner
Untuk menjamin konsistensi byte-demi-byte terhadap spesifikasi kanonikal:
1. **UUID (16 Raw Bytes vs Hex String):** Di dalam berkas manifest JSON, UUID disajikan dalam format canonical string heksadesimal 36 karakter bertanda hubung (RFC 4122). Namun, saat disuntikkan ke dalam AAD atau konteks exporter MLS, modul uji wajib mengonversinya menjadi **16 byte biner mentah (*16 raw octets*)**.
2. **Nonce (12 Byte Big-Endian Struct):** Nonce disusun dari `UploadSessionSalt` (8 byte) diikuti counter `uint32be(chunk_index)` (4 byte). Di JSON disajikan dalam string heksadesimal 24 karakter (`12 byte * 2`).
3. **AAD (83 Byte Kanonikal):** Seluruh 10 field kanonikal wajib dikodekan secara big-endian persis 83 byte tanpa padding liar. Di JSON dapat disertakan representasi heksadesimal 166 karakter sebagai referensi verifikasi mandiri.
4. **Ciphertext dan Auth Tag:** Tag autentikasi berukuran tepat 16 byte (128-bit) dan framing total berukuran tepat $L_i + 28\text{ byte}$.

### 4.4. Metodologi Anti-Circular Test Oracle dan Provenance Data
Untuk memastikan fungsi implementasi prototipe yang sedang diuji (*SUT*) tidak bertindak sebagai validator dirinya sendiri:
- **Kalkulasi Nilai Ekspektasi Terpisah (*External Precomputation*):** Seluruh hash digest (`expected_chunk_plaintext_sha256`, `expected_file_sha256`, `expected_storage_sha256`) wajib dihitung sebelumnya menggunakan skrip independen di luar modul SUT (misalnya utilitas baris perintah OpenSSL standar: `openssl dgst -sha256` atau skrip utilitas mandiri berbasis modul bawaan Node.js `node:crypto`).
- **Penyimpanan Nilai Ground Truth Statis:** Nilai-nilai hash tersebut dibekukan di dalam berkas JSON manifest sebelum test suite dijalankan. Fungsi pengujian perakitan chunk (*file assembly*) membandingkan hasil penggabungannya terhadap ground truth statis tersebut.
- **Pencatatan Provenance Lengkap:** Setiap blok fixture wajib memuat metadata asal-usul (*provenance*), meliputi versi generator, seed generator deterministik, dan parameter lingkungan pembangkitan.

### 4.5. Taksonomi Fixture Positif dan Negatif
Suite fixture dirancang mencakup spektrum kasus uji positif dan negatif untuk membuktikan keandalan logika aplikasi:

```
+-------------------------------------------------------------------------------------------------------------+
|                                    TAKSONOMI RANCANGAN FIXTURE MANIFEST EXP-02                              |
+----+--------------------------+----------+------------------------------------------------------------------+
| No | Fixture ID               | Kategori | Karakteristik dan Tujuan Pengujian                               |
+----+--------------------------+----------+------------------------------------------------------------------+
| 1  | FIX-POS-01-1MIB-EXACT    | Positif  | Plaintext tepat 1 MiB (1.048.576 B, N = 1, L0 = 1.048.576 B).    |
| 2  | FIX-POS-02-1MIB-PLUS-1B  | Positif  | Plaintext 1 MiB + 1 B (1.048.577 B, N = 2, L0=1 MiB, L1=1 B).    |
| 3  | FIX-POS-03-10MB-LIMIT    | Positif  | Batas kuota berkas 10 MB (10.000.000 B, N = 10, L9 = 562.816 B). |
| 4  | FIX-POS-04-ARBITRARY-500K| Positif  | Berkas ukuran non-standar (500.000 B, N = 1, L0 = 500.000 B).    |
+----+--------------------------+----------+------------------------------------------------------------------+
| 5  | FIX-NEG-01-ZERO-BYTE     | Negatif  | Berkas 0 byte (S = 0). Wajib ditolak pada tahap pra-enkripsi.    |
| 6  | FIX-NEG-02-OVER-QUOTA    | Negatif  | Berkas 10.000.001 byte (> 10 MB). Wajib ditolak kuota berkas.    |
| 7  | FIX-NEG-03-SUM-MISMATCH  | Negatif  | Ukuran berkas S != sum(Li). Terdeteksi anomali pada assembly.    |
| 8  | FIX-NEG-04-CHUNK-GAP     | Negatif  | Chunk index melompat (misal: Chunk 0 lalu Chunk 2 tanpa Chunk 1).|
| 9  | FIX-NEG-05-INDEX-BOUNDS  | Negatif  | Chunk index >= N (misal: chunk_index = 1 pada N = 1). Ditolak.   |
| 10 | FIX-NEG-06-CORRUPT-HASH  | Negatif  | Expected hash diubah secara sengaja. Assembly wajib melempar err.|
| 11 | FIX-NEG-07-CORRUPT-UUID  | Negatif  | Format UUID tidak memenuhi spesifikasi RFC 4122. Ditolak sintaks.|
| 12 | FIX-NEG-08-AAD-MISMATCH  | Negatif  | Nilai Total Chunks pada AAD tidak cocok dengan metadata sesi.    |
+----+--------------------------+----------+------------------------------------------------------------------+
```

### 4.6. Penanganan Fixture yang Tidak Lengkap, Inkonsisten, atau Rusak
Apabila berkas fixture manifest mengalami kerusakan parsing JSON, nilai hash ground truth hilang, atau terdapat ketidakkonsistenan internal pada saat test runner dijalankan:
- Kasus uji yang terdampak **DILARANG KERAS DIBERI STATUS PASS**.
- Kasus uji tersebut **DILARANG DIANGGAP SEBAGAI BUKTI KEBERHASILAN SISTEM**.
- Kondisi ini wajib diklasifikasikan sebagai **`UNVERIFIED / Prerequisite Failure`**.
- Test runner wajib mencatat galat prasyarat pada log pengujian dan menghentikan eksekusi skenario terkait.

---

## 5. KEPUTUSAN DESAIN 2: SKEMA METADATA DIGEST DAN VERIFIKASI INTEGRITAS PENYIMPANAN

### 5.1. Pemisahan Tegas Lima Lapisan Keabsahan Data
Untuk mencegah kerancuan peran antara integritas fisik media lokal dan autentikasi kriptografis end-to-end, ditegaskan pemisahan lima lapisan keabsahan sesuai [B-CHAT-08C.2](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08C.2_Final_Gate_Evidence_Integrity_Correction_EXP-02.md) §3.2 dan [B-CHAT-08C.4](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08C.4_Final_Security_Claim_Chunk_Consistency_Checklist_Reconciliation_EXP-02.md) §3.2:

```
+-------------------------------------------------------------------------------------------------------------+
|                                    LIMA LAPISAN KEABSAHAN DATA PADA EXP-02                                  |
+--------+------------------------------------+-----------------------+---------------------------------------+
| Lapisan| Nama Lapisan Keabsahan             | Mekanisme Evaluasi    | Batas Jaminan & Fungsi Operasional    |
+--------+------------------------------------+-----------------------+---------------------------------------+
| L-1    | Pemeriksaan Panjang Framing        | Ukuran file == Li+28B | Hanya membuktikan kepatuhan format    |
|        | (Framing Length Check)             |                       | framing Format A; tidak mendeteksi bit|
|        |                                    |                       | flip atau payload tertukar berukuran =|
+--------+------------------------------------+-----------------------+---------------------------------------+
| L-2    | Pengikatan Sesi & Artefak          | Pencocokan 9 parameter| Mengikat artefak fisik ke konteks sesi|
|        | (Session & Artifact Identity)      | metadata non-rahasia  | aktif; membuktikan konsistensi skema; |
|        |                                    |                       | TIDAK membuktikan validitas kunci CEK.|
+--------+------------------------------------+-----------------------+---------------------------------------+
| L-3    | Integritas Byte Media Penyimpanan  | Komparasi SHA-256     | Mendeteksi korupsi media lokal, silent|
|        | (Storage Byte Integrity / Digest)  | H_act == H_exp        | disk corruption, atau write terputus; |
|        |                                    | (Read-back vs RAM)    | BUKAN pengganti AEAD; unkeyed hash.   |
+--------+------------------------------------+-----------------------+---------------------------------------+
| L-4    | Autentikasi Kriptografis AEAD      | crypto.subtle.decrypt | SATU-SATUNYA pembuktian kriptografis  |
|        | (Cryptographic AEAD Authentication)| dengan CEK, Nonce, &  | bahwa ciphertext dan AAD belum pernah |
|        |                                    | AAD 83-B (Tag 128-bit)| diubah sejak dienkripsi pengirim sah. |
+--------+------------------------------------+-----------------------+---------------------------------------+
| L-5    | Validasi Perakitan Manifest        | Assembly Validator:   | Memvalidasi integritas makro berkas:  |
|        | (Manifest & Assembly Validation)   | urutan tanpa celah &  | seluruh chunk lengkap bersambungan dan|
|        |                                    | sum(Li) == S          | hash plaintext akhir cocok dgn ground.|
+--------+------------------------------------+-----------------------+---------------------------------------+
```

### 5.2. Skema Field Wajib Metadata Digest Lokal
Untuk mendukung operasional Lapisan L-2 dan L-3 sebelum pengiriman ulang (*retry*) diizinkan, dirancang skema metadata penyimpanan digest lokal (*Storage Metadata Digest Schema*):

```json
{
  "storage_digest_version": "1.0.0",
  "artifact_file_name": "chunk_0.bin",
  "session_and_artifact_identity": {
    "room_uuid": "11111111-1111-4111-8111-111111111111",
    "message_uuid": "22222222-2222-4222-8222-222222222222",
    "attachment_uuid": "33333333-3333-4333-8333-333333333333",
    "upload_session_id": "44444444-4444-4444-8444-444444444444",
    "upload_session_salt_hex": "0123456789abcdef",
    "chunk_index": 0,
    "total_chunks": 1,
    "plaintext_length": 1048576,
    "expected_ciphertext_frame_length": 1048604
  },
  "storage_integrity_verification": {
    "pre_write_expected_sha256_hex": "9c8b7a6d5e...",
    "recorded_at_epoch_ms": 1793875200000,
    "io_close_confirmed": true,
    "post_write_readback_verified": true,
    "verified_actual_sha256_hex": "9c8b7a6d5e...",
    "verification_status": "VERIFIED_READY_FOR_RETRY"
  }
}
```

### 5.3. Batasan Keamanan dan Pengakuan Kelemahan Unkeyed Hash
Dokumen B-CHAT-08D menegaskan secara eksplisit batas-batas keamanan mekanisme hash lokal:
1. **Bukan MAC / Digital Signature:** Algoritma SHA-256 murni beroperasi tanpa kunci (*unkeyed cryptographic hash*). Nilai digest ini **tidak memiliki sifat otentikasi asal data (*data origin authentication*)**.
2. **Ketiadaan Proteksi Terhadap Penyerang Lokal Berizin Tulis:** Jika penyerang memiliki akses ke sistem berkas lokal tempat ciphertext dan berkas metadata disimpan, penyerang dapat memodifikasi isi berkas ciphertext sekaligus menghitung ulang dan memperbarui nilai `pre_write_expected_sha256_hex`. Mekanisme storage hash lokal **tidak dapat mendeteksi manipulasi simultan semacam ini**.
3. **Fungsi Defensif yang Sah:** Fungsi mekanisme ini **secara eksklusif ditujukan untuk mendeteksi gangguan integritas non-adversarial lokal**, seperti kegagalan write I/O sistem operasi, proses terhenti di tengah jalan (*partial flush*), atau *silent data corruption* pada media disk sebelum retry dikirimkan.
4. **Perlindungan Terakhir pada Sisi Penerima:** Integritas dan keaslian data terhadap manipulasi pihak ketiga dijamin secara mutlak oleh **verifikasi Authentication Tag 128-bit AES-256-GCM pada sisi penerima (Lapisan L-4)**, selama kelima syarat penolakan pada B-CHAT-08C.4 §4.1 terpenuhi.

### 5.4. Larangan Mutlak Penyimpanan CEK Plaintext
- Kunci enkripsi simetris (CEK) adalah rahasia 256-bit yang hanya boleh berada pada modul memori Web Crypto API yang terlindungi.
- **CEK DILARANG KERAS dicatat dalam log, berkas metadata `.meta.json`, header berkas, basis data lokal, maupun variabel diagnostik**.
- Pengikatan artefak ke sesi dilakukan sepenuhnya melalui identifier non-rahasia (`UploadSessionID`, `UploadSessionSalt`, `ChunkIndex`, `AttachmentUUID`).

### 5.5. Kebijakan Operasional Persistensi, Retry, dan Pembatalan Sesi (Session Abandonment)
1. **Syarat Kelayakan Retry Transmisi:** Artefak ciphertext yang tersimpan di disk lokal **HANYA SAH digunakan untuk pengiriman ulang (*retry*)** jika dan hanya jika seluruh kondisi berikut terpenuhi:
   - Handle berkas telah dikonfirmasi ditutup (`io_close_confirmed == true`).
   - Panjang berkas tepat sama dengan $L_i + 28\text{ byte}$.
   - Pembacaan ulang (*read-back*) menghasilkan nilai digest aktual yang cocok 100% dengan expected digest ($H_{\text{act}} == H_{\text{exp}}$).
   - Identitas sesi pada berkas metadata cocok 100% dengan konteks sesi pengunggahan yang saat itu sedang aktif.
2. **Larangan Mutlak Enkripsi Ulang (No Re-Encryption):** Retry transmisi wajib menggunakan byte ciphertext yang telah lulus verifikasi integritas penyimpanan. **Dilarang memanggil ulang fungsi enkripsi Web Crypto dengan pasangan $(\text{CEK}, \text{Nonce})$ yang sama**.
3. **Kewajiban Pembatalan Sesi (Session Abandonment):** Jika terjadi crash proses, pemutusan I/O yang belum terkonfirmasi, atau ketidakcocokan nilai digest ($H_{\text{act}} \ne H_{\text{exp}}$), status persistensi dianggap tidak pasti (*uncertain state*). Sesi pengunggahan tersebut **WAJIB DIBATALKAN SEUTUHNYA (*FAIL-CLOSED SESSION ABANDONMENT*)**.
4. **Pembangkitan Tiga Entitas Baru:** Pengunggahan pengganti wajib dimulai sebagai sesi baru dari awal dengan membangkitkan:
   - **CEK baru** via CSPRNG (kunci simetris 256-bit baru),
   - **UploadSessionID baru** (UUID v4 16 byte biner baru), dan
   - **UploadSessionSalt baru** via CSPRNG (salt acak 64-bit baru).

---

## 6. KEPUTUSAN DESAIN 3: KEBIJAKAN OPERASIONAL CIRCUIT BREAKER DAN HENTI DARURAT

### 6.1. Pemisahan Tiga Kelas Operasional Kegagalan
Sesuai amanat [B-CHAT-08C.4](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-08C.4_Final_Security_Claim_Chunk_Consistency_Checklist_Reconciliation_EXP-02.md) §5, kebijakan henti darurat (*circuit breaker*) memisahkan secara ketat tiga kelas operasional kegagalan:

```
+-------------------------------------------------------------------------------------------------------------+
|                                    TAKSONOMI TIGA KELAS KEGAGALAN CIRCUIT BREAKER                           |
+----+--------------------------------+---------------------------------------------------+-------------------+
| No | Kelas Kegagalan                | Karakteristik Kasus dan Definisi Operasional      | Kebijakan Aksi    |
+----+--------------------------------+---------------------------------------------------+-------------------+
| 1  | Fatal Security Violation       | Pelanggaran fundamental terhadap keselamatan      | EMERGENCY HARD    |
|    | (Insiden Keamanan Fatal)       | kriptografi dan arsitektur keamanan:              | STOP SEKETIKA     |
|    |                                | - Penggunaan ulang pasangan (CEK, Nonce) (UJI-E1).| (Suite dihentikan |
|    |                                | - Ciphertext termodifikasi lolos verifikasi GCM.  | total, fail-closed|
|    |                                | - AAD korup diterima tanpa OperationError.        | status FAIL, catat|
|    |                                | - Plaintext parsial bocor sebelum autentikasi.    | log insiden fatal)|
|    |                                | - Berkas > 10 MB atau pesan > 25 MB lolos enkripsi|                   |
|    |                                | - Crash recovery mengulang enkripsi dgn CEK lama. |                   |
+----+--------------------------------+---------------------------------------------------+-------------------+
| 2  | Scenario-Level Failure         | Kegagalan fungsional pada skenario pengujian      | CATAT SEBAGAI     |
|    | (Kegagalan Tingkat Skenario)   | lokal yang tidak merusak fondasi keamanan sistem: | FAIL SKENARIO     |
|    |                                | - Injeksi galat I/O negatif tidak melempar tipe.  | (Lanjutkan uji    |
|    |                                | - Validasi sintaks UUID tidak menolak format cacat| skenario lain,    |
|    |                                | - Logika retry tidak memicu pembatalan sesuai pola| akumulasi counter)|
+----+--------------------------------+---------------------------------------------------+-------------------+
| 3  | UNVERIFIED / Prerequisite Fail | Ketidakabsahan akibat kegagalan instrumen pengujian| KLASIFIKASIKAN    |
|    | (Kegagalan Prasyarat Pengujian)| atau ketiadaan bukti pendukung:                   | SEBAGAI           |
|    |                                | - Web Crypto API host tidak merespons / crash.    | UNVERIFIED        |
|    |                                | - File fixture manifest korup atau hash hilang.   | (Dilarang status  |
|    |                                | - Lingkungan runtime tidak memenuhi prasyarat.    | PASS; investigasi)|
+----+--------------------------------+---------------------------------------------------+-------------------+
```

### 6.2. Analisis dan Rekomendasi Tujuh Parameter Kebijakan Circuit Breaker

```
+-------------------------------------------------------------------------------------------------------------+
|                            MATRIKS KEPUTUSAN TUJUH PARAMETER OPERASIONAL CIRCUIT BREAKER                    |
+----+-----------------------------------+---------------------------------------------------+----------------+
| No | Parameter Kebijakan               | Rekomendasi Spesifikasi Desain B-CHAT-08D         | Status Putusan |
+----+-----------------------------------+---------------------------------------------------+----------------+
| 1  | Ambang Kegagalan Beruntun         | Nilai ambang kuantitatif ditetapkan: M = 3        | DESIGN REVIEW  |
|    | (Consecutive Failure Threshold)   | Tiga kegagalan beruntun pada Kelas 2 memicu henti.| REQUIRED       |
+----+-----------------------------------+---------------------------------------------------+----------------+
| 2  | Cakupan Penghitung                | Per-Category Counter (berlaku per kelompok uji)   | DESIGN REVIEW  |
|    | (Counter Scope)                   | Dikelompokkan: Kuota, Integritas AAD, Perakitan.  | REQUIRED       |
+----+-----------------------------------+---------------------------------------------------+----------------+
| 3  | Aturan Reset Penghitung (PASS)    | Hasil PASS mereset counter kategori terkait ke 0. | DESIGN REVIEW  |
|    | (Reset on PASS)                   | Kegagalan terisolasi yang disusul PASS dinilai OK.| REQUIRED       |
+----+-----------------------------------+---------------------------------------------------+----------------+
| 4  | Perlakuan Hasil UNVERIFIED        | UNVERIFIED TIDAK menambah counter Kelas 2, namun  | DESIGN REVIEW  |
|    | (Handling UNVERIFIED Results)     | UNVERIFIED berturut-turut >= 2x menghentikan grup | REQUIRED       |
+----+-----------------------------------+---------------------------------------------------+----------------+
| 5  | Pengaruh Pengulangan Skenario     | Retry skenario yang sama TIDAK mereset counter;   | DESIGN REVIEW  |
|    | (Scenario Retry & Context Switch) | Pindah ke kelompok kategori baru mereset counter. | REQUIRED       |
+----+-----------------------------------+---------------------------------------------------+----------------+
| 6  | Kondisi Pemulihan Pasca-Henti     | Kelas 1 (Fatal): Audit kode, tidak ada auto-resume| DESIGN REVIEW  |
|    | (Recovery & Resume Conditions)    | Kelas 2/3 (Ambang): Investigasi log, restart grup.| REQUIRED       |
+----+-----------------------------------+---------------------------------------------------+----------------+
| 7  | Format Rekaman Bukti Insiden      | Berkas JSON insiden darurat terstruktur           | DESIGN REVIEW  |
|    | (Incident Audit Log Format)       | (emergency_stop_incident_log.json) otomatis dibuat| REQUIRED       |
+----+-----------------------------------+---------------------------------------------------+----------------+
```

#### Rincian Penjelasan Parameter Rekomendasi:
1. **Ambang Kegagalan Beruntun ($M = 3$):**  
   Untuk prototipe Node-only EXP-02, angka 3 kegagalan beruntun pada kelompok skenario yang sama merupakan ambang yang paling proporsional. Angka $M = 1$ terlalu sensitif terhadap anomali pengujian lokal negatif, sedangkan $M \ge 5$ membiarkan pengujian membuang sumber daya pada sistem yang jelas-jelas mengalami kerusakan logika berulang.
2. **Cakupan Penghitung Per-Kelompok Skenario (*Per-Category Counter*):**  
   Penghitung kegagalan tidak boleh bersifat global tunggal untuk seluruh suite 35 skenario, karena kegagalan beruntun pada kasus uji parsing UUID negatif tidak boleh membatalkan pengujian independen terkait efisiensi memori (Kategori F) atau penegakan kuota (Kategori C). Penghitung juga tidak boleh per-skenario tunggal karena skenario pengujian EXP-02 bersifat diskret.
3. **Reset Penghitung Saat PASS:**  
   Ketika suatu kasus uji dalam satu kelompok berhasil dengan status PASS, penghitung kegagalan beruntun untuk kelompok tersebut di-reset kembali ke 0. Ini memastikan bahwa kegagalan acak tunggal yang terisolasi tidak terakumulasi secara artifisial dengan kegagalan lain yang dipisahkan oleh pengujian sukses.
4. **Perlakuan Terhadap Hasil UNVERIFIED:**  
   Hasil `UNVERIFIED` mencerminkan kegagalan lingkungan atau instrumen, bukan bukti kegagalan logika kriptografi aplikasi. Oleh karena itu, `UNVERIFIED` tidak diakumulasikan ke dalam penghitung kegagalan Kelas 2. Namun, untuk mencegah test runner terjebak dalam eksekusi tanpa bukti pada lingkungan yang rusak, **dua hasil `UNVERIFIED` berturut-turut ($\ge 2$) pada kelompok yang sama wajib menghentikan eksekusi kelompok tersebut**.
5. **Aturan Retry Skenario dan Pergantian Sesi:**  
   Jika modul pengujian mengulang (*retry*) skenario yang sama dan kembali mengalami kegagalan, kejadian tersebut tetap dihitung sebagai kegagalan beruntun berikutnya (counter bertambah, tidak di-reset). Counter hanya di-reset jika skenario menghasilkan PASS atau pengujian beralih ke kelompok kategori baru.
6. **Kondisi Pemulihan Pasca-Henti:**  
   - Penghentian akibat Kelas 1 (*Fatal Security Violation*) bersifat terminal: seluruh suite dibatalkan dan pengujian tidak boleh dilanjutkan sebelum dilakukan analisis forensik dan perbaikan kode sumber.
   - Penghentian akibat terlampauinya ambang batas Kelas 2 atau Kelas 3 mengizinkan pengulangan pengujian kelompok skenario bersangkutan dari awal setelah dilakukan verifikasi lingkungan atau konfigurasi fixture.
7. **Format Bukti Insiden Henti Darurat (`emergency_stop_incident_log.json`):**  
   Saat circuit breaker terpicu, modul wajib menuliskan rekaman bukti henti darurat yang memuat:
   ```json
   {
     "incident_timestamp": "2026-11-05T00:00:00.000Z",
     "trigger_class": "FATAL_SECURITY_VIOLATION",
     "trigger_reason": "NONCE_REUSE_DETECTED",
     "scenario_id": "UJI-E1",
     "consecutive_failures_count": 1,
     "active_upload_session_id": "44444444-4444-4444-8444-444444444444",
     "stack_trace": "Error: Nonce reuse rejected by in-memory ledger...",
     "circuit_breaker_action": "IMMEDIATE_HARD_STOP_FAIL_CLOSED"
   }
   ```

---

## 7. MATRIKS REKOMENDASI DESAIN DAN KEPUTUSAN YANG MEMERLUKAN PERSETUJUAN

Berikut adalah matriks komprehensif yang memisahkan secara transparan antara fakta yang telah ditetapkan pada dokumen terdahulu, rekomendasi teknis baru yang diajukan dalam B-CHAT-08D, serta status persetujuannya:

```
+--------------------------------------------------------------------------------------------------------------------------------------------------+
|                                  MATRIKS REKOMENDASI DESAIN DAN STATUS PERSETUJUAN PRE-EKSEKUSI EXP-02                                           |
+----+-----------------------+-----------------------+------------------------------------------+------------------------------+-------------------+
| No | Isu Desain            | Kondisi Terdahulu     | Rekomendasi Teknis Baru (B-CHAT-08D)     | Alasan Teknis & Dampak       | Status Persetujuan|
|    |                       | (B-CHAT-08C.4)        |                                          | Arsitektural                 |                   |
+----+-----------------------+-----------------------+------------------------------------------+------------------------------+-------------------+
| 1  | Format Fixture        | Prinsip independensi  | Format berkas JSON statis deklaratif     | Memastikan test oracle bebas | DESIGN REVIEW     |
|    | Manifest Sintetis     | & anti-circular oracle| mandiri (exp02_synthetic_manifests.json) | circular bias; readable dan  | REQUIRED          |
|    |                       | jelas; skema = DDR.   | dengan skema field lengkap & provenance. | mudah diaudit kasat mata.    |                   |
+----+-----------------------+-----------------------+------------------------------------------+------------------------------+-------------------+
| 2  | Metode Pembangkitan   | Larangan test oracle  | Pembangkitan ground truth hash terpisah  | Mencegah bug internal SUT    | DESIGN REVIEW     |
|    | Ground Truth Hash     | tunggal; metode = DDR.| menggunakan script standalone / OpenSSL  | merambat ke data uji; hash   | REQUIRED          |
|    |                       |                       | sebelum test suite dijalankan.           | dibekukan sebagai konstanta. |                   |
+----+-----------------------+-----------------------+------------------------------------------+------------------------------+-------------------+
| 3  | Format Penyimpanan    | Alur 9 langkah hash OK| Skema JSON metadata digest lokal         | Mendeteksi korupsi I/O lokal | DESIGN REVIEW     |
|    | Metadata Digest Lokal | batasan hash = DDR.   | (.meta.json berdampingan dgn artefak)    | sebelum retry tanpa mengklaim| REQUIRED          |
|    |                       |                       | memuat 9 parameter sesi & pre-write hash.| fitur MAC atau tanda tangan. |                   |
+----+-----------------------+-----------------------+------------------------------------------+------------------------------+-------------------+
| 4  | Penanganan Status     | Session abandonment   | Pembatalan sesi mutlak (fail-closed);    | Mencegah nonce reuse dan     | DESIGN REVIEW     |
|    | Persistensi Ragu-ragu | wajib; 3 entitas baru.| sesi lama ditinggalkan; bangkitkan CEK   | kontaminasi state; perlindung| REQUIRED          |
|    |                       |                       | baru, UploadSessionID baru, & Salt baru. | an integritas sesi asinkron. |                   |
+----+-----------------------+-----------------------+------------------------------------------+------------------------------+-------------------+
| 5  | Ambang Kegagalan      | Taksonomi 3 kelas OK; | Ditetapkan M = 3 kegagalan beruntun      | Mencegah runaway test pada   | DESIGN REVIEW     |
|    | Beruntun (Threshold)  | ambang angka = DDR.   | pada kelompok skenario yang sama.        | kerusakan berulang; seimbang | REQUIRED          |
|    |                       |                       | (Fatal Security Violation langsung henti)| antara sensitivitas & beban. |                   |
+----+-----------------------+-----------------------+------------------------------------------+------------------------------+-------------------+
| 6  | Cakupan Penghitung    | Belum dispesifikasikan| Per-Category Counter (penghitung lokal   | Mencegah kegagalan parsing   | DESIGN REVIEW     |
|    | Circuit Breaker       | (status terbuka).     | per kelompok skenario pengujian).        | membatalkan uji performa mem.| REQUIRED          |
+----+-----------------------+-----------------------+------------------------------------------+------------------------------+-------------------+
| 7  | Aturan Reset Counter  | Belum dispesifikasikan| Hasil PASS mereset counter kategori ke 0;| Menjamin kegagalan terisolasi| DESIGN REVIEW     |
|    | terhadap Hasil Uji    | (status terbuka).     | Retry gagal TIDAK mereset counter.       | tidak memicu false hard stop.| REQUIRED          |
+----+-----------------------+-----------------------+------------------------------------------+------------------------------+-------------------+
| 8  | Perlakuan Hasil       | Kategori UNVERIFIED   | UNVERIFIED tidak menambah counter fail   | Memisahkan galat lingkungan  | DESIGN REVIEW     |
|    | UNVERIFIED pada CB    | terdefinisi di 08C.3. | Kelas 2; >= 2x UNVERIFIED henti kelompok.| dari kegagalan cipher kripto.| REQUIRED          |
+----+-----------------------+-----------------------+------------------------------------------+------------------------------+-------------------+
| 9  | Format Pencatatan     | Belum dispesifikasikan| Berkas JSON log insiden darurat terformat| Memberikan jejak audit foren-| DESIGN REVIEW     |
|    | Bukti Henti Darurat   | format lognya.        | (emergency_stop_incident_log.json).      | sik saat circuit breaker aktifREQUIRED          |
+----+-----------------------+-----------------------+------------------------------------------+------------------------------+-------------------+
```

---

## 8. REKONSILIASI CHECKLIST 12 BUTIR PRE-EKSEKUSI

Tabel berikut menyajikan pemetaan dampak langsung hasil perancangan B-CHAT-08D terhadap Checklist 12 Butir Pre-Eksekusi EXP-02, membandingkan status saat ini (*current status*) dengan status yang dapat dinaikkan setelah dokumen B-CHAT-08D disetujui secara resmi oleh Pemilik Proyek:

```
+--------------------------------------------------------------------------------------------------------------------------------------------------+
|                                           TABEL REKONSILIASI CHECKLIST 12 BUTIR PRE-EKSEKUSI EXP-02                                              |
+----+------------------------------------+-----------------------+-----------------------+---------------------+-------------------+--------------+
| No | Prasyarat Kesiapan                 | Status B-CHAT-08C.4   | Status Pasca-B-08D    | Bukti Dokumentatif  | Bukti Empiris yang| Status Pasca-|
|    |                                    | (Kondisi Awal)        | (Rancangan Lengkap)   | yang Diselesaikan   | Masih Diperlukan  | Persetujuan  |
+----+------------------------------------+-----------------------+-----------------------+---------------------+-------------------+--------------+
| 1  | Parameter teknis kanonikal telah   | VERIFIED DOCUMENTALLY | VERIFIED DOCUMENTALLY | Tabel 14 parameter  | Belum ada eksekusi| VERIFIED     |
|    | direkonsiliasi tanpa pertentangan  |                       |                       | terkunci di B-08A.5 | pengujian PoC.    | DOCUMENTALLY |
+----+------------------------------------+-----------------------+-----------------------+---------------------+-------------------+--------------+
| 2  | Hierarki dokumen tidak menyisakan  | VERIFIED DOCUMENTALLY | VERIFIED DOCUMENTALLY | Hierarki 3 tingkat  | N/A (Ketentuan    | VERIFIED     |
|    | konflik spesifikasi operasional    |                       |                       | di B-08B.1 & B-08C  | tata kelola).     | DOCUMENTALLY |
+----+------------------------------------+-----------------------+-----------------------+---------------------+-------------------+--------------+
| 3  | Fixture manifest sintetis tepercaya| PARTIALLY VERIFIED    | PARTIALLY VERIFIED    | Skema detail JSON,  | Pembuatan berkas  | VERIFIED     |
|    | telah ditentukan koridornya        |                       | [Rancangan Selesai;   | 12 skenario fixture,| fisik di sandbox  | DOCUMENTALLY |
|    |                                    |                       | menunggu persetujuan] | & anti-circular OK  | saat PoC dimulai. | (DESIGN SPEC)|
+----+------------------------------------+-----------------------+-----------------------+---------------------+-------------------+--------------+
| 4  | Batas persistensi dan aturan crash | PARTIALLY VERIFIED    | PARTIALLY VERIFIED    | Skema metadata hash | Pengujian I/O disk| VERIFIED     |
|    | recovery telah ditetapkan          |                       | [Rancangan Selesai;   | lokal 9 parameter & | & simulasi crash; | DOCUMENTALLY |
|    |                                    |                       | menunggu persetujuan] | alur retry lengkap  | durabilitas fsync | (DESIGN SPEC)|
|    |                                    |                       |                       | dispesifikasikan.   | tetap UNVERIFIED. | [fsync=UNV]  |
+----+------------------------------------+-----------------------+-----------------------+---------------------+-------------------+--------------+
| 5  | Batas klaim cleanup memori         | VERIFIED DOCUMENTALLY | VERIFIED DOCUMENTALLY | 3 fase pembersihan &| Pengukuran alokasi| VERIFIED     |
|    | (best-effort) telah ditetapkan     |                       |                       | batasan runtime V8  | buffer di Node.js.| DOCUMENTALLY |
+----+------------------------------------+-----------------------+-----------------------+---------------------+-------------------+--------------+
| 6  | Desain application-level nonce     | PARTIALLY VERIFIED    | PARTIALLY VERIFIED    | Desain in-memory    | Implementasi modul| PARTIALLY    |
|    | ledger telah ditetapkan            |                       |                       | ledger diformalkan  | & uji anti-reuse  | VERIFIED     |
|    | operasionalnya                     |                       |                       | di B-08B.1 & B-08C  | empiris di sandbox| (Menunggu PoC|
+----+------------------------------------+-----------------------+-----------------------+---------------------+-------------------+--------------+
| 7  | Matriks skenario telah             | VERIFIED DOCUMENTALLY | VERIFIED DOCUMENTALLY | Rekonsiliasi 35     | Eksekusi 35 uji   | VERIFIED     |
|    | direkonsiliasi tuntas (35 skenario)|                       |                       | skenario granular OK| pada test runner. | DOCUMENTALLY |
+----+------------------------------------+-----------------------+-----------------------+---------------------+-------------------+--------------+
| 8  | Lingkungan eksekusi workstation    | UNVERIFIED            | UNVERIFIED            | Data disalin dari   | Audit empiris host| UNVERIFIED   |
|    | aktual telah diverifikasi faktual  |                       | [Tetap Terpisah]      | laporan EXP-01      | saat ini di mesin | (Wajib pada  |
|    |                                    |                       |                       | (belum audit kini). | workstation.      | Prasyarat 1) |
+----+------------------------------------+-----------------------+-----------------------+---------------------+-------------------+--------------+
| 9  | Direktori sandbox terisolasi telah | VERIFIED DOCUMENTALLY | VERIFIED DOCUMENTALLY | Koridor sandbox di  | Pembuatan folder  | VERIFIED     |
|    | ditentukan koridor batasannya      |                       |                       | scratch/bchat_proto | fisik di disk.    | DOCUMENTALLY |
+----+------------------------------------+-----------------------+-----------------------+---------------------+-------------------+--------------+
| 10 | Batas berkas yang boleh dibuat saat| VERIFIED DOCUMENTALLY | VERIFIED DOCUMENTALLY | Daftar berkas diizin| Pengecekan daftar | VERIFIED     |
|    | otorisasi terbit telah jelas       |                       |                       | kan terkunci ketat  | berkas di sandbox.| DOCUMENTALLY |
+----+------------------------------------+-----------------------+-----------------------+---------------------+-------------------+--------------+
| 11 | Kriteria evaluasi PASS, FAIL, dan  | PARTIALLY VERIFIED    | PARTIALLY VERIFIED    | Kriteria 3 hasil &  | Evaluasi kelulusan| VERIFIED     |
|    | UNVERIFIED telah didefinisikan     |                       | [Rancangan Selesai;   | ambang circuit break| skenario pada test| DOCUMENTALLY |
|    |                                    |                       | menunggu persetujuan] | er diformalkan.     | runner prototipe. | (DESIGN SPEC)|
+----+------------------------------------+-----------------------+-----------------------+---------------------+-------------------+--------------+
| 12 | Kondisi henti darurat (circuit     | PARTIALLY VERIFIED    | PARTIALLY VERIFIED    | Kebijakan M=3, scope| Pengujian modul   | VERIFIED     |
|    | breaker / fail-closed) telah jelas |                       | [Rancangan Selesai;   | per-kategori & reset| henti darurat dgn | DOCUMENTALLY |
|    |                                    |                       | menunggu persetujuan] | diformalkan tuntas. | injeksi kesalahan.| (DESIGN SPEC)|
+----+------------------------------------+-----------------------+-----------------------+---------------------+-------------------+--------------+
```

### Catatan Kritis Perubahan Status Checklist:
1. **Butir 3, 4, 11, dan 12:** Saat ini tetap ditandai `PARTIALLY VERIFIED` karena rekomendasi desain baru saja diselesaikan dan sedang diajukan untuk ditinjau. **Hanya setelah Pemilik Proyek memberikan persetujuan tertulis atas dokumen B-CHAT-08D ini, status keempat butir tersebut dapat secara sah dinaikkan menjadi `VERIFIED DOCUMENTALLY (DESIGN SPECIFIED)`**.
2. **Butir 6 (Nonce Ledger):** Tetap berstatus `PARTIALLY VERIFIED` karena spesifikasi desain telah lengkap sejak B-CHAT-08B.1, namun modul kode dan pengujian empiris anti-reuse (UJI-E1) belum diwujudkan di sandbox.
3. **Butir 8 (Audit Lingkungan Workstation):** Tetap berstatus `UNVERIFIED` secara mutlak. Mandat B-CHAT-08D tidak menjalankan audit runtime host karena audit tersebut membutuhkan eksekusi skrip, yang merupakan ranah terpisah pasca-otorisasi pre-eksekusi.
4. **Status Otorisasi Eksperimen:** Tetap **`EXP-02 NOT AUTHORIZED`**. Penuntasan dokumen desain B-CHAT-08D tidak otomatis memberikan otorisasi untuk memulai eksperimen atau pembuatan kode prototipe.

---

## 9. RISIKO, ASUMSI, DAN KETERBATASAN YANG MASIH TERSISA

Meskipun perancangan prasyarat dokumentasi telah diselesaikan secara komprehensif, auditor dan tim pengembang wajib mengakui risiko serta keterbatasan teknis yang melekat pada prototipe EXP-02:

1. **Keterbatasan Runtime Node.js CLI vs Peramban Nyata (*Browser Environment*):**  
   Pengujian prototipe EXP-02 (bila diotorisasi) dijalankan pada runtime Node.js CLI. Karakteristik manajemen memori V8, aliran thread Web Worker, dan perilaku Garbage Collection pada Node.js **tidak dapat digeneralisasi secara mutlak** sebagai cerminan perilaku peramban web nyata (Google Chrome/Chromium, Mozilla Firefox/Gecko, dan Apple Safari/WebKit). Uji ketiadaan *UI freeze* tetap memerlukan validasi lanjutan pada tahapan pengembangan frontend.
2. **Ketiadaan Jaminan Durabilitas Fisik Sistem Berkas (*Hardware fsync Durability*):**  
   Mekanisme penulisan berkas dan storage hash pada EXP-02 mengevaluasi logika aplikasi Node.js (`fs.promises.writeFile`). Prototipe ini **tidak menguji ketahanan terhadap pemadaman listrik mendadak (*abrupt power loss*) atau crash fisik media penyimpanan**. Sinkronisasi disk tingkat rendah (`fsync`, flush cache hardware) berada di luar cakupan EXP-02 dan tetap berstatus `UNVERIFIED`.
3. **Batasan Keamanan Hash Tanpa Kunci (*Unkeyed Local Digest*):**  
   Sebagaimana ditegaskan pada Bab 5, berkas metadata digest `.meta.json` yang disimpan berdampingan dengan ciphertext di media lokal tidak melindungi sistem dari penyerang dengan hak akses sistem berkas lokal (*local OS privilege*). Mekanisme ini murni perlindungan integritas I/O lokal sebelum pengiriman ulang (*retry*).
4. **Ketiadaan Mekanisme Pembersihan Memori Aman (*No Secure Memory Wipe*):**  
   Bahasa JavaScript dan runtime Node.js/V8 tidak menyediakan fungsi native untuk pembersihan memori aman (*secure zeroing / memory wiping*) atas objek biner yang telah dialokasikan. Pelepasan buffer bersifat *best-effort* melalui dereferensi variabel dan penyerahan siklus pembersihan kepada Garbage Collector V8.

---

## 10. STATUS GERBANG AKHIR DAN PENYATAAN OTORISASI

### 10.1. Penetapan Status Evaluasi Desain
Berdasarkan penuntasan analisis dan perumusan spesifikasi desain konkret atas ketiga prasyarat dokumentasi yang sebelumnya terbuka, status evaluasi teknis gerbang pre-eksekusi EXP-02 secara resmi ditetapkan sebagai:

$$\mathbf{STATUS\ GERBANG:\ DESIGN\ REVIEW\ REQUIRED\ —\ EXP\text{-}02\ NOT\ AUTHORIZED}$$

**Alasan Penetapan Status:**
1. Tiga prasyarat perancangan dokumentatif (Skema Fixture Manifest, Skema Metadata Digest, dan Kebijakan Circuit Breaker) telah diselesaikan secara konkret dan bebas konflik, beralih dari status `DESIGN DECISION REQUIRED` menjadi **usulan spesifikasi desain matang**.
2. Dokumen ini sengaja tidak memutlakkan rekomendasinya sebagai kebijakan yang telah disetujui, melainkan menyajikannya secara transparan untuk ditinjau dan disahkan oleh Pemilik Proyek (*Design Review Required*).
3. Prasyarat 1 empiris (Audit Lingkungan Workstation) dan penerbitan otorisasi eksekusi EXP-02 tetap merupakan keputusan manajerial terpisah yang belum diterbitkan oleh Pemilik Proyek.

---

### 10.2. Komitmen Kepatuhan Nol Eksekusi (Zero Execution Compliance)
Penyusunan seluruh isi dokumen B-CHAT-08D dilaksanakan dengan kepatuhan penuh terhadap komitmen operasional mutlak:
- **Zero Prototype Code:** Direktori sandbox `scratch/bchat_prototype/exp02_chunking_aad/` **belum dibuat** dan tidak ada kode prototipe yang ditulis.
- **Zero Fixture/Hash Files:** Tidak ada berkas fixture manifest JSON fisik, berkas biner `.bin`, maupun berkas hash yang dibuat di disk.
- **Zero Test & Benchmark:** Tidak ada pengujian kriptografi, simulasi I/O, atau pengukuran memori yang dijalankan.
- **Zero Dependency Installation:** Tidak ada paket npm yang dipasang atau diperbarui.
- **Zero Runtime Execution:** Tidak ada audit runtime host, skrip CLI, proses Node.js, atau server aplikasi yang dijalankan.
- **Zero Codebase & DB Changes:** Direktori aplikasi Laravel, Next.js, dan database MySQL tidak disentuh.
- **Zero Git Operations:** Tidak ada perintah Git yang dijalankan.
- **Zero Historical Overwrite:** Seluruh dokumen historis repositori tetap tersimpan utuh tanpa penimpaan.

---

### 10.3. Pernyataan Eksplisit Penutup Status Otorisasi EXP-02

$$\mathbf{STATUS\ FINAL\ OPERASIONAL:\ EXP\text{-}02\ NOT\ AUTHORIZED}$$

> [!CAUTION]
> **PERINGATAN OPERASIONAL PENUTUP:**  
> Dokumen **B-CHAT-08D** ini secara resmi menyelesaikan seluruh analisis dan perancangan prasyarat dokumentasi EXP-02. **TIDAK ADA OTORISASI OTOMATIS** untuk memulai pembuatan direktori sandbox, instalasi dependensi, pembuatan berkas fixture fisik, atau penulisan kode prototipe EXP-02. Seluruh pekerjaan rekayasa perangkat lunak berada dalam status siaga penuh (*standby*) menunggu peninjauan desain dan penerbitan persetujuan tertulis eksplisit terpisah dari Pemilik Proyek.

---
*(Dokumen B-CHAT-08D selesai disusun secara paripurna sebagai instrumen penyelesaian prasyarat desain EXP-02, siap ditinjau oleh Pemilik Proyek).*

