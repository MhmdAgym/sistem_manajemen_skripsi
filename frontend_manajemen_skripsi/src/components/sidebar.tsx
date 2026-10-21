"use client";

import React, { useState } from "react";
import Link from "next/link";
import Image from "next/image";
import { usePathname } from "next/navigation";
import { useAuth } from "@/hooks/auth";
import {
  LayoutDashboard,
  Users,
  UserCheck,
  FileCheck,
  GraduationCap,
  FileText,
  BookOpen,
  ClipboardList,
  ChevronLeft,
  ChevronRight,
  X,
  ShieldCheck
} from "lucide-react";

interface SidebarProps {
  isOpen?: boolean; // Tambahan prop untuk sinkronisasi mobile
  onClose?: () => void;
}

export function Sidebar({ onClose }: SidebarProps) {
  const pathname = usePathname();
  const [isCollapsed, setIsCollapsed] = useState(false);

  const { user } = useAuth({ middleware: "auth" });
  const role = user?.role;

  const getMenuItems = () => {
    switch (role) {
      case "admin":
        return [
          { title: "Dashboard", href: "/dashboardadmin", icon: LayoutDashboard },
          { title: "Data Mahasiswa", href: "/dashboardadmin/data_mahasiswa", icon: Users },
          { title: "Data Dosen", href: "/dashboardadmin/data_dosen", icon: UserCheck },
          { title: "Manajemen Akun", href: "/dashboardadmin/manajemen_akun", icon: ShieldCheck },
          { title: "Manajemen Sempro", href: "/dashboardadmin/manajemen_sempro", icon: FileCheck },
          { title: "Manajemen Ujian", href: "/dashboardadmin/manajemen_ujian", icon: GraduationCap },
          { title: "Berita Acara", href: "/dashboardadmin/berita_acara", icon: FileText },
        ];
      case "dosen":
        return [
          { title: "Dashboard Dosen", href: "/dashboarddosen", icon: LayoutDashboard },
          { title: "Pembimbing Akademik", href: "/dashboarddosen/pembimbing_akademik", icon: Users },
          { title: "Pembimbing Skripsi", href: "/dashboarddosen/pembimbing_skripsi", icon: ClipboardList },
          { title: "Penguji", href: "/dashboarddosen/penguji", icon: FileCheck },
        ];
      case "mahasiswa":
        return [
          { title: "Dashboard Mahasiswa", href: "/dashboardmahasiswa", icon: LayoutDashboard },
          { title: "Pengajuan Judul", href: "/dashboardmahasiswa/pengajuan_judul", icon: BookOpen },
          { title: "Bimbingan Saya", href: "/dashboardmahasiswa/bimbingan", icon: ClipboardList },
          { title: "Seminar Proposal", href: "/dashboardmahasiswa/sempro", icon: FileCheck },
          { title: "Ujian Skripsi", href: "/dashboardmahasiswa/ujian_skripsi", icon: GraduationCap },
        ];
      default:
        return [];
    }
  };

  const menuItems = getMenuItems();

  return (
    <aside
      className={`h-full bg-[#15803D] text-white p-4 flex flex-col justify-between transition-all duration-300 ease-in-out shrink-0 ${isCollapsed ? "lg:w-20" : "lg:w-72"
        } w-72 lg:rounded-3xl my-0 shadow-xl border-r border-emerald-600/30`}
    >
      <div className="space-y-6">
        {/* Header Logo, Title & Tombol Toggle */}
        <div className={`flex items-center pt-2 ${isCollapsed ? "justify-center px-0" : "justify-between px-1"}`}>
          <div className={`flex items-center gap-3 ${isCollapsed ? "justify-center w-full" : ""}`}>
            <div className="w-10 h-10 rounded-2xl bg-white/15 border border-white/20 flex items-center justify-center p-1.5 shadow-inner shrink-0">
              <Image
                src="/images/logo-unuha.webp"
                alt="Logo UNUHA"
                width={32}
                height={32}
                className="w-full h-full object-contain"
                priority
              />
            </div>

            {!isCollapsed && (
              <div className="transition-opacity duration-200 min-w-0 flex-1">
                <h2 className="font-bold text-sm tracking-tight text-white leading-tight truncate">
                  Manajemen Skripsi
                </h2>
                <p className="text-[10px] text-emerald-100/90 font-medium uppercase truncate">
                  {role === "admin" && "Universitas Nurul Huda"}
                  {role === "dosen" && "Universitas Nurul Huda"}
                  {role === "mahasiswa" && "Universitas Nurul Huda"}
                  {!role && "UNUHA Portal"}
                </p>
              </div>
            )}
          </div>

          {!isCollapsed && (
            <button
              onClick={() => setIsCollapsed(!isCollapsed)}
              className="hidden lg:flex p-1.5 rounded-lg text-emerald-100 hover:bg-white/10 transition shrink-0"
              title="Perkecil Sidebar"
            >
              <ChevronLeft className="w-5 h-5" />
            </button>
          )}

          {/* Tombol Close di Mobile */}
          <button
            onClick={onClose}
            className="lg:hidden p-1.5 rounded-lg text-emerald-100 hover:bg-white/10 transition shrink-0"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {isCollapsed && (
          <div className="hidden lg:flex justify-center pt-2">
            <button
              onClick={() => setIsCollapsed(false)}
              className="p-1.5 rounded-lg text-emerald-100 hover:bg-white/10 transition"
              title="Perbesar Sidebar"
            >
              <ChevronRight className="w-5 h-5" />
            </button>
          </div>
        )}

        {/* Navigation Links */}
        <nav className="space-y-1.5 pt-4">
          {menuItems.map((item) => {
            const Icon = item.icon;
            const isActive = pathname === item.href;

            return (
              <Link
                key={item.href}
                href={item.href}
                onClick={onClose}
                title={isCollapsed ? item.title : undefined}
                className={`flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-xs font-semibold transition-all duration-200 ${isActive
                  ? "bg-white text-[#15803D] shadow-md"
                  : "text-emerald-50 hover:bg-white/15 hover:text-white"
                  } ${isCollapsed ? "justify-center px-0" : ""}`}
              >
                <Icon className={`w-4 h-4 shrink-0 ${isActive ? "text-[#15803D]" : "text-emerald-100"}`} />
                {!isCollapsed && <span className="whitespace-nowrap truncate">{item.title}</span>}
              </Link>
            );
          })}
        </nav>
      </div>

      {/* Footer Sidebar */}
      {!isCollapsed && (
        <div className="p-3 bg-white/10 border border-white/15 rounded-2xl space-y-1 transition-all duration-200">
          <p className="text-[11px] font-bold text-white whitespace-nowrap">Sistem Skripsi v1.0</p>
          <p className="text-[10px] text-emerald-100/80 whitespace-nowrap">Universitas Nurul Huda</p>
        </div>
      )}
    </aside>
  );
}