# SPESIFIKASI KEBUTUHAN SISTEM
# BIMBINGAN ONLINE CHAT REAL-TIME
## Sistem Manajemen Skripsi UNUHA

**Kode Dokumen:** B-CHAT-01  
**Versi:** 1.0 — Draft Final Kebutuhan Fungsional  
**Status:** Pembahasan kebutuhan fungsional selesai  
**Platform:** Laravel 12 (Backend) dan Next.js 14 (Frontend)  
**Pengguna:** Mahasiswa dan Dosen Pembimbing  
**Tahap:** Proposal dan Skripsi

---

# 1. PENDAHULUAN

## 1.1 Latar Belakang

Sistem Manajemen Skripsi UNUHA membutuhkan fasilitas komunikasi antara mahasiswa dan dosen pembimbing agar proses konsultasi bimbingan dapat dilakukan secara daring melalui sistem.

Fitur Bimbingan Online Chat Real-Time dikembangkan untuk menyediakan komunikasi privat antara mahasiswa dan dosen pembimbing sesuai penugasan pembimbing dan tahap akademik.

Fitur ini harus mendukung pengiriman pesan secara langsung, lampiran dokumen, pencarian percakapan, notifikasi, penyimpanan riwayat, serta penggunaan ketika koneksi internet terputus.

## 1.2 Tujuan

1. Menyediakan komunikasi privat mahasiswa dan dosen pembimbing.
2. Memisahkan percakapan Proposal dan Skripsi.
3. Menyediakan pengiriman pesan dan lampiran secara real-time.
4. Menjamin kerahasiaan percakapan dan dokumen.
5. Mendukung komunikasi ketika pengguna mengalami gangguan koneksi.
6. Mempertahankan riwayat percakapan.
7. Menyediakan pencarian dan ekspor riwayat percakapan.
8. Menyediakan pengalaman penggunaan yang nyaman pada HP, tablet, dan laptop.

## 1.3 Ruang Lingkup

Versi pertama mencakup:

- Ruang chat privat.
- Pesan teks.
- Reply pesan.
- Edit dan hapus pesan.
- Lampiran PDF dan DOCX.
- Pratinjau PDF.
- Pengiriman dan penerimaan real-time.
- Status pesan.
- Indikator mengetik.
- Status online/offline.
- Notifikasi.
- Pencarian pesan dan lampiran.
- Pengarsipan ruang chat.
- Offline outbox.
- Sinkronisasi lintas perangkat.
- Draf otomatis.
- Ekspor percakapan PDF.
- Pengaturan ruang chat.
- Tampilan responsif dan aksesibilitas.

## 1.4 Di Luar Ruang Lingkup Versi Pertama

1. Pesan suara.
2. Reaksi emoji.
3. Fitur blokir pengguna.
4. Pelaporan pesan.
5. Pemantauan atau notifikasi screenshot.
6. Penerusan pesan (forward).
7. Lampiran JPG, PNG, ZIP, video, dan format selain PDF/DOCX.
8. Pengelolaan percakapan oleh Admin.
9. Konversi otomatis percakapan menjadi logbook bimbingan.

---

# 2. AKTOR DAN HAK AKSES

## 2.1 Mahasiswa

Mahasiswa dapat:

- Membuka ruang chat dengan dosen pembimbing yang ditugaskan kepadanya.
- Mengirim dan menerima pesan.
- Mengirim lampiran.
- Membalas pesan.
- Mengedit pesan sendiri.
- Menghapus pesan sendiri.
- Mencari percakapan.
- Menyalin pesan.
- Mengunduh lampiran.
- Mengekspor riwayat percakapan.
- Mengatur notifikasi.
- Mengatur ukuran teks.
- Mengarsipkan atau membuka kembali ruang chat hanya sesuai kewenangan yang ditetapkan.
- Melihat status pengiriman dan pembacaan pesan.

## 2.2 Dosen Pembimbing

Dosen dapat:

- Membuka ruang chat mahasiswa yang berada dalam penugasannya.
- Mengirim dan menerima pesan.
- Mengirim lampiran.
- Membalas pesan.
- Mengedit dan menghapus pesan sendiri.
- Mencari percakapan dalam ruang yang diizinkan.
- Menyalin pesan.
- Mengunduh lampiran.
- Mengekspor riwayat percakapan.
- Mengatur notifikasi.
- Mengatur ukuran teks.
- Membuka kembali ruang arsip yang menjadi kewenangannya.

Dosen hanya dapat mengakses ruang chat yang sesuai dengan penugasan pembimbing aktif atau hak akses historis yang masih berlaku.

## 2.3 Admin

Admin tidak memiliki akses terhadap isi percakapan.

Admin tidak boleh:

- Membaca pesan.
- Mencari isi percakapan.
- Membuka atau mengunduh lampiran.
- Melihat pratinjau PDF.
- Membaca riwayat chat.
- Mengubah metadata ruang chat.
- Mengarsipkan atau membuka kembali ruang chat.
- Menghapus pesan.
- Mengubah status ruang chat.

Pembatasan harus diterapkan pada backend/API dan otorisasi WebSocket, bukan hanya dengan menyembunyikan menu pada frontend.

Admin juga tidak otomatis menerima notifikasi chat.

---

# 3. MODEL DAN SIKLUS HIDUP RUANG CHAT

## 3.1 Model Ruang Chat

Ruang chat menggunakan hubungan privat antara satu mahasiswa dan satu dosen pembimbing.

Tidak ada ruang percakapan grup dalam versi pertama.

### Tahap Proposal

- Satu mahasiswa memiliki satu dosen pembimbing Proposal sesuai penugasan.
- Dibuat satu ruang chat privat untuk pasangan mahasiswa dan dosen tersebut.

### Tahap Skripsi

- Mahasiswa dapat memiliki dua dosen pembimbing Skripsi.
- Setiap dosen memiliki ruang chat sendiri dengan mahasiswa.
- Tidak ada penggabungan dua dosen dalam satu ruang chat.

### Pergantian Tahap

Ruang chat Proposal dan Skripsi tetap terpisah meskipun dosennya sama.

Riwayat Proposal tidak dipindahkan ke ruang Skripsi.

## 3.2 Pembuatan Ruang Chat

Ruang chat tidak langsung dibuat ketika dosen ditugaskan.

Ruang chat dibuat ketika pesan pertama berhasil disimpan di server.

Ketentuan:

1. Pengirim pertama dapat berupa mahasiswa atau dosen pembimbing yang sedang ditugaskan.
2. Mahasiswa hanya dapat memulai percakapan setelah penugasan dosen resmi dan aktif.
3. Pada tahap Skripsi, masing-masing dari dua dosen dapat memulai ruang chat sendiri.
4. Pesan yang masih berstatus lokal atau belum tersimpan di server belum membuat ruang chat.
5. Waktu pembuatan menggunakan waktu resmi server ketika pesan pertama berhasil disimpan.

## 3.3 Nama dan Avatar

Nama ruang chat dibuat otomatis oleh sistem.

Contoh:

- Ahmad — Proposal
- Ahmad — Skripsi

Nama ruang chat tidak dapat diubah oleh pengguna.

Avatar menggunakan foto profil peserta jika tersedia. Jika tidak, sistem menggunakan inisial nama.

Tidak tersedia foto khusus ruang chat.

## 3.4 Informasi Ruang Chat

Informasi yang dapat dilihat oleh kedua peserta:

- Nama lengkap mahasiswa.
- NIM mahasiswa.
- Nama lengkap dosen.
- NIDN dosen.
- Tahap Proposal atau Skripsi.
- Tanggal pembuatan ruang chat.
- Status ruang chat: Aktif atau Arsip.
- Waktu aktivitas terakhir.

Informasi ini tidak boleh dibuka oleh Admin.

## 3.5 Aktivitas Terakhir

Aktivitas terakhir diperbarui ketika:

1. Pesan berhasil dikirim dan disimpan.
2. Lampiran berhasil dikirim dan lolos pemeriksaan keamanan.
3. Pesan diedit.
4. Pesan dihapus.

Aktivitas terakhir tidak menampilkan isi pesan.

## 3.6 Pergantian Dosen Pembimbing

Ketika dosen pembimbing diganti:

- Ruang chat dosen lama tetap tersimpan.
- Ruang chat lama menjadi arsip dan hanya dapat dibaca oleh peserta lama.
- Dosen baru mendapatkan ruang chat baru.
- Riwayat chat lama tidak dipindahkan.
- Draf ruang chat lama tidak dipindahkan.
- Mahasiswa dan dosen baru menerima notifikasi penugasan.
- Ruang chat baru tetap menunggu pesan pertama sebelum benar-benar dibuat.

---

# 4. PEMISAHAN CHAT DAN LOGBOOK BIMBINGAN

Chat dan logbook bimbingan merupakan dua fasilitas yang berbeda.

Tabel `bimbingan` yang telah digunakan sebagai logbook tetap memiliki fungsi formal pencatatan bimbingan.

Chat harus menggunakan domain penyimpanan tersendiri.

Ketentuan:

- Pesan chat tidak otomatis membuat logbook.
- Percakapan tidak dihitung sebagai pertemuan bimbingan formal.
- Tidak tersedia konversi otomatis chat menjadi logbook pada versi pertama.
- Riwayat chat tidak menggantikan dokumentasi formal bimbingan.

---

# 5. PENGELOLAAN PESAN

## 5.1 Jenis Pesan

Versi pertama mendukung:

1. Pesan teks.
2. Pesan teks dengan satu atau beberapa lampiran.
3. Pesan lampiran saja tanpa teks.

## 5.2 Format Pesan

- Hanya teks biasa.
- Tidak mendukung bold, italic, dan strikethrough.
- Mendukung pergantian baris.
- Tidak ada batas panjang karakter pesan yang ditentukan pada kebutuhan fungsional.

## 5.3 Posisi Pesan

- Pesan sendiri berada di sebelah kanan.
- Pesan lawan bicara berada di sebelah kiri.
- Pesan berurutan dari pengirim yang sama dikelompokkan secara visual.
- Identitas pengirim tidak perlu diulang pada setiap pesan.
- Pergantian pengirim memiliki jarak visual yang sedikit lebih besar.

## 5.4 Waktu Pesan

Setiap pesan menampilkan jam dan menit, contoh `14.35`.

Waktu pesan resmi berasal dari waktu server ketika pesan pertama kali berhasil disimpan.

Edit pesan tidak mengubah waktu pengiriman awal.

Pesan lokal yang belum terkirim boleh menampilkan waktu sementara dari perangkat.

## 5.5 Pengelompokan Tanggal

Pesan dikelompokkan berdasarkan tanggal.

Label:

- Hari ini.
- Kemarin.
- Nama hari, tanggal, bulan, dan tahun.

Contoh: Senin, 2 November 2026.

Label tanggal ditampilkan di tengah area percakapan.

Urutan percakapan dari pesan lama menuju pesan baru.

## 5.6 Edit Pesan

Pengguna dapat mengedit pesan miliknya sendiri dalam waktu 10 menit.

Ketentuan:

- Waktu 10 menit dihitung sejak pesan pertama berhasil disimpan oleh server.
- Waktu tidak dimulai ketika pesan masih berstatus lokal.
- Edit tidak memperpanjang batas 10 menit.
- Hanya isi terbaru yang ditampilkan.
- Pesan diberi penanda `Diedit`.
- Waktu pengiriman awal tetap dipertahankan.
- Isi lampiran tidak dapat diedit atau diganti.

Pesan lampiran saja tidak dapat diedit.

Untuk mengganti lampiran yang salah setelah dikirim, pengguna harus menghapus seluruh pesan dan mengirim pesan baru.

## 5.7 Hapus Pesan

Pengguna dapat menghapus pesan miliknya sendiri tanpa batas waktu.

Setiap penghapusan memerlukan konfirmasi.

Teks dialog:

**Hapus pesan?**

Pesan dan seluruh lampiran yang menyertainya akan dihapus dan tidak dapat diakses kembali oleh kedua pihak.

Tombol:

- Batal.
- Hapus pesan.

Setelah dihapus:

- Kedua peserta melihat placeholder `Pesan ini telah dihapus`.
- Pesan tetap berada pada posisi kronologis semula.
- Waktu pesan tetap ditampilkan.
- Isi pesan tidak dapat diakses.
- Lampiran tidak dapat diakses.
- Tidak boleh ada URL lama yang tetap memberikan akses.
- Penghapusan disinkronkan kepada peserta lain.

Jika pesan belum dikirim atau masih berstatus Gagal, penghapusan membatalkan antrean lokal agar pesan tidak terkirim setelah koneksi kembali.

Jika penghapusan dilakukan saat proses upload atau pemindaian berjalan:

- Proses upload dibatalkan.
- Pemindaian dihentikan jika memungkinkan.
- File sementara dinonaktifkan atau dihapus.
- Hasil pemindaian yang datang terlambat tidak boleh mengaktifkan kembali lampiran.

## 5.8 Reply

Reply tersedia untuk:

- Pesan teks.
- Pesan lampiran saja.
- Pesan teks dengan lampiran.

Tampilan kutipan:

- Isi ringkas pesan teks; atau
- Nama file dan jenis lampiran.
- Jika beberapa lampiran, tampilkan nama file pertama dan jumlah sisanya.

Jika pesan asli telah dihapus, kutipan berubah menjadi:

`Pesan ini telah dihapus`

Konten lama dan lampiran tidak boleh muncul kembali.

Ketika kutipan ditekan:

- Sistem menuju pesan asli.
- Riwayat lama dimuat otomatis jika diperlukan.
- Pesan asli diberi sorotan sementara.

## 5.9 Forward

Tidak tersedia pada versi pertama.

## 5.10 Reaksi Emoji

Tidak tersedia pada versi pertama.

## 5.11 Penyalinan Pesan

Pengguna dapat:

- Menyalin satu pesan.
- Memilih beberapa pesan sekaligus untuk disalin.

Format hasil salinan memuat:

- Nama pengirim.
- Waktu pesan.
- Isi pesan.

Contoh:

`Dosen Pembimbing (09.10): Silakan perbaiki bagian latar belakang.`

Urutan mengikuti kronologi percakapan.

Jika pesan yang dipilih telah dihapus, hasil salinan hanya memuat placeholder penghapusan.

Setelah berhasil disalin, tampilkan notifikasi:

`Pesan berhasil disalin.`

### Mode Multi-Select

Ketika pengguna memilih beberapa pesan:

- Pesan terpilih diberi latar berbeda.
- Tanda centang ditampilkan.
- Jumlah pesan terpilih terlihat.
- Pengguna dapat membatalkan pilihan.

Tindakan:

- Ruang aktif: Salin dan Hapus pesan sendiri.
- Ruang arsip: hanya Salin.

Pesan milik lawan bicara tidak dapat dihapus.

Sebelum penghapusan massal, tampilkan jumlah pesan milik sendiri yang akan dihapus dan minta konfirmasi.

---

# 6. STATUS PESAN DAN KETERBACAAN

## 6.1 Status Pengiriman

Status yang digunakan:

| Status | Makna |
|---|---|
| Mengirim... | Sedang diproses |
| Menunggu dikirim | Disimpan sementara di perangkat karena offline |
| Terkirim | Berhasil disimpan di server |
| Terbaca | Benar-benar terlihat pada layar penerima |
| Gagal | Pengiriman gagal dan membutuhkan tindakan pengguna |

## 6.2 Indikator Visual

- Satu centang abu-abu: Terkirim.
- Dua centang hijau: Terbaca.
- Pending dan Gagal menggunakan label masing-masing, bukan tanda centang terkirim/terbaca.

Status hanya terlihat pada pesan keluar milik sendiri.

## 6.3 Aturan Terbaca

Pesan baru menjadi Terbaca jika benar-benar muncul pada layar penerima.

Membuka ruang chat saja tidak cukup.

Jika pesan berada di luar area layar, pesan belum boleh dianggap terbaca.

Status baca berlaku pada tingkat akun dan disinkronkan antarperangkat.

## 6.4 Separator Pesan Belum Dibaca

Label `PESAN BELUM DIBACA` muncul satu kali sebelum pesan pertama yang belum dibaca.

Pesan yang baru benar-benar terlihat di layar dapat ditandai terbaca.

---

# 7. INDIKATOR MENGETIK DAN STATUS ONLINE

## 7.1 Typing Indicator

Ketika mahasiswa mengetik, dosen melihat indikator mengetik.

Ketika dosen mengetik, mahasiswa melihat indikator mengetik.

Ketentuan:

- Hanya terlihat oleh peserta lain dalam ruang yang sama.
- Menghilang ketika pengguna berhenti mengetik atau meninggalkan ruang.
- Tidak disimpan sebagai riwayat pesan.
- Tidak ditampilkan kepada Admin.
- Status mengetik berlaku pada tingkat akun, termasuk ketika akun digunakan di beberapa perangkat.

## 7.2 Online/Offline

Hanya status Online dan Offline.

Tidak ada Last Seen.

Ketentuan:

- Online jika setidaknya satu perangkat pengguna masih terhubung.
- Offline jika seluruh sesi perangkat terputus atau logout.
- Logout mengubah status menjadi Offline segera jika tidak ada perangkat lain yang aktif.
- Menutup browser atau tab tanpa logout mengubah status setelah batas heartbeat terlewati.
- Tidak ada auto logout hanya karena akun digunakan pada perangkat lain.

Interval heartbeat dan timeout ditentukan dalam desain teknis.

---

# 8. PENGELOLAAN LAMPIRAN

## 8.1 Format yang Didukung

Hanya:

- PDF.
- DOCX.

Tidak mendukung JPG, PNG, ZIP, video, dan format lain pada versi pertama.

## 8.2 Batas Lampiran

- Maksimum 10 MB per file (dihitung berdasarkan ukuran file asli/plaintext sebelum enkripsi).
- Maksimum 5 file dalam satu pesan.
- Maksimum 25 MB batas total gabungan ukuran file asli (plaintext) dalam satu pesan.
- Lampiran bersifat opsional.
- Catatan teknis: Kebutuhan kapasitas transfer dan penyimpanan untuk menampung data terenkripsi, authentication tag, dan manifest ditangani oleh toleransi teknis sistem pada backend tanpa mengurangi kuota file asli pengguna.

## 8.3 Pemilihan File

Sistem mendukung:

- Pemilihan file melalui dialog.
- Drag and drop untuk mengurutkan file sebelum dikirim.
- Urutan pilihan awal dipertahankan.
- Urutan lampiran dikunci setelah pesan dikirim.
- Nama file panjang dipotong secara visual dengan `...`.
- Nama asli tetap dapat dilihat melalui interaksi antarmuka setelah didekripsi di perangkat pengguna.
- Nama asli dan jenis file asli dilindungi secara terenkripsi; server hanya mengelola identitas biner teknis dan tipe data generik.
- File bernama sama tetap dapat disimpan sebagai berkas yang berbeda tanpa saling menimpa melalui penamaan teknis acak berbasis UUID.

## 8.4 Validasi Format, Pemeriksaan Struktur, dan Isolasi Berkas Klien

Perangkat pengirim wajib melakukan pemeriksaan berlapis sebelum enkripsi:
- Validasi format awal: Memeriksa kesesuaian ekstensi dan magic bytes dokumen (PDF `%PDF-` atau kontainer ZIP untuk DOCX `50 4B 03 04`). Pemeriksaan header kontainer ZIP ini hanya validasi awal kontainer dan bukan bukti bahwa dokumen aman atau valid sebagai DOCX.
- Batas ukuran file asli: Memastikan ukuran file asli (plaintext) tidak melebihi 10 MB per file dan 25 MB total gabungan per pesan.
- Validasi struktur dasar: Memeriksa keutuhan struktur dasar dokumen (tabel trailer PDF atau struktur dasar XML pada arsip DOCX). Validasi ini bukan jaminan mendeteksi seluruh exploit, malware, macro tersembunyi, atau script berbahaya.
- Penolakan format makro: Menolak secara mutlak format `.docm`, `.dotm`, dan format berkas lain yang mendukung eksekusi macro aktif.
- Sanitasi nama berkas: Memeriksa nama asli dari karakter traversal atau injeksi sebelum nama asli dienkripsi ke dalam metadata privat.

Server melakukan validasi operasional transfer biner:
- Memeriksa batas ukuran payload biner yang diizinkan untuk setiap chunk upload.
- Pencocokan hash klien dan server merupakan pemeriksaan transfer best-effort yang dapat membantu mendeteksi kerusakan data tidak disengaja. Pemeriksaan ini tidak menjamin deteksi manipulasi, tidak membuktikan autentisitas pengirim, dan tidak membuktikan validitas plaintext. Hash hasil perhitungan mandiri server disimpan sebagai referensi untuk pemeriksaan ulang integritas penyimpanan (*at-rest integrity*).
- Menolak sesi upload yang tidak valid, tidak berwenang, atau melebihi kuota teknis server.

Perangkat penerima wajib memvalidasi ulang struktur berkas setelah proses dekripsi per chunk berhasil dan merender dokumen dalam lingkungan terisolasi (*sandboxed viewer*). Validator klien dan isolasi renderer merupakan lapisan mitigasi tambahan, bukan pengganti antivirus penuh berbasis host; dokumen yang rusak atau tidak lolos validasi struktur wajib ditolak (fail-closed).













## 8.5 Melebihi Batas Ukuran

Jika file lebih dari 10 MB, tampilkan:

- Nama file.
- Ukuran sebenarnya.
- Batas maksimum.
- Penjelasan bahwa file perlu diperkecil.

Lampiran lain yang valid tidak boleh ikut dibatalkan.

## 8.6 Upload Progress

Setiap file memiliki progres tersendiri, misalnya 65%.

Pengguna dapat membatalkan upload per file dengan konfirmasi.

Membatalkan satu file tidak membatalkan upload file lain.

Jika pengguna ingin mengganti file saat upload berlangsung:

1. Batalkan upload terlebih dahulu.
2. Tampilkan konfirmasi.
3. Setelah pembatalan, pengguna dapat memilih file pengganti.

## 8.7 Penghapusan Lampiran Sebelum Pesan Dikirim

- File yang baru dipilih dapat dihapus tanpa konfirmasi sebelum upload dimulai.
- File yang sudah diunggah tetapi belum seluruh proses pesan selesai dapat dihapus dengan konfirmasi.
- Penghapusan menonaktifkan akses terhadap file tersebut.
- Upload lampiran lain tetap berjalan.
- Jika semua lampiran dihapus, pesan teks tetap dapat dikirim.

## 8.8 Validasi Format dan Keamanan Berkas Lokal Klien

Setiap lampiran wajib melalui validasi format dan struktur berkas pada perangkat klien (client-side) karena konten dienkripsi end-to-end sehingga server tidak memiliki akses terhadap plaintext untuk pemindaian antivirus konvensional.

Ketentuan:
- Lampiran ditahan pada perangkat penerima selama proses verifikasi kriptografi, dekripsi per chunk, dan validasi struktur berkas lokal berlangsung.
- Penerima tidak boleh membuka atau mengekspor berkas sebelum status verifikasi kriptografi dan validasi struktur berhasil.
- Jika struktur berkas tidak valid, korup, atau manipulasi terdeteksi (authentication tag gagal), lampiran ditolak dan ditandai gagal.
- Jika pemeriksaan lokal gagal atau lingkungan browser tidak mendukung runtime validasi, berkas tetap ditahan (fail-closed) dan tidak boleh dianggap aman.
- Keterbatasan pemeriksaan lokal (bahwa validasi format dan struktur dasar bukan pengganti antivirus penuh berbasis signature host dan tidak menjamin eliminasi seluruh muatan eksploitasi berkas) harus dijelaskan secara transparan dalam dokumentasi sistem.

Pesan untuk pengguna ketika validasi berkas lokal gagal:
`File tidak dapat dibuka karena verifikasi keamanan atau struktur berkas gagal.`

Indikator proses:
`Memverifikasi keamanan dan struktur file...`

## 8.9 Pratinjau PDF

PDF dapat dilihat dalam modal di atas ruang chat.

Pada HP, pratinjau menggunakan layar penuh.

Fitur:

- Zoom.
- Navigasi halaman sebelumnya/berikutnya.
- Input langsung nomor halaman.
- Indikator halaman saat ini dan jumlah halaman.
- Unduh.

Posisi halaman terakhir dan tingkat zoom disimpan secara lokal berdasarkan akun, file, dan perangkat.

Tidak disinkronkan antarperangkat.

Jika pratinjau gagal atau PDF rusak:

- Tampilkan informasi kesalahan.
- Tombol Unduh tetap tersedia jika file masih dapat diunduh secara sah.

## 8.10 DOCX

DOCX hanya mendukung unduhan pada versi pertama.

Tidak tersedia pratinjau DOCX dalam aplikasi.

## 8.11 Unduhan

Kedua peserta dapat mengunduh PDF dan DOCX yang berhak mereka akses.

Termasuk pada ruang arsip selama:

- Pengguna masih berhak mengakses ruang.
- Lampiran belum dihapus.
- Otorisasi masih valid.

Unduhan langsung tanpa dialog konfirmasi.

Setiap file memiliki progres dan tombol batal.

Jika gagal:

- Status `Gagal`.
- Tombol `Coba lagi`.

Jika koneksi terputus, sistem melanjutkan unduhan jika teknologi memungkinkan. Jika tidak, unduhan dimulai kembali dari awal.

### Unduh Semua

Jika terdapat beberapa lampiran:

- Unduh semua sebagai file terpisah, bukan ZIP.
- Urutan mengikuti urutan lampiran.
- Antrean berjalan satu per satu.
- Setiap file memiliki progres dan tombol batal.
- Kegagalan satu file tidak menghentikan file berikutnya.
- File yang gagal dapat dicoba kembali.

Antrean harus tetap berjalan ketika pengguna berpindah halaman dalam aplikasi.

Sistem diupayakan mempertahankan progres antrean setelah browser ditutup dan dibuka kembali jika kemampuan browser memungkinkan.

Jika tidak didukung, pengguna diberi informasi dan dapat mengunduh ulang.

Ketika logout:

- Unduhan dihentikan atau dijeda.
- Status dan progres lokal dipertahankan jika memungkinkan.
- Unduhan dapat dilanjutkan setelah akun yang sama login kembali.
- File yang sudah selesai diunduh tetap berada di folder Downloads perangkat.

## 8.12 Penghapusan dan Privasi Lampiran

Menghapus pesan menghapus atau menonaktifkan seluruh akses lampiran terkait.

Tidak boleh ada akses melalui URL lama.

Akses preview dan download harus memvalidasi ulang:

- Autentikasi.
- Keanggotaan ruang chat.
- Penugasan atau hak historis yang berlaku.
- Status lampiran.
- Status penghapusan.

Admin tidak boleh mengakses lampiran.

---

# 9. PENCARIAN PESAN DAN LAMPIRAN

## 9.1 Ruang Lingkup Pencarian

Pengguna dapat mencari:

- Kata.
- Frasa.
- Nama file lampiran.

Pencarian tersedia dalam ruang chat dan lintas ruang chat yang diizinkan.

## 9.2 Hak Akses Pencarian

- Mahasiswa hanya mencari ruang miliknya.
- Dosen hanya mencari ruang yang dapat diakses.
- Admin tidak dapat mencari isi chat.
- Pesan yang sudah dihapus tidak muncul dalam hasil.

## 9.3 Filter

Mendukung:

- Tanggal.
- Rentang tanggal.
- Memiliki lampiran.
- PDF.
- DOCX.

## 9.4 Hasil Pencarian

Hasil menampilkan:

- Nama pihak lain.
- Tahap Proposal/Skripsi.
- Cuplikan pesan.
- Tanggal dan waktu.
- Nama file jika hasil berasal dari lampiran.
- Ikon jenis PDF atau DOCX.

Hasil dikelompokkan berdasarkan tanggal.

Urutan hasil terbaru ke terlama.

## 9.5 Navigasi Hasil

Ketika hasil dipilih:

- Sistem membuka ruang chat terkait.
- Memuat riwayat lama jika diperlukan.
- Mengarahkan ke pesan yang ditemukan.
- Memberi sorotan sementara pada pesan.
- Kata atau frasa yang cocok diberi sorotan warna.

## 9.6 Indikator Pencarian

Saat pencarian diproses:

`Mencari pesan...`

Jika tidak ditemukan:

`Tidak ada pesan yang cocok dengan pencarian Anda.`

Kolom pencarian memiliki ikon X untuk menghapus seluruh kata pencarian.

Riwayat kata kunci pencarian tidak disimpan.

---

# 10. NOTIFIKASI

## 10.1 Jenis Notifikasi

- Notifikasi dalam aplikasi.
- Browser notification.
- Web Push jika didukung browser/perangkat dan izin diberikan.

Isi notifikasi tidak boleh memuat teks pesan atau nama file.

## 10.2 Notifikasi Pesan Baru

Format umum:

- Nama pengirim.
- Tahap Proposal atau Skripsi.
- Pesan generik `Mengirim pesan baru.`

Contoh:

`Ahmad — Proposal: Mengirim pesan baru.`

Reply menggunakan notifikasi pesan baru biasa.

Edit dan hapus pesan tidak mengirim notifikasi tambahan.

## 10.3 Aturan Notifikasi

- Hanya peserta ruang chat yang menerima.
- Admin tidak menerima.
- Jika penerima sedang melihat ruang chat yang sama, pesan ditampilkan langsung tanpa browser notification tambahan.
- Jika penerima berada di halaman lain dalam aplikasi, tampilkan notifikasi dalam aplikasi dan perbarui badge.
- Jika penerima tidak sedang membuka aplikasi, browser/Web Push dapat digunakan sesuai izin dan dukungan platform.
- Jika browser permission ditolak, notifikasi dalam aplikasi tetap berfungsi.

## 10.4 Pengaturan Notifikasi Global

Mahasiswa dan dosen dapat mengaktifkan atau menonaktifkan:

- Notifikasi dalam aplikasi.
- Browser/Web Push notification.
- Suara.
- Getaran jika didukung.

Pengaturan suara dan getaran bersifat terpisah.

Suara menggunakan suara bawaan perangkat/browser, bukan audio khusus.

Suara dan getaran hanya digunakan ketika pengguna berada di halaman lain, bukan saat sedang membaca ruang chat terkait.

## 10.5 Mute per Ruang Chat

Setiap peserta dapat mengatur Mute secara mandiri untuk setiap ruang chat.

Durasi:

- 1 jam.
- 8 jam.
- 1 minggu.
- Selamanya sampai diaktifkan kembali.

Mute berlaku untuk notifikasi dalam aplikasi dan browser pada ruang tersebut.

Mute:

- Tidak menghentikan penerimaan pesan.
- Tidak menghentikan pembaruan unread count.
- Tidak memengaruhi ruang chat lain.
- Tetap berlaku saat ruang diarsipkan atau dibuka kembali.
- Menghormati durasi yang sudah ditentukan.

Mute ruang tetap dihormati meskipun pengaturan notifikasi global dimatikan lalu diaktifkan kembali.

## 10.6 Notifikasi Penugasan Dosen

Ketika dosen baru ditugaskan:

- Mahasiswa menerima notifikasi.
- Dosen baru menerima notifikasi.
- Notifikasi menyebut nama dan tahap bimbingan.
- Ruang chat belum otomatis dibuat sampai pesan pertama berhasil dikirim.

## 10.7 Notifikasi Pembukaan Ruang Arsip

Jika dosen pembimbing yang berwenang membuka kembali ruang arsip:

- Mahasiswa menerima notifikasi.
- Isi chat tidak disertakan.

## 10.8 Notifikasi Tahap Akademik

- Ruang Proposal otomatis diarsipkan setelah Sempro.
- Ruang Skripsi otomatis diarsipkan setelah ujian skripsi/munaqosah.
- Pengarsipan otomatis tidak mengirim notifikasi.

## 10.9 Notifikasi Pemeliharaan

Pemeliharaan terencana mengirim notifikasi 30 menit sebelum pelaksanaan.

Jika jadwal berubah, ditunda, atau dibatalkan, pengguna diberi notifikasi.

Ketika layanan kembali normal:

`Layanan chat telah kembali normal. Anda sekarang dapat mengirim dan menerima pesan seperti biasa.`

Notifikasi pemeliharaan dikirim kepada seluruh mahasiswa dan dosen yang berhak menggunakan chat, bukan Admin.

Tidak ada pengingat berkala selama pemeliharaan berlangsung.

---

# 11. STATUS LAYANAN DAN KONEKSI

## 11.1 Status Layanan Chat

Indikator hanya ditampilkan pada halaman daftar ruang chat.

Status:

- Normal: hijau.
- Pemeliharaan: kuning.
- Gangguan: merah.

Tampilan berupa teks, warna, dan ikon.

Tidak perlu menampilkan penyebab teknis.

## 11.2 Status Koneksi Pengguna

Ditampilkan di halaman daftar ruang chat dan di dalam ruang chat.

Status:

- Terhubung: hijau.
- Menghubungkan kembali...: kuning.
- Offline: merah.

Indikator koneksi pengguna berbeda dari status online/offline lawan bicara.

Kedua indikator layanan dan koneksi pengguna ditampilkan berdampingan di bagian atas daftar chat.

Status diperbarui otomatis tanpa refresh manual.

## 11.3 Gangguan Layanan

Ketika server mengalami gangguan, tampilkan:

`Layanan chat sedang mengalami gangguan. Pesan akan dikirim setelah layanan kembali normal.`

## 11.4 Pemeliharaan

Tampilkan:

`Layanan chat sedang dalam pemeliharaan. Silakan coba kembali nanti.`

Jika jadwal diketahui, tampilkan waktu mulai dan perkiraan selesai.

## 11.5 Perubahan Status

Status lawan bicara diperbarui otomatis pada daftar chat dan header ruang chat.

Tidak ada informasi last seen.

---

# 12. ARSIP DAN PEMBUKAAN KEMBALI RUANG CHAT

## 12.1 Pengarsipan Otomatis

- Proposal diarsipkan segera setelah Sempro.
- Skripsi diarsipkan segera setelah ujian skripsi/munaqosah.

Pengarsipan mengikuti perubahan status akademik.

Jika status akademik kemudian dikoreksi kembali menjadi belum selesai, ruang chat tetap arsip.

Tidak ada pembukaan otomatis akibat koreksi status.

## 12.2 Ruang Arsip

Ruang arsip:

- Tetap dapat dibaca peserta sebelumnya.
- Riwayat tetap tersedia.
- Lampiran masih dapat diakses jika pengguna berhak dan file belum dihapus.
- Tidak dapat mengirim pesan.
- Tidak dapat mengedit pesan lama.
- Tidak dapat mengirim lampiran.

## 12.3 Pembukaan Kembali

Hanya dosen pembimbing yang relevan dan berwenang yang dapat membuka kembali ruang arsip.

Ketika dibuka kembali:

- Ruang lama digunakan kembali.
- Riwayat tidak dipindahkan.
- Tidak dibuat riwayat baru.
- Mahasiswa menerima notifikasi.
- Pengaturan Mute tetap berlaku.

Admin tidak dapat mengubah status ruang chat.

## 12.4 Daftar Ruang Chat

Daftar memiliki dua tab:

- Aktif.
- Arsip.

Informasi setiap ruang:

- Nama pihak lain.
- Tahap.
- Cuplikan pesan terakhir.
- Waktu aktivitas terakhir.
- Jumlah pesan belum dibaca.
- Penanda arsip jika relevan.
- Label Draf atau Konflik Draf sesuai ketentuan draf.

Urutan terbaru ke terlama berdasarkan aktivitas terakhir.

Jumlah unread ditampilkan per ruang.

Pesan yang dihapus tidak dihitung sebagai pesan belum dibaca.

---

# 13. OFFLINE OUTBOX DAN SINKRONISASI PESAN

## 13.1 Pengiriman Saat Offline

Pengguna tetap dapat menulis dan menekan Kirim ketika offline.

Pesan disimpan sementara pada perangkat.

Status:

`Menunggu dikirim`

Ketika koneksi kembali:

- Sistem mencoba mengirim otomatis.
- Sistem melakukan percobaan ulang secara terbatas.
- Jika ambang batas tercapai, status menjadi `Gagal`.
- Pengguna dapat menekan `Coba lagi`.

Jumlah dan interval percobaan ditentukan pada desain teknis.

## 13.2 Ketahanan Antrean

Pending dan Gagal tetap tersimpan setelah browser ditutup.

Ketika aplikasi dibuka kembali:

- Antrean dipulihkan.
- Sistem memeriksa koneksi.
- Pengiriman dilanjutkan jika akun yang sama telah login.

Antrean tidak boleh dikirim ketika pengguna logout.

## 13.3 Penghapusan Pesan Pending

Menghapus pesan `Menunggu dikirim` atau `Gagal` membatalkan item lokal.

Item tidak boleh terkirim setelah koneksi kembali.

## 13.4 Pengiriman Ganda

Sistem harus menghindari duplikasi jika server sebenarnya sudah menerima pesan tetapi acknowledgment tidak sampai ke perangkat.

Mekanisme idempotensi harus ditentukan dalam desain teknis.

## 13.5 Status Mengirim Saat Browser Ditutup

Jika browser ditutup ketika pesan masih `Mengirim...`:

- Pesan tetap dipertahankan secara lokal.
- Ketika aplikasi dibuka kembali, sistem memeriksa apakah server sudah menyimpannya.
- Jika sudah, status menjadi Terkirim.
- Jika belum, pengiriman dapat dilanjutkan.
- Tidak boleh menghasilkan pesan ganda.

## 13.6 Pengiriman Saat Penerima Offline

Pesan dapat dikirim meskipun penerima offline.

Server menyimpan pesan.

Pengirim menerima status Terkirim setelah penyimpanan berhasil.

Penerima memperoleh pesan ketika membuka aplikasi atau melakukan sinkronisasi.

Tidak perlu pengiriman ulang oleh pengirim.

## 13.7 Pesan Baru Saat Offline

Riwayat terakhir yang berhasil dimuat tetap dapat dibaca offline.

Ketika koneksi kembali:

- Ambil pesan masuk yang terlewat.
- Perbarui unread count.
- Sinkronkan status terkirim dan terbaca.
- Hindari duplikasi pesan.

## 13.8 Pengiriman Saat Pengguna Berpindah Halaman

Pengiriman tetap berlangsung ketika pengguna berpindah halaman selama sesi aplikasi aktif.

Jika koneksi terputus, pesan masuk antrean offline.

---

# 14. PENYIMPANAN RIWAYAT CHAT DI PERANGKAT

## 14.1 Penyimpanan Lokal Dua Mode: Pribadi dan Publik

Penyimpanan riwayat bimbingan dan draf pada perangkat lokal (IndexedDB) dilindungi secara at-rest menggunakan WebCrypto API untuk mengurangi risiko pembacaan langsung dari media simpan peramban, serta mendukung dua mode operasi perangkat:

### A. Mode Perangkat Pribadi / Tepercaya (Default)
- Riwayat pesan, metadata lampiran, dan draf lokal tetap tersimpan secara terenkripsi setelah pengguna menutup peramban atau melakukan logout.
- Data lokal dipisahkan per akun pengguna di dalam penyimpanan peramban.
- Login kembali dengan akun yang sama pada perangkat tepercaya memulihkan data lokal secara langsung tanpa perlu mengunduh ulang seluruh riwayat dari server selama material kunci lokal masih valid.
- Batasan Realistis: Penyimpanan lokal terenkripsi bukan perlindungan mutlak terhadap akun sistem operasi yang sama, profil peramban bersama tanpa isolasi pengguna OS, ekstensi peramban berbahaya, malware endpoint, serangan XSS pada origin aplikasi, atau pihak yang memiliki akses administratif ke perangkat. Mekanisme pengikatan kunci lokal akan dirancang lebih lanjut pada B-CHAT-06.

### B. Mode Perangkat Publik / Bersama
- Riwayat pesan, draf, dan material kunci sesi tidak disimpan secara persisten setelah sesi berakhir.
- Saat pengguna melakukan logout atau sesi kedaluwarsa, sistem melakukan pembersihan operasional *best-effort* terhadap kunci sesi dan data sementara di peramban.
- Mode ini tidak meninggalkan cache plaintext yang disengaja.
- Penggunaan mode publik tidak menghapus snapshot backup terenkripsi yang tersimpan di server.
- Batasan: Sistem tidak dapat menjamin penghapusan seluruh residual data fisik yang mungkin tersisa pada level sistem operasi, memori swap, cache peramban pihak ketiga, atau perangkat yang telah dikompromikan.

## 14.2 Pengelolaan Data Lokal

Versi pertama tidak menyediakan tombol manual untuk menghapus seluruh riwayat chat lokal.

---

# 15. DRAF OTOMATIS

## 15.1 Penyimpanan Draf

Ketika pengguna menulis pesan, sistem otomatis menyimpan draf di perangkat.

Ketentuan:

- Draf tidak dikirim ke server sebagai pesan sebelum pengguna menekan Kirim.
- Penyimpanan lokal dienkripsi.
- Dipisahkan berdasarkan akun dan ruang chat.
- Draf tetap ada ketika pengguna berpindah ruang.
- Draf dipulihkan saat ruang dibuka kembali.

## 15.2 Status Draf

Tampilkan:

- `Menyimpan draf...`
- `Draf tersimpan`
- `Gagal menyimpan draf`

Jika belum tersinkronisasi:

`Belum tersinkronisasi`

Setelah berhasil:

`Tersinkronisasi`

## 15.3 Penghapusan Draf

Tersedia tombol `Hapus draf`.

Konfirmasi:

**Hapus draf?**

Tulisan yang tersimpan pada ruang chat ini akan dihapus dan tidak dapat dikembalikan.

Tombol:

- Batal.
- Hapus draf.

## 15.4 Setelah Pesan Berhasil Dikirim

Jika pesan berhasil disimpan server:

- Composer dibersihkan.
- Draf lokal dihapus.
- Status draf diperbarui.

Jika pengiriman gagal, isi pesan tetap dipertahankan.

Jika browser ditutup sebelum acknowledgment, draf tetap dipertahankan sampai proses rekonsiliasi selesai.

## 15.5 Draf di Ruang Arsip

Draf tetap disimpan dan dapat dibaca.

Pengguna tidak dapat mengirim sampai ruang dibuka kembali.

Setelah dibuka kembali, draf dapat diedit dan dikirim.

Draf ruang dosen lama tidak boleh dipindahkan ke ruang dosen baru.

## 15.6 Sinkronisasi Draf Antarperangkat

Draf dari akun yang sama dapat disinkronkan ke perangkat lain.

Perubahan di perangkat pertama diperbarui di perangkat kedua.

Sinkronisasi tidak boleh mengirim draf sebagai pesan otomatis.

## 15.7 Konflik Draf

Jika draf diedit di perangkat lain, pengguna diberi peringatan.

Jika kedua perangkat mengubah draf sebelum sinkronisasi selesai:

- Kedua versi dipertahankan.
- Sistem menampilkan konflik.
- Pengguna memilih versi perangkat A, versi perangkat B, atau menggabungkan secara manual.
- Tidak boleh memilih versi secara otomatis.
- Versi yang tidak dipilih tetap disimpan sampai keputusan selesai.
- Versi lama baru boleh dibersihkan setelah hasil pilihan berhasil disimpan.

Status daftar chat:

`Konflik Draf`

Jika terdapat konflik, label Draf biasa tidak ditampilkan bersamaan.

Setelah konflik selesai:

- Jika masih ada draf, tampilkan Draf.
- Jika tidak ada, hilangkan label.

## 15.8 Draf Dikirim dari Perangkat Lain

Jika draf dikirim dari perangkat pertama:

- Draf yang sama pada perangkat kedua dihapus.
- Composer perangkat kedua dibersihkan jika tidak ada perubahan baru.

Jika perangkat kedua sedang mengedit:

- Perubahan baru tidak boleh hilang.
- Perubahan terbaru dipertahankan sebagai draf baru.
- Tidak boleh dikirim otomatis.

Tampilkan:

`Draf ini telah dikirim dari perangkat lain. Perubahan terbaru Anda disimpan sebagai draf baru.`

## 15.9 Sesi Berakhir

Jika sesi berakhir saat mengetik:

- Draf lokal dipertahankan.
- Pengguna diminta login kembali.
- Draf dipulihkan setelah login menggunakan akun yang sama.
- Tidak ada pengiriman sebelum autentikasi berhasil.

## 15.10 Browser Crash atau Perangkat Mati

Sistem memulihkan draf terakhir yang berhasil disimpan.

Perubahan yang belum sempat tersimpan tidak dijamin dapat dipulihkan.

---

# 16. POSISI BACA DAN NAVIGASI PESAN

## 16.1 Posisi Baca Terakhir

Ketika ruang chat dibuka kembali, sistem mengembalikan posisi baca terakhir.

Posisi disimpan per akun dan ruang chat.

Ketentuan:

- Tetap ada ketika berpindah halaman.
- Dipertahankan setelah browser ditutup.
- Tidak otomatis menandai pesan sebagai Terbaca hanya karena posisi dipulihkan.
- Status Terbaca tetap membutuhkan pesan terlihat di layar.

Penyimpanan posisi baca pada perangkat lain tidak ditetapkan sebagai kebutuhan sinkronisasi.

## 16.2 Tombol Pesan Baru

Jika pengguna membaca riwayat lama dan ada pesan baru:

- Tidak melakukan auto-scroll.
- Tampilkan tombol `X pesan baru ↓`.
- Jumlah bertambah sesuai pesan baru yang masuk.
- Tombol mengarahkan pengguna ke pesan terbaru.

## 16.3 Auto-Scroll

Jika pengguna berada dekat bagian bawah percakapan, pesan baru dapat langsung terlihat.

Jika pengguna sedang membaca pesan lama, posisi tidak berubah.

## 16.4 Tombol Kembali ke Pesan Terbaru

Tersedia tombol mengambang dengan ikon panah ke bawah saja.

Tanpa tulisan.

Tombol mengarahkan pengguna ke pesan terbaru dan tetap dapat digunakan ketika tidak ada pesan baru.

## 16.5 Infinite Scroll

Pesan terbaru dimuat terlebih dahulu.

Pesan lama dimuat ketika pengguna menggulir ke atas.

Ketika pesan lama berhasil dimuat, posisi baca pengguna harus tetap stabil.

Indikator:

`Memuat pesan...`

Jika tidak ada pesan lama:

`Tidak ada pesan sebelumnya.`

Jika pemuatan gagal, tampilkan informasi kegagalan dan tombol `Coba lagi`.

## 16.6 Tampilan Pesan Panjang

Pesan sangat panjang dapat ditampilkan dalam bentuk ringkas dengan tombol:

`Lihat selengkapnya`

Pengguna dapat membuka seluruh isi.

Isi pesan tidak boleh dipotong atau dihapus.

Batas tampilan ringkas ditentukan pada desain teknis.

## 16.7 Pesan Sambutan

Ruang chat yang belum memiliki pesan menampilkan sambutan sesuai tahap.

Proposal:

`Ini adalah awal percakapan bimbingan Proposal Anda dengan Dosen Pembimbing. Silakan mulai percakapan dengan mengirim pesan.`

Skripsi:

`Ini adalah awal percakapan bimbingan Skripsi Anda dengan Dosen Pembimbing. Silakan mulai percakapan dengan mengirim pesan.`

Pesan sambutan bukan pesan dari peserta.

---

# 17. TAUTAN EKSTERNAL

## 17.1 Deteksi URL

URL otomatis dikenali dan dapat diklik.

Jika terdapat beberapa URL, hanya URL pertama yang memiliki Link Preview.

URL lainnya tetap dapat diklik.

Jika preview URL pertama gagal, sistem tidak berpindah ke URL kedua.

## 17.2 Link Preview (Client-Side Best-Effort)

Link Preview dijalankan secara client-side best-effort oleh perangkat pengirim:
- Metadata preview (judul, cuplikan deskripsi, gambar preview) diambil langsung dari perangkat pengirim dengan mekanisme client-side fetch.
- Backend Laravel sama sekali tidak menyediakan endpoint proxy pengambil URL eksternal, sehingga mengeliminasi risiko Server-Side Request Forgery (SSRF) pada infrastruktur server UNUHA.
- Metadata preview yang berhasil diambil dienkripsi bersama payload pesan E2EE sebelum dikirim.
- Jika pengambilan metadata gagal (karena batasan CORS, CSP, timeout, atau kebijakan situs target), tautan ditampilkan sebagai URL teks biasa tanpa preview (graceful degradation).
- Kegagalan pengambilan preview tidak boleh menggagalkan pengiriman pesan teks.
- Batasan Keamanan Klien: Mekanisme client-side ini tidak menjamin proteksi mutlak terhadap serangan DNS rebinding, redirect internal (HTTP 3xx), atau penjangkauan subnet privat pada jaringan lokal pengguna; klien hanya menerapkan sanitasi masukan URL dasar untuk menolak skema non-HTTP/HTTPS.

## 17.3 Konfirmasi Membuka Link

Sebelum membuka situs eksternal:

`Anda akan membuka situs eksternal. Pastikan tautan ini aman sebelum melanjutkan.`

Tombol:

- Batal.
- Lanjutkan.

## 17.4 URL Panjang

URL panjang boleh dipersingkat secara visual.

URL sebenarnya tidak boleh berubah.

Klik dan salin harus menggunakan URL asli.

---

# 18. EKSPOR RIWAYAT PERCAKAPAN

## 18.1 Hak Akses

Mahasiswa dan dosen dapat mengekspor riwayat ruang chat yang mereka akses.

Berlaku untuk ruang aktif dan arsip.

Admin tidak dapat membaca isi ekspor atau membuka riwayat percakapan melalui fitur ini.

## 18.2 Format

Hanya PDF.

Seluruh riwayat satu ruang chat diekspor.

Tidak tersedia filter rentang tanggal.

## 18.3 Isi PDF

Mencakup:

- Teks pesan.
- Nama pengirim.
- Tanggal dan waktu.
- Nama lampiran.
- Jenis lampiran PDF/DOCX.
- Penanda Diedit.
- Placeholder pesan yang dihapus.

Isi file lampiran tidak dimasukkan.

## 18.4 Header

Header memuat:

- Logo UNUHA.
- Judul `Riwayat Percakapan Bimbingan`.
- Nama mahasiswa.
- NIM.
- Nama dosen pembimbing.
- NIDN.
- Tahap Proposal/Skripsi.
- Tanggal dan waktu ekspor.

## 18.5 Format Dokumen

- Formal dan sederhana.
- Cocok untuk dokumentasi akademik.
- Bukan berita acara atau dokumen resmi pelaksanaan bimbingan.
- Nomor halaman `Halaman X dari Y`.
- Footer:

`Dokumen ini merupakan hasil ekspor riwayat percakapan dari Sistem Manajemen Skripsi UNUHA.`

- Watermark abu-abu transparan `DOKUMEN PRIVAT` pada setiap halaman.

## 18.6 Nama File

Nama otomatis berdasarkan peserta dan tahap.

Contoh:

`Riwayat_Chat_Ahmad_DosenPembimbing_Proposal.pdf`

## 18.7 Konfirmasi dan Progres

Sebelum ekspor, tampilkan dialog:

- Batal.
- Lanjutkan.

Saat proses berlangsung:

- Tampilkan loading.
- Tombol berubah menjadi `Sedang membuat PDF...`.
- Nonaktifkan permintaan ekspor berulang.

Pengguna dapat membatalkan proses.

Jika dibatalkan:

- Proses dihentikan sejauh kemampuan teknis memungkinkan.
- File PDF tidak boleh diberikan jika belum lengkap.
- Tidak membuat catatan audit sukses.
- Tombol ekspor kembali aktif.
- Percakapan tidak berubah.

## 18.8 Penyimpanan Hasil

PDF diunduh ke perangkat pengguna.

Tidak menyimpan salinan PDF permanen pada server.

## 18.9 Audit Ekspor

Catatan audit memuat:

- Identitas pengguna.
- Ruang dan tahap.
- Tanggal/waktu.
- Status sukses/gagal.

Tidak menyimpan isi pesan atau salinan PDF.

Admin tidak dapat melihat catatan audit ekspor.

Catatan tersebut hanya untuk keamanan internal sistem.

---

# 19. TAMPILAN DAN KENYAMANAN PENGGUNAAN

## 19.1 Ukuran Teks

Tersedia:

- Kecil.
- Normal (bawaan).
- Besar.

Pengaturan bersifat pribadi dan tidak memengaruhi perangkat peserta lain.

Pengaturan tetap berlaku setelah logout/login.

## 19.2 Tema

Tidak tersedia pengaturan Light/Dark khusus chat.

Tampilan mengikuti tema utama aplikasi.

## 19.3 Responsivitas

Lebar gelembung pesan menyesuaikan ukuran layar.

Mendukung HP, tablet, dan laptop.

Gelembung tidak memenuhi seluruh layar tanpa alasan.

## 19.4 Posisi Composer

Composer selalu berada di bawah layar ketika pengguna menggulir percakapan.

Riwayat pesan bergulir secara terpisah.

Pada HP, composer harus memperhatikan keyboard virtual.

## 19.5 Tinggi Composer

Composer bertambah tinggi otomatis ketika pengguna mengetik beberapa baris.

Memiliki batas tinggi maksimum.

Setelah batas tercapai, teks digulir di dalam kotak penulisan.

Composer tidak boleh menutupi seluruh area percakapan.

## 19.6 Tombol Kirim

Tombol Kirim berada di sebelah kanan composer.

Mudah dijangkau pada HP dan laptop.

## 19.7 Tombol Lampiran

Tombol Lampirkan File selalu terlihat di sebelah kiri composer.

Mendukung PDF dan DOCX.

## 19.8 Pintasan Keyboard

- Enter: mengirim pesan.
- Shift + Enter: membuat baris baru.
- Pada HP, pengiriman melalui tombol Kirim di layar.

Tidak tersedia pengaturan untuk menonaktifkan perilaku Enter pada versi pertama.

## 19.9 Konfirmasi Pengiriman

Tidak ada dialog konfirmasi sebelum mengirim pesan.

Pesan langsung masuk ke proses pengiriman.

## 19.10 Pengiriman Berulang

Tombol Kirim dinonaktifkan sementara selama proses pengiriman yang sedang diproses.

Tombol kembali aktif setelah proses selesai.

Pesan berikutnya dapat dikirim setelahnya.

Sistem tetap wajib mencegah pesan ganda jika server telah menyimpan pesan tetapi acknowledgment terlambat.

## 19.11 Indikator Pengiriman

Ketika pesan sedang diproses:

- Tampilkan `Mengirim...`.
- Sertakan animasi kecil atau ikon berputar.

Setelah berhasil, tampilkan Terkirim.

Jika gagal, tampilkan Gagal dan tombol Coba lagi.

## 19.12 Animasi

Pesan baru memiliki animasi masuk sederhana.

Tidak boleh berlebihan dan tidak mengganggu aktivitas membaca.

## 19.13 Tooltip

Ikon memiliki keterangan:

- Kursor diarahkan pada perangkat desktop.
- Interaksi sentuhan yang sesuai pada perangkat sentuh.

## 19.14 Area Sentuh

Tombol dan ikon memiliki area sentuh nyaman di HP.

Tidak mudah salah tekan.

Ikon tetap proporsional.

## 19.15 Keyboard Focus

Navigasi Tab dan Shift + Tab didukung.

Elemen yang mendapat fokus menampilkan penanda visual jelas.

Fokus tidak boleh hilang secara tiba-tiba.

## 19.16 Tombol Esc

Esc dapat menutup:

- Pratinjau PDF.
- Dialog.
- Panel pencarian.
- Panel informasi ruang chat.

Menutup tampilan sementara tidak boleh menghilangkan tulisan yang sedang diketik.

## 19.17 Loading Skeleton

Loading skeleton ditampilkan ketika:

- Ruang chat dibuka.
- Riwayat pesan dimuat.
- Data percakapan disinkronkan jika diperlukan.

Bentuk menyerupai gelembung pesan.

Skeleton hilang setelah data tersedia.

## 19.18 Peringatan Meninggalkan Ruang Chat

Jika masih ada pesan dengan status:

- Mengirim...
- Menunggu dikirim.
- Gagal.

Sistem memberikan peringatan sebelum pengguna meninggalkan ruang chat.

Pengguna dapat memilih tetap di ruang chat atau melanjutkan keluar.

Pesan dan antrean lokal tidak boleh hilang.

Meninggalkan ruang chat tidak otomatis memicu pengiriman ulang.

## 19.19 Tidak Ada Pengaturan Tambahan

Tidak diperlukan:

- Pengaturan tema Light/Dark khusus chat.
- Pengaturan lebar gelembung manual.
- Pengaturan untuk menonaktifkan Enter.
- Penghitung karakter.
- Riwayat pencarian terakhir.
- Tombol menyembunyikan panel daftar chat secara manual.

---

# 20. KEBUTUHAN KEAMANAN DAN PRIVASI

## 20.1 Otorisasi

Seluruh operasi harus memeriksa identitas pengguna dan hak akses ruang.

Pemeriksaan berlaku untuk:

- API.
- WebSocket.
- Pesan.
- Lampiran.
- Pencarian.
- Ekspor.
- Sinkronisasi.
- Status baca.
- Draf.
- Riwayat lokal yang disinkronkan.

## 20.2 Pemisahan Peserta

Hanya mahasiswa dan dosen yang menjadi peserta ruang yang dapat mengakses konten.

Admin tidak dapat mengakses konten meskipun memiliki akun dengan role admin.

## 20.3 Lampiran Privat

File tidak menggunakan URL publik yang dapat diakses tanpa otorisasi.

Unduh dan preview harus melalui mekanisme akses privat.

## 20.4 Pemeriksaan Keamanan Berkas Klien

Validasi format, struktur dasar, dan penolakan format macro (.docm/.dotm) wajib berprinsip fail-closed pada perangkat klien. Pengujian lokal dan isolasi renderer merupakan mitigasi bertingkat, bukan antivirus penuh. Server tidak melakukan pemindaian antivirus plaintext karena arsitektur E2EE.

## 20.5 Validasi Berkas dan Privasi Metadata

Validasi berkas mencakup keutuhan format, isolasi renderer dokumen, dan batas ukuran file asli (10 MB per file, 25 MB gabungan). Nama file asli (`original_name`) dan `mime_type` diklasifikasikan sebagai rahasia dan wajib dienkripsi end-to-end; pencocokan hash klien dan server merupakan pemeriksaan transfer best-effort yang dapat membantu mendeteksi kerusakan data tidak disengaja, bukan bukti autentisitas pengirim atau validitas plaintext; server hanya memproses batas teknis upload biner dan tipe generik `application/octet-stream`.

## 20.6 Penyimpanan Lokal Terenkripsi

Mendukung Mode Pribadi (data terenkripsi persisten pasca-logout) dan Mode Publik (pembersihan best-effort kunci sesi dan data sementara). Batasan terhadap lingkungan perangkat yang dikompromikan dan residu penyimpanan diakui secara transparan.

## 20.7 Link Preview

Pengambilan metadata tautan dilakukan langsung dari peramban pengirim tanpa proxy backend untuk mencegah risiko SSRF pada server. Klien menerapkan sanitasi masukan URL dan penanganan kegagalan secara graceful tanpa menggagalkan pengiriman pesan.

## 20.8 Integritas Riwayat

Pesan yang sudah dihapus tidak boleh muncul kembali akibat sinkronisasi terlambat.

Lampiran yang sudah dihapus tidak boleh diaktifkan kembali oleh hasil scan yang datang terlambat.

---

# 21. KRITERIA PENERIMAAN FUNGSIONAL

Implementasi belum dapat dianggap selesai hanya karena halaman chat dapat dibuka.

Fitur harus memenuhi setidaknya pengujian berikut.

## 21.1 Hak Akses

- Mahasiswa hanya mengakses ruang sendiri.
- Dosen hanya mengakses ruang yang ditugaskan.
- Dosen tidak dapat mengakses ruang dosen lain.
- Admin tidak dapat membaca isi chat.
- Admin tidak dapat mengakses lampiran.
- Akses WebSocket memeriksa keanggotaan ruang.

## 21.2 Ruang Chat

- Proposal dan Skripsi terpisah.
- Dua dosen Skripsi memiliki ruang terpisah.
- Ruang dibuat saat pesan pertama berhasil disimpan.
- Pergantian dosen tidak memindahkan riwayat.

## 21.3 Pesan

- Pengiriman teks berhasil.
- Pengiriman pesan lampiran saja berhasil.
- Reply berfungsi.
- Edit hanya berlaku dalam 10 menit.
- Edit tidak mengubah waktu awal.
- Hapus menampilkan placeholder.
- Penghapusan tersinkronisasi pada kedua peserta.
- Pesan pending yang dihapus tidak dikirim setelah koneksi kembali.

## 21.4 Lampiran

- PDF valid dapat dikirim.
- DOCX valid dapat dikirim.
- Format lain ditolak.
- Batas 10 MB per file diterapkan.
- Maksimum lima file per pesan.
- Upload progress berjalan.
- Pembatalan satu file tidak membatalkan file lain.
- Scan gagal menyebabkan file tetap ditahan.
- Malware menyebabkan file ditolak.
- Penerima tidak dapat mengakses file sebelum scan selesai.
- Penghapusan pesan menonaktifkan akses lampiran.
- PDF dapat dipratinjau.
- DOCX dapat diunduh.
- Unduhan banyak file berjalan terpisah dan berurutan.

## 21.5 Real-Time

- Pesan muncul pada penerima tanpa refresh manual.
- Typing indicator bekerja.
- Online/offline diperbarui otomatis.
- Status baca hanya berubah saat pesan benar-benar terlihat.
- Perubahan edit/hapus tersinkronisasi.

## 21.6 Offline

- Pesan offline disimpan di antrean lokal.
- Status Menunggu dikirim terlihat.
- Pesan dikirim kembali setelah koneksi tersedia.
- Retry terbatas diterapkan.
- Status Gagal menyediakan Coba lagi.
- Tidak ada pesan ganda setelah acknowledgment hilang.
- Riwayat masuk tersinkronisasi tanpa duplikasi.

## 21.7 Draf

- Draf tersimpan otomatis.
- Draf pulih setelah browser ditutup.
- Draf terpisah per akun dan ruang.
- Draf tidak terkirim otomatis.
- Sinkronisasi lintas perangkat berfungsi.
- Konflik draf mempertahankan kedua versi.
- Pengguna menentukan hasil konflik.
- Perubahan perangkat kedua tidak hilang jika draf dikirim dari perangkat pertama.

## 21.8 Notifikasi

- Pesan baru mengirim notifikasi kepada peserta yang berhak.
- Notifikasi tidak memuat isi pesan atau nama file.
- Admin tidak menerima.
- Mute per ruang bekerja.
- Pengaturan global dan izin browser dihormati.
- Notifikasi penugasan dan pembukaan arsip bekerja.

## 21.9 Arsip

- Tahap Proposal otomatis diarsipkan setelah Sempro.
- Tahap Skripsi otomatis diarsipkan setelah munaqosah.
- Ruang arsip tetap dapat dibaca.
- Pengiriman pada arsip ditolak.
- Hanya dosen berwenang yang dapat membuka kembali.
- Koreksi status akademik tidak otomatis membuka ruang.

## 21.10 Pencarian dan Ekspor

- Pencarian mengikuti ACL.
- Pesan terhapus tidak muncul.
- Filter berfungsi.
- Navigasi hasil menuju pesan yang tepat.
- Ekspor memuat seluruh riwayat satu ruang.
- Ekspor tidak memuat isi file lampiran.
- Pembatalan ekspor tidak menghasilkan PDF tidak lengkap.
- Audit ekspor tidak menyimpan konten pesan.

## 21.11 Tampilan

- Responsif pada HP, tablet, dan laptop.
- Composer tetap di bawah.
- Keyboard virtual tidak menutupi composer.
- Auto-scroll tidak mengganggu pembacaan riwayat lama.
- Posisi baca terakhir dipulihkan.
- Tombol dan ikon memiliki area sentuh yang layak.
- Navigasi keyboard memiliki penanda fokus.
- Loading skeleton tampil sesuai kebutuhan.

---

# 22. HAL YANG BELUM DIPUTUSKAN DAN HARUS MASUK DESAIN TEKNIS

Hal-hal berikut sengaja tidak diputuskan pada tahap kebutuhan fungsional.

1. Mekanisme dan algoritma enkripsi lokal.
2. Pengelolaan kunci enkripsi dan pemulihan lintas perangkat.
3. Mekanisme sinkronisasi draf dan resolusi konflik.
4. Jumlah dan interval retry offline.
5. Idempotency key dan rekonsiliasi pengiriman.
6. Interval heartbeat dan timeout online/offline.
7. Mekanisme pembacaan pesan berdasarkan visibilitas layar.
8. Batas ukuran total gabungan lampiran.
9. Implementasi antivirus/antimalware dan penanganan kegagalannya.
10. Mekanisme penyimpanan privat dan retensi file.
11. Mekanisme pembatalan upload, scan, dan download.
12. Strategi antrean unduhan yang bertahan setelah browser ditutup.
13. Strategi WebSocket, event broadcasting, dan fallback sinkronisasi.
14. Implementasi Web Push dan batasan browser.
15. Mekanisme PDF preview.
16. Strategi pembuatan PDF ekspor untuk riwayat besar.
17. Batas ringkasan pesan panjang.
18. Penyimpanan posisi baca terakhir.
19. Strategi Link Preview yang aman terhadap SSRF.
20. Pengaturan retensi audit ekspor.
21. Penanganan konflik perubahan status akademik dan pengarsipan.
22. Mekanisme validasi penugasan pembimbing ketika terjadi perubahan data.

Keputusan teknis harus tetap memenuhi kebutuhan fungsional yang sudah disetujui.

Jika ditemukan ketidakmungkinan teknis atau risiko keamanan, implementasi harus dihentikan pada bagian terkait dan dikonsultasikan terlebih dahulu.

---

# 23. STRATEGI PENGEMBANGAN

Pengembangan disarankan dilakukan secara bertahap.

Urutan awal yang disarankan:

1. Audit kesiapan teknis.
2. Desain model data dan hak akses.
3. Implementasi dasar ruang chat.
4. Implementasi pesan dan sinkronisasi real-time.
5. Implementasi lampiran dan keamanan.
6. Implementasi status baca, typing, dan presence.
7. Implementasi offline outbox.
8. Implementasi draf otomatis dan sinkronisasi lintas perangkat.
9. Implementasi pencarian dan ekspor.
10. Implementasi notifikasi dan pengarsipan.
11. Penyempurnaan UI/UX.
12. Pengujian integrasi dan keamanan.
13. Dokumentasi hasil pengujian.

Urutan tersebut masih berupa rencana awal dan dapat disesuaikan setelah audit teknis.

Setiap tahap harus memiliki:

- Ruang lingkup jelas.
- Prasyarat.
- Risiko.
- Kriteria penerimaan.
- Bukti pengujian.
- Hasil yang terdokumentasi.

---

# 24. KETENTUAN PENGENDALIAN PERUBAHAN

Dokumen ini menjadi acuan utama kebutuhan fungsional B-CHAT-01.

Ketentuan:

1. Tidak boleh mengubah keputusan yang telah disetujui secara diam-diam.
2. Perubahan kebutuhan harus dibahas dan disetujui pengguna.
3. Hal yang belum diputuskan tidak boleh diasumsikan sebagai keputusan final.
4. Temuan teknis yang bertentangan dengan kebutuhan harus dilaporkan.
5. Tidak boleh mengurangi keamanan demi mempermudah implementasi.
6. Tidak boleh memperluas fitur di luar ruang lingkup tanpa persetujuan.
7. Fitur logbook yang sudah ada harus tetap terpisah dari chat.
8. Perubahan database harus mempertimbangkan data dan relasi yang sudah ada.

---

# 25. STATUS DOKUMEN

**Status:** Draft Final Kebutuhan Fungsional — menunggu peninjauan dan persetujuan pengguna.

Setelah disetujui, dokumen dapat digunakan sebagai baseline untuk:

- Audit kesiapan teknis B-CHAT-03.
- Desain teknis B-CHAT-04.
- Rencana implementasi B-CHAT-05.
- Implementasi bertahap B-CHAT-06.

**PENTING:** Persetujuan dokumen ini tidak otomatis memberikan izin untuk mengubah kode, database, konfigurasi, file, Git, menjalankan pengujian, atau melakukan deployment.

Setiap tahap implementasi tetap membutuhkan persetujuan tersendiri.

---

**Akhir Dokumen**  
Sistem Manajemen Skripsi UNUHA  
Bimbingan Online Chat Real-Time — B-CHAT-01