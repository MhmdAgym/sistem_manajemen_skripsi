import { redirect } from 'next/navigation'

export const metadata = {
    title: 'Sistem Manajemen Skripsi - Universitas Nurul Huda',
}

export default function Home() {
    // Mengalihkan langsung ke halaman login (sesuaikan rutenya jika berbeda, misal: '/auth/login')
    redirect('/login')
}