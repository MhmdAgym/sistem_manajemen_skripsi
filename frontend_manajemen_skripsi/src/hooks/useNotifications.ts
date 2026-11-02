import useSWR from 'swr'
import { useEffect, useCallback, useState } from 'react'
import axios from '@/lib/axios'
import { getEcho } from '@/lib/echo'
import { useAuth } from '@/hooks/auth'

export interface NotificationData {
    event_identity: string
    event_type: string
    title: string
    message: string
    url?: string
    metadata?: Record<string, any>
}

export interface AcademicNotificationItem {
    id: string
    type?: string
    notifiable_type?: string
    notifiable_id?: number
    data: NotificationData
    read_at: string | null
    created_at: string
    updated_at?: string
}

export function isSafeInternalUrl(url: string | undefined | null): boolean {
    if (!url || typeof url !== 'string') return false
    const trimmed = url.trim()
    if (!trimmed.startsWith('/')) return false
    if (trimmed.startsWith('//') || trimmed.startsWith('/\\')) return false
    if (trimmed.includes('://')) return false
    return true
}

export function formatNotificationTime(dateString: string | null | undefined): string {
    if (!dateString) return ''
    try {
        const date = new Date(dateString)
        if (isNaN(date.getTime())) return ''

        const now = new Date()
        const diffInSeconds = Math.floor((now.getTime() - date.getTime()) / 1000)

        if (diffInSeconds < 60) return 'Baru saja'
        if (diffInSeconds < 3600) return `${Math.floor(diffInSeconds / 60)} mnt lalu`
        if (diffInSeconds < 86400) return `${Math.floor(diffInSeconds / 3600)} jam lalu`
        if (diffInSeconds < 172800) return 'Kemarin'

        return date.toLocaleDateString('id-ID', {
            day: 'numeric',
            month: 'short',
        })
    } catch {
        return ''
    }
}

export function useNotifications() {
    const { user } = useAuth({ middleware: 'auth' })
    const [connectionState, setConnectionState] = useState<'connected' | 'connecting' | 'disconnected'>('disconnected')

    // 1. Fetch Notifikasi List
    const {
        data: listResponse,
        error: listError,
        mutate: mutateList,
    } = useSWR(
        user ? '/api/notifications' : null,
        () => axios.get('/api/notifications?per_page=15').then(res => res.data),
        {
            revalidateOnFocus: false,
            shouldRetryOnError: false,
        }
    )

    // 2. Fetch Unread Count
    const {
        data: unreadResponse,
        error: unreadError,
        mutate: mutateUnread,
    } = useSWR(
        user ? '/api/notifications/unread-count' : null,
        () => axios.get('/api/notifications/unread-count').then(res => res.data),
        {
            revalidateOnFocus: false,
            shouldRetryOnError: false,
        }
    )

    const isListLoading = Boolean(user && !listResponse && !listError)
    const isUnreadLoading = Boolean(user && !unreadResponse && !unreadError)

    const notifications: AcademicNotificationItem[] = listResponse?.data || []
    const unreadCount: number = unreadResponse?.data?.unread_count ?? 0

    // 3. Mark Single as Read
    const markAsRead = useCallback(async (id: string) => {
        try {
            // Optimistic update
            await mutateList((current: any) => {
                if (!current?.data) return current
                return {
                    ...current,
                    data: current.data.map((item: AcademicNotificationItem) =>
                        item.id === id ? { ...item, read_at: new Date().toISOString() } : item
                    ),
                }
            }, false)

            await mutateUnread((current: any) => {
                if (!current?.data) return current
                return {
                    ...current,
                    data: {
                        unread_count: Math.max(0, (current.data.unread_count || 1) - 1),
                    },
                }
            }, false)

            await axios.patch(`/api/notifications/${id}/read`)
            await mutateList()
            await mutateUnread()
        } catch (error) {
            console.error('[NOTIF] Gagal menandai notifikasi dibaca:', error)
            await mutateList()
            await mutateUnread()
        }
    }, [mutateList, mutateUnread])

    // 4. Mark All as Read
    const markAllRead = useCallback(async () => {
        try {
            // Optimistic update
            await mutateList((current: any) => {
                if (!current?.data) return current
                return {
                    ...current,
                    data: current.data.map((item: AcademicNotificationItem) => ({
                        ...item,
                        read_at: item.read_at || new Date().toISOString(),
                    })),
                }
            }, false)

            await mutateUnread({
                status: 'success',
                data: { unread_count: 0 },
            }, false)

            await axios.patch('/api/notifications/mark-all-read')
            await mutateList()
            await mutateUnread()
        } catch (error) {
            console.error('[NOTIF] Gagal menandai semua dibaca:', error)
            await mutateList()
            await mutateUnread()
        }
    }, [mutateList, mutateUnread])

    // 5. Setup Real-time Private Channel Listener
    useEffect(() => {
        if (!user?.id) {
            return
        }

        const echo = getEcho()
        if (!echo) {
            return
        }

        const channelName = `users.${user.id}.notifications`
        const privateChannel = echo.private(channelName)

        // Update connection state
        if ((echo.connector as any)?.pusher?.connection) {
            const pusherConnection = (echo.connector as any).pusher.connection
            setConnectionState(pusherConnection.state === 'connected' ? 'connected' : 'connecting')

            pusherConnection.bind('connected', () => setConnectionState('connected'))
            pusherConnection.bind('connecting', () => setConnectionState('connecting'))
            pusherConnection.bind('disconnected', () => setConnectionState('disconnected'))
            pusherConnection.bind('failed', () => setConnectionState('disconnected'))
        }

        const handleBroadcast = (incoming: any) => {
            // Sync with backend API source of truth
            mutateList()
            mutateUnread()
        }

        // Listen on custom event name from AcademicNotificationBroadcasted::broadcastAs()
        privateChannel.listen('.academic.notification', handleBroadcast)
        privateChannel.listen('academic.notification', handleBroadcast)

        return () => {
            privateChannel.stopListening('.academic.notification')
            privateChannel.stopListening('academic.notification')
            echo.leave(channelName)
        }
    }, [user?.id, mutateList, mutateUnread])

    return {
        notifications,
        unreadCount,
        isLoading: isListLoading || isUnreadLoading,
        isError: !!listError || !!unreadError,
        connectionState,
        markAsRead,
        markAllRead,
        refresh: () => {
            mutateList()
            mutateUnread()
        },
    }
}
