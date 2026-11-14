# B-CHAT-05A — REKONSILIASI TEMUAN AUDIT
## SISTEM BIMBINGAN ONLINE CHAT REAL-TIME DENGAN END-TO-END ENCRYPTION (E2EE)

**Proyek:** Sistem Manajemen Skripsi UNUHA  
**Dokumen acuan:** B-CHAT-01, B-CHAT-04, B-CHAT-05  
**Jenis dokumen:** Rekonsiliasi keputusan desain dan temuan audit  
**Status:** DISETUJUI — 7 TEMUAN TELAH DIREKONSILIASI  
**Tahap berikutnya:** Penyelarasan B-CHAT-05 dan penyusunan B-CHAT-06  
**Batas pekerjaan:** Spesifikasi dan validasi kelayakan; belum merupakan persetujuan implementasi.

---

## 1. TUJUAN

Dokumen ini dibuat untuk menyelesaikan tujuh temuan dari audit konsistensi dan kelayakan teknis B-CHAT-05.

Tujuannya:

1. Mempertahankan persyaratan fungsional B-CHAT-01 yang sudah disetujui.
2. Menyelesaikan konflik antara kebutuhan fungsional dan usulan teknis.
3. Menetapkan keputusan desain yang menjadi acuan dokumen berikutnya.
4. Memisahkan keputusan desain dari klaim kelayakan teknis yang belum terbukti.
5. Menentukan pekerjaan validasi yang harus diselesaikan sebelum implementasi E2EE.

Dokumen ini tidak menggantikan B-CHAT-01, B-CHAT-04, atau B-CHAT-05. Dokumen ini menjadi catatan rekonsiliasi dan sumber keputusan untuk memperbarui ketiganya jika diperlukan.

## 2. PRINSIP REKONSILIASI

Ketentuan berikut wajib dipatuhi:

- Persyaratan fungsional B-CHAT-01 tidak boleh diubah secara sepihak.
- Usulan teknis yang belum terbukti tidak boleh dianggap sebagai implementasi final.
- Perubahan kebutuhan harus memperoleh persetujuan pengguna.
- Keamanan E2EE tidak boleh diklaim hanya berdasarkan pemilihan algoritma atau library.
- Server tidak boleh memperoleh plaintext pesan, lampiran, draf, maupun isi riwayat.
- Keterbatasan browser, WebAssembly, perangkat, dan jaringan harus didokumentasikan.
- Setiap keputusan harus memiliki kriteria validasi dan bukti yang dapat diperiksa.

## 3. HASIL REKONSILIASI TUJUH TEMUAN

### 3.1. Konflik 1 — Link Preview dan CORS

**Keputusan: DISETUJUI**

Link Preview menggunakan pengambilan metadata secara langsung dari perangkat pengguna (*client-side best-effort*).

Ketentuan:

1. Perangkat pengirim mencoba mengambil metadata URL pertama dalam pesan.
2. Metadata yang diharapkan meliputi judul, gambar, dan deskripsi.
3. Pengambilan metadata bergantung pada izin CORS situs tujuan.
4. Jika pengambilan gagal, URL tetap ditampilkan sebagai tautan biasa.
5. Tidak menggunakan proxy Laravel sebagai fallback.
6. URL berikutnya tetap dapat diklik tanpa menghasilkan preview.
7. Jika URL pertama gagal menghasilkan preview, sistem tidak mencoba URL kedua sebagai pengganti.
8. Perlindungan terhadap URL manipulatif, redirect berbahaya, dan alamat IP internal tetap menjadi persyaratan keamanan proses pengambilan metadata.
9. Kegagalan preview tidak boleh menggagalkan pengiriman pesan.

**Batasan:**

- Preview tidak dijamin tersedia untuk semua situs.
- CORS dapat menyebabkan metadata tidak dapat dibaca browser.
- Tidak boleh mengirim isi pesan atau plaintext percakapan ke server hanya untuk membuat preview.

**Kriteria validasi:**

- URL dengan CORS yang mengizinkan.
- URL dengan CORS yang menolak.
- URL tidak valid atau tidak tersedia.
- URL dengan redirect.
- URL yang mengarah ke alamat IP privat atau internal.
- Pesan dengan beberapa URL.
- Kegagalan preview tanpa mengganggu pengiriman pesan.

### 3.2. Konflik 2 — Pemeriksaan Keamanan File

**Keputusan: DISETUJUI**

Nama pemeriksaan keamanan file ditetapkan sebagai:

**Validasi Format dan Sanitasi Struktur Berkas Klien.**

Pemeriksaan ini bukan antivirus penuh.

Ketentuan:

1. Memvalidasi ekstensi file.
2. Memvalidasi MIME type.
3. Memeriksa magic bytes.
4. Memeriksa struktur PDF dan DOCX.
5. Menolak DOCM dan format macro-enabled lainnya.
6. Pemeriksaan dilakukan sebelum enkripsi pada perangkat pengirim.
7. Pemeriksaan dilakukan kembali setelah dekripsi pada perangkat penerima.
8. PDF dan DOCX diproses melalui mekanisme rendering terisolasi.
9. File yang pemeriksaannya gagal atau tidak tersedia harus ditahan.
10. File yang belum lolos pemeriksaan tidak boleh ditampilkan sebagai file aman atau dapat dikirim.
11. Server tidak boleh menerima plaintext untuk melakukan pemindaian antivirus karena prinsip E2EE.

**Batasan:**

Validasi format dan struktur tidak menjamin file bebas dari seluruh malware. Isolasi renderer juga tidak memberikan jaminan keamanan mutlak.

Dokumentasi harus menyebutkan keterbatasan pemeriksaan di browser secara jelas dan tidak menggunakan istilah antivirus penuh.

**Kriteria validasi:**

- PDF valid.
- DOCX valid.
- File dengan ekstensi palsu.
- MIME dan magic bytes tidak sesuai.
- File rusak atau struktur tidak valid.
- DOCM dan format macro-enabled.
- File berukuran melebihi batas.
- Pemeriksaan gagal atau tidak tersedia.
- File berbahaya yang ditujukan untuk menguji ketahanan renderer.

### 3.3. Konflik 3 — Ekspor PDF Seluruh Riwayat

**Keputusan: DISETUJUI**

Persyaratan B-CHAT-01 tetap berlaku:

1. Ekspor mencakup seluruh riwayat dalam satu room.
2. Tidak ada batas jumlah pesan yang ditentukan secara sembarang.
3. Tidak ada filter rentang tanggal.
4. PDF dibuat secara lokal setelah proses dekripsi.
5. Isi lampiran tidak dimasukkan ke PDF.
6. PDF hanya mencantumkan nama dan jenis lampiran.
7. Server tidak menyimpan salinan permanen PDF hasil ekspor.
8. Audit ekspor hanya menyimpan metadata aktivitas, bukan isi percakapan atau dokumen.

**Arah validasi teknis:**

- Menggunakan pemrosesan bertahap (*incremental/streaming PDF generation*) jika layak.
- Mengelola penggunaan memori.
- Menghindari pemuatan seluruh riwayat ke memori sekaligus jika dapat dihindari.
- Mengukur performa pada Android kelas menengah dan laptop/PC.
- Menguji jumlah pesan 1.000, 10.000, dan 50.000.

**Ketentuan penting:**

Kekhawatiran OOM tidak boleh dianggap sebagai fakta sebelum ada pengujian.

Jika ekspor seluruh riwayat ternyata tidak memenuhi target performa, hasil pengujian harus menjadi dasar pembahasan perubahan spesifikasi. Batas ekspor tidak boleh ditambahkan tanpa persetujuan pengguna.

### 3.4. Konflik 4 — Enkripsi Lampiran per Chunk

**Keputusan: DISETUJUI SEBAGAI ARAH DESAIN**

Lampiran menggunakan pendekatan enkripsi independen per chunk dengan AES-256-GCM.

Ketentuan:

1. Setiap chunk dienkripsi secara independen.
2. Setiap penggunaan kunci yang sama wajib menggunakan nonce unik.
3. AAD mengikat identitas file, sesi unggah, indeks chunk, jumlah chunk, dan metadata relevan.
4. Setiap chunk memiliki authentication tag sendiri.
5. Manifest final mengautentikasi susunan dan kelengkapan seluruh chunk.
6. Perangkat penerima melakukan verifikasi kriptografis setelah dekripsi.
7. Server hanya menerima dan menyimpan ciphertext.
8. Pembatalan dan pengulangan unggah tidak boleh menyebabkan penggunaan ulang nonce dengan kunci yang sama.
9. Chunk yang belum lengkap tidak boleh dianggap sebagai lampiran siap digunakan.
10. Ukuran chunk 1 MB tetap merupakan kandidat pengujian, bukan keputusan final.

**Hal yang wajib dikaji lebih lanjut:**

- Mekanisme derivasi kunci.
- Strategi nonce yang aman dan tahan terhadap retry.
- Format AAD.
- Struktur dan autentikasi manifest.
- Pengikatan identitas sesi unggah.
- Penanganan chunk hilang, duplikat, tertukar, atau dimodifikasi.
- Pemulihan unggah setelah koneksi terputus.
- Pengelolaan kunci ketika unggah dibatalkan.
- Penghapusan chunk sementara dan penanganan unggah kedaluwarsa.

**Batasan:**

Server tidak dapat memverifikasi authentication tag AES-GCM tanpa kunci. Verifikasi autentikasi plaintext harus dilakukan oleh perangkat yang memiliki kunci.

Rancangan ini belum boleh dianggap aman sebelum ditinjau secara kriptografis dan dibuktikan melalui pengujian.

### 3.5. Konflik 5 — Pemulihan Riwayat dan Keanggotaan MLS

**Keputusan: DISETUJUI SEBAGAI ARAH DESAIN**

Backup riwayat E2EE dan state MLS harus dikelola secara terpisah.

Alur yang disepakati:

1. Pengguna memverifikasi akun pada perangkat baru.
2. Perangkat baru menggunakan recovery key untuk membuka backup riwayat secara lokal.
3. Perangkat baru membuat KeyPackage MLS sendiri.
4. Perangkat lama atau perangkat tepercaya lain yang berwenang menyetujui penambahan perangkat.
5. Proses MLS Add dan Commit membentuk epoch baru.
6. Perangkat baru mulai menerima pesan masa depan setelah keanggotaannya sah.
7. Riwayat lama dipulihkan dari backup terpisah.
8. Kunci epoch MLS lama tidak disimpan permanen hanya untuk memulihkan riwayat.
9. Recovery key saja tidak otomatis memberikan keanggotaan ke grup MLS aktif.

**Hal yang wajib dijabarkan dalam B-CHAT-06:**

- Pemulihan ketika perangkat lama masih tersedia.
- Pemulihan ketika seluruh perangkat lama tidak tersedia.
- Penambahan perangkat menggunakan perangkat tepercaya.
- Penambahan perangkat dengan recovery key.
- Pengelolaan KeyPackage.
- Proses Add dan Commit.
- Sinkronisasi perangkat setelah pemulihan.
- Penanganan perangkat yang dicabut aksesnya.
- Batas antara pemulihan riwayat dan keanggotaan grup aktif.

**Batasan:**

Keberhasilan memulihkan riwayat tidak boleh dianggap sebagai bukti bahwa perangkat baru sudah sah menjadi anggota grup MLS aktif.

### 3.6. Konflik 6 — XSS dan Keamanan Aplikasi E2EE

**Keputusan: DISETUJUI SEBAGAI PERSYARATAN WAJIB**

Keamanan aplikasi browser menjadi bagian dari keamanan E2EE.

Ketentuan:

1. Content Security Policy (CSP) ketat wajib diterapkan.
2. Hindari `eval()` dan eksekusi kode dinamis.
3. Jangan merender HTML mentah dari pesan pengguna.
4. Sanitasi konten yang memang harus ditampilkan sebagai HTML.
5. Evaluasi pemisahan proses kriptografi melalui Web Worker.
6. Lindungi integritas dependency dan proses build.
7. Uji kompatibilitas CSP dengan WebAssembly/OpenMLS.
8. Lakukan pengujian XSS melalui pesan, nama file, Link Preview, dan konten PDF/DOCX.
9. CSP tidak boleh dilemahkan hanya demi kompatibilitas library.
10. Semua risiko script yang berjalan pada origin aplikasi harus didokumentasikan.

**Kriteria validasi:**

- Reflected XSS.
- Stored XSS.
- DOM-based XSS.
- Konten pesan manipulatif.
- Nama file berbahaya.
- Metadata Link Preview berbahaya.
- Konten PDF/DOCX yang mencoba mengeksploitasi renderer.
- Evaluasi CSP dan seluruh dependency frontend.
- Evaluasi isolasi Web Worker dan akses terhadap material kriptografi.

**Batasan:**

CSP bukan jaminan mutlak bebas XSS. Jika JavaScript berbahaya berhasil berjalan pada origin aplikasi, plaintext dan kunci yang sedang digunakan berpotensi terpapar.

### 3.7. Konflik 7 — Penyimpanan Lokal dan Perangkat Publik

**Keputusan: DISETUJUI**

Sistem memiliki dua mode perangkat.

#### A. Mode Perangkat Pribadi/Tepercaya

Menjadi mode default.

Ketentuan:

1. Riwayat dan draf terenkripsi tetap tersimpan setelah logout.
2. Akun lain tidak boleh mengakses data lokal akun sebelumnya.
3. Kunci lokal dilindungi autentikasi perangkat.
4. Login kembali dengan akun yang sama dapat memulihkan data tanpa recovery key selama kunci lokal masih tersedia.
5. Kehilangan atau kerusakan kunci lokal mengikuti prosedur pemulihan yang sudah disepakati.

#### B. Mode Perangkat Publik/Tidak Tepercaya

Ketentuan:

1. Riwayat dan draf tidak disimpan secara persisten setelah sesi berakhir.
2. Kunci sesi hanya digunakan selama sesi aktif.
3. Saat logout atau sesi berakhir, kunci sesi dan data sementara dibersihkan sejauh memungkinkan.
4. Mode ini tidak boleh meninggalkan cache plaintext yang disengaja.
5. Riwayat dapat dipulihkan melalui perangkat tepercaya atau recovery key sesuai prosedur pemulihan.
6. Penggunaan mode publik tidak boleh menghapus backup terenkripsi pada server.

**Batasan:**

Penghapusan data browser tidak dapat menjamin penghapusan setiap salinan yang mungkin dibuat sistem operasi, browser, ekstensi, atau perangkat yang telah dikompromikan. Sistem harus menjelaskan keterbatasan tersebut.

---

## 4. PENYESUAIAN ANTARDOKUMEN

| Dokumen | Penyesuaian yang diperlukan |
|---|---|
| B-CHAT-01 | Mempertahankan kebutuhan fungsional. Menambahkan rujukan mode perangkat publik jika belum tercantum. |
| B-CHAT-04 | Menyesuaikan rancangan teknis dengan tujuh keputusan rekonsiliasi. |
| B-CHAT-05 | Memasukkan seluruh keputusan sebagai persyaratan dan batas validasi E2EE. |
| B-CHAT-06 | Menguraikan protokol MLS, pemulihan perangkat, kriptografi lampiran, XSS, penyimpanan lokal, dan pengujian implementasi. |

Perubahan dokumen harus mempertahankan riwayat keputusan dan tidak menghapus persyaratan B-CHAT-01 yang masih berlaku.

## 5. DAFTAR RISIKO YANG MASIH TERBUKA

| Risiko | Tingkat perhatian | Status |
|---|---|---|
| XSS dapat mengekspos plaintext dan kunci | Kritis | Wajib divalidasi |
| Kompatibilitas OpenMLS/WebAssembly pada browser | Tinggi | Belum terbukti |
| Persistensi state MLS di IndexedDB | Tinggi | Belum terbukti |
| Nonce dan autentikasi chunk AES-GCM | Kritis | Perlu kajian kriptografi |
| Pemulihan keanggotaan MLS tanpa perangkat lama | Tinggi | Perlu desain lanjutan |
| Kinerja ekspor PDF seluruh riwayat | Tinggi | Perlu benchmark |
| Validasi file tanpa antivirus plaintext | Tinggi | Keterbatasan harus didokumentasikan |
| Risiko data lokal pada perangkat publik | Tinggi | Perlu pengujian |
| Sinkronisasi draf terenkripsi lintas perangkat | Tinggi | Belum terbukti |
| Pengiriman ciphertext melalui Laravel Reverb | Tinggi | Perlu validasi arsitektur |
| Metadata komunikasi yang terlihat server | Tinggi | Perlu analisis privasi |

Tingkat perhatian merupakan klasifikasi untuk prioritas validasi, bukan pernyataan bahwa eksploitasi telah terjadi.

## 6. KRITERIA VALIDASI LANJUTAN

Sebelum B-CHAT-05 dinyatakan lulus, bukti berikut harus tersedia:

### 6.1. Kelayakan MLS/OpenMLS

- Bukti integrasi pada Next.js 14 App Router.
- Bukti kompilasi dan eksekusi WebAssembly pada browser target.
- Bukti persistensi state MLS.
- Bukti pembuatan dan pertukaran KeyPackage.
- Bukti Add, Commit, dan rotasi epoch.
- Bukti perangkat yang dicabut tidak menerima kunci masa depan.
- Bukti kompatibilitas pada Android kelas menengah dan laptop/PC.

### 6.2. Kelayakan Backend dan Realtime

- Bukti Laravel Reverb dapat mendistribusikan ciphertext.
- Bukti otorisasi private channel.
- Bukti transactional outbox dan idempotency.
- Bukti rekonsiliasi ketika ACK hilang.
- Bukti sinkronisasi melalui HTTP catch-up.
- Bukti Admin tidak dapat mengakses plaintext maupun endpoint privat peserta.

### 6.3. Kelayakan Lampiran

- Pengujian chunk hilang.
- Pengujian chunk duplikat.
- Pengujian chunk tertukar.
- Pengujian perubahan ciphertext.
- Pengujian authentication tag gagal.
- Pengujian manifest tidak cocok.
- Pengujian retry dan pembatalan unggah.
- Pengujian pemulihan unggah setelah koneksi terputus.
- Pengujian integritas akhir setelah dekripsi.

### 6.4. Kelayakan Backup dan Pemulihan

- Backup terenkripsi.
- Verifikasi integritas snapshot.
- Pemulihan menggunakan recovery key.
- Pemulihan pada perangkat baru.
- Penanganan konflik backup.
- Penanganan backup rusak atau tidak lengkap.
- Bukti server tidak dapat membaca backup.

### 6.5. Kinerja

Target awal B-CHAT-05 tetap menjadi acuan:

| Skenario | Target |
|---|---:|
| Enkripsi/dekripsi pesan teks | ≤ 1 detik |
| Memuat riwayat lokal | ≤ 2 detik |
| Membuka room | ≤ 3 detik |
| Memproses lampiran 25 MB | ≤ 30 detik |
| Memulihkan backup | ≤ 60 detik |

Benchmark wajib menggunakan:

- 1.000 pesan.
- 10.000 pesan.
- 50.000 pesan.
- Jaringan normal.
- Jaringan lambat.
- Jaringan terputus-putus.
- Android kelas menengah.
- Laptop/PC.
- Minimal tiga pengulangan setiap skenario.

Untuk pengujian lampiran, gunakan skenario yang sudah disepakati:

1. Satu file 1 MB.
2. Satu file 10 MB.
3. Lima file dengan total tepat 25 MB.
4. Lima file dengan ukuran berbeda dan total di bawah 25 MB.

Tidak boleh menguji satu file berukuran 25 MB karena batas per file adalah 10 MB.

## 7. SYARAT KELULUSAN B-CHAT-05

B-CHAT-05 hanya dapat dinyatakan lulus apabila:

1. Seluruh persyaratan keamanan kritis terpenuhi.
2. Tidak ada risiko kritis atau tinggi yang belum ditangani tanpa keputusan penerimaan risiko yang terdokumentasi.
3. Kelayakan MLS/OpenMLS pada browser terbukti.
4. Kelayakan backend dan realtime terbukti.
5. Enkripsi lampiran dan pemulihannya tervalidasi.
6. Pengujian performa selesai dengan bukti pengukuran.
7. Seluruh keterbatasan dan risiko residual didokumentasikan.
8. Tidak ada perubahan persyaratan B-CHAT-01 secara sepihak.

Jika salah satu kriteria wajib gagal, status B-CHAT-05 tetap **BELUM LULUS**.

## 8. BATAS PEKERJAAN

Persetujuan dokumen B-CHAT-05A hanya berarti keputusan desain dan rekonsiliasi telah disetujui.

Persetujuan ini TIDAK berarti:

- Implementasi kode disetujui.
- Migrasi database disetujui.
- Perubahan database disetujui.
- Pengujian otomatis atau manual disetujui.
- Build atau deployment disetujui.
- Pemilihan library kriptografi final disetujui.
- Sistem E2EE telah terbukti aman.

Semua aktivitas tersebut memerlukan tahapan dan persetujuan tersendiri.

## 9. LANGKAH BERIKUTNYA

Urutan pekerjaan yang direkomendasikan:

1. Simpan B-CHAT-05A sebagai dokumen referensi.
2. Lakukan audit konsistensi B-CHAT-05 terhadap B-CHAT-01, B-CHAT-04, dan B-CHAT-05A.
3. Perbarui spesifikasi B-CHAT-05 berdasarkan hasil audit, dengan persetujuan sebelum perubahan file.
4. Susun B-CHAT-06 sebagai rancangan teknis terperinci.
5. Lakukan validasi kelayakan teknis secara bertahap.
6. Susun laporan bukti dan risiko residual.
7. Minta persetujuan eksplisit sebelum memasuki implementasi.

---

**STATUS AKHIR B-CHAT-05A**

- Tujuh temuan audit: DISETUJUI.
- Konflik fungsional dan teknis: DIREKONSILIASIKAN PADA TINGKAT KEPUTUSAN DESAIN.
- Kelayakan teknis: BELUM TERBUKTI.
- Keamanan implementasi E2EE: BELUM TERBUKTI.
- Implementasi: BELUM DISETUJUI.

**Dokumen ini menjadi acuan resmi rekonsiliasi temuan audit B-CHAT-05.**