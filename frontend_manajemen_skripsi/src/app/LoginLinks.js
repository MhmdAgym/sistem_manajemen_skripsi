'use client'

import Link from 'next/link'
import { useAuth } from '@/hooks/auth'

const LoginLinks = () => {
    // Hilangkan 'middleware: 'guest'' agar tidak memaksa redirect dan aman mengecek user
    const { user } = useAuth()

    return (
        <div className="hidden fixed top-0 right-0 px-6 py-4 sm:block">
            {user ? (
                <Link
                    href="/dashboard"
                    className="ml-4 text-sm text-gray-700 dark:text-gray-300 underline font-semibold"
                >
                    Dashboard ({user.name})
                </Link>
            ) : (
                <>
                    <Link
                        href="/login"
                        className="text-sm text-gray-700 dark:text-gray-300 underline font-semibold"
                    >
                        Login
                    </Link>

                    <Link
                        href="/register"
                        className="ml-4 text-sm text-gray-700 dark:text-gray-300 underline font-semibold"
                    >
                        Register
                    </Link>
                </>
            )}
        </div>
    )
}

export default LoginLinks