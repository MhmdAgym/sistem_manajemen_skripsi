'use client'

import { useAuth } from '@/hooks/auth'
import axios from '@/lib/axios'
import { useState } from 'react'
import Image from 'next/image'
import { Mail, CheckCircle2, AlertCircle, Loader2 } from 'lucide-react'

const Page = () => {
    const { user, logout } = useAuth({
        middleware: 'auth',
        redirectIfAuthenticated: '/dashboard',
    })

    const [status, setStatus] = useState(null)
    const [errorMessage, setErrorMessage] = useState(null)
    const [isLoading, setIsLoading] = useState(false)

    const handleResend = async () => {
        setIsLoading(true)
        setStatus(null)
        setErrorMessage(null)

        try {
            const response = await axios.post('/email/verification-notification')
            setStatus(response.data?.status || 'verification-link-sent')
        } catch (error) {
            if (error.response?.status === 429) {
                setErrorMessage(
                    'Terlalu banyak permintaan pengiriman email. Silakan tunggu 1 menit sebelum mencoba lagi.',
                )
            } else {
                setErrorMessage(
                    error.response?.data?.message ||
                    'Gagal mengirim ulang email verifikasi. Silakan periksa koneksi Anda dan coba lagi.',
                )
            }
        } finally {
            setIsLoading(false)
        }
    }

    return (
        <div className="relative min-h-screen flex items-center justify-center px-4 py-10 overflow-hidden">
            {/* Background Image */}
            <div className="absolute inset-0 z-0">
                <Image
                    src="/images/background-login-hijau.jpg"
                    alt="Background"
                    fill
                    className="object-cover"
                    priority
                />
                {/* Overlay gelap agar kartu tetap terbaca jelas */}
                <div className="absolute inset-0 bg-black/30" />
            </div>

            {/* Verification Card */}
            <div className="relative z-10 w-full max-w-md">
                <div className="bg-white/95 backdrop-blur-sm rounded-2xl shadow-2xl px-8 pt-8 pb-8 text-center">

                    {/* Logo & Header */}
                    <div className="flex flex-col items-center mb-6">
                        <div className="mb-3">
                            <Image
                                src="/images/logo-unuha.webp"
                                alt="Logo UNUHA"
                                width={80}
                                height={80}
                                className="object-contain"
                                priority
                            />
                        </div>
                        <h1 className="text-xl font-bold text-gray-800 leading-tight">
                            Sistem Manajemen Skripsi
                        </h1>
                        <p className="text-sm text-gray-500 mt-1">
                            Verifikasi Alamat Email
                        </p>
                    </div>

                    {/* Mail Icon Indicator */}
                    <div className="mx-auto mb-5 flex h-14 w-14 items-center justify-center rounded-full bg-green-50 text-green-700 ring-8 ring-green-50/50">
                        <Mail className="h-7 w-7" />
                    </div>

                    {/* Description Message */}
                    <p className="mb-4 text-sm text-gray-600 leading-relaxed text-left">
                        Terima kasih telah mendaftar! Sebelum melanjutkan, silakan konfirmasi akun Anda dengan mengeklik tautan verifikasi yang telah kami kirimkan ke Gmail Anda.
                    </p>

                    {/* Info email pengguna */}
                    {user?.email && (
                        <div className="mb-5 rounded-lg border border-slate-200 bg-slate-50/80 px-3.5 py-2.5 text-xs text-slate-600 text-left">
                            <span className="block text-[11px] font-semibold text-slate-500 uppercase tracking-wider mb-0.5">
                                Dikirim ke alamat email:
                            </span>
                            <span className="font-semibold text-slate-800 break-all">
                                {user.email}
                            </span>
                        </div>
                    )}

                    {/* Status Sukses: Link Terkirim */}
                    {status === 'verification-link-sent' && (
                        <div className="mb-5 flex items-start gap-2.5 rounded-lg border border-green-200 bg-green-50 px-3.5 py-3 text-left text-xs font-medium text-green-800">
                            <CheckCircle2 className="h-4 w-4 text-green-600 mt-0.5 shrink-0" />
                            <div>
                                Tautan verifikasi baru berhasil dikirim. Silakan periksa kotak masuk atau folder spam email Anda.
                            </div>
                        </div>
                    )}

                    {/* Status: Sudah Terverifikasi */}
                    {status === 'already-verified' && (
                        <div className="mb-5 flex items-start gap-2.5 rounded-lg border border-green-200 bg-green-50 px-3.5 py-3 text-left text-xs font-medium text-green-800">
                            <CheckCircle2 className="h-4 w-4 text-green-600 mt-0.5 shrink-0" />
                            <div>
                                Alamat email Anda sudah berhasil diverifikasi. Sedang mengalihkan ke dashboard...
                            </div>
                        </div>
                    )}

                    {/* Status Gagal: Error */}
                    {errorMessage && (
                        <div className="mb-5 flex items-start gap-2.5 rounded-lg border border-red-200 bg-red-50 px-3.5 py-3 text-left text-xs font-medium text-red-800">
                            <AlertCircle className="h-4 w-4 text-red-600 mt-0.5 shrink-0" />
                            <div>{errorMessage}</div>
                        </div>
                    )}

                    {/* Action Buttons */}
                    <div className="space-y-3 pt-1">
                        <button
                            type="button"
                            onClick={handleResend}
                            disabled={isLoading}
                            className="w-full flex items-center justify-center gap-2 rounded-lg bg-green-700 hover:bg-green-800 active:bg-green-900 disabled:opacity-60 disabled:cursor-not-allowed text-white font-semibold py-2.5 text-sm tracking-wide shadow-md transition-all duration-150 focus:outline-none focus:ring-2 focus:ring-green-400">
                            {isLoading ? (
                                <>
                                    <Loader2 className="w-4 h-4 animate-spin" />
                                    Mengirim Ulang...
                                </>
                            ) : (
                                'Kirim Ulang Email Verifikasi'
                            )}
                        </button>

                        <button
                            type="button"
                            onClick={logout}
                            className="w-full py-2 text-xs font-medium text-gray-500 hover:text-gray-800 transition">
                            Keluar dari Akun (Logout)
                        </button>
                    </div>

                </div>
            </div>
        </div>
    )
}

export default Page
