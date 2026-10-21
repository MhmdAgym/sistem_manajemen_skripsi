'use client'

import InputError from '@/components/InputError'
import AuthSessionStatus from '@/app/(auth)/AuthSessionStatus'
import { useAuth } from '@/hooks/auth'
import { useEffect, useState } from 'react'
import { useSearchParams } from 'next/navigation'
import Image from 'next/image'
import Link from 'next/link'
import { Eye, EyeOff, Loader2 } from 'lucide-react'

const PasswordReset = () => {
    const searchParams = useSearchParams()

    const { resetPassword } = useAuth({ middleware: 'guest' })

    const [email, setEmail] = useState('')
    const [password, setPassword] = useState('')
    const [passwordConfirmation, setPasswordConfirmation] = useState('')
    const [errors, setErrors] = useState([])
    const [status, setStatus] = useState(null)
    const [showPassword, setShowPassword] = useState(false)
    const [showConfirmPassword, setShowConfirmPassword] = useState(false)
    const [isLoading, setIsLoading] = useState(false)

    const submitForm = event => {
        event.preventDefault()
        setIsLoading(true)

        resetPassword({
            email,
            password,
            password_confirmation: passwordConfirmation,
            setErrors,
            setStatus,
        })

        setTimeout(() => setIsLoading(false), 3000)
    }

    useEffect(() => {
        setEmail(searchParams.get('email') || '')
    }, [searchParams])

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
                <div className="absolute inset-0 bg-black/30" />
            </div>

            {/* Reset Password Card */}
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
                            Buat Password Baru
                        </h1>
                        <p className="text-sm text-gray-500 mt-1 text-center">
                            Silakan masukkan password baru untuk akun Anda
                        </p>
                    </div>

                    {/* Session Status */}
                    <AuthSessionStatus className="mb-4" status={status} />

                    <form onSubmit={submitForm} noValidate>
                        {/* Email Address */}
                        <div className="mb-4">
                            <label
                                htmlFor="email"
                                className="block text-sm font-medium text-gray-700 mb-1">
                                Email
                            </label>
                            <input
                                id="email"
                                type="email"
                                value={email}
                                onChange={event => setEmail(event.target.value)}
                                required
                                disabled={isLoading}
                                placeholder="Masukkan email Anda"
                                className="w-full rounded-lg border border-gray-300 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 placeholder-gray-400 shadow-sm focus:border-green-500 focus:ring-2 focus:ring-green-200 focus:outline-none transition disabled:opacity-60 disabled:cursor-not-allowed"
                            />
                            <InputError messages={errors.email} className="mt-1.5" />
                        </div>

                        {/* Password Baru */}
                        <div className="mb-4">
                            <label
                                htmlFor="password"
                                className="block text-sm font-medium text-gray-700 mb-1">
                                Password Baru
                            </label>
                            <div className="relative">
                                <input
                                    id="password"
                                    type={showPassword ? 'text' : 'password'}
                                    value={password}
                                    onChange={event => setPassword(event.target.value)}
                                    required
                                    autoFocus
                                    disabled={isLoading}
                                    placeholder="Masukkan password baru"
                                    className="w-full rounded-lg border border-gray-300 px-4 py-2.5 pr-11 text-sm text-gray-900 placeholder-gray-400 shadow-sm focus:border-green-500 focus:ring-2 focus:ring-green-200 focus:outline-none transition disabled:opacity-60 disabled:cursor-not-allowed"
                                />
                                <button
                                    type="button"
                                    onClick={() => setShowPassword(prev => !prev)}
                                    className="absolute inset-y-0 right-0 flex items-center px-3 text-gray-400 hover:text-gray-600 transition"
                                    tabIndex={-1}
                                    aria-label={showPassword ? 'Sembunyikan password' : 'Tampilkan password'}>
                                    {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                                </button>
                            </div>
                            <InputError messages={errors.password} className="mt-1.5" />
                        </div>

                        {/* Konfirmasi Password Baru */}
                        <div className="mb-6">
                            <label
                                htmlFor="passwordConfirmation"
                                className="block text-sm font-medium text-gray-700 mb-1">
                                Konfirmasi Password Baru
                            </label>
                            <div className="relative">
                                <input
                                    id="passwordConfirmation"
                                    type={showConfirmPassword ? 'text' : 'password'}
                                    value={passwordConfirmation}
                                    onChange={event => setPasswordConfirmation(event.target.value)}
                                    required
                                    disabled={isLoading}
                                    placeholder="Ulangi password baru"
                                    className="w-full rounded-lg border border-gray-300 px-4 py-2.5 pr-11 text-sm text-gray-900 placeholder-gray-400 shadow-sm focus:border-green-500 focus:ring-2 focus:ring-green-200 focus:outline-none transition disabled:opacity-60 disabled:cursor-not-allowed"
                                />
                                <button
                                    type="button"
                                    onClick={() => setShowConfirmPassword(prev => !prev)}
                                    className="absolute inset-y-0 right-0 flex items-center px-3 text-gray-400 hover:text-gray-600 transition"
                                    tabIndex={-1}
                                    aria-label={showConfirmPassword ? 'Sembunyikan password' : 'Tampilkan password'}>
                                    {showConfirmPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                                </button>
                            </div>
                            <InputError messages={errors.password_confirmation} className="mt-1.5" />
                        </div>

                        {/* Tombol Simpan Password */}
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
                                'Simpan Password Baru'
                            )}
                        </button>

                        <div className="text-center mt-5">
                            <Link
                                href="/login"
                                className="text-sm text-green-700 hover:text-green-900 hover:underline transition font-medium">
                                &larr; Kembali ke halaman login
                            </Link>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    )
}

export default PasswordReset