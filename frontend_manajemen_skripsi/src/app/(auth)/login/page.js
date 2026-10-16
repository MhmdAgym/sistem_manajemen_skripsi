'use client'

import InputError from '@/components/InputError'
import AuthSessionStatus from '@/app/(auth)/AuthSessionStatus'
import { useAuth } from '@/hooks/auth'
import { useEffect, useState } from 'react'
import { useRouter } from 'next/navigation'
import Image from 'next/image'
import Link from 'next/link'
import { Eye, EyeOff, Loader2 } from 'lucide-react'

const Login = () => {
    const router = useRouter()

    const { login } = useAuth({
        middleware: 'guest',
        redirectIfAuthenticated: '/dashboard',
    })

    const [email, setEmail] = useState('')
    const [password, setPassword] = useState('')
    const [shouldRemember, setShouldRemember] = useState(false)
    const [errors, setErrors] = useState([])
    const [status, setStatus] = useState(null)
    const [showPassword, setShowPassword] = useState(false)
    const [isLoading, setIsLoading] = useState(false)

    useEffect(() => {
        if (router.reset?.length > 0 && errors.length === 0) {
            setStatus(atob(router.reset))
        } else {
            setStatus(null)
        }
    })

    const submitForm = async event => {
        event.preventDefault()
        setIsLoading(true)

        login({
            email,
            password,
            remember: shouldRemember,
            setErrors,
            setStatus,
        })

        // Reset loading jika ada error (auth.js akan redirect jika berhasil)
        setTimeout(() => setIsLoading(false), 3000)
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
                {/* Overlay ringan agar form tetap mudah dibaca */}
                <div className="absolute inset-0 bg-black/30" />
            </div>

            {/* Login Card */}
            <div className="relative z-10 w-full max-w-md">
                <div className="bg-white/95 backdrop-blur-sm rounded-2xl shadow-2xl px-8 pt-8 pb-8">

                    {/* Logo & Header */}
                    <div className="flex flex-col items-center mb-7">
                        <div className="mb-4">
                            <Image
                                src="/images/logo-unuha.webp"
                                alt="Logo UNUHA"
                                width={80}
                                height={80}
                                className="object-contain"
                                priority
                            />
                        </div>
                        <h1 className="text-xl font-bold text-gray-800 text-center leading-tight">
                            Sistem Manajemen Skripsi
                        </h1>
                        <p className="text-sm text-gray-500 mt-1 text-center">
                            Silakan masuk untuk melanjutkan
                        </p>
                    </div>

                    {/* Session Status */}
                    <AuthSessionStatus className="mb-4" status={status} />

                    <form onSubmit={submitForm} noValidate>

                        {/* Email / NIM / NIDN */}
                        <div className="mb-4">
                            <label
                                htmlFor="email"
                                className="block text-sm font-medium text-gray-700 mb-1">
                                Email / NIM / NIDN
                            </label>
                            <input
                                id="email"
                                type="email"
                                value={email}
                                onChange={event => setEmail(event.target.value)}
                                required
                                autoFocus
                                disabled={isLoading}
                                placeholder="Masukkan email, NIM, atau NIDN"
                                className="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm text-gray-900 placeholder-gray-400 shadow-sm focus:border-green-500 focus:ring-2 focus:ring-green-200 focus:outline-none transition disabled:opacity-60 disabled:cursor-not-allowed"
                            />
                            <InputError messages={errors.email} className="mt-1.5" />
                        </div>

                        {/* Password */}
                        <div className="mb-4">
                            <label
                                htmlFor="password"
                                className="block text-sm font-medium text-gray-700 mb-1">
                                Password
                            </label>
                            <div className="relative">
                                <input
                                    id="password"
                                    type={showPassword ? 'text' : 'password'}
                                    value={password}
                                    onChange={event => setPassword(event.target.value)}
                                    required
                                    disabled={isLoading}
                                    autoComplete="current-password"
                                    placeholder="Masukkan password"
                                    className="w-full rounded-lg border border-gray-300 px-4 py-2.5 pr-11 text-sm text-gray-900 placeholder-gray-400 shadow-sm focus:border-green-500 focus:ring-2 focus:ring-green-200 focus:outline-none transition disabled:opacity-60 disabled:cursor-not-allowed"
                                />
                                <button
                                    type="button"
                                    onClick={() => setShowPassword(prev => !prev)}
                                    className="absolute inset-y-0 right-0 flex items-center px-3 text-gray-400 hover:text-gray-600 transition"
                                    tabIndex={-1}
                                    aria-label={showPassword ? 'Sembunyikan password' : 'Tampilkan password'}>
                                    {showPassword
                                        ? <EyeOff className="w-4 h-4" />
                                        : <Eye className="w-4 h-4" />}
                                </button>
                            </div>
                            <InputError messages={errors.password} className="mt-1.5" />
                        </div>

                        {/* Remember Me & Lupa Password */}
                        <div className="flex items-center justify-between mb-6">
                            <label htmlFor="remember_me" className="inline-flex items-center gap-2 cursor-pointer select-none">
                                <input
                                    id="remember_me"
                                    type="checkbox"
                                    name="remember"
                                    checked={shouldRemember}
                                    onChange={event => setShouldRemember(event.target.checked)}
                                    className="rounded border-gray-300 text-green-600 shadow-sm focus:ring-green-300 focus:ring-2"
                                />
                                <span className="text-sm text-gray-600">Ingat saya</span>
                            </label>

                            <Link
                                href="/forgot-password"
                                className="text-sm text-green-700 hover:text-green-900 hover:underline transition">
                                Lupa password?
                            </Link>
                        </div>

                        {/* Tombol Masuk */}
                        <button
                            type="submit"
                            disabled={isLoading}
                            className="w-full flex items-center justify-center gap-2 rounded-lg bg-green-700 hover:bg-green-800 active:bg-green-900 disabled:opacity-60 disabled:cursor-not-allowed text-white font-semibold py-2.5 text-sm tracking-wide shadow-md transition-all duration-150 focus:outline-none focus:ring-2 focus:ring-green-400">
                            {isLoading ? (
                                <>
                                    <Loader2 className="w-4 h-4 animate-spin" />
                                    Memproses...
                                </>
                            ) : (
                                'Masuk'
                            )}
                        </button>

                        {/* Link Register */}
                        <p className="mt-6 text-center text-sm text-gray-500">
                            Belum memiliki akun?{' '}
                            <Link
                                href="/register"
                                className="font-semibold text-green-700 hover:text-green-900 hover:underline transition">
                                Daftar di sini
                            </Link>
                        </p>

                    </form>
                </div>
            </div>
        </div>
    )
}

export default Login
