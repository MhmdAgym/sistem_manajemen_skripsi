'use client'

import InputError from '@/components/InputError'
import { useAuth } from '@/hooks/auth'
import { useState } from 'react'
import Image from 'next/image'
import Link from 'next/link'
import { Eye, EyeOff, Loader2 } from 'lucide-react'

const daftarProdi = {
    'Fakultas Agama Islam': [
        'Pendidikan Agama Islam',
        'Pendidikan Guru Madrasah Ibtidaiyah',
        'Pendidikan Bahasa Arab',
    ],
    'Fakultas Ilmu Pendidikan': [
        'Pendidikan Fisika',
        'Pendidikan Ekonomi',
        'Pendidikan Bahasa Inggris',
        'Pendidikan Bahasa dan Sastra Indonesia',
        'Pendidikan Teknologi Informasi',
    ],
    'Fakultas Sains dan Teknologi': [
        'Informatika',
        'Matematika',
        'Sains Pertanian',
    ],
}

const Page = () => {
    const { register } = useAuth({
        middleware: 'guest',
        redirectIfAuthenticated: '/dashboard',
    })

    const [name, setName] = useState('')
    const [email, setEmail] = useState('')
    const [role, setRole] = useState('mahasiswa')
    const [nomorInduk, setNomorInduk] = useState('')
    const [noHandphone, setNoHandphone] = useState('')
    const [jenisKelamin, setJenisKelamin] = useState('')
    const [fakultas, setFakultas] = useState('')
    const [prodi, setProdi] = useState('')
    const [password, setPassword] = useState('')
    const [passwordConfirmation, setPasswordConfirmation] = useState('')
    const [errors, setErrors] = useState([])
    const [isLoading, setIsLoading] = useState(false)
    const [showPassword, setShowPassword] = useState(false)
    const [showPasswordConfirmation, setShowPasswordConfirmation] = useState(false)

    const submitForm = async event => {
        event.preventDefault()
        setIsLoading(true)

        try {
            await register({
                name,
                email,
                role,
                nomor_induk: nomorInduk,
                no_handphone: noHandphone,
                jenis_kelamin: jenisKelamin,
                ...(role === 'mahasiswa' ? { fakultas, prodi } : {}),
                password,
                password_confirmation: passwordConfirmation,
                setErrors,
            })
        } finally {
            setIsLoading(false)
        }
    }

    const handleRoleChange = event => {
        const selectedRole = event.target.value
        setRole(selectedRole)
        setNomorInduk('')
        setErrors([])
    }

    const handleFakultasChange = event => {
        setFakultas(event.target.value)
        setProdi('')
    }

    const inputClasses =
        'w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm text-gray-900 placeholder-gray-400 shadow-sm focus:border-green-500 focus:ring-2 focus:ring-green-200 focus:outline-none transition disabled:opacity-60 disabled:cursor-not-allowed bg-white'

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
                {/* Overlay gelap agar form tetap mudah dibaca */}
                <div className="absolute inset-0 bg-black/30" />
            </div>

            {/* Register Card */}
            <div className="relative z-10 w-full max-w-2xl my-4">
                <div className="bg-white/95 backdrop-blur-sm rounded-2xl shadow-2xl px-6 py-8 sm:px-8 sm:py-9">

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
                        <h1 className="text-xl font-bold text-gray-800 text-center leading-tight">
                            Sistem Manajemen Skripsi
                        </h1>
                        <p className="text-sm text-gray-500 mt-1 text-center">
                            Daftar akun baru sebagai Mahasiswa atau Dosen
                        </p>
                    </div>

                    <form onSubmit={submitForm} noValidate>
                        <InputError messages={errors.general} className="mb-4" />

                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">

                            {/* Role (Daftar sebagai) */}
                            <div>
                                <label
                                    htmlFor="role"
                                    className="block text-sm font-medium text-gray-700 mb-1">
                                    Daftar sebagai
                                </label>
                                <select
                                    id="role"
                                    value={role}
                                    onChange={handleRoleChange}
                                    disabled={isLoading}
                                    className={inputClasses}
                                    required>
                                    <option value="mahasiswa">Mahasiswa</option>
                                    <option value="dosen">Dosen</option>
                                </select>
                                <InputError messages={errors.role} className="mt-1.5" />
                            </div>

                            {/* Nomor Induk (NIM / NIDN) */}
                            <div>
                                <label
                                    htmlFor="nomor_induk"
                                    className="block text-sm font-medium text-gray-700 mb-1">
                                    {role === 'mahasiswa' ? 'NIM' : 'NIDN'}
                                </label>
                                <input
                                    id="nomor_induk"
                                    type="text"
                                    inputMode="numeric"
                                    maxLength={10}
                                    value={nomorInduk}
                                    onChange={event => {
                                        setNomorInduk(
                                            event.target.value.replace(/\D/g, '').slice(0, 10),
                                        )
                                    }}
                                    disabled={isLoading}
                                    placeholder={
                                        role === 'mahasiswa'
                                            ? 'Masukkan 10 digit NIM'
                                            : 'Masukkan NIDN'
                                    }
                                    className={inputClasses}
                                    required
                                />
                                <InputError messages={errors.nomor_induk} className="mt-1.5" />
                                <p className="mt-1 text-xs text-gray-500">
                                    Maksimal 10 digit angka.
                                </p>
                            </div>

                            {/* Nama Lengkap */}
                            <div>
                                <label
                                    htmlFor="name"
                                    className="block text-sm font-medium text-gray-700 mb-1">
                                    Nama lengkap
                                </label>
                                <input
                                    id="name"
                                    type="text"
                                    value={name}
                                    onChange={event => setName(event.target.value)}
                                    disabled={isLoading}
                                    placeholder="Masukkan nama lengkap"
                                    className={inputClasses}
                                    required
                                />
                                <InputError messages={errors.name} className="mt-1.5" />
                            </div>

                            {/* Email */}
                            <div>
                                <label
                                    htmlFor="email"
                                    className="block text-sm font-medium text-gray-700 mb-1">
                                    Alamat email
                                </label>
                                <input
                                    id="email"
                                    type="email"
                                    value={email}
                                    onChange={event => setEmail(event.target.value)}
                                    disabled={isLoading}
                                    placeholder="nama@student.unuha.ac.id"
                                    className={inputClasses}
                                    required
                                />
                                <InputError messages={errors.email} className="mt-1.5" />
                            </div>

                            {/* Nomor Handphone */}
                            <div>
                                <label
                                    htmlFor="no_handphone"
                                    className="block text-sm font-medium text-gray-700 mb-1">
                                    Nomor handphone
                                </label>
                                <input
                                    id="no_handphone"
                                    type="tel"
                                    value={noHandphone}
                                    onChange={event => setNoHandphone(event.target.value)}
                                    disabled={isLoading}
                                    placeholder="Contoh: 08123456789"
                                    className={inputClasses}
                                    required
                                />
                                <InputError messages={errors.no_handphone} className="mt-1.5" />
                            </div>

                            {/* Jenis Kelamin */}
                            <div>
                                <label
                                    htmlFor="jenis_kelamin"
                                    className="block text-sm font-medium text-gray-700 mb-1">
                                    Jenis kelamin
                                </label>
                                <select
                                    id="jenis_kelamin"
                                    value={jenisKelamin}
                                    onChange={event => setJenisKelamin(event.target.value)}
                                    disabled={isLoading}
                                    className={inputClasses}
                                    required>
                                    <option value="">Pilih jenis kelamin</option>
                                    <option value="Laki-laki">Laki-laki</option>
                                    <option value="Perempuan">Perempuan</option>
                                </select>
                                <InputError messages={errors.jenis_kelamin} className="mt-1.5" />
                            </div>

                            {/* Khusus Mahasiswa: Fakultas & Program Studi */}
                            {role === 'mahasiswa' && (
                                <>
                                    <div>
                                        <label
                                            htmlFor="fakultas"
                                            className="block text-sm font-medium text-gray-700 mb-1">
                                            Fakultas
                                        </label>
                                        <select
                                            id="fakultas"
                                            value={fakultas}
                                            onChange={handleFakultasChange}
                                            disabled={isLoading}
                                            className={inputClasses}
                                            required>
                                            <option value="">Pilih fakultas</option>
                                            {Object.keys(daftarProdi).map(namaFakultas => (
                                                <option key={namaFakultas} value={namaFakultas}>
                                                    {namaFakultas}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError messages={errors.fakultas} className="mt-1.5" />
                                    </div>

                                    <div>
                                        <label
                                            htmlFor="prodi"
                                            className="block text-sm font-medium text-gray-700 mb-1">
                                            Program studi
                                        </label>
                                        <select
                                            id="prodi"
                                            value={prodi}
                                            onChange={event => setProdi(event.target.value)}
                                            disabled={isLoading || !fakultas}
                                            className={inputClasses}
                                            required>
                                            <option value="">
                                                {fakultas
                                                    ? 'Pilih program studi'
                                                    : 'Pilih fakultas terlebih dahulu'}
                                            </option>
                                            {(daftarProdi[fakultas] || []).map(namaProdi => (
                                                <option key={namaProdi} value={namaProdi}>
                                                    {namaProdi}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError messages={errors.prodi} className="mt-1.5" />
                                    </div>
                                </>
                            )}

                            {/* Password */}
                            <div>
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
                                        disabled={isLoading}
                                        autoComplete="new-password"
                                        placeholder="Minimal 8 karakter"
                                        className={`${inputClasses} pr-11`}
                                        required
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setShowPassword(prev => !prev)}
                                        className="absolute inset-y-0 right-0 flex items-center px-3 text-gray-400 hover:text-gray-600 transition"
                                        tabIndex={-1}
                                        aria-label={
                                            showPassword
                                                ? 'Sembunyikan password'
                                                : 'Tampilkan password'
                                        }>
                                        {showPassword ? (
                                            <EyeOff className="w-4 h-4" />
                                        ) : (
                                            <Eye className="w-4 h-4" />
                                        )}
                                    </button>
                                </div>
                                <InputError messages={errors.password} className="mt-1.5" />
                            </div>

                            {/* Konfirmasi Password */}
                            <div>
                                <label
                                    htmlFor="password_confirmation"
                                    className="block text-sm font-medium text-gray-700 mb-1">
                                    Konfirmasi password
                                </label>
                                <div className="relative">
                                    <input
                                        id="password_confirmation"
                                        type={showPasswordConfirmation ? 'text' : 'password'}
                                        value={passwordConfirmation}
                                        onChange={event =>
                                            setPasswordConfirmation(event.target.value)
                                        }
                                        disabled={isLoading}
                                        autoComplete="new-password"
                                        placeholder="Ulangi password"
                                        className={`${inputClasses} pr-11`}
                                        required
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setShowPasswordConfirmation(prev => !prev)}
                                        className="absolute inset-y-0 right-0 flex items-center px-3 text-gray-400 hover:text-gray-600 transition"
                                        tabIndex={-1}
                                        aria-label={
                                            showPasswordConfirmation
                                                ? 'Sembunyikan konfirmasi password'
                                                : 'Tampilkan konfirmasi password'
                                        }>
                                        {showPasswordConfirmation ? (
                                            <EyeOff className="w-4 h-4" />
                                        ) : (
                                            <Eye className="w-4 h-4" />
                                        )}
                                    </button>
                                </div>
                                <InputError
                                    messages={errors.password_confirmation}
                                    className="mt-1.5"
                                />
                            </div>

                        </div>

                        {/* Tombol Submit */}
                        <div className="mt-6">
                            <button
                                type="submit"
                                disabled={isLoading}
                                className="w-full flex items-center justify-center gap-2 rounded-lg bg-green-700 hover:bg-green-800 active:bg-green-900 disabled:opacity-60 disabled:cursor-not-allowed text-white font-semibold py-2.5 text-sm tracking-wide shadow-md transition-all duration-150 focus:outline-none focus:ring-2 focus:ring-green-400">
                                {isLoading ? (
                                    <>
                                        <Loader2 className="w-4 h-4 animate-spin" />
                                        Mendaftarkan...
                                    </>
                                ) : (
                                    'Daftar'
                                )}
                            </button>
                        </div>

                        {/* Link ke Login */}
                        <p className="mt-6 text-center text-sm text-gray-500">
                            Sudah memiliki akun?{' '}
                            <Link
                                href="/login"
                                className="font-semibold text-green-700 hover:text-green-900 hover:underline transition">
                                Masuk di sini
                            </Link>
                        </p>
                    </form>

                </div>
            </div>
        </div>
    )
}

export default Page
