# Dokumentasi Breakdown Fitur — Sistem Manajemen Skripsi
**Universitas Nurul Huda (UNUHA)**  
Dibuat: Oktober 2026 | Analisis menyeluruh seluruh kode frontend & backend

---

## Daftar Isi
1. [Gambaran Umum Arsitektur](#1-gambaran-umum-arsitektur)
2. [Konfigurasi Lingkungan (Environment)](#2-konfigurasi-lingkungan)
3. [Struktur Database & Relasi Antar Tabel](#3-struktur-database--relasi-antar-tabel)
4. [Backend — Laravel 12](#4-backend--laravel-12)
   - [Model Eloquent](#41-model-eloquent)
   - [API Routes](#42-api-routes)
   - [Auth Controllers](#43-auth-controllers)
   - [CRUD Controllers](#44-crud-controllers)
5. [Frontend — Next.js 14](#5-frontend--nextjs-14)
   - [Struktur Direktori](#51-struktur-direktori)
   - [Konfigurasi & Utilitas](#52-konfigurasi--utilitas)
   - [Alur Autentikasi (useAuth)](#53-alur-autentikasi-useauth)
   - [Halaman Auth](#54-halaman-auth)
   - [Dashboard & Layout](#55-dashboard--layout)
   - [Halaman Admin](#56-halaman-admin)
   - [Halaman Dosen](#57-halaman-dosen)
   - [Halaman Mahasiswa](#58-halaman-mahasiswa)
6. [Fitur yang Sudah Selesai vs Placeholder](#6-fitur-yang-sudah-selesai-vs-placeholder)
7. [Alur Bisnis Utama](#7-alur-bisnis-utama)
8. [Design System & Komponen UI](#8-design-system--komponen-ui)
9. [Catatan Teknis & Rekomendasi](#9-catatan-teknis--rekomendasi)

---

## 1. Gambaran Umum Arsitektur

```
┌─────────────────────────────────────────────────────────────────┐
│  FRONTEND (Next.js 14)          BACKEND (Laravel 12)            │
│  http://localhost:3000    ←──►  http://localhost:8000            │
│                                                                   │
│  - App Router (TypeScript)      - Laravel Breeze (Sanctum)       │
│  - Tailwind CSS                 - MySQL (manajemen_skripsi)      │
│  - SWR (data fetching)          - Session-based Auth             │
│  - Lucide Icons                 - Queue + Job (mail)             │
│  - Shadcn/UI (Radix)            - SMTP Gmail (verifikasi)        │
└─────────────────────────────────────────────────────────────────┘
```

**Komunikasi:** Axios dengan `withCredentials: true` dan `withXSRFToken: true` untuk session cookie Sanctum yang stateful.

**Tiga Peran (Role):**
| Role | Redirect Setelah Login | Deskripsi |
|------|----------------------|-----------|
| `admin` | `/dashboardadmin` | Mengelola seluruh data sistem |
| `dosen` | `/dashboarddosen` | Pembimbing, PA, dan Penguji |
| `mahasiswa` | `/dashboardmahasiswa` | Pengajuan judul, bimbingan, sempro, ujian |

---

## 2. Konfigurasi Lingkungan

### Backend `.env` (Laravel)
| Variabel | Nilai | Keterangan |
|----------|-------|------------|
| `APP_URL` | `http://localhost:8000` | URL backend |
| `FRONTEND_URL` | `http://localhost:3000` | Redirect setelah verifikasi email |
| `DB_CONNECTION` | `mysql` | Driver database |
| `DB_DATABASE` | `manajemen_skripsi` | Nama database |
| `SESSION_DRIVER` | `database` | Sesi disimpan di DB |
| `SANCTUM_STATEFUL_DOMAINS` | `localhost:3000` | Izin domain frontend untuk Sanctum |
| `SESSION_DOMAIN` | `localhost` | Cookie domain |
| `MAIL_MAILER` | `smtp` | Driver email |
| `MAIL_HOST` | `smtp.gmail.com` | Server Gmail |
| `MAIL_PORT` | `587` | Port STARTTLS |

### Frontend (Next.js)
| Variabel | Nilai | Keterangan |
|----------|-------|------------|
| `NEXT_PUBLIC_BACKEND_URL` | `http://localhost:8000` | Base URL untuk Axios |

---

## 3. Struktur Database & Relasi Antar Tabel

### Diagram Relasi (ERD Ringkas)

```
users (id, name, email, password, role, nomor_induk, email_verified_at)
  │
  ├──► admin (id_admin, users_id ←FK, nama_admin)
  │
  ├──► mahasiswa (id_mahasiswa, user_id ←FK, NIM, nama_mahasiswa, prodi, 
  │              fakultas, no_handphone, jenis_kelamin)
  │       │
  │       └──► skripsi (id_skripsi, id_mahasiswa ←FK, judul, status_judul)
  │                 │
  │                 ├──► dosen_pa (id_dosen_pa, id_dosen ←FK, id_skripsi ←FK)
  │                 │
  │                 ├──► dosen_pembimbing (id_pembimbing, id_skripsi ←FK, id_dosen ←FK)
  │                 │
  │                 ├──► bimbingan (id_bimbingan, id_skripsi ←FK, tanggal, catatan)
  │                 │
  │                 └──► sempro (id_sempro, id_skripsi ←FK, tanggal_sempro, status_sempro)
  │                           │
  │                           ├──► penguji_sempro (id_penguji_sempro, id_dosen ←FK, 
  │                           │                   id_sempro ←FK, peran)
  │                           ├──► nilai_sempro (id_nilai_sempro, id_sempro ←FK,
  │                           │                 tanggal_ujian, status_ujian, id_penguji_sempro ←FK)
  │                           │
  │                           └──► ujian_skripsi (id_ujian_skripsi, id_sempro ←FK,
  │                                               tanggal_ujian, status_ujian)
  │                                     │
  │                                     ├──► dosen_penguji_skripsi (id_penguji_skripsi,
  │                                     │                           id_dosen ←FK, 
  │                                     │                           id_ujian_skripsi ←FK, peran)
  │                                     └──► nilai_skripsi (id_nilai, id_ujian_skripsi ←FK,
  │                                                         id_dosen ←FK, nilai, catatan)
  │
  └──► dosen (id_dosen, user_id ←FK, NIDN, nama_dosen, no_handphone, jenis_kelamin)
```

### Daftar Tabel Lengkap

| No | Tabel | Primary Key | Kolom Utama | Keterangan |
|----|-------|-------------|-------------|------------|
| 1 | `users` | `id` | name, email, password, role, nomor_induk, email_verified_at | Akun autentikasi semua peran |
| 2 | `admin` | `id_admin` | users_id, nama_admin | Profil admin (FK → users.id) |
| 3 | `mahasiswa` | `id_mahasiswa` | user_id, NIM, nama_mahasiswa, prodi, fakultas, no_handphone, jenis_kelamin | Profil mahasiswa |
| 4 | `dosen` | `id_dosen` | user_id, NIDN, nama_dosen, no_handphone, jenis_kelamin | Profil dosen |
| 5 | `skripsi` | `id_skripsi` | id_mahasiswa, judul, status_judul (`diajukan`) | Pengajuan judul skripsi |
| 6 | `dosen_pa` | `id_dosen_pa` | id_dosen, id_skripsi | Penugasan dosen pembimbing akademik |
| 7 | `dosen_pembimbing` | `id_pembimbing` | id_skripsi, id_dosen | Penugasan dosen pembimbing skripsi |
| 8 | `bimbingan` | `id_bimbingan` | id_skripsi, tanggal, catatan | Log sesi bimbingan |
| 9 | `sempro` | `id_sempro` | id_skripsi, tanggal_sempro, status_sempro | Seminar Proposal |
| 10 | `penguji_sempro` | `id_penguji_sempro` | id_dosen, id_sempro, peran | Dosen penguji sempro |
| 11 | `nilai_sempro` | `id_nilai_sempro` | id_sempro, tanggal_ujian, status_ujian, id_penguji_sempro | Nilai hasil sempro |
| 12 | `ujian_skripsi` | `id_ujian_skripsi` | id_sempro, tanggal_ujian, status_ujian | Ujian sidang skripsi |
| 13 | `dosen_penguji_skripsi` | `id_penguji_skripsi` | id_dosen, id_ujian_skripsi, peran | Dosen penguji sidang skripsi |
| 14 | `nilai_skripsi` | `id_nilai` | id_ujian_skripsi, id_dosen, nilai, catatan | Nilai per dosen penguji sidang |

> **Catatan FK Penting:**
> - `admin.users_id` (non-standar) → `users.id`
> - `mahasiswa.user_id` → `users.id`
> - `dosen.user_id` → `users.id`
> - Tabel `skripsi`, `dosen_pa`, `dosen_pembimbing`, `sempro`, `ujian_skripsi` tidak memiliki kolom `timestamps`

---

## 4. Backend — Laravel 12

### 4.1 Model Eloquent

#### `User` (`app/Models/User.php`)
- **Implements:** `MustVerifyEmail`
- **Fillable:** `name`, `email`, `password`, `role`, `nomor_induk`
- **Hidden:** `password`, `remember_token`
- **Casts:** `email_verified_at` → datetime, `password` → hashed
- **Relasi yang tersedia (dari profil terkait):** Tabel `mahasiswa`, `dosen`, `admin` dapat diakses via relasi `hasOne`

#### `Mahasiswa` (`app/Models/Mahasiswa.php`)
- **Tabel:** `mahasiswa` | **PK:** `id_mahasiswa`
- **Fillable:** NIM, user_id, nama_mahasiswa, prodi, no_handphone, jenis_kelamin, fakultas
- **Relasi:**
  - `user()` → `belongsTo(User)` via `user_id`

#### `Dosen` (`app/Models/Dosen.php`)
- **Tabel:** `dosen` | **PK:** `id_dosen`
- **Fillable:** NIDN, user_id, nama_dosen, no_handphone, jenis_kelamin
- **Relasi:**
  - `user()` → `belongsTo(User)` via `user_id`

#### `Skripsi` (`app/Models/Skripsi.php`)
- **Tabel:** `skripsi` | **PK:** `id_skripsi` | **Timestamps:** false
- **Fillable:** id_mahasiswa, judul, status_judul
- **Relasi:**
  - `mahasiswa()` → `belongsTo(Mahasiswa)` via `id_mahasiswa`

#### `DosenPA` (`app/Models/DosenPA.php`)
- **Tabel:** `dosen_pa` | **PK:** `id_dosen_pa` | **Timestamps:** false
- **Fillable:** id_dosen, id_skripsi
- **Relasi:** `dosen()` → belongsTo(Dosen), `skripsi()` → belongsTo(Skripsi)

#### `DosenPembimbing` (`app/Models/DosenPembimbing.php`)
- **Tabel:** `dosen_pembimbing` | **PK:** `id_pembimbing` | **Timestamps:** false
- **Fillable:** id_skripsi, id_dosen
- **Relasi:** `skripsi()` → belongsTo(Skripsi), `dosen()` → belongsTo(Dosen)

#### `NilaiSempro` (`app/Models/NilaiSempro.php`)
- **Fillable:** id_sempro, tanggal_ujian, status_ujian, id_penguji_sempro
- **Relasi:** `sempro()` → belongsTo(Sempro), `pengujiSempro()` → belongsTo(PengujiSempro)

#### `NilaiSkripsi` (`app/Models/NilaiSkripsi.php`)
- **Fillable:** id_ujian_skripsi, id_dosen, nilai, catatan
- **Relasi:** `ujianSkripsi()` → belongsTo(UjianSkripsi), `dosen()` → belongsTo(Dosen)

---

### 4.2 API Routes (`routes/api.php`)

#### Route Autentikasi (auth:sanctum)
| Method | Endpoint | Deskripsi |
|--------|----------|-----------|
| GET | `/api/user` | Ambil data user yang sedang login |

#### CRUD Routes (apiResource — tidak perlu auth middleware saat ini)
| Resource | Endpoint Prefix | Controller |
|----------|----------------|------------|
| Admin | `/api/admin` | `AdminController` |
| Mahasiswa | `/api/mahasiswa` | `MahasiswaController` |
| Dosen | `/api/dosen` | `DosenController` |
| Skripsi | `/api/skripsi` | `SkripsiController` |
| Dosen PA | `/api/dosen-pa` | `DosenPAController` |
| Dosen Pembimbing | `/api/dosen-pembimbing` | `DosenPembimbingController` |
| Bimbingan | `/api/bimbingan` | `BimbinganController` |
| Sempro | `/api/sempro` | `SemproController` |
| Penguji Sempro | `/api/penguji-sempro` | `PengujiSemproController` |
| Nilai Sempro | `/api/nilai-sempro` | `NilaiSemproController` |
| Ujian Skripsi | `/api/ujian-skripsi` | `UjianSkripsiController` |
| Penguji Skripsi | `/api/dosen-penguji-skripsi` | `DosenPengujiSkripsiController` |
| Nilai Skripsi | `/api/nilai-skripsi` | `NilaiSkripsiController` |

> Setiap `apiResource` secara otomatis menghasilkan: **GET /index, POST /store, GET /show, PUT /update, DELETE /destroy**

---

### 4.3 Auth Controllers

#### `AuthenticatedSessionController` (Login/Logout)
- **POST `/login`** → `store()`: Autentikasi via `LoginRequest::authenticate()`, regenerate session, return 204
- **POST `/logout`** → `destroy()`: Logout guard web, invalidate session, regenerate CSRF token, return 204

#### `RegisteredUserController` (Registrasi)
- **POST `/register`** → `store()`:
  - Validasi: name, email, password+confirm, role (`mahasiswa`/`dosen`), nomor_induk (regex angka max 10), no_handphone, jenis_kelamin
  - Khusus mahasiswa: fakultas (3 pilihan) + prodi (divalidasi terhadap daftar per fakultas)
  - `DB::transaction`: Buat `User` → buat `Mahasiswa` atau `Dosen`
  - `event(new Registered($user))` → trigger pengiriman email verifikasi
  - `Auth::login($user)` → session::regenerate()

#### `VerifyEmailController` (Verifikasi Email)
- **GET `/api/verify-email/{id}/{hash}`**:
  - Cari user by `id`, validasi `hash` vs `sha1(email)`
  - Tandai `email_verified_at` jika belum terverifikasi
  - Redirect ke `FRONTEND_URL + /dashboardadmin|dosen|mahasiswa?verified=1` berdasarkan `user->role`

#### `EmailVerificationNotificationController` (Kirim Ulang)
- **POST `/email/verification-notification`**: Kirim ulang link verifikasi (dengan throttle bawaan Laravel)

#### `PasswordResetLinkController` & `NewPasswordController`
- Alur forgot password & reset password standar Laravel Breeze

---

### 4.4 CRUD Controllers

Semua controller CRUD mengikuti pola yang sama:

| Method | Aksi | Eager Load |
|--------|------|------------|
| `index()` | List semua data | With relasi terkait |
| `store()` | Tambah data | Validasi request |
| `show($id)` | Detail satu data | With relasi terkait |
| `update(Request, $id)` | Ubah data | Validasi `sometimes` |
| `destroy($id)` | Hapus data | - |

**Pola khusus pada beberapa controller:**

- **`DosenPAController`**: Cek duplikasi — satu skripsi hanya boleh memiliki satu Dosen PA
- **`DosenPengujiSkripsiController`**: Cek unique constraint — dosen tidak bisa menjadi penguji dua kali di ujian yang sama. Constraint juga di-cek saat update
- **`NilaiSemproController`**: Eager load relasi `sempro` dan `pengujiSempro` bersama

---

## 5. Frontend — Next.js 14

### 5.1 Struktur Direktori

```
src/
├── app/
│   ├── (auth)/                      # Grup route publik (guest)
│   │   ├── login/page.js            # ✅ Halaman Login
│   │   ├── register/page.js         # ✅ Halaman Registrasi
│   │   ├── verify-email/page.js     # ✅ Halaman Verifikasi Email
│   │   ├── forgot-password/         # ✅ Lupa Password
│   │   ├── password-reset/          # ✅ Reset Password
│   │   ├── AuthCard.js              # Komponen card auth dasar
│   │   ├── AuthSessionStatus.js     # Tampilan status sesi
│   │   └── layout.js
│   │
│   ├── (app)/
│   │   ├── (auth)/                  # Rute yang memerlukan login
│   │   ├── (dashboard)/
│   │   │   ├── layout.tsx           # Layout dashboard (Sidebar + Topbar)
│   │   │   ├── dashboardadmin/      # Panel Admin
│   │   │   │   ├── page.tsx         # ✅ Dashboard utama admin (MainDashboard)
│   │   │   │   ├── data_mahasiswa/  # ⚠️ Placeholder (data statis)
│   │   │   │   ├── data_dosen/      # ⚠️ Placeholder (data statis)
│   │   │   │   ├── manajemen_sempro/# ⚠️ Placeholder kosong
│   │   │   │   ├── manajemen_ujian/ # ⚠️ Placeholder kosong
│   │   │   │   └── berita_acara/   # ⚠️ Placeholder kosong
│   │   │   ├── dashboarddosen/      # Panel Dosen
│   │   │   │   ├── page.tsx         # ⚠️ Placeholder (redirect)
│   │   │   │   ├── pembimbing_akademik/  # ⚠️ Placeholder
│   │   │   │   ├── pembimbing_skripsi/   # ⚠️ Placeholder
│   │   │   │   └── penguji/         # ⚠️ Placeholder
│   │   │   └── dashboardmahasiswa/  # Panel Mahasiswa
│   │   │       ├── page.tsx         # ⚠️ Placeholder (redirect)
│   │   │       ├── pengajuan_judul/ # ⚠️ Placeholder
│   │   │       ├── bimbingan/       # ⚠️ Placeholder
│   │   │       ├── sempro/          # ⚠️ Placeholder
│   │   │       └── ujian skripsi/   # ⚠️ Placeholder
│   │   └── layout.js
│   ├── layout.js                    # Root layout
│   └── page.js                      # Root redirect
│
├── components/
│   ├── sidebar.tsx                  # ✅ Sidebar role-aware dengan collapse
│   ├── topbar.tsx                   # ✅ Topbar dengan notifikasi & user menu
│   ├── dashboard1/main-dashboard.tsx # ✅ Widget dashboard admin
│   ├── ui/                          # Komponen Shadcn/Radix UI
│   ├── InputError.js                # Tampilan error validasi form
│   └── ...
│
├── hooks/
│   └── auth.js                      # ✅ Hook useAuth (SWR)
│
└── lib/
    └── axios.js                     # ✅ Instance Axios terkonfigurasi
```

---

### 5.2 Konfigurasi & Utilitas

#### `lib/axios.js`
```js
Axios.create({
  baseURL: process.env.NEXT_PUBLIC_BACKEND_URL, // http://localhost:8000
  headers: { 'X-Requested-With': 'XMLHttpRequest' },
  withCredentials: true,   // Kirim cookie sesi
  withXSRFToken: true      // Kirim token CSRF Sanctum
})
```

---

### 5.3 Alur Autentikasi (`useAuth`)

Hook `useAuth` di `src/hooks/auth.js` adalah pusat kendali autentikasi:

```
┌──────────────────────────────────────────────────────────────┐
│ useSWR('/api/user') → Cek sesi login saat ini               │
│                                                              │
│ Fungsi yang tersedia:                                        │
│ ├── register()       → csrf() → POST /register              │
│ ├── login()          → csrf() → POST /login                  │
│ ├── forgotPassword() → csrf() → POST /forgot-password       │
│ ├── resetPassword()  → csrf() → POST /reset-password        │
│ ├── resendEmailVerification() → POST /email/verification-notification │
│ └── logout()         → POST /logout → redirect /login       │
│                                                              │
│ useEffect — Guard Logic:                                     │
│ ├── middleware=guest + user logged in → redirect by role    │
│ ├── middleware=auth + email unverified → /verify-email      │
│ ├── di /verify-email + sudah verified → redirect by role    │
│ └── middleware=auth + error → logout()                      │
└──────────────────────────────────────────────────────────────┘
```

**Redirect berdasarkan role:**
```
admin     → /dashboardadmin
dosen     → /dashboarddosen
mahasiswa → /dashboardmahasiswa
```

---

### 5.4 Halaman Auth

#### `/login` — `page.js` ✅ SELESAI
- Background: `/images/background-login-hijau.jpg` + overlay `bg-black/30`
- Card `bg-white/95 backdrop-blur-sm rounded-2xl`
- Logo UNUHA (`/images/logo-unuha.webp`) + judul sistem
- Field: Email/NIM/NIDN, Password (dengan toggle show/hide)
- Checkbox: Ingat saya | Link: Lupa password
- Button submit dengan `Loader2` spinner saat loading
- Error validasi via `InputError`

#### `/register` — `page.js` ✅ SELESAI
- Desain konsisten dengan login (background + card)
- Grid 2 kolom (responsive)
- Field dinamis berdasarkan role:
  - **Mahasiswa:** Role, NIM, Nama, Email, No. HP, Jenis Kelamin, Fakultas, Prodi
  - **Dosen:** Role, NIDN, Nama, Email, No. HP, Jenis Kelamin
- Dropdown Prodi dinamis berdasarkan pilihan Fakultas
- Validasi frontend + error dari backend ditampilkan per field

**Daftar Fakultas & Prodi:**
| Fakultas | Program Studi |
|----------|---------------|
| Fakultas Agama Islam | Pendidikan Agama Islam, PGMI, Pendidikan Bahasa Arab |
| Fakultas Ilmu Pendidikan | Pend. Fisika, Pend. Ekonomi, Pend. Bahasa Inggris, Pend. Bahasa & Sastra Indonesia, Pend. Teknologi Informasi |
| Fakultas Sains dan Teknologi | Informatika, Matematika, Sains Pertanian |

#### `/verify-email` — `page.js` ✅ SELESAI
- Middleware: `auth` (hanya user yang login bisa akses)
- Tampilkan email tujuan dari `user.email`
- Tombol: "Kirim Ulang Email Verifikasi" dengan loading state
- Handle HTTP 429 (terlalu banyak request) → pesan error khusus
- Status sukses (`verification-link-sent`) dan error ditampilkan terpisah
- Tombol Logout

---

### 5.5 Dashboard & Layout

#### `(dashboard)/layout.tsx`
- State: `sidebarOpen` untuk mobile toggle
- Overlay gelap saat sidebar terbuka di mobile (backdrop)
- Komponen: `<Sidebar>` + `<Topbar>` + `{children}`
- Footer: "© {year} Universitas Nurul Huda"

#### `components/sidebar.tsx`
- **Role-aware:** Menu berbeda berdasarkan `user.role` dari `useAuth`
- **Collapsible:** Toggle antara expanded (w-72) dan collapsed (w-20) di desktop
- **Responsive:** Slide-in drawer di mobile dengan tombol X
- Active state: `bg-white text-[#15803D]` pada link aktif
- Warna: `bg-[#15803D]` (hijau UNUHA)

**Menu per Role:**
| Admin | Dosen | Mahasiswa |
|-------|-------|-----------|
| Dashboard | Dashboard Dosen | Dashboard Mahasiswa |
| Data Mahasiswa | Pembimbing Akademik | Pengajuan Judul |
| Data Dosen | Pembimbing Skripsi | Bimbingan Saya |
| Manajemen Sempro | Penguji | Seminar Proposal |
| Manajemen Ujian | | Ujian Skripsi |
| Berita Acara | | |

#### `components/topbar.tsx`
- Tombol hamburger menu (mobile)
- **Bell icon** dengan badge notifikasi merah (hardcoded "3" — placeholder)
- **Settings dropdown** (placeholder menu: Kelola Dosen & Kuota, Pengaturan Periode)
- **Avatar dropdown**: Initial dari `user.name`, menu Profil Akun + Keluar
- Logout via `useAuth().logout()`

---

### 5.6 Halaman Admin

#### `dashboardadmin/page.tsx` ✅ SELESAI
- Menggunakan komponen `MainDashboard` dari `components/dashboard1/`
- Widget statistik sistem (cards ringkasan)

#### `dashboardadmin/data_mahasiswa/page.tsx` ⚠️ PLACEHOLDER
- Data statis hardcoded (3 baris dummy)
- Tabel dengan kolom: Nama, NIM, Prodi, Status, Aksi
- Search bar ada tapi tidak fungsional
- Tombol "Tambah Mahasiswa" dan "Edit" belum terhubung ke API

#### `dashboardadmin/data_dosen/page.tsx` ⚠️ PLACEHOLDER
- Data statis hardcoded (2 baris dummy)
- List sederhana: Nama, NIDN, Jabatan
- Tombol "Detail" belum fungsional

#### `dashboardadmin/manajemen_sempro/page.tsx` ⚠️ PLACEHOLDER
- Hanya template header + pesan "Belum ada jadwal"

#### `dashboardadmin/manajemen_ujian/` ⚠️ PLACEHOLDER
- Belum diimplementasikan

#### `dashboardadmin/berita_acara/page.tsx` ⚠️ PLACEHOLDER
- Minimal layout

---

### 5.7 Halaman Dosen

#### `dashboarddosen/page.tsx` ⚠️ PLACEHOLDER
- Redirect placeholder (belum ada konten)

#### `dashboarddosen/pembimbing_akademik/` ⚠️ PLACEHOLDER
#### `dashboarddosen/pembimbing_skripsi/` ⚠️ PLACEHOLDER
#### `dashboarddosen/penguji/` ⚠️ PLACEHOLDER

---

### 5.8 Halaman Mahasiswa

#### `dashboardmahasiswa/page.tsx` ⚠️ PLACEHOLDER
- Redirect placeholder

#### `dashboardmahasiswa/pengajuan_judul/` ⚠️ PLACEHOLDER
#### `dashboardmahasiswa/bimbingan/` ⚠️ PLACEHOLDER
#### `dashboardmahasiswa/sempro/` ⚠️ PLACEHOLDER
#### `dashboardmahasiswa/ujian skripsi/` ⚠️ PLACEHOLDER (perhatikan: ada spasi dalam nama folder)

---

## 6. Fitur yang Sudah Selesai vs Placeholder

### ✅ Sudah Berfungsi Penuh
| Fitur | Backend | Frontend |
|-------|---------|----------|
| Registrasi pengguna (Mahasiswa & Dosen) | ✅ | ✅ |
| Login dengan session Sanctum | ✅ | ✅ |
| Logout | ✅ | ✅ |
| Verifikasi email (link Gmail) | ✅ | ✅ |
| Kirim ulang email verifikasi (+ throttle) | ✅ | ✅ |
| Lupa password | ✅ | ✅ |
| Reset password via email | ✅ | ✅ |
| Guard middleware (redirect by role) | ✅ | ✅ |
| Sidebar role-aware | - | ✅ |
| Topbar dengan user info | - | ✅ |
| Dashboard utama admin | - | ✅ |
| API CRUD Mahasiswa | ✅ | ⚠️ Data statis |
| API CRUD Dosen | ✅ | ⚠️ Data statis |
| API CRUD Skripsi | ✅ | - |
| API CRUD Dosen PA | ✅ | - |
| API CRUD Dosen Pembimbing | ✅ | - |
| API CRUD Bimbingan | ✅ | - |
| API CRUD Sempro | ✅ | ⚠️ Placeholder |
| API CRUD Penguji Sempro | ✅ | - |
| API CRUD Nilai Sempro | ✅ | - |
| API CRUD Ujian Skripsi | ✅ | ⚠️ Placeholder |
| API CRUD Penguji Skripsi | ✅ | - |
| API CRUD Nilai Skripsi | ✅ | - |

### ⚠️ Dibutuhkan Pengembangan Lanjutan
| Fitur | Prioritas |
|-------|-----------|
| Integrasi API live di halaman Data Mahasiswa | Tinggi |
| Integrasi API live di halaman Data Dosen | Tinggi |
| Halaman pengajuan judul mahasiswa (frontend) | Tinggi |
| Halaman bimbingan mahasiswa (frontend) | Tinggi |
| Halaman Sempro — jadwal, penugasan penguji | Tinggi |
| Halaman Ujian Skripsi — sidang, penguji, nilai | Tinggi |
| Halaman Pembimbing Akademik (dosen) | Sedang |
| Halaman Pembimbing Skripsi (dosen) | Sedang |
| Halaman Penguji (dosen) | Sedang |
| Berita Acara (PDF generator) | Sedang |
| Manajemen Akun | Sedang |
| Notifikasi real-time (Bell icon) | Rendah |
| Halaman Profil Akun | Rendah |

---

## 7. Alur Bisnis Utama

### 7.1 Registrasi & Onboarding
```
1. User isi form register (role: mahasiswa/dosen)
2. Backend validasi + DB::transaction (buat users + mahasiswa/dosen)
3. Email verifikasi dikirim via Gmail SMTP
4. User klik link di email → Backend verify + redirect ke dashboard
5. Dashboard sesuai role ditampilkan
```

### 7.2 Alur Skripsi Mahasiswa (Ideal — belum semua diimplementasikan)
```
1. Mahasiswa login → dashboardmahasiswa
2. Pengajuan judul → POST /api/skripsi (status_judul: diajukan)
3. Admin menetapkan Dosen PA → POST /api/dosen-pa
4. Admin menetapkan Dosen Pembimbing → POST /api/dosen-pembimbing
5. Bimbingan berlangsung → POST /api/bimbingan (log catatan)
6. Daftar Sempro → POST /api/sempro (tanggal, status)
7. Admin tetapkan penguji sempro → POST /api/penguji-sempro
8. Input nilai sempro → POST /api/nilai-sempro
9. Daftar Ujian Skripsi → POST /api/ujian-skripsi
10. Admin tetapkan penguji sidang → POST /api/dosen-penguji-skripsi
11. Input nilai per dosen penguji → POST /api/nilai-skripsi
12. Berita acara di-generate
```

---

## 8. Design System & Komponen UI

### Warna Utama
| Token | Nilai | Penggunaan |
|-------|-------|------------|
| Primary Green | `#15803D` | Sidebar, button utama, aksen |
| Hover Green | `#166534` | Hover button, aksen |
| Light Green | `#10B981` (emerald-500) | Gradient header halaman |
| Background | `bg-muted` | Konten dashboard |
| Card | `bg-white` | Panel konten |

### Tipografi
- **Font:** Tailwind default (Inter-stack via Next.js)
- **Heading dashboard:** `text-2xl font-bold tracking-tight`
- **Label form:** `text-sm font-medium text-gray-700`
- **Data tabel:** `text-xs`

### Komponen UI (Shadcn/Radix)
- `DropdownMenu` — Menu notifikasi, settings, user
- `Avatar` + `AvatarFallback` — Inisial nama user
- `ui/` — Button, Input, Label, dll (Radix-based)

### Ikon
- Library: **Lucide React**
- Ikon yang digunakan: `LayoutDashboard`, `Users`, `UserCheck`, `FileCheck`, `GraduationCap`, `FileText`, `BookOpen`, `ClipboardList`, `Eye`, `EyeOff`, `Loader2`, `Bell`, `Settings`, `User`, `Menu`, `ChevronLeft`, `ChevronRight`, `X`, `Mail`, `CheckCircle2`, `AlertCircle`, `Search`, `Plus`, `Calendar`

### Aset Gambar
| File | Path | Digunakan di |
|------|------|-------------|
| Logo UNUHA | `/images/logo-unuha.webp` | Login, Register, Verify, Sidebar |
| Background | `/images/background-login-hijau.jpg` | Login, Register, Verify |

---

## 9. Catatan Teknis & Rekomendasi

### ⚠️ Hal yang Perlu Diperhatikan

1. **API Routes Tanpa Auth Middleware**  
   Semua endpoint CRUD (`/api/mahasiswa`, `/api/dosen`, dll.) saat ini terbuka tanpa proteksi `auth:sanctum`. Perlu ditambahkan middleware agar hanya user yang login bisa mengakses.

2. **Nama Folder dengan Spasi**  
   Folder `dashboardmahasiswa/ujian skripsi/` memiliki spasi — ini tidak ideal untuk URL routing Next.js. Sebaiknya diganti menjadi `ujian_skripsi`.

3. **Href Typo di Sidebar**  
   Menu "Pembimbing Akademik" dosen mengarah ke `/dashboarddosen/pembimbing_akademi` (tanpa 'k' di akhir), tapi folder aktual adalah `pembimbing_akademik`.

4. **Kolom `admin.users_id`**  
   Berbeda dengan konvensi (`user_id`) yang digunakan di tabel `mahasiswa` dan `dosen`. Ini bisa menyebabkan kebingungan saat menulis relasi Eloquent.

5. **Notifikasi Topbar Hardcoded**  
   Badge "3" dan isi dropdown notifikasi di `topbar.tsx` masih berupa data statis.

6. **Halaman `data_mahasiswa` dan `data_dosen` Telah Direset ke Placeholder**  
   Kedua halaman ini saat ini menampilkan data dummy statis dan belum terkoneksi ke API backend.

### ✅ Rekomendasi Pengembangan Selanjutnya

1. **Prioritas 1:** Hubungkan `data_mahasiswa/page.tsx` ke `GET /api/mahasiswa` dengan fetch SWR, tampilkan data real dari database, lengkapi search/filter, dan modal detail/edit.

2. **Prioritas 2:** Hubungkan `data_dosen/page.tsx` ke `GET /api/dosen` dengan tampilan jumlah mahasiswa bimbingan.

3. **Prioritas 3:** Implementasikan halaman pengajuan judul mahasiswa yang terhubung ke `POST /api/skripsi`.

4. **Prioritas 4:** Tambahkan middleware `auth:sanctum` ke semua route CRUD di `api.php`.

5. **Prioritas 5:** Implementasikan halaman Manajemen Sempro dan Ujian Skripsi untuk admin.

6. **Jangka Panjang:** Implementasikan notifikasi real-time (Laravel Broadcasting + Pusher/Soketi), halaman profil user, dan generator Berita Acara PDF.
