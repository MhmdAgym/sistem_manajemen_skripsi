import LoginLinks from '@/app/LoginLinks'

export const metadata = {
    title: 'Sistem Manajemen Skripsi - Universitas Nurul Huda',
}

const Home = () => {
    return (
        <div className="relative flex items-top justify-center min-h-screen bg-gray-100 dark:bg-gray-900 sm:items-center sm:pt-0">
            <LoginLinks />

            <div className="max-w-4xl mx-auto sm:px-6 lg:px-8 text-center">
                {/* Bagian Identitas / Logo Kampus */}
                <div className="flex flex-col items-center justify-center pt-8 sm:pt-0">
                    <div className="w-24 h-24 bg-red-600 rounded-full flex items-center justify-center shadow-lg mb-4">
                        <span className="text-white text-3xl font-bold">UNUHA</span>
                    </div>
                    <h1 className="text-3xl sm:text-4xl font-extrabold text-gray-900 dark:text-white tracking-tight">
                        Sistem Manajemen Skripsi
                    </h1>
                    <p className="mt-3 text-lg text-gray-600 dark:text-gray-300 font-medium">
                        Fakultas Ilmu Pendidikan - Program Studi Pendidikan Bahasa Inggris
                    </p>
                    <p className="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Universitas Nurul Huda
                    </p>
                </div>

                {/* Kartu Informasi Penggunaan */}
                <div className="mt-10 bg-white dark:bg-gray-800 overflow-hidden shadow-xl sm:rounded-lg p-8 text-left">
                    <h2 className="text-xl font-bold text-gray-900 dark:text-white mb-4">
                        Selamat Datang di Portal Skripsi
                    </h2>
                    <p className="text-gray-600 dark:text-gray-300 leading-relaxed">
                        Aplikasi ini dirancang untuk memudahkan mahasiswa dan dosen pembimbing dalam mengelola pengajuan judul, bimbingan, hingga proses administrasi akhir skripsi secara terintegrasi.
                    </p>
                    <div className="mt-6 flex gap-4">
                        <a
                            href="/login"
                            className="inline-flex items-center px-6 py-3 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-500 active:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition ease-in-out duration-150">
                            Masuk ke Sistem
                        </a>
                    </div>
                </div>

                {/* Footer */}
                <div className="flex justify-center mt-8 text-sm text-gray-500 dark:text-gray-400">
                    <p>Universitas Nurul Huda &copy; 2026 - All rights reserved</p>
                </div>
            </div>
        </div>
    )
}

export default Home