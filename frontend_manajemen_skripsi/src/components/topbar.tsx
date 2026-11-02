"use client"

import React, { useState } from "react"
import { useRouter } from "next/navigation"
import {
  Bell,
  Settings,
  User,
  Menu,
  CheckCheck,
  Loader2,
  AlertCircle,
  Inbox,
  RefreshCw,
  ExternalLink,
} from "lucide-react"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import { Avatar, AvatarFallback } from "@/components/ui/avatar"
import { useAuth } from "@/hooks/auth"
import {
  useNotifications,
  isSafeInternalUrl,
  formatNotificationTime,
  AcademicNotificationItem,
} from "@/hooks/useNotifications"

interface TopbarProps {
  onMenuClick?: () => void
}

export function Topbar({ onMenuClick }: TopbarProps) {
  const router = useRouter()
  const { user, logout } = useAuth({ middleware: "auth" })
  const {
    notifications,
    unreadCount,
    isLoading,
    isError,
    connectionState,
    markAsRead,
    markAllRead,
    refresh,
  } = useNotifications()

  const [isMarkingAll, setIsMarkingAll] = useState(false)

  const handleNotificationClick = async (item: AcademicNotificationItem) => {
    if (!item.read_at) {
      await markAsRead(item.id)
    }
    const targetUrl = item.data?.url
    if (isSafeInternalUrl(targetUrl)) {
      router.push(targetUrl as string)
    }
  }

  const handleMarkAllRead = async (e: React.MouseEvent) => {
    e.stopPropagation()
    if (unreadCount === 0 || isMarkingAll) return
    setIsMarkingAll(true)
    try {
      await markAllRead()
    } finally {
      setIsMarkingAll(false)
    }
  }

  return (
    <header className="lg:-mx-7 sticky top-0 z-30 bg-background/80 backdrop-blur supports-[backdrop-filter]:bg-background/60 border-b border-border mb-6 rounded-xl lg:rounded-none">
      <div className="h-16 px-4 md:px-7 flex items-center justify-between gap-3">
        {/* Mobile Menu Button */}
        <button
          onClick={onMenuClick}
          className="lg:hidden rounded-full p-2 hover:bg-muted focus:outline-none focus:ring-2 focus:ring-ring"
          aria-label="Buka menu navigasi"
        >
          <Menu className="size-5" />
        </button>

        {/* Spacer Pengganti Search Bar */}
        <div className="flex-1" />

        {/* Actions Menu */}
        <div className="flex items-center gap-2">
          {/* Notifications Dropdown */}
          <DropdownMenu onOpenChange={(open) => { if (open) refresh() }}>
            <DropdownMenuTrigger
              className="relative rounded-full p-2 hover:bg-muted focus:outline-none focus:ring-2 focus:ring-ring"
              aria-label={`Notifikasi, ${unreadCount} belum dibaca`}
            >
              <Bell className="size-5" aria-hidden />
              {unreadCount > 0 && (
                <span
                  className="absolute right-1 top-1 inline-flex items-center justify-center text-[10px] bg-red-600 text-white font-semibold rounded-full h-4 min-w-4 px-1 shadow-sm animate-in fade-in zoom-in-75 duration-200"
                  aria-hidden="true"
                >
                  {unreadCount > 99 ? "99+" : unreadCount}
                </span>
              )}
            </DropdownMenuTrigger>

            <DropdownMenuContent align="end" className="w-80 sm:w-96 p-0 shadow-lg border border-border">
              {/* Header Notifikasi */}
              <div className="p-3 bg-muted/40 border-b border-border flex items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                  <span className="font-semibold text-sm text-foreground">Notifikasi</span>
                  {/* Status Koneksi WebSocket Reverb */}
                  <span
                    className={`inline-block size-2 rounded-full ${
                      connectionState === "connected"
                        ? "bg-emerald-500 ring-2 ring-emerald-500/20"
                        : connectionState === "connecting"
                        ? "bg-amber-500 animate-pulse"
                        : "bg-slate-400"
                    }`}
                    title={
                      connectionState === "connected"
                        ? "Real-time aktif (WebSocket Reverb terhubung)"
                        : connectionState === "connecting"
                        ? "Menghubungkan ke WebSocket..."
                        : "Koneksi WebSocket terputus (data disinkronkan via API)"
                    }
                  />
                </div>

                <div className="flex items-center gap-1">
                  {unreadCount > 0 && (
                    <button
                      onClick={handleMarkAllRead}
                      disabled={isMarkingAll}
                      className="text-xs text-primary hover:text-primary/80 font-medium flex items-center gap-1 px-2 py-1 rounded hover:bg-muted disabled:opacity-50 transition-colors"
                      title="Tandai semua notifikasi sudah dibaca"
                    >
                      {isMarkingAll ? (
                        <Loader2 className="size-3 animate-spin" />
                      ) : (
                        <CheckCheck className="size-3.5" />
                      )}
                      <span>Tandai Semua Dibaca</span>
                    </button>
                  )}
                  <button
                    onClick={() => refresh()}
                    className="p-1 rounded text-muted-foreground hover:text-foreground hover:bg-muted transition-colors"
                    title="Segarkan notifikasi"
                    aria-label="Segarkan notifikasi"
                  >
                    <RefreshCw className={`size-3.5 ${isLoading ? "animate-spin" : ""}`} />
                  </button>
                </div>
              </div>

              {/* Daftar Notifikasi */}
              <div className="max-h-80 overflow-y-auto divide-y divide-border/60">
                {isLoading && notifications.length === 0 ? (
                  <div className="py-8 px-4 text-center text-muted-foreground flex flex-col items-center justify-center gap-2">
                    <Loader2 className="size-6 animate-spin text-primary" />
                    <span className="text-xs">Memuat notifikasi akademik...</span>
                  </div>
                ) : isError && notifications.length === 0 ? (
                  <div className="py-8 px-4 text-center text-muted-foreground flex flex-col items-center justify-center gap-2">
                    <AlertCircle className="size-6 text-destructive" />
                    <span className="text-xs text-destructive font-medium">Gagal memuat notifikasi</span>
                    <button
                      onClick={() => refresh()}
                      className="mt-1 text-xs text-primary underline hover:text-primary/80"
                    >
                      Coba lagi
                    </button>
                  </div>
                ) : notifications.length === 0 ? (
                  <div className="py-8 px-4 text-center text-muted-foreground flex flex-col items-center justify-center gap-2">
                    <Inbox className="size-8 text-muted-foreground/50" />
                    <span className="text-xs font-medium">Tidak ada notifikasi</span>
                    <span className="text-[11px] text-muted-foreground/80">
                      Pemberitahuan akademik Anda akan muncul di sini.
                    </span>
                  </div>
                ) : (
                  notifications.map((item) => {
                    const isUnread = !item.read_at
                    const hasSafeUrl = isSafeInternalUrl(item.data?.url)

                    return (
                      <div
                        key={item.id}
                        onClick={() => handleNotificationClick(item)}
                        className={`p-3 text-left transition-colors cursor-pointer flex gap-3 items-start ${
                          isUnread
                            ? "bg-primary/[0.04] hover:bg-primary/[0.08]"
                            : "hover:bg-muted/50"
                        }`}
                        role="button"
                        tabIndex={0}
                        onKeyDown={(e) => {
                          if (e.key === "Enter" || e.key === " ") {
                            e.preventDefault()
                            handleNotificationClick(item)
                          }
                        }}
                      >
                        {/* Indikator Belum Dibaca */}
                        <div className="pt-1 shrink-0">
                          <span
                            className={`block size-2 rounded-full ${
                              isUnread ? "bg-emerald-600" : "bg-transparent"
                            }`}
                            aria-hidden="true"
                          />
                        </div>

                        <div className="flex-1 min-w-0">
                          <div className="flex items-start justify-between gap-1">
                            <h4
                              className={`text-xs ${
                                isUnread ? "font-semibold text-foreground" : "font-medium text-foreground/80"
                              } line-clamp-1`}
                            >
                              {item.data?.title || "Pemberitahuan Akademik"}
                            </h4>
                            <span className="text-[10px] text-muted-foreground shrink-0 whitespace-nowrap">
                              {formatNotificationTime(item.created_at)}
                            </span>
                          </div>

                          <p className="text-xs text-muted-foreground line-clamp-2 mt-0.5">
                            {item.data?.message || "-"}
                          </p>

                          {hasSafeUrl && (
                            <span className="inline-flex items-center gap-1 text-[11px] text-primary mt-1 font-medium hover:underline">
                              <span>Buka tautan</span>
                              <ExternalLink className="size-2.5" />
                            </span>
                          )}
                        </div>
                      </div>
                    )
                  })
                )}
              </div>
            </DropdownMenuContent>
          </DropdownMenu>

          {/* Settings */}
          <DropdownMenu>
            <DropdownMenuTrigger className="rounded-full p-2 hover:bg-muted focus:outline-none focus:ring-2 focus:ring-ring">
              <Settings className="size-5" aria-hidden />
              <span className="sr-only">Buka pengaturan</span>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-56">
              <DropdownMenuLabel>Pengaturan</DropdownMenuLabel>
              <DropdownMenuSeparator />
              <DropdownMenuItem asChild>
                <button className="w-full text-left">Kelola Dosen & Kuota</button>
              </DropdownMenuItem>
              <DropdownMenuItem asChild>
                <button className="w-full text-left">Pengaturan Periode</button>
              </DropdownMenuItem>
              <DropdownMenuSeparator />
              <div className="px-2 py-1.5" />
            </DropdownMenuContent>
          </DropdownMenu>

          {/* User Profile */}
          <DropdownMenu>
            <DropdownMenuTrigger className="rounded-full p-1.5 hover:bg-muted focus:outline-none focus:ring-2 focus:ring-ring">
              <Avatar className="size-8">
                <AvatarFallback className="bg-[#15803D] text-white font-bold text-xs">
                  {user?.name ? user.name.substring(0, 2).toUpperCase() : "ADM"}
                </AvatarFallback>
              </Avatar>
              <span className="sr-only">Buka menu profil</span>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-56">
              <DropdownMenuLabel className="flex items-center gap-2">
                <User className="size-4" />
                <span className="truncate">{user?.name || "Pengguna"}</span>
              </DropdownMenuLabel>
              <DropdownMenuSeparator />
              <DropdownMenuItem asChild>
                <a href="/profile">Profil Akun</a>
              </DropdownMenuItem>
              <DropdownMenuSeparator />
              <DropdownMenuItem className="text-destructive cursor-pointer" onClick={logout}>
                Keluar
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </div>
    </header>
  )
}