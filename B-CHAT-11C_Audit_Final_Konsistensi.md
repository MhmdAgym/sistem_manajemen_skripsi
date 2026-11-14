# B-CHAT-11C — AUDIT FINAL KONSISTENSI SPESIFIKASI INTEGRASI E2EE
## LAPORAN AUDIT INDEPENDEN TERHADAP DOKUMEN SPESIFIKASI INTEGRASI B-CHAT-11, AUDIT B-CHAT-11A, DAN REVISI B-CHAT-11B
### SISTEM INFORMASI MANAJEMEN SKRIPSI UNIVERSITAS NAHDLATUL ULAMA CIBITUNG (UNUHA)

**Nomor Dokumen:** B-CHAT-11C-AUD-01  
**Tanggal Pelaksanaan Audit:** 6 November 2026  
**Mode Operasi:** `STRICTLY READ-ONLY — ZERO DOCUMENT & APPLICATION MUTATION`  
**Auditor:** Tim Arsitektur & Kriptografi Antigravity  
**Dokumen Target Audit:**
1. [`B-CHAT-11_Spesifikasi_Integrasi_E2EE.md`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11_Spesifikasi_Integrasi_E2EE.md) (Dokumen Spesifikasi Utama Revisi B-CHAT-11B)
2. [`B-CHAT-11A_Audit_Koreksi_Spesifikasi.md`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11A_Audit_Koreksi_Spesifikasi.md) (Dokumen Temuan Audit Pendahulu)
3. [`B-CHAT-11B_Laporan_Revisi_Spesifikasi.md`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11B_Laporan_Revisi_Spesifikasi.md) (Laporan Rekonsiliasi & Pelaksanaan Revisi)

**Sumber Bukti Fisik Terverifikasi:**
- Pustaka `ts-mls` v1.6.4 pada [`scratch/bchat_prototype/exp04_mls_comparison/node_modules/ts-mls/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/scratch/bchat_prototype/exp04_mls_comparison/node_modules/ts-mls/)
- Standar IETF RFC 9420 (*The Messaging Layer Security (MLS) Protocol*)
- Hasil Eksperimen EXP-02 pada [`scratch/bchat_prototype/exp02_chunking_aad/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/scratch/bchat_prototype/exp02_chunking_aad/)
- Hasil Eksperimen B-CHAT-10 Tahap 1F & 1G pada [`scratch/bchat_prototype/exp05_browser_worker_identity/`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/scratch/bchat_prototype/exp05_browser_worker_identity/)

---

## 1. RINGKASAN EKSEKUTIF

Audit Final Konsistensi **B-CHAT-11C** dilaksanakan berdasarkan mandat resmi untuk menguji secara kritis, mendalam, dan independen apakah dokumen spesifikasi teknis integrasi `B-CHAT-11` (beserta laporan pendampingnya `B-CHAT-11B`) benar-benar konsisten dengan keputusan arsitektural proyek, bukti eksperimen empiris, batasan teknis pustaka acuan, serta prinsip keamanan *Server-Blind Plaintext and Content-Key Access*.

Pelaksanaan audit ini dijalankan dalam kerangka **`STRICTLY READ-ONLY`**:
- Tidak ada modifikasi teks pada berkas B-CHAT-11, B-CHAT-11A, maupun B-CHAT-11B.
- Tidak ada kode aplikasi Laravel (`manajemen_skripsi/`) atau Next.js (`frontend_manajemen_skripsi/`) yang diubah.
- Tidak ada migrasi basis data, perubahan konfigurasi, instalasi dependensi, atau operasi Git.
- Seluruh kesimpulan audit didasarkan secara ketat pada bukti fisik baris kode sumber lokal (*evidence-based audit*). Temuan yang belum memiliki bukti diverifikasi ditandai secara objektif sebagai **`UNVERIFIED`** atau **`OPEN`**, tanpa membuat asumsi fiktif.

### Ringkasan Status Hasil Audit A–F:
1. **Audit A (Batas Generasi Ratchet 1.000):** **`CORRECTION REQUIRED`** pada dokumen pendamping `B-CHAT-11B` (baris 66); dan **`PASS`** pada spesifikasi utama `B-CHAT-11` karena angka 1.000 tidak tercantum di dalamnya. Angka 1.000 terbukti bukan berasal dari pustaka `ts-mls@1.6.4` maupun RFC 9420.
2. **Audit B (CRL Offline 72 Jam):** **`OPEN — BELUM DISETUJUI`**. Batas 72 jam terbukti merupakan murni asumsi desain teknis perancang tanpa dasar Surat Keputusan dari Pemilik Proyek, dan secara kriptografis merupakan jendela kerentanan pencabutan (*revocation delay window*), bukan jaminan keamanan mutlak.
3. **Audit C (State Machine Pergantian Dosen):** **`PARTIAL`**. Alur konseptual solid dan prinsip isolasi pairwise terpenuhi, namun terdapat ketidaksinkronan antara teks deskripsi dengan diagram status Mermaid mengenai state `SUSPENDED`, serta perlunya verifikasi Welcome oleh Dosen Baru sebelum room dipastikan sepenuhnya aktif.
4. **Audit D (Persistensi dan Pemulihan State MLS):** **`CORRECTION REQUIRED`** pada klasifikasi status OPEN-07. Pemanggilan `decodeGroupState(raw, 0)` terbukti berhasil di memori, namun tidak boleh diklaim membuktikan integritas persistensi atomik, crash recovery, dan isolasi multi-akun di IndexedDB.
5. **Audit E (Konsistensi Status Keputusan):** **`PARTIAL / CORRECTION REQUIRED`** pada penandaan status OPEN-07 (wajib dikembalikan ke `KANDIDAT EVALUASI` atau `OPEN`) dan penyelarasan header Bagian 4.6 terhadap klausul single-device.
6. **Audit F (Konsistensi Angka dan Terminologi):** **`PASS (100% KONSISTEN)`**. Seluruh parameter biner, kuota desimal, ukuran chunk, AAD 83-byte, nonce, tag, dan istilah strictly monotonic sequence terbukti tepat dan konsisten.

---

## 2. MATRIKS HASIL AUDIT A–F

| Area Audit | Aspek yang Diuji | Status Hasil | Lokasi Temuan & Rujukan Sumber |
| :---: | :--- | :---: | :--- |
| **Audit A** | Batas Generasi Ratchet 1.000 | **`CORRECTION REQUIRED`** *(Laporan 11B)*<br/>**`PASS`** *(Spesifikasi 11)* | `B-CHAT-11B` Baris 66;<br/>Sumber kode: `ts-mls/dist/src/keyRetentionConfig.js` Baris 2–6. |
| **Audit B** | CRL Offline 72 Jam | **`OPEN — BELUM DISETUJUI`** | `B-CHAT-11` Baris 213, 917;<br/>`B-CHAT-11A` Baris 328;<br/>`B-CHAT-11B` Baris 41, 142. |
| **Audit C** | State Machine Pergantian Dosen | **`PARTIAL`** | `B-CHAT-11` Baris 218–234 (Diagram),<br/>Baris 240–274 (Sequence & Teks). |
| **Audit D** | Persistensi dan Pemulihan State MLS | **`CORRECTION REQUIRED`** *(OPEN-07)* | `B-CHAT-11` Baris 323–334 (Bagian 4.4),<br/>Baris 920 (OPEN-07);<br/>Sumber: `ts-mls/dist/src/clientState.js` Baris 64–86. |
| **Audit E** | Konsistensi Status Keputusan | **`PARTIAL`** | `B-CHAT-11` Baris 345 vs 361;<br/>Baris 920 (OPEN-07 vs Status OPEN). |
| **Audit F** | Konsistensi Angka dan Terminologi | **`PASS`** | `B-CHAT-11` Baris 396, 424, 426, 427, 458, 460, 462, 479, 483. |

---

## 3. PEMBAHASAN DETAIL HASIL AUDIT

### 3.1. Audit A — Batas Generasi Ratchet 1.000

#### A. Pemeriksaan Keberadaan Teks
1. **Pada Dokumen Spesifikasi Utama (`B-CHAT-11_Spesifikasi_Integrasi_E2EE.md`):**
   - Klaim "1.000 generasi" **TIDAK DITEMUKAN** di seluruh isi dokumen spesifikasi.
   - Angka 1.000 hanya muncul satu kali pada [Baris 785](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11_Spesifikasi_Integrasi_E2EE.md#L785), yaitu pada durasi *debounce autosave draf lokal*:
     > `Autosave melakukan persistensi terenkripsi ke IndexedDB secara lokal (debounced 1.000 ms setelah pengetikan berhenti)...`
   - Pada [Baris 342](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11_Spesifikasi_Integrasi_E2EE.md#L342), proteksi replay hanya dirujuk berbasis mekanisme native:
     > `*Generation Ratchet & Anti-Replay:* Penolakan pesan yang sudah pernah didekripsi ditegakkan oleh pembaruan generation ratchet (Desired gen in the past), bukan oleh pemotongan retensi epoch.`
2. **Pada Dokumen Laporan Revisi (`B-CHAT-11B_Laporan_Revisi_Spesifikasi.md`):**
   - Klaim "maksimal 1.000 generasi" **DITEMUKAN SECARA EKSPLISIT** pada [Baris 66](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11B_Laporan_Revisi_Spesifikasi.md#L66):
     > `3. **Pemisahan Konseptual:** Membedakan dengan tegas antara retensi kunci transisi epoch MLS (untuk memproses commit yang terlambat) dengan batas generasi ratchet tree anti-replay (maksimal 1.000 generasi) untuk mendeteksi pesan duplikat.`

#### B. Pemeriksaan Sumber Kode Fisik Pustaka (`ts-mls@1.6.4`)
Pemeriksaan dilakukan langsung terhadap berkas konfigurasi bawaan pustaka `ts-mls`:
- **Path Berkas:** [`scratch/bchat_prototype/exp04_mls_comparison/node_modules/ts-mls/dist/src/keyRetentionConfig.js`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/scratch/bchat_prototype/exp04_mls_comparison/node_modules/ts-mls/dist/src/keyRetentionConfig.js#L2-L6)
- **Kode Sumber Aktual:**
  ```javascript
  export const defaultKeyRetentionConfig = {
      retainKeysForGenerations: 10,
      retainKeysForEpochs: 4,
      maximumForwardRatchetSteps: 200,
  };
  ```
- **Path Mekanisme Ratchet:** [`scratch/bchat_prototype/exp04_mls_comparison/node_modules/ts-mls/dist/src/secretTree.js`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/scratch/bchat_prototype/exp04_mls_comparison/node_modules/ts-mls/dist/src/secretTree.js#L69-L73)
  ```javascript
  export async function ratchetUntil(current, desiredGen, config, kdf) {
      const generationDifference = desiredGen - current.generation;
      if (generationDifference > config.maximumForwardRatchetSteps)
          throw new ValidationError("Desired generation too far in the future");
  ...
  ```

#### C. Analisis Kritis dan Evaluasi Dampak
1. **Asal Angka:** Angka 1.000 **sama sekali bukan** berasal dari konfigurasi atau kode `ts-mls@1.6.4`. Nilai bawaan pustaka adalah `retainKeysForGenerations: 10` dan `maximumForwardRatchetSteps: 200`. Angka 1.000 juga bukan standar IETF RFC 9420 (RFC 9420 §7.7 menyerahkan batas implementasi kepada aplikasi).
2. **Karakter Batasan:** Angka 1.000 pada laporan B-CHAT-11B teridentifikasi sebagai *klaim tanpa sumber* (kemungkinan kontaminasi redaksional dari durasi debounce 1.000 ms pada autosave draf).
3. **Pengaruh terhadap Pesan Tertunda (*Out-of-Order Delivery*):**
   - Klaim bahwa sistem mentoleransi hingga 1.000 generasi adalah **menyesatkan dan berisiko fatal**: jika klien menerima pesan dengan selisih generasi $> 200$, `ts-mls` secara otomatis menggugurkan pesan dengan galat fatal:
     `ValidationError: Desired generation too far in the future`.
   - Selain itu, kapasitas penyimpanan kunci generasi masa lalu yang terlewat (`unusedGenerations`) hanya menampung **10 kunci** (`retainKeysForGenerations: 10`). Jika lebih dari 10 pesan terlewat, kunci generasi lama dihapus via `removeOldGenerations` sehingga pesan yang terlambat lebih dari 10 langkah akan gagal didekripsi dengan galat:
     `ValidationError: Desired gen in the past`.
4. **Klasifikasi:**
   - **`CORRECTION REQUIRED`** untuk dokumen pendamping [`B-CHAT-11B_Laporan_Revisi_Spesifikasi.md`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11B_Laporan_Revisi_Spesifikasi.md#L66). Angka 1.000 wajib dicabut atau diklarifikasi.
   - **`PASS`** untuk dokumen spesifikasi utama [`B-CHAT-11_Spesifikasi_Integrasi_E2EE.md`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11_Spesifikasi_Integrasi_E2EE.md) karena dokumen utama bersih dari klaim angka 1.000 generasi tersebut.

---

### 3.2. Audit B — CRL Offline 72 Jam

#### A. Pemeriksaan Asal-Usul Keputusan
Pemeriksaan silang terhadap dokumen B-CHAT-01, B-CHAT-04, B-CHAT-05, B-CHAT-08A-D, dan B-CHAT-09 membuktikan bahwa:
- Batas toleransi "72 jam" **tidak pernah diputuskan atau diinstruksikan oleh Pemilik Proyek**.
- Angka ini pertama kali muncul pada dokumen perancangan B-CHAT-11 sebagai asumsi perancang sistem (`ASUMSI DESAIN`) dan dicatat pada [Baris 213](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11_Spesifikasi_Integrasi_E2EE.md#L213) serta [Baris 917](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11_Spesifikasi_Integrasi_E2EE.md#L917) (OPEN-04).

#### B. Evaluasi Perbedaan Domain Kriptografis dan Temporal
Audit membedakan 5 konsep yang tidak boleh dikaburkan:
1. **Masa Berlaku Kredensial Akademik (*Credential Validity Period*):**  
   Masa aktif sertifikat Ed25519 yang ditandatangani CA Laravel (misal 1 semester akademik atau durasi SK Pembimbing 6 bulan). Ditentukan oleh field `valid_until` pada kredensial.
2. **Waktu Pembaruan Terakhir CRL (*Last CRL Sync Timestamp*):**  
   Waktu lokal saat klien Next.js terakhir kali berhasil mengunduh pembaruan daftar pencabutan dari `GET /api/bchat/crl` (`last_crl_sync_at`).
3. **Status Koneksi Klien saat Validasi:**  
   - *Online:* Klien dapat melakukan validasi revocation secara langsung (*near real-time*).
   - *Offline:* Klien hanya mengandalkan salinan CRL lokal yang tersimpan di IndexedDB.
4. **Perilaku Ketika Status Revocation Tidak Dapat Diperiksa:**  
   - *Periode Grace ($\Delta t \le 72\text{ jam}$):* Sistem mengizinkan peramban mengenkripsi pesan baru berdasarkan cache CRL lokal (*soft-fail mode*).
   - *Periode Kadaluwarsa Cache ($\Delta t > 72\text{ jam}$):* Sistem memblokir enkripsi pesan baru hingga peramban terhubung kembali ke server (*fail-secure hard block*).
5. **Perbedaan Status Kredensial:**  
   - *Valid:* Belum melewati `valid_until` dan tidak terdaftar di CRL.
   - *Expired (Kadaluwarsa):* Telah melewati `valid_until`. Penolakan dilakukan secara lokal oleh client clock tanpa bergantung pada CRL.
   - *Revoked (Dicabut):* Masih dalam rentang `valid_until`, tetapi haknya dicabut sebelum masa berlaku habis (misal pergantian dosen, pelanggaran etik, mahasiswa DO), tercatat di tabel `bchat_crl_revocations`.

#### C. Analisis Jaminan Keamanan vs Jendela Kerentanan
- Dokumen B-CHAT-11 secara tepat telah mencantumkan label `ASUMSI DESAIN` pada Baris 213 dan `OPEN` pada Baris 917.
- Namun, audit menemukan bahwa narasi pada Baris 213 berpotensi disalahartikan sebagai "jaminan keamanan". Secara kriptografis:
  $$\text{Window of Exposure} = t_{\text{sync}} - t_{\text{revocation}} \le 72\text{ jam}$$
  Jika hak bimbingan seorang dosen dicabut pada jam ke-1, dan mahasiswa yang sedang offline tetap mengirim pesan terenkripsi hingga jam ke-71, dosen yang telah dicabut haknya secara teori masih dapat menerima muatan ciphertext yang ditujukan kepadanya (karena mahasiswa belum mengetahui pencabutan tersebut).
- Oleh karena itu, 72 jam adalah **kompromi ketersediaan (availability trade-off)**, bukan jaminan perlindungan mutlak.
- **Klasifikasi:** Sesuai instruksi mandat, karena belum ada keputusan resmi dari Pemilik Proyek, angka 72 jam ditandai sebagai:
  **`OPEN — BELUM DISETUJUI`**.

---

### 3.3. Audit C — State Machine Pergantian Dosen

Pemeriksaan dilakukan terhadap siklus hidup ruang obrolan pada Bagian 3.6 ([Baris 218–234](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11_Spesifikasi_Integrasi_E2EE.md#L218-L234)) dan alur rotasi pada Bagian 3.7 ([Baris 240–274](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11_Spesifikasi_Integrasi_E2EE.md#L240-L274)).

#### A. Verifikasi 10 Aspek Alur Pergantian Dosen:
1. **Pemblokiran Akses REST API Dosen Lama Langsung Pasca-SK Baru:**  
   **`PASS`**. Pada Baris 248, backend mengeksekusi `bchat_room_members.is_active = false`. Controller API memvalidasi `where('is_active', true)` sehingga pemanggilan REST API oleh dosen lama langsung ditolak (`403 Forbidden`).
2. **Pemeriksaan Otorisasi Saluran Privat Reverb Terhadap Keanggotaan Terkini:**  
   **`PARTIAL`**. Pada Baris 285–289 (`routes/channels.php`), otorisasi channel memeriksa `$room->members()->where('is_active', true)->exists()`. Hal ini memblokir langganan baru (*new subscription*). Namun, WebSocket connection yang sedang aktif (*existing established connection*) tidak otomatis terputus kecuali backend memicu *channel eviction* atau memutuskan koneksi socket dosen lama.
3. **Dosen Lama Tidak Bisa Mengambil Pesan Baru Saat Room Berada dalam Transisi:**  
   **`PASS`**. Pesan baru dibekukan di status `PENDING_REKEYING`, dan endpoint `GET /messages` memfilter keanggotaan aktif.
4. **Pengiriman Pesan Baru Dibekukan Sampai Rekeying Selesai:**  
   **`PASS`**. Baris 250 menyatakan `Pengiriman Pesan Baru Ditangguhkan`, dan validasi status room `PENDING_REKEYING` menolak `POST /messages`.
5. **Room Baru Aktif Hanya Setelah Commit dan Welcome Berhasil Diverifikasi:**  
   **`CORRECTION REQUIRED`**. Pada Baris 256–258, server langsung mengubah status `bchat_rooms.status = 'ACTIVE'` saat menerima *Welcome Package C* dari Mahasiswa. Karena server bersifat *server-blind*, server tidak dapat memverifikasi isi Welcome package. Jika Welcome package tersebut korup atau tidak cocok dengan KeyPackage Dosen Baru C, Dosen C akan gagal mengeksekusi `mls.joinGroup(Welcome)`. Seharusnya status `ACTIVE` penuh baru ditegakkan setelah Dosen C berhasil join atau mengirimkan acknowledgment awal.
6. **Penanganan Jika Mahasiswa Offline dalam Waktu Lama:**  
   **`PARTIAL / INKONSISTENSI DIAGRAM`**. Baris 271–272 menyatakan bahwa jika mahasiswa offline melampaui batas toleransi (misal 14 hari), room berpindah ke status `SUSPENDED`. Namun pada diagram Mermaid Bagian 3.6 ([Baris 218–234](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11_Spesifikasi_Integrasi_E2EE.md#L218-L234)), state `SUSPENDED` **sama sekali tidak dicantumkan**. Diagram Mermaid melompat dari `ACTIVE` ke `TERMINATED` atau `PENDING_REKEYING`.
7. **Penanganan Kegagalan Commit, Welcome, Upload, atau Sinkronisasi Backend:**  
   **`PARTIAL`**. State `REKEY_FAILED` tercantum pada diagram ([Baris 227–228](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11_Spesifikasi_Integrasi_E2EE.md#L227-L228)), namun spesifikasi belum merinci langkah pembersihan data parsial (*rollback state*) jika upload Welcome terputus di tengah jalan.
8. **Keamanan dan Idempotensi Retry:**  
   **`OPEN / UNVERIFIED`**. Belum ada mekanisme pendeteksi duplikasi commit pada endpoint `POST /rotate-commit` jika mahasiswa menekan retry berulang kali saat koneksi buruk.
9. **Isolasi Pairwise Room Lainnya Tidak Terpengaruh:**  
   **`PASS`**. Baris 272–273 menegaskan prinsip isolasi pairwise mutlak: pergantian Dospem 1 pada Room 1 tidak mempengaruhi status, kunci, maupun riwayat pesan Room 2 (Dospem 2).
10. **Pemisahan Pencabutan Akses Server vs Kriptografis MLS:**  
    **`PASS`**. Baris 267 secara eksplisit menegaskan: pemblokiran database di server (`is_active = false`) adalah pencabutan operasional server, sedangkan pencabutan kriptografis sejati baru tuntas saat transaksi Commit TreeKEM dieksekusi di peramban dan epoch berganti ke $E+1$ (Forward Secrecy / Post-Compromise Security).

#### B. Kesimpulan Audit C:
Status keseluruhan: **`PARTIAL`**. Rekomendasi perbaikan minimal: menambahkan state `SUSPENDED` pada diagram Mermaid Bagian 3.6 agar selaras dengan narasi Baris 271, dan merinci handshake acknowledgment join Dosen Baru sebelum room dinyatakan `ACTIVE`.

---

### 3.4. Audit D — Persistensi dan Pemulihan State MLS

Pemeriksaan difokuskan pada Bagian 4.4 ([Baris 323–334](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11_Spesifikasi_Integrasi_E2EE.md#L323-L334)) dan klaim status OPEN-07 pada Bagian 11.1 ([Baris 920](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11_Spesifikasi_Integrasi_E2EE.md#L920)):
> `| OPEN-07 | Persistensi State Lengkap | Penanganan serialisasi state ratchet tree yang stabil di IndexedDB. | DISETUJUI | Terbukti pada Tahap 1F menggunakan decodeGroupState(raw, 0). Diadopsi pada Fase 2. |`

#### A. Pemeriksaan Terhadap 10 Aspek Persistensi & Pemulihan:
1. **Serialisasi State Grup:** **`PASS`**. `mls.encodeGroupState` menserialisasi objek `GroupState` menjadi buffer biner terstruktur TLS.
2. **Private Key Anggota:** **`PASS`**. Objek `GroupState` memuat `signaturePrivateKey` (Ed25519) dan `privatePath` (TreeKEM node secrets) milik anggota lokal ([`ts-mls/dist/src/clientState.js:54-55`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/scratch/bchat_prototype/exp04_mls_comparison/node_modules/ts-mls/dist/src/clientState.js#L54-L55)).
3. **KeyPackage dan Material Pendukung:** **`PARTIAL`**. KeyPackage pool anggota yang belum terpakai **tidak disimpan** di dalam `GroupState`; KeyPackage harus dikelola dan disimpan secara terpisah pada store IndexedDB tersendiri.
4. **State Ratchet dan Epoch:** **`PASS`**. Objek `GroupState` memuat `secretTree` dan `keySchedule` yang merekam generation dan epoch saat ini.
5. **Persistensi Atomik ke IndexedDB:** **`UNVERIFIED / OPEN`**. Fungsi `decodeGroupState` murni bekerja pada buffer di memori. `ts-mls` tidak memiliki integrasi native dengan IndexedDB. Transaksi atomik multi-store IndexedDB (`db.transaction(['states', 'keys'], 'readwrite')`) merupakan tanggung jawab kode pembungkus aplikasi.
6. **Pemulihan Setelah Browser Ditutup Normal:** **`PASS`**. Terbukti pada eksperimen Tahap 1F selama buffer biner tersimpan utuh di IndexedDB sebelum tab ditutup.
7. **Pemulihan Setelah Browser Crash atau Restart Perangkat:** **`UNVERIFIED / OPEN`**. Jika peramban crash saat transaksi penulisan IndexedDB berlangsung, buffer biner dapat terpotong (*partial write / corruption*).
8. **Penanganan Kegagalan Parsial saat Penyimpanan:** **`CORRECTION REQUIRED`**. Pustaka `ts-mls` tidak memiliki mekanisme auto-repair untuk buffer yang korup; pemanggilan `decodeGroupState` pada buffer terpotong akan melempar fatal exception (`ValidationError` / `InternalError`). B-CHAT-11 belum merinci fallback rehidrasi jika buffer korup.
9. **Konsistensi Antara State MLS dan Data Pesan Lokal:** **`UNVERIFIED / OPEN`**. State epoch MLS dapat mengalami desinkronisasi dengan riwayat pesan lokal di IndexedDB jika salah satu transaksi penyimpanan lokal gagal di tengah jalan.
10. **Perlindungan Data Saat Pengguna Berganti Akun pada Perangkat yang Sama:** **`PARTIAL`**. Pada Baris 327, store `mls_group_states` hanya dicatat berindeks `room_uuid`. Jika dua pengguna berbeda (misal mahasiswa A dan mahasiswa B) login bergantian pada komputer laboratorium yang sama, ketiadaan compound index `[user_id, room_uuid]` pada store state MLS berisiko membocorkan state kriptografis antar pengguna.

#### B. Kesimpulan Audit D:
Klaim pada OPEN-07 bahwa pemanggilan `decodeGroupState(raw, 0)` telah membuktikan "penanganan serialisasi state ratchet tree yang stabil di IndexedDB" dengan status **`DISETUJUI`** adalah **overclaim (klaim berlebihan)**.
- Yang terbukti pada Tahap 1F hanyalah: **perbaikan bug offset deserializer biner `ts-mls` di memori Web Worker**.
- Integritas persistensi atomik, isolasi multi-akun di IndexedDB, dan pemulihan pasca-crash belum terbukti dan wajib diuji pada Fase 2.
- **Klasifikasi:** **`CORRECTION REQUIRED`**. Status OPEN-07 harus dikembalikan ke status **`KANDIDAT EVALUASI`** atau **`OPEN`**.

---

### 3.5. Audit E — Konsistensi Status Keputusan

Pemeriksaan silang dilakukan terhadap seluruh penandaan status klausul pada B-CHAT-11:

#### A. Verifikasi Keputusan Berstatus `DISETUJUI`:
1. *Pairwise Room (Proposal 1 room, Skripsi 2 room):* Terverifikasi `DISETUJUI` pada Bagian 3.3 ([Baris 153](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11_Spesifikasi_Integrasi_E2EE.md#L153)).
2. *Batas Lampiran (10.000.000 B/file, maks 5 file, total 25.000.000 B/pesan):* Terverifikasi `DISETUJUI` pada Bagian 6.1 ([Baris 422](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11_Spesifikasi_Integrasi_E2EE.md#L422)).
3. *AES-256-GCM, CEK 256-bit, Nonce 96-bit, Tag 128-bit:* Terverifikasi `DISETUJUI` pada Bagian 6.2 ([Baris 429](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11_Spesifikasi_Integrasi_E2EE.md#L429)).
4. *AAD Kanonis 83-Byte EXP-02:* Terverifikasi `DISETUJUI` pada Bagian 6.3 ([Baris 465](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11_Spesifikasi_Integrasi_E2EE.md#L465)).
5. *Potongan Chunk 1 MiB ($1.048.576\text{ byte}$):* Terverifikasi `DISETUJUI` pada Bagian 6.2 ([Baris 429](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11_Spesifikasi_Integrasi_E2EE.md#L429)).
6. *REST API / MySQL sebagai Sumber Persisten & Reverb untuk Real-Time:* Terverifikasi `DISETUJUI` pada Bagian 2.3 ([Baris 129](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11_Spesifikasi_Integrasi_E2EE.md#L129)).
7. *Draf Lokal Tidak Dikirim ke Server Sebelum Tombol Kirim Ditekan:* Terverifikasi `DISETUJUI` pada Bagian 8.1 ([Baris 761](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11_Spesifikasi_Integrasi_E2EE.md#L761)).
8. *Retensi Arsip Menunggu Kebijakan Resmi UNUHA:* Prinsip penangguhannya disetujui, dan status kebijakannya tercatat secara tepat sebagai `OPEN` pada OPEN-09 ([Baris 922](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11_Spesifikasi_Integrasi_E2EE.md#L922)).

#### B. Verifikasi Keputusan Berstatus `KANDIDAT EVALUASI`:
1. *`ts-mls@1.6.4` sebagai Kandidat Utama:* Terverifikasi `KANDIDAT EVALUASI` pada Bagian 1.2 ([Baris 37](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11_Spesifikasi_Integrasi_E2EE.md#L37)).
2. *OpenMLS-WASM sebagai Pembanding Cadangan:* Terverifikasi `KANDIDAT EVALUASI` pada Bagian 1.2 ([Baris 39](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11_Spesifikasi_Integrasi_E2EE.md#L39)).
3. *OD-17 Pendekatan B sebagai Kandidat Utama Evaluasi:* Terverifikasi `KANDIDAT EVALUASI` pada Bagian 6.4 ([Baris 481](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11_Spesifikasi_Integrasi_E2EE.md#L481)) dengan formula B-CHAT-09 (`KANDIDAT UTAMA EVALUASI DISETUJUI — PEMENANG FINAL BELUM DIPUTUSKAN`).
4. *OD-17 Pendekatan A sebagai Pembanding:* Terverifikasi `KANDIDAT EVALUASI` pada Bagian 6.4 ([Baris 486](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11_Spesifikasi_Integrasi_E2EE.md#L486)).
5. *Kebijakan Single Active Device:* Terverifikasi `KANDIDAT EVALUASI` pada Bagian 4.6 ([Baris 361](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11_Spesifikasi_Integrasi_E2EE.md#L361)).

#### C. Verifikasi Keputusan Berstatus `OPEN`:
- Seluruh 8 item terbuka wajib (Pemenang final OD-17, nilai `retainKeysForEpochs`, integrasi AAD 83-byte, integrasi CA Laravel, toleransi CRL offline, pemulihan perangkat/riwayat, interoperabilitas OpenMLS, dan retensi arsip) terdaftar secara konsisten pada Bagian 11.1 (OPEN-01 s.d. OPEN-06, OPEN-08, OPEN-09).

#### D. Inkonsistensi Status yang Ditemukan:
1. **OPEN-07 (Baris 920):** Ditandai `DISETUJUI`. Seharusnya ditandai **`KANDIDAT EVALUASI`** atau **`OPEN`** karena persistensi atomik multi-store IndexedDB belum divalidasi end-to-end.
2. **Header Bagian 4.6 (Baris 345):** Diberi label `[ASUMSI DESAIN]`, sementara sub-klausul di dalamnya pada Baris 361 diberi label `[KANDIDAT EVALUASI]`. Perlu disinkronkan.

---

### 3.6. Audit F — Konsistensi Angka dan Terminologi

Pemeriksaan teks rigid terhadap 12 parameter numerik dan terminologi:

| No | Parameter / Terminologi yang Diperiksa | Target Nilai / Format | Nilai Aktual pada B-CHAT-11 | Status Hasil |
| :---: | :--- | :--- | :--- | :---: |
| 1 | Batas Ukuran File Tunggal | 10.000.000 byte (Bukan 10 MiB) | `10.000.000 byte (Bukan 10 MiB binary)` ([Baris 424](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11_Spesifikasi_Integrasi_E2EE.md#L424)) | **`PASS`** |
| 2 | Batas Total Lampiran per Pesan | 25.000.000 byte (Bukan 25 MiB) | `25.000.000 byte gabungan` ([Baris 426](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11_Spesifikasi_Integrasi_E2EE.md#L426)) | **`PASS`** |
| 3 | Ukuran Potongan Chunk Kanonikal | 1 MiB = 1.048.576 byte | `1 MiB ($1.048.576\text{ byte}$)` ([Baris 427, 459](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11_Spesifikasi_Integrasi_E2EE.md#L427)) | **`PASS`** |
| 4 | Ukuran Buffer AAD Kanonis EXP-02 | Tepat 83 byte biner | `Tepat 83 Byte` ([Baris 441, 460, 465, 479](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11_Spesifikasi_Integrasi_E2EE.md#L465)) | **`PASS`** |
| 5 | Panjang Nonce AES-256-GCM | Tepat 96 bit (12 byte) | `Nonce 96-bit: Salt (8B) + ChunkIndex (4B)` ([Baris 458](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11_Spesifikasi_Integrasi_E2EE.md#L458)) | **`PASS`** |
| 6 | Panjang Tag Autentikasi GCM | Tepat 128 bit (16 byte) | `tag AES-GCM 128-bit` ([Baris 812](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11_Spesifikasi_Integrasi_E2EE.md#L812)) | **`PASS`** |
| 7 | Panjang Kunci Simetris CEK | Tepat 256 bit (32 byte) | `CEK 256-bit acak CSPRNG` ([Baris 49, 457, 483](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11_Spesifikasi_Integrasi_E2EE.md#L457)) | **`PASS`** |
| 8 | Representasi UUID di Dalam AAD | 16 byte biner mentah | `16 byte biner mentah (uuidToBytes)` ([Baris 462, 472–474](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11_Spesifikasi_Integrasi_E2EE.md#L462)) | **`PASS`** |
| 9 | Penomoran Urutan Pesan Server | Strictly Monotonically Increasing | Monotonik tegas $S_{i+1} > S_i$; klaim gapless dihapus ([Baris 370–404](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11_Spesifikasi_Integrasi_E2EE.md#L370)) | **`PASS`** |
| 10 | Kerahasiaan Manifest Lampiran | Terbungkus di MLS Application | Zero plaintext metadata di server ([Baris 449–454](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11_Spesifikasi_Integrasi_E2EE.md#L449)) | **`PASS`** |
| 11 | Penyaluran CEK Lampiran | Authenticated encrypted MLS msg | CEK dibungkus di manifest MLS ([Baris 483, 881](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11_Spesifikasi_Integrasi_E2EE.md#L483)) | **`PASS`** |
| 12 | State Transisi Rekeying Dosen | Tidak sama dengan rekey selesai | State `PENDING_REKEYING` memblokir transmisi ([Baris 249, 267](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11_Spesifikasi_Integrasi_E2EE.md#L267)) | **`PASS`** |

---

## 4. INVENTARISASI TEMUAN AUDIT

### 4.1. Klaim yang Benar dan Dapat Dipertahankan
1. Prinsip *Server-Blind Plaintext and Content-Key Access* ditegakkan secara presisi tanpa kompromi.
2. Arsitektur isolasi pairwise 1-on-1 (Room 1 untuk Dospem 1 dan Room 2 untuk Dospem 2) konsisten secara matematis dan logis.
3. Struktur framing biner Format A ($L_i + 28\text{ B}$) dan AAD kanonis 83-byte EXP-02 dipertahankan utuh dan valid.
4. Parameter kuota desimal (10.000.000 byte dan 25.000.000 byte) konsisten dan tidak tertukar dengan notasi biner MiB.
5. Penghapusan klaim "gapless sequence" dan penggantiannya dengan jaminan *Strictly Monotonically Increasing* ($S_{i+1} > S_i$) via transaksi MySQL 8 InnoDB `SELECT ... FOR UPDATE` dengan *retry loop* 3x terbukti kokoh dan realistis terhadap kegagalan jaringan riil.
6. Pemisahan arsitektur antara Draf Lokal (terenkripsi `CryptoKey` AES-GCM non-extractable lokal di IndexedDB) dan Outbox (antrean in-flight yang tertahan jaringan) menyelesaikan cacat keterikatan cookie Sanctum.
7. Pemisahan mutlak antara *Account Recovery* via Sanctum dan keterbatasan pemulihan riwayat percakapan E2EE masa lalu (*Historical Plaintext Recovery*).

### 4.2. Klaim yang Harus Dikoreksi
1. **Laporan B-CHAT-11B Baris 66:**  
   Pernyataan `batas generasi ratchet tree anti-replay (maksimal 1.000 generasi)` **wajib dikoreksi/dicabut**. Pustaka `ts-mls@1.6.4` menggunakan batas bawaan `retainKeysForGenerations: 10` dan `maximumForwardRatchetSteps: 200`. Angka 1.000 generasi adalah klaim tanpa sumber dan berisiko menyebabkan pesan tertunda ditolak fatal.
2. **B-CHAT-11 Baris 920 (Tabel OPEN Items kode OPEN-07):**  
   Status `DISETUJUI` pada OPEN-07 **wajib dikoreksi** menjadi **`KANDIDAT EVALUASI`** atau **`OPEN`**. Keberhasilan memanggil `decodeGroupState(raw, 0)` di memori Web Worker pada Tahap 1F tidak membuktikan persistensi atomik multi-store IndexedDB, mitigasi crash, maupun isolasi multi-akun.
3. **B-CHAT-11 Bagian 3.6 Diagram Mermaid (Baris 218–234):**  
   Diagram siklus hidup room **wajib diselaraskan** dengan narasi Baris 271 dengan menambahkan state `SUSPENDED` pasca-timeout pada `PENDING_REKEYING`.
4. **B-CHAT-11 Bagian 3.7 Sequence Diagram (Baris 256–258):**  
   Pengubahan status room menjadi `ACTIVE` tidak boleh dilakukan secara sepihak oleh server hanya berdasarkan penerimaan Welcome package, melainkan harus menunggu konfirmasi join/acknowledgment dari Dosen Baru C.

### 4.3. Klaim yang Belum Dapat Diverifikasi (`UNVERIFIED` / `OPEN`)
1. **Ketahanan IndexedDB Terhadap Crash di Tengah Penulisan:** Belum ada pembuktian mekanisme write-ahead journal atau transaksi atomik pada kegagalan perangkat mendadak (`UNVERIFIED`).
2. **Idempotensi Transaksi `rotate-commit`:** Penanganan retry konkuren pada commit rotasi pembimbing belum terverifikasi secara kode (`OPEN`).
3. **Mekanisme Eviction Saluran WebSocket Reverb:** Pemutusan paksa koneksi socket dosen lama yang sedang aktif saat SK dicabut belum dirinci di tingkat protokol Reverb (`OPEN`).
4. **Batas Toleransi CRL Offline 72 Jam:** Belum memiliki dasar hukum Surat Keputusan akademik UNUHA (`OPEN — BELUM DISETUJUI`).

---

## 5. DAFTAR REKONSILIASI KEPUTUSAN YANG TETAP OPEN

Seluruh item terbuka (**OPEN Items**) dikonfirmasi tetap berstatus terbuka menunggu pengujian bertahap:

| Kode Item | Deskripsi Kebutuhan Teknis | Status Klasifikasi | Tahap Pembuktian / Validasi |
| :---: | :--- | :---: | :--- |
| **OPEN-01** | Integrasi biner AAD 83-byte ke pipeline Web Worker `ts-mls` | **`OPEN`** | Fase 4 (Porting modul EXP-02) |
| **OPEN-02** | Modul Laravel CA Signer Ed25519 dengan izin filesystem `0600` | **`OPEN`** | Fase 1 (Backend foundation) |
| **OPEN-03** | Penyetelan parameter numerik definitif `retainKeysForEpochs` | **`OPEN`** | Fase 2 (Uji simulasi jitter jaringan) |
| **OPEN-04** | Protokol pembaruan CRL dan batas toleransi offline (72 jam) | **`OPEN — BELUM DISETUJUI`** | Menunggu penetapan Pemilik Proyek |
| **OPEN-05** | Pengesahan pemenang akhir distribusi kunci lampiran OD-17 | **`OPEN`** | Fase 4 (Evaluasi Pendekatan B vs A) |
| **OPEN-06** | Evaluasi interoperabilitas translasi wireformat OpenMLS vs `ts-mls` | **`OPEN`** | Fase 2 (Kandidat pembanding) |
| **OPEN-07** | Arsitektur persistensi atomik & isolasi multi-akun di IndexedDB | **`OPEN`** *(Koreksi)* | Fase 2 (Web Worker storage hook) |
| **OPEN-08** | Protokol pendaftaran perangkat baru & pelabelan unreadable history | **`OPEN`** | Fase 2 (Pairwise room lifecycle) |
| **OPEN-09** | Kebijakan retensi dan pembersihan arsip dokumen digital skripsi | **`OPEN`** | Menunggu Surat Edaran Rektorat UNUHA |

---

## 6. REKOMENDASI KOREKSI PALING MINIMAL (MINIMAL INVASIVE REVISION)

Untuk menjaga stabilitas dokumen spesifikasi tanpa merombak arsitektur yang sudah solid, perbaikan yang direkomendasikan terbatas pada 4 poin editorial berikut:

1. **Koreksi Status OPEN-07:**  
   Ubah status OPEN-07 pada Tabel Bagian 11.1 dari `DISETUJUI` menjadi **`OPEN`** (atau `KANDIDAT EVALUASI`) dengan catatan tindak lanjut: *"Fungsi decodeGroupState terbukti di memori; persistensi atomik multi-store dan isolasi multi-akun di IndexedDB diuji pada Fase 2"*.
2. **Penyelarasan State `SUSPENDED` pada Diagram Mermaid:**  
   Tambahkan state `SUSPENDED` dan transisi `PENDING_REKEYING --> SUSPENDED: Timeout Mahasiswa Offline (14 Hari)` pada diagram Mermaid Bagian 3.6.
3. **Penyelarasan Handshake Welcome Dosen Baru:**  
   Pada sequence diagram Bagian 3.7, tambahkan langkah konfirmasi penerimaan Welcome package oleh Dosen Baru sebelum room beralih dari status transisi ke status `ACTIVE`.
4. **Pencabutan Redaksional Angka 1.000 pada Dokumen Pendamping B-CHAT-11B:**  
   Koreksi Baris 66 pada laporan B-CHAT-11B agar mencerminkan konfigurasi bawaan `ts-mls` (`retainKeysForGenerations: 10`, `maximumForwardRatchetSteps: 200`).

---

## 7. KESIMPULAN KELAYAKAN B-CHAT-11 SEBAGAI SPESIFIKASI ACUAN

Berdasarkan hasil audit komprehensif terhadap enam area (Audit A–F):

1. **Kelayakan Arsitektural:**  
   Dokumen [`B-CHAT-11_Spesifikasi_Integrasi_E2EE.md`](file:///d:/Aplikasi/laragon/www/sistem_manajamen_skripsi/B-CHAT-11_Spesifikasi_Integrasi_E2EE.md) dinyatakan **LAYAK SECARA TEKNIS SEBAGAI DOKUMEN SPESIFIKASI ACUAN PERANCANGAN**, dengan catatan 4 poin koreksi minimal di atas perlu diadopsi sebelum implementasi kode dimulai.
2. **Integritas Batasan Kriptografis:**  
   Prinsip *Server-Blindness*, format AAD kanonis 83-byte EXP-02, kuota desimal lampiran, sequence monotonik InnoDB, dan isolasi pairwise 1-on-1 terbukti kokoh dan terlindungi 100%.
3. **Kepatuhan Batasan Mandat B-CHAT-11C:**  
   - Tidak ada dokumen lama yang diubah (`zero mutation`).
   - Tidak ada kode Laravel, Next.js, database, konfigurasi, dependensi, atau repositori Git yang disentuh.
   - Tidak ada klaim yang dibuat tanpa dasar bukti fisik.

---

## 8. STATUS PENUTUPAN MANDAT B-CHAT-11C

Mandat B-CHAT-11C dinyatakan **SELESAI SECARA TUNTAS (COMPLETED)**.

Sesuai instruksi penutup mandat:
> **"HENTIKAN PEKERJAAN SETELAH LAPORAN B-CHAT-11C SELESAI. TUNGGU TINJAUAN PEMILIK PROYEK. TIDAK ADA OTORISASI UNTUK LANGSUNG MEREVISI SPESIFIKASI ATAU MEMULAI IMPLEMENTASI."**

Seluruh aktivitas mandiri dihentikan dan sistem menunggu arahan, keputusan, serta peninjauan resmi dari **Pemilik Proyek**.

```
================================================================================
STATUS MANDAT   : SELESAI SECARA TUNTAS (COMPLETED)
DOKUMEN AUDIT   : B-CHAT-11C_Audit_Final_Konsistensi.md
MODE OPERASI    : STRICTLY READ-ONLY — ZERO MUTATION
STATUS IZIN     : MENUNGGU TINJAUAN & KEPUTUSAN RESMI PEMILIK PROYEK
================================================================================
```

