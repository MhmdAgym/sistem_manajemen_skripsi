# SPESIFIKASI FINAL B-CHAT-05
## Audit Kelayakan Teknis Bimbingan Online Chat Real-Time dengan End-to-End Encryption (E2EE)

**Proyek:** Sistem Manajemen Skripsi UNUHA  
**Backend:** Laravel 12  
**Frontend:** Next.js 14.2.10  
**Database:** MySQL 8.0.30  
**Status:** Spesifikasi desain disepakati; validasi teknis belum selesai  
**Mode pekerjaan:** Read-only / audit dan perencanaan

---

## 1. Tujuan dan Batasan Akses Server

B-CHAT-05 bertujuan menilai kelayakan penerapan E2EE untuk fitur Bimbingan Online Chat Real-Time sebelum desain teknis rinci B-CHAT-06 dibuat.

Arsitektur E2EE membatasi kemampuan server untuk membaca konten percakapan dan lampiran:
- Server maupun administrator tidak memiliki akses terhadap plaintext pesan, plaintext lampiran, draf, dan kunci dekripsi E2EE.
- Server tetap memproses dan menyimpan metadata operasional yang diperlukan untuk autentikasi, otorisasi, pengiriman, sinkronisasi, dan penyimpanan (antara lain identitas peserta, ID ruang, tahap bimbingan, stempel waktu, ukuran biner ciphertext, jumlah chunk, status upload transfer, posisi baca, dan metadata koneksi yang sah).
- Penyimpanan lokal pada perangkat pengguna dilindungi secara at-rest untuk mengurangi risiko pembacaan langsung, namun tidak menjamin keamanan mutlak terhadap lingkungan sistem operasi atau profil peramban yang telah dikompromikan.

Audit mencakup:
- Kelayakan protokol dan pustaka kriptografi.
- Integrasi Next.js dan Laravel.
- Realtime dan sinkronisasi.
- Multi-device dan pemulihan.
- Enkripsi lampiran.
- Backup dan integritas data.
- Keamanan perangkat.
- Performa dan kapasitas.

## 2. Keputusan arsitektur kriptografi

### 2.1 Protokol utama

- MLS berdasarkan RFC 9420 ditetapkan sebagai kandidat utama.
- Signal Protocol dan Matrix menjadi pembanding.
- OpenMLS menjadi salah satu implementasi yang dievaluasi.
- Belum ada pustaka final yang dipilih.
- Kelayakan OpenMLS pada browser/WASM harus dibuktikan terlebih dahulu.
- Protokol harus mendukung Forward Secrecy (FS), Post-Compromise Security (PCS), multi-device, dan perubahan keanggotaan.

Tidak boleh membuat algoritma kriptografi sendiri.

### 2.2 Pembagian tanggung jawab

**Next.js / perangkat pengguna:**
- Enkripsi dan dekripsi pesan.
- Pengelolaan kunci perangkat.
- Enkripsi dan dekripsi lampiran.
- Pencarian lokal.
- Pembuatan PDF riwayat.
- Pengelolaan draf terenkripsi.
- Verifikasi dan pemulihan backup.

**Laravel:**
- Autentikasi dan otorisasi.
- Pengelolaan ruang percakapan.
- Penyimpanan dan distribusi ciphertext.
- Metadata minimum.
- Manajemen perangkat dan sinkronisasi.
- Pencatatan aktivitas teknis tanpa rahasia kriptografi.

Server tidak boleh menerima kunci dekripsi dalam bentuk plaintext.

### 2.3 Realtime

- Pesan realtime dikirim sebagai ciphertext.
- Laravel Reverb/private channel dapat dievaluasi sebagai sarana distribusi.
- Private channel tidak dianggap sebagai pengganti E2EE.
- Pengiriman harus mempertimbangkan transactional outbox, idempotensi, dan mekanisme catch-up melalui HTTP.
- Pesan tidak boleh hilang atau berganda ketika ACK terputus.

## 3. Enkripsi pesan dan lampiran

### 3.1 Pesan

- Isi pesan dilindungi oleh MLS.
- Pesan MLS tidak perlu dibungkus ulang menggunakan AES-GCM.
- Balasan, pesan edit, dan riwayat percakapan tetap dilindungi E2EE.

### 3.2 Lampiran Terenkripsi

- Format dokumen yang didukung: PDF dan DOCX. Berkas berformat makro (.docm/.dotm) ditolak mutlak.
- Batas ukuran file asli (plaintext):
  - Maksimal 10 MB per file asli sebelum enkripsi.
  - Maksimal 5 file asli per pesan.
  - Maksimal 25 MB total gabungan file asli per pesan.
  - Catatan: Batas ini dihitung murni dari ukuran plaintext asli. Kebutuhan kapasitas transfer biner per chunk dan penyimpanan dianalisis tersendiri pada desain teknis tanpa memotong batas kuota file asli pengguna.
- Algoritma enkripsi lampiran: AES-256-GCM (Web Crypto API di browser klien).
- Nonce dan Kunci: Setiap lampiran menggunakan kunci simetris 256-bit unik yang dihasilkan secara acak melalui generator kriptografi aman (`crypto.getRandomValues`).
- Distribusi Kunci: Kunci lampiran dan metadata sensitif (`original_name`, `mime_type`, ukuran plaintext asli) dikemas dalam payload terenkripsi yang hanya didistribusikan melalui pesan grup MLS (RFC 9420) kepada peserta ruang yang berwenang.
- Perlindungan Metadata: Server sama sekali tidak menerima maupun menyimpan nama file asli atau tipe MIME spesifik dalam bentuk plaintext. Server hanya mencatat `file_uuid`, path biner acak, dan tipe generik `application/octet-stream`.
- Backup Kunci: Kunci lampiran disertakan dalam snapshot backup E2EE terenkripsi pengguna.
- Alternatif Evaluasi: ChaCha20-Poly1305 dipertahankan sebagai baseline pembanding untuk pengujian performa perangkat rendah.

### 3.3 Upload Bertahap (Chunking), Integritas, dan Verifikasi Dekripsi

- Protokol pengunggahan bertahap (resumable chunked upload) wajib didukung untuk efisiensi jaringan dan keandalan file besar.
- Enkripsi dan Dekripsi per Chunk: File dipecah dan dienkripsi di klien sebagai unit AEAD independen per chunk menggunakan AES-256-GCM dengan nonce unik per chunk untuk mencegah nonce reuse. Pada Web Crypto API (`SubtleCrypto`), dekripsi dilakukan per-chunk di mana setiap chunk biner diverifikasi tag autentikasinya secara utuh sebelum plaintext chunk tersebut digabungkan atau digunakan.
- Manifest Lampiran: Klien pengirim menyusun manifest yang memuat urutan chunk, hash ciphertext tiap chunk, dan parameter kriptografi. Verifikasi autentisitas manifest wajib dilakukan menggunakan mekanisme yang ditentukan dan divalidasi pada B-CHAT-06. Sebelum mekanisme tersebut difinalisasi dan dibuktikan, autentisitas manifest belum dapat dianggap terjamin.
- Pemisahan Tiga Lapisan Keamanan Lampiran:
  1. Lapisan Protokol MLS: Mengautentikasi identitas pengirim dan pesan kontrol dalam grup MLS.
  2. Lapisan Distribusi Kunci: Menyalurkan kunci simetris lampiran secara rahasia melalui payload MLS.
  3. Lapisan Pengikatan Kriptografis: Mengikat secara kriptografis identitas pengirim MLS, pesan kontrol, manifest, dan seluruh chunk lampiran (mekanisme final ditetapkan pada B-CHAT-06).
- Peran dan Batas Pengetahuan Server:
  - Server memvalidasi aspek operasional transport: sesi upload aktif, kepemilikan ruang, nomor urut chunk (`chunk_index`), ukuran biner chunk, dan batas kuota teknis sesi.
  - Pemeriksaan Transfer Best-Effort: Pencocokan hash klien dan server merupakan pemeriksaan transfer best-effort yang dapat membantu mendeteksi kerusakan data tidak disengaja. Pemeriksaan ini tidak menjamin deteksi manipulasi, tidak membuktikan autentisitas pengirim, dan tidak membuktikan validitas plaintext. Hash hasil perhitungan mandiri server dari bytes yang diterima disimpan sebagai referensi untuk pemeriksaan ulang integritas penyimpanan (*at-rest integrity*).
  - Status Transfer Server: Penandaan `upload_status = completed` di server hanya mencatat kelengkapan operasional biner, dan tidak membuktikan bahwa verifikasi kriptografi telah berhasil.
  - Server TIDAK memvalidasi authentication tag AES-GCM, Additional Authenticated Data (AAD), maupun tanda tangan/HMAC manifest.
- Peran dan Verifikasi Klien Penerima:
  - Klien penerima mengunduh seluruh chunk biner beserta manifest.
  - Klien penerima memverifikasi keaslian dan keutuhan manifest (memastikan tidak ada chunk yang ditukar, dikurangi, atau diubah urutannya) sesuai mekanisme yang ditentukan di B-CHAT-06.
  - Klien penerima memverifikasi Authentication Tag 128-bit AES-GCM dan AAD untuk setiap chunk independen sebelum memanfaatkan datanya.
  - Klien menggabungkan plaintext, memverifikasi hash plaintext akhir terhadap metadata manifest terenkripsi, dan melakukan validasi struktur dokumen lokal sebelum disajikan kepada pengguna.
- Sesi Upload dan Penghapusan Operasional: Kedaluwarsa setelah 24 jam tanpa aktivitas; chunk sementara pada server dijadwalkan untuk dihapus secara logis/operasional sesuai kemampuan sistem penyimpanan server privat (tanpa menjamin pemusnahan seluruh snapshot fisik storage atau backup server). Upload baru menggunakan sesi, ID, dan kunci enkripsi baru.

### 3.4 Validasi Format, Pemeriksaan Struktur, dan Keterbatasan Berkas Klien

Karena server hanya bertindak sebagai blind storage untuk ciphertext biner:
- Perangkat pengirim melakukan validasi format awal (magic bytes PDF `%PDF-` atau header ZIP DOCX `50 4B 03 04`), penolakan mutlak berkas makro (.docm/.dotm), pemeriksaan integritas struktur dasar kontainer, dan sanitasi nama berkas sebelum dienkripsi dan dipecah. Pemeriksaan kontainer dan struktur dasar ini bukan jaminan mendeteksi seluruh malware, exploit, atau script berbahaya.
- Perangkat penerima melakukan validasi struktur ulang terhadap plaintext hasil dekripsi sebelum merender pratinjau dalam lingkungan terisolasi (*sandboxed viewer*).
- Server hanya memvalidasi batas ukuran biner teknis dan integritas transfer ciphertext best-effort.
- Jika validasi lokal di perangkat pengirim atau penerima gagal, pemrosesan berkas dihentikan secara fail-closed dan berkas tidak disajikan kepada pengguna.
- Dokumentasi teknis wajib mencantumkan secara tegas bahwa validasi format, struktur dasar, dan isolasi renderer di sisi klien BUKAN merupakan pengganti antivirus endpoint/server yang komprehensif, melainkan mitigasi pertahanan bertingkat (defense-in-depth) yang sesuai dengan batasan privasi E2EE.

## 4. Multi-device dan identitas keamanan

- Setiap perangkat merupakan klien kriptografi tersendiri.
- Perangkat baru diverifikasi melalui QR dari perangkat tepercaya.
- Recovery key menjadi mekanisme alternatif jika perangkat utama tidak tersedia.
- Perangkat baru berstatus Belum Terverifikasi sampai proses selesai.
- Sebelum terverifikasi, perangkat tidak boleh membaca riwayat, mengirim pesan, mengakses lampiran, atau memperoleh kunci.
- Perubahan identitas keamanan kontak wajib memunculkan peringatan dan verifikasi ulang.
- Perangkat tepercaya tidak perlu melakukan QR approval setiap login biasa.
- Kunci lokal dilindungi saat tersimpan.
- Akses kunci lokal memerlukan autentikasi perangkat; biometrik digunakan jika tersedia dengan PIN atau autentikasi perangkat sebagai alternatif.

### Pencabutan perangkat

- Status perangkat berubah menjadi Dicabut.
- Perangkat tidak dapat mengirim atau mengambil pesan baru.
- Keanggotaan MLS diperbarui melalui Commit dan rotasi epoch.
- Kunci epoch berikutnya hanya diberikan kepada perangkat yang masih terverifikasi.
- Perangkat tepercaya lainnya menerima pemberitahuan.
- Data lama yang sudah pernah didekripsi atau disalin tidak dapat ditarik kembali.

### Perangkat berisiko

Jika perangkat dicurigai diretas:
- Status berubah menjadi Berisiko.
- Akses perangkat ditangguhkan.
- Pengguna menerima pemberitahuan melalui perangkat tepercaya lainnya.
- Sistem melakukan pencabutan dan rotasi kunci.
- Pemulihan dilakukan melalui perangkat tepercaya lain atau recovery key.
- Aktivitas dicatat tanpa isi pesan dan kunci rahasia.

## 5. Recovery key dan pemulihan

- Recovery key dibuat secara acak saat aktivasi E2EE pertama.
- Pengguna menyimpan dan mengonfirmasi penyimpanan recovery key.
- Recovery key tidak diketahui server maupun Admin.
- Recovery key tidak diminta pada setiap login biasa.
- Recovery key diperlukan untuk perangkat baru jika perangkat tepercaya tidak tersedia atau saat pemulihan.
- Backup dilindungi oleh recovery key melalui mekanisme pembungkusan kunci.
- Server hanya menyimpan backup terenkripsi.

### Kehilangan perangkat

Jika perangkat utama hilang:
- Pengguna masuk melalui akun.
- Pemulihan dilakukan menggunakan recovery key.
- Sistem membuat identitas perangkat baru.
- Daftar perangkat diperbarui.
- Perangkat lama dapat dicabut.
- Kunci masa depan dirotasi.

Jika seluruh perangkat dan recovery key hilang:
- Akun masih dapat dipulihkan melalui mekanisme verifikasi akun.
- Identitas kriptografi dan recovery key baru dibuat.
- Riwayat lama tidak dapat dipulihkan.
- Sistem menampilkan peringatan kehilangan riwayat dan meminta konfirmasi eksplisit sebelum aktivasi baru.

### Batas percobaan

- Maksimal 5 percobaan recovery key gagal berturut-turut.
- Setelah itu cooldown 30 menit.
- Percobaan dicatat tanpa menyimpan recovery key.
- Cooldown tidak otomatis mencabut perangkat lama yang masih tepercaya.

## 6. Backup E2EE

Backup riwayat dipisahkan dari state MLS perangkat.

Isi backup:
- Riwayat percakapan yang diperlukan untuk pemulihan.
- Kunci lampiran.
- Data pendukung yang dibutuhkan untuk menampilkan kembali riwayat.

Ketentuan:
- Server hanya menyimpan ciphertext backup.
- Backup dibuat otomatis setelah perubahan riwayat yang relevan tersinkronisasi.
- Perubahan dapat digabung dalam batch.
- Perubahan offline menunggu koneksi.
- Kegagalan backup tidak menghapus pesan yang telah tersimpan di server.
- Snapshot valid sebelumnya tetap tersedia.
- Dua snapshot valid terakhir dipertahankan, mengikuti kebijakan retensi institusi.
- Snapshot baru hanya menggantikan status terbaru setelah verifikasi berhasil.
- Konflik antarperangkat tidak boleh ditimpa otomatis.
- Cabang perubahan yang konflik harus dipertahankan dan direkonsiliasi.

### Status backup

- Tersinkronisasi.
- Menunggu sinkronisasi.
- Sedang mencadangkan.
- Gagal mencadangkan.

### Struktur integritas snapshot

Setiap snapshot memiliki:
1. ID unik.
2. Nomor versi.
3. ID perangkat sumber.
4. Waktu pembuatan.
5. Hash ciphertext.
6. Authentication tag untuk autentikasi saat dekripsi.
7. Referensi snapshot sebelumnya.

Server hanya memeriksa metadata dan hash ciphertext. Verifikasi autentikasi isi dilakukan pada perangkat pengguna.

## 7. Fitur E2EE terkait

### Pencarian

- Pencarian dilakukan secara lokal setelah dekripsi.
- Indeks pencarian disimpan terenkripsi.
- Mendukung kata, frasa, dan nama file lampiran.
- Mendukung pencarian dalam ruang chat dan antar-ruang yang diizinkan.
- Filter tanggal, rentang tanggal, lampiran, PDF, dan DOCX.
- Server tidak menerima plaintext kata kunci atau isi percakapan.

### PDF export

- PDF dibuat lokal setelah dekripsi.
- Memuat identitas mahasiswa, dosen, tahap, tanggal ekspor, nomor halaman, disclaimer, dan watermark DOKUMEN PRIVAT.
- Lampiran hanya ditampilkan sebagai nama dan jenis file.
- PDF tidak disimpan permanen di server.
- Audit ekspor hanya mencatat pengguna, ruang, tahap, waktu, dan hasil.
- Admin tidak dapat membaca audit pribadi tersebut.

### Link Preview (Client-Side Best-Effort)

- Metadata preview URL pertama diambil secara langsung oleh perangkat pengirim menggunakan client-side fetch (best-effort).
- Server bimbingan UNUHA sama sekali tidak menyediakan layanan proxy pengambilan preview URL, sehingga menghilangkan risiko Server-Side Request Forgery (SSRF) pada infrastruktur backend server.
- Metadata yang berhasil diambil dienkripsi bersama payload pesan MLS.
- Jika pengambilan preview gagal (akibat pembatasan CORS, CSP, timeout, atau kebijakan situs target), klien merender tautan sebagai URL teks standar yang aman (graceful degradation).
- Kegagalan preview tidak boleh menggagalkan pengiriman pesan teks.
- Batasan Keamanan Klien: Mekanisme client-side ini tidak menjamin proteksi mutlak terhadap serangan DNS rebinding, redirect internal (HTTP 3xx), atau penjangkauan subnet privat pada jaringan lokal pengguna; klien hanya menerapkan sanitasi masukan URL dasar untuk menolak skema non-HTTP/HTTPS.

### Draf

- Draf dienkripsi lokal sebelum sinkronisasi.
- Server hanya menyimpan ciphertext.
- Sinkronisasi draf mengikuti aturan konflik yang telah disepakati.
- Kedua versi konflik dipertahankan sampai pengguna memilih atau menggabungkan.
- Draf perangkat lain tidak boleh otomatis dikirim.
- Jika perangkat lain mengirim pesan, perubahan terbaru pada perangkat aktif tetap disimpan sebagai draf baru.

### Notifikasi

- Tidak memuat isi pesan maupun nama file.
- Hanya memuat pengirim, tahap, dan informasi umum pesan baru.
- Hanya dikirim kepada peserta yang berwenang.
- Berlaku untuk notifikasi in-app, browser, dan Web Push.

## 8. Aktivitas keamanan

Halaman Riwayat Aktivitas Keamanan hanya dapat dilihat pemilik akun.

Aktivitas yang dicatat:
- Perangkat ditambahkan atau dicabut.
- Perubahan recovery key.
- Percobaan pemulihan.
- Perubahan identitas keamanan.
- Informasi waktu dan perangkat.

Retensi log keamanan: satu tahun, dengan penghapusan otomatis setelah masa retensi. Pengguna dapat melihat tetapi tidak mengubah atau menghapus log. Admin tidak dapat membaca log pribadi.

Notifikasi keamanan:
1. Perangkat baru berhasil diverifikasi.
2. Pemulihan recovery key berhasil.
3. Pemulihan gagal berulang kali.
4. Perangkat dicabut.
5. Recovery key diganti.
6. Identitas keamanan kontak berubah.

## 9. Pengujian performa

### Perangkat sasaran

- HP Android kelas menengah.
- Laptop/PC.

### Kondisi jaringan

- Normal.
- Lambat.
- Terputus-putus.

### Target waktu awal

| Aktivitas | Target |
|---|---:|
| Enkripsi/dekripsi pesan teks | ≤ 1 detik |
| Membaca riwayat lokal | ≤ 2 detik |
| Membuka ruang chat | ≤ 3 detik |
| Memproses lampiran 25 MB | ≤ 30 detik |
| Pemulihan backup | ≤ 60 detik |

Target tersebut belum terbukti dan dapat direvisi berdasarkan hasil benchmark.

### Metrik sumber daya

- RAM.
- CPU.
- Responsivitas antarmuka.
- Suhu dan baterai HP jika dapat diukur.
- Penyimpanan lokal untuk riwayat, draf, indeks, dan backup.

### Sampel lampiran

1. Satu file 1 MB.
2. Satu file 10 MB.
3. Lima file dengan total tepat 25 MB.
4. Lima file dengan ukuran berbeda dan total di bawah 25 MB.

Setiap file tetap maksimal 10 MB.

### Kapasitas riwayat

- Kecil: 1.000 pesan.
- Menengah: 10.000 pesan.
- Besar: 50.000 pesan.

Menggunakan data sintetis, bukan data percakapan asli.

### Pengulangan

Minimal 3 kali per skenario pada setiap kategori perangkat. Catat rata-rata, waktu terlama, penggunaan sumber daya, dan tingkat kegagalan.

### Backup

Catat:
- Waktu pembuatan snapshot.
- Ukuran ciphertext.
- Waktu sinkronisasi.
- Waktu verifikasi integritas.
- Waktu pemulihan.
- Penggunaan RAM dan CPU.

### Simulasi kegagalan backup

1. Koneksi terputus saat snapshot dibuat atau diunggah.
2. Perangkat mati mendadak.
3. Ciphertext rusak atau tidak lengkap.
4. Pemulihan snapshot terbaru gagal.

Snapshot valid sebelumnya tidak boleh hilang atau tertimpa oleh snapshot tidak valid.

## 10. Kriteria kelulusan

### Keamanan wajib

1. Server tidak memiliki akses terhadap plaintext isi pesan, lampiran, draf, dan kunci E2EE.
2. Perangkat belum terverifikasi tidak memperoleh riwayat percakapan atau kunci dekripsi.
3. Perangkat dicabut tidak memperoleh pesan baru.
4. Kegagalan pemulihan tidak merusak backup valid.
5. Recovery key dan kunci dekripsi plaintext tidak muncul dalam log, database, atau notifikasi.
6. Server hanya memproses metadata operasional yang sah dan tidak menyimpan nama file asli atau tipe MIME spesifik dalam bentuk plaintext.
7. Sistem mendukung pemisahan Mode Perangkat Pribadi (terenkripsi persisten) dan Mode Publik (pembersihan best-effort pasca-sesi).
8. Batasan keamanan lingkungan penyimpanan lokal (terhadap akses fisik/OS/ekstensi) dan validasi berkas diakui secara transparan.

### Aturan keputusan akhir

B-CHAT-05 hanya boleh dinyatakan lulus jika:
- Semua pemeriksaan keamanan wajib lulus.
- Tidak ada risiko kritis atau tinggi yang belum diselesaikan.
- Kelayakan MLS/OpenMLS di browser dibuktikan melalui validasi teknis.
- Target performa diukur pada kedua kategori perangkat.
- Bukti, keterbatasan, dan risiko residual dicatat.
- Kegagalan pada satu kriteria wajib membuat status tetap Belum Lulus sampai diperbaiki.

## 11. Risiko dan hal yang belum terbukti

Hal-hal berikut tidak boleh dianggap selesai hanya karena spesifikasinya telah disepakati:

1. Kompatibilitas OpenMLS/WASM di browser dan Next.js 14.
2. Persistensi state MLS di browser dan pemulihan setelah crash.
3. Integrasi MLS dengan Laravel Reverb.
4. Transactional outbox dan idempotensi pesan.
5. Mekanisme multi-device dan distribusi kunci historis.
6. Implementasi recovery key dan backup terenkripsi.
7. Pengikatan kriptografis antara identitas pengirim MLS, pesan kontrol, manifest, dan chunk lampiran.
8. Mekanisme autentikasi manifest dan manajemen nonce per chunk AES-256-GCM.
9. Definisi kanonikal representasi byte biner dan checksum pada sistem chunking lampiran.
10. Arsitektur pemisahan status transfer server dan status verifikasi kriptografi klien.
11. Penentuan ukuran chunk, batas request upload, dan konfigurasi performa server nyata.
12. Efektivitas mitigasi risiko client-side parsing dokumen dan Link Preview di browser.
13. Pengujian pembersihan data lokal pada Mode Perangkat Publik dan ketahanan terhadap lingkungan host yang dikompromikan.
14. Pengujian pemulihan backup dan pencabutan perangkat.

Bila kandidat pustaka tidak memenuhi kebutuhan, alternatif harus dievaluasi tanpa mengurangi persyaratan E2EE.

## 12. Batas pekerjaan dan gerbang berikutnya

B-CHAT-05 tidak mengizinkan:
- Instalasi pustaka.
- Perubahan kode.
- Perubahan database atau migrasi.
- Perubahan konfigurasi.
- Perubahan Git atau push.
- Eksekusi test, build, atau deploy.

Tahap berikutnya adalah **B-CHAT-06 — Desain Database, API, dan Realtime**, setelah hasil validasi kelayakan teknis B-CHAT-05 cukup untuk menentukan arsitektur.

B-CHAT-06 tetap merupakan tahap desain dan audit terlebih dahulu. Persetujuan desain tidak otomatis menjadi izin implementasi.

---

**Kesimpulan:** Spesifikasi desain B-CHAT-05 telah dikonsolidasikan. Status kelulusan teknis tetap *Belum Terbukti*. Implementasi hanya dapat dipertimbangkan setelah bukti kelayakan, keamanan, dan performa tersedia serta pengguna memberikan persetujuan eksplisit.
