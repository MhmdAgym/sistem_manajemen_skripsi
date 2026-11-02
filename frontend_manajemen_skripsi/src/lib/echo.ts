import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import axios from '@/lib/axios'

declare global {
    interface Window {
        Pusher: typeof Pusher
        Echo: Echo<any> | null
    }
}

let echoInstance: Echo<any> | null = null

/**
 * Inisialisasi atau ambil instance singleton Laravel Echo untuk koneksi WebSocket Reverb.
 * Aman untuk SSR (mengembalikan null jika dipanggil di server).
 */
export function getEcho(): Echo<any> | null {
    if (typeof window === 'undefined') {
        return null
    }

    if (echoInstance) {
        return echoInstance
    }

    window.Pusher = Pusher

    const appKey = process.env.NEXT_PUBLIC_REVERB_APP_KEY || 'unuha_reverb_key'
    const host = process.env.NEXT_PUBLIC_REVERB_HOST || window.location.hostname
    const port = Number(process.env.NEXT_PUBLIC_REVERB_PORT || 8080)
    const scheme = process.env.NEXT_PUBLIC_REVERB_SCHEME || (window.location.protocol === 'https:' ? 'https' : 'http')
    const useTLS = scheme === 'https'

    try {
        echoInstance = new Echo({
            broadcaster: 'reverb',
            key: appKey,
            wsHost: host,
            wsPort: port,
            wssPort: port,
            forceTLS: useTLS,
            enabledTransports: ['ws', 'wss'],
            authorizer: (channel: { name: string }) => {
                return {
                    authorize: (socketId: string, callback: (error: any, authData: any) => void) => {
                        axios.post('/broadcasting/auth', {
                            socket_id: socketId,
                            channel_name: channel.name,
                        })
                        .then(response => {
                            callback(null, response.data)
                        })
                        .catch(error => {
                            callback(error, null)
                        })
                    },
                }
            },
        })

        window.Echo = echoInstance
        return echoInstance
    } catch (error) {
        console.error('[ECHO] Gagal menginisialisasi Laravel Echo:', error)
        return null
    }
}

/**
 * Putuskan koneksi dan bersihkan instance Laravel Echo (dipanggil saat logout).
 */
export function destroyEcho(): void {
    if (echoInstance) {
        try {
            echoInstance.disconnect()
        } catch (e) {
            console.error('[ECHO] Gagal mendiskoneksi Echo:', e)
        }
        echoInstance = null
        if (typeof window !== 'undefined') {
            window.Echo = null
        }
    }
}
