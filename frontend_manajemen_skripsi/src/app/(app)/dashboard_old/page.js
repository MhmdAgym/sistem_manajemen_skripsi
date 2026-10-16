'use client'

import { useAuth } from '@/hooks/auth'

export default function DashboardPage() {
    const { user } = useAuth({ middleware: 'auth' })

    // Jika data user belum selesai dimuat
    if (!user) {
        return <div className="p-6">Memuat data...</div>
    }

    return (
        <div className="space-y-6">
            {/* Bagian Header Sambutan (Sama untuk semua) */}
            <div className="bg-white dark:bg-gray-800 p-6 rounded-lg shadow">
                <h1 className="text-2xl font-bold text-gray-900 dark:text-white">
                    Selamat Datang, {user.name}
                </h1>
                <p className="text-gray-600 dark:text-gray-400 mt-1">
                    Role Anda saat ini: <span className="uppercase font-semibold text-indigo-600">{user.role}</span> 
                    {user.nomor_induk && ` | Nomor Induk: ${user.nomor_induk}`}
                </p>
            </div>

            {/* FITUR BERDASARKAN ROLE (Conditional Rendering) */}
            
            {/* 1. TAMPILAN KHUSUS MAHASISWA */}
            {user.role === 'mahasiswa' && (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div className="bg-blue-50 dark:bg-gray-700 p-6 rounded-lg shadow">
                        <h3 className="font-bold text-lg text-blue-900 dark:text-blue-200">Pengajuan Judul Skripsi</h3>
                        <p className="text-sm text-gray-600 dark:text-gray-300 mt-2">Ajukan judul penelitian skripsi Anda untuk divalidasi oleh dosen pembimbing.</p>
                        <button className="mt-4 px-4 py-2 bg-blue-600 text-white rounded text-sm font-semibold hover:bg-blue-700">
                            Ajukan Judul
                        </button>
                    </div>
                    <div className="bg-blue-50 dark:bg-gray-700 p-6 rounded-lg shadow">
                        <h3 className="font-bold text-lg text-blue-900 dark:text-blue-200">Logbook Bimbingan</h3>
                        <p className="text-sm text-gray-600 dark:text-gray-300 mt-2">Catat progress bimbingan harian bersama dosen pembimbing.</p>
                    </div>
                </div>
            )}

            {/* 2. TAMPILAN KHUSUS DOSEN (PA / Penguji) */}
            {(user.role === 'dosen_pa' || user.role === 'dosen_penguji' || user.role === 'dosen') && (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div className="bg-green-50 dark:bg-gray-700 p-6 rounded-lg shadow">
                        <h3 className="font-bold text-lg text-green-900 dark:text-green-200">Daftar Mahasiswa Bimbingan</h3>
                        <p className="text-sm text-gray-600 dark:text-gray-300 mt-2">Kelola dan setujui pengajuan judul serta bimbingan mahasiswa.</p>
                        <button className="mt-4 px-4 py-2 bg-green-600 text-white rounded text-sm font-semibold hover:bg-green-700">
                            Periksa Bimbingan
                        </button>
                    </div>
                    <div className="bg-green-50 dark:bg-gray-700 p-6 rounded-lg shadow">
                        <h3 className="font-bold text-lg text-green-900 dark:text-green-200">Input Nilai Ujian</h3>
                        <p className="text-sm text-gray-600 dark:text-gray-300 mt-2">Berikan penilaian sidang proposal atau skripsi mahasiswa.</p>
                    </div>
                </div>
            )}

            {/* 3. TAMPILAN KHUSUS ADMIN */}
            {user.role === 'admin' && (
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div className="bg-purple-50 dark:bg-gray-700 p-6 rounded-lg shadow">
                        <h3 className="font-bold text-lg text-purple-900 dark:text-purple-200">Kelola Data Pengguna</h3>
                        <p className="text-sm text-gray-600 dark:text-gray-300 mt-2">Manajemen akun mahasiswa dan dosen.</p>
                    </div>
                    <div className="bg-purple-50 dark:bg-gray-700 p-6 rounded-lg shadow">
                        <h3 className="font-bold text-lg text-purple-900 dark:text-purple-200">Ploting Dosen</h3>
                        <p className="text-sm text-gray-600 dark:text-gray-300 mt-2">Atur penempatan dosen pembimbing dan penguji.</p>
                    </div>
                    <div className="bg-purple-50 dark:bg-gray-700 p-6 rounded-lg shadow">
                        <h3 className="font-bold text-lg text-purple-900 dark:text-purple-200">Rekapitulasi Skripsi</h3>
                        <p className="text-sm text-gray-600 dark:text-gray-300 mt-2">Lihat laporan keseluruhan status skripsi mahasiswa.</p>
                    </div>
                </div>
            )}
        </div>
    )
}