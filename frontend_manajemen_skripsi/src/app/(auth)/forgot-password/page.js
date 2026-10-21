'use client'

import Button from '@/components/Button'
import Input from '@/components/Input'
import InputError from '@/components/InputError'
import Label from '@/components/Label'
import { useAuth } from '@/hooks/auth'
import { useState } from 'react'
import AuthSessionStatus from '@/app/(auth)/AuthSessionStatus'
import Image from 'next/image'
import Link from 'next/link'
import { Loader2 } from 'lucide-react'

const Page = () => {
    const { forgotPassword } = useAuth({
        middleware: 'guest',
        redirectIfAuthenticated: '/dashboard',
    })

    const [email, setEmail] = useState('')
    const [errors, setErrors] = useState([])
    const [status, setStatus] = useState(null)
    const [isLoading, setIsLoading] = useState(false)

    const submitForm = async event => {
        event.preventDefault()
        setIsLoading(true)

        forgotPassword({
            email,
            setErrors,
            setStatus,
            // Matikan loading setelah beberapa detik jika proses selesai/gagal
            onComplete: () => setIsLoading(false)
        })

        // Fallback jaga-jaga jika hook tidak memanggil onComplete
        setTimeout(() => setIsLoading(false), 3000)
    }

    return (
        <div className="relative min-h-screen flex items-center justify-center px-4 py-10 overflow-hidden">
            {/* Background Image yang sama seperti Login */}
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

            {/* Card Container */}
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
                            Reset Password
                        </h1>
                        <p className="text-sm text-gray-500 mt-1 text-center">
                            Sistem Manajemen Skripsi
                        </p>
                    </div>

                    <div className="mb-4 text-sm text-gray-600 text-center">
                        Lupa password? Masukkan email, NIM, atau NIDN Anda, dan kami akan mengirimkan tautan untuk mereset password Anda.
                    </div>

                    {/* Session Status */}
                    <AuthSessionStatus className="mb-4" status={status} />

                    <form onSubmit={submitForm} noValidate>
                        {/* Email / NIM / NIDN Address */}
                        <div className="mb-4">
                            <Label htmlFor="email">Email / NIM / NIDN</Label>
                            <Input
                                id="email"
                                type="text"
                                name="email"
                                value={email}
                                className="block mt-1 w-full"
                                onChange={event => setEmail(event.target.value)}
                                placeholder="Masukkan email, NIM, atau NIDN"
                                required
                                autoFocus
                                disabled={isLoading}
                            />
                            <InputError messages={errors.email} className="mt-1.5" />
                        </div>

                        <div className="flex flex-col gap-3 mt-6">
                            <Button
                                disabled={isLoading}
                                className="w-full flex items-center justify-center gap-2 rounded-lg bg-green-700 hover:bg-green-800 active:bg-green-900 text-white font-semibold py-2.5 text-sm tracking-wide shadow-md transition-all duration-150">
                                {isLoading ? (
                                    <>
                                        <Loader2 className="w-4 h-4 animate-spin" />
                                        Memproses...
                                    </>
                                ) : (
                                    'Kirim Tautan Reset Password'
                                )}
                            </Button>

                            <div className="text-center mt-2">
                                <Link
                                    href="/login"
                                    className="text-sm text-green-700 hover:text-green-900 hover:underline transition font-medium">
                                    &larr; Kembali ke halaman login
                                </Link>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    )
}

export default Page