import { Nunito } from 'next/font/google'
import { ThemeProvider } from '@/components/theme-provider'
import '@/app/global.css'

const nunitoFont = Nunito({
    subsets: ['latin'],
    display: 'swap',
})

const RootLayout = ({ children }) => {
    return (
        <html lang="en" className={nunitoFont.className}>
            <body className="antialiased">
                <ThemeProvider
                    attribute="class"
                    defaultTheme="light"
                    enableSystem
                    disableTransitionOnChange
                >
                    {children}
                </ThemeProvider>
            </body>
        </html>
    )
}

export const metadata = {
    title: 'Sistem Manajemen Skripsi',
    description: 'Sistem Manajemen Skripsi',
}

export default RootLayout