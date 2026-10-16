"use client"

import React from "react"
import { Bell, Settings, User, Menu } from "lucide-react"
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

interface TopbarProps {
  onMenuClick?: () => void
}

export function Topbar({ onMenuClick }: TopbarProps) {
  const { user, logout } = useAuth({ middleware: "auth" })

  return (
    <header className="lg:-mx-7 sticky top-0 z-30 bg-background/80 backdrop-blur supports-[backdrop-filter]:bg-background/60 border-b border-border mb-6 rounded-xl lg:rounded-none">
      <div className="h-16 px-4 md:px-7 flex items-center justify-between gap-3">
        {/* Mobile Menu Button */}
        <button
          onClick={onMenuClick}
          className="lg:hidden rounded-full p-2 hover:bg-muted focus:outline-none focus:ring-2"
          aria-label="Open menu"
        >
          <Menu className="size-5" />
        </button>

        {/* Spacer Pengganti Search Bar */}
        <div className="flex-1" />

        {/* Actions Menu */}
        <div className="flex items-center gap-2">
          {/* Notifications */}
          <DropdownMenu>
            <DropdownMenuTrigger className="relative rounded-full p-2 hover:bg-muted focus:outline-none focus:ring-2">
              <Bell className="size-5" aria-hidden />
              <span className="sr-only">Open notifications</span>
              {/* Badge Notifikasi Warna Merah */}
              <span className="absolute right-1 top-1 inline-flex items-center justify-center text-[10px] bg-red-600 text-white font-semibold rounded-full h-4 min-w-4 px-1 shadow-sm">
                3
              </span>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-64">
              <DropdownMenuLabel>Notifikasi</DropdownMenuLabel>
              <DropdownMenuSeparator />
              <DropdownMenuItem>Judul baru diajukan oleh Ahmad</DropdownMenuItem>
              <DropdownMenuItem>Jadwal Sempro Budi dikonfirmasi</DropdownMenuItem>
              <DropdownMenuItem>3 Berkas butuh verifikasi</DropdownMenuItem>
              <DropdownMenuSeparator />
              <DropdownMenuItem className="text-muted-foreground">Lihat semua</DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>

          {/* Settings */}
          <DropdownMenu>
            <DropdownMenuTrigger className="rounded-full p-2 hover:bg-muted focus:outline-none focus:ring-2">
              <Settings className="size-5" aria-hidden />
              <span className="sr-only">Open settings</span>
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
              <div className="px-2 py-1.5">
              </div>
            </DropdownMenuContent>
          </DropdownMenu>

          {/* User Profile */}
          <DropdownMenu>
            <DropdownMenuTrigger className="rounded-full p-1.5 hover:bg-muted focus:outline-none focus:ring-2">
              <Avatar className="size-8">
                <AvatarFallback className="bg-[#15803D] text-white font-bold text-xs">
                  {user?.name ? user.name.substring(0, 2).toUpperCase() : "ADM"}
                </AvatarFallback>
              </Avatar>
              <span className="sr-only">Open user menu</span>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-56">
              <DropdownMenuLabel className="flex items-center gap-2">
                <User className="size-4" />
                {user?.name || "Admin Prodi"}
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