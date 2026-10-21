const AuthSessionStatus = ({ status, className, ...props }) => {
    // Kamus terjemahan pesan bawaan Laravel ke Bahasa Indonesia
    const translateStatus = (text) => {
        if (!text) return null

        const translations = {
            'We have emailed your password reset link.':
                'Kami telah mengirimkan tautan reset password ke email Anda.',
            'Your password has been reset.':
                'Password Anda telah berhasil diubah. Silakan masuk kembali.',
        }

        return translations[text] || text
    }

    return (
        <>
            {status && (
                <div
                    className={`${className} font-medium text-sm text-green-600`}
                    {...props}>
                    {translateStatus(status)}
                </div>
            )}
        </>
    )
}

export default AuthSessionStatus