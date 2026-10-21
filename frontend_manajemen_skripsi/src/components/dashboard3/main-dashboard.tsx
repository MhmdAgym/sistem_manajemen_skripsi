"use client";

import React, { useState, useEffect } from "react";
import {
  GraduationCap,
  Clock,
  CheckCircle2,
  BookOpen,
  UserCheck,
  Loader2
} from "lucide-react";
import axios from "@/lib/axios";
import { AxiosError } from "axios";

interface DosenPAInfo {
  id_dosen?: number;
  nama_dosen?: string;
  NIDN?: string;
  user_id?: number;
}

export function MainDashboard() {
  const [dosenPA, setDosenPA] = useState<DosenPAInfo | null>(null);
  const [statusJudul, setStatusJudul] = useState<string>("Belum Mengajukan");
  const [loading, setLoading] = useState(true);
  // null = belum selesai load | false = tidak ada PA | true = ada PA
  const [dosenPaStatus, setDosenPaStatus] = useState<"loading" | "found" | "not_found" | "error">("loading");

  useEffect(() => {
    const fetchDashboardData = async () => {
      try {
        setLoading(true);
        setDosenPaStatus("loading");

        // 1. Ambil riwayat pengajuan judul — pakai axios (session cookie otomatis)
        try {
          const resJudul = await axios.get("/api/mahasiswa/pengajuan-judul");
          const pengajuanData = resJudul.data?.data;
          if (pengajuanData && pengajuanData.length > 0) {
            setStatusJudul(pengajuanData[0].status);
          }
        } catch {
          // Jika endpoint ini gagal, status tetap "Belum Mengajukan"
        }

        // 2. Ambil profil mahasiswa untuk mendapatkan Dosen PA dari relasi DB
        const resProfile = await axios.get("/api/mahasiswa/profile");
        const profileData = resProfile.data;

        // Backend mengembalikan dosen_pa sebagai objek dari formatMahasiswa()
        // Struktur: { id_dosen, nama_dosen, NIDN, user_id }
        const assignedDosen = profileData?.dosen_pa ?? null;

        if (assignedDosen && assignedDosen.nama_dosen) {
          setDosenPA(assignedDosen);
          setDosenPaStatus("found");
        } else {
          setDosenPA(null);
          setDosenPaStatus("not_found");
        }
      } catch (err) {
        const error = err as AxiosError;
        if (error.response?.status === 401 || error.response?.status === 403) {
          // Biarkan useAuth hook (Topbar) yang menangani redirect ke /login
          setDosenPaStatus("error");
        } else {
          console.error("Gagal memuat data dashboard:", err);
          setDosenPaStatus("error");
        }
      } finally {
        setLoading(false);
      }
    };

    fetchDashboardData();
  }, []);

  const stats = [
    {
      title: "Status Judul",
      count: loading ? "..." : statusJudul,
      desc: "Progres Pengajuan Judul",
      icon: CheckCircle2,
      color: "bg-emerald-50 text-emerald-600 border border-emerald-100"
    },
    { title: "Jadwal Sempro", count: "Menunggu", desc: "Penjadwalan Proposal", icon: Clock, color: "bg-teal-50 text-teal-600 border border-teal-100" },
    { title: "Ujian Sidang", count: "Belum", desc: "Syarat Belum Terpenuhi", icon: GraduationCap, color: "bg-green-50 text-green-600 border border-green-100" },
    { title: "Bimbingan", count: "0 x", desc: "Total Konsultasi", icon: BookOpen, color: "bg-emerald-100/70 text-emerald-700 border border-emerald-200" },
  ];

  const renderDosenPACard = () => {
    if (dosenPaStatus === "loading") {
      return (
        <div className="py-6 flex justify-center text-xs text-slate-400 gap-2 items-center">
          <Loader2 className="w-4 h-4 animate-spin" /> Memuat data dosen...
        </div>
      );
    }

    if (dosenPaStatus === "found" && dosenPA) {
      return (
        <div className="p-3.5 bg-emerald-50/70 rounded-2xl border border-emerald-200/80 space-y-2">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 bg-emerald-600 text-white font-bold rounded-xl flex items-center justify-center text-xs shadow-sm">
              PA
            </div>
            <div>
              <h4 className="font-bold text-xs text-slate-800">
                {dosenPA.nama_dosen || "Dosen Pembimbing"}
              </h4>
              <p className="text-[10px] text-emerald-700 font-medium">
                NIDN: {dosenPA.NIDN || "—"}
              </p>
            </div>
          </div>
          <p className="text-[11px] text-emerald-800 font-medium pt-1 border-t border-emerald-200/60">
            Status: Terhubung &amp; Aktif
          </p>
        </div>
      );
    }

    if (dosenPaStatus === "not_found") {
      return (
        <div className="p-4 bg-amber-50 rounded-2xl border border-amber-200 text-amber-800 text-xs text-center leading-relaxed">
          ⚠️ Dosen Pembimbing Akademik (PA) Anda belum ditetapkan oleh Admin. Silakan hubungi bagian akademik.
        </div>
      );
    }

    // error state — jangan tampilkan pesan PA belum ditetapkan
    return (
      <div className="p-4 bg-slate-50 rounded-2xl border border-slate-200 text-slate-500 text-xs text-center">
        Gagal memuat informasi Dosen PA. Coba muat ulang halaman.
      </div>
    );
  };

  return (
    <div className="space-y-6 font-sans text-slate-800">

      {/* WELCOME BANNER MAHASISWA */}
      <div className="p-6 bg-gradient-to-r from-emerald-700 via-emerald-600 to-teal-700 rounded-3xl text-white shadow-lg shadow-emerald-900/10 flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
        <div>
          <span className="px-3 py-1 bg-white/25 backdrop-blur-md rounded-full text-[11px] font-semibold uppercase tracking-wider text-emerald-50 border border-white/10">
            Dashboard Mahasiswa
          </span>
          <h1 className="text-2xl font-bold mt-2 tracking-tight">Selamat Datang, Mahasiswa</h1>
          <p className="text-xs text-emerald-100 mt-1 max-w-xl leading-relaxed">
            Pantau status pengajuan judul skripsi, jadwal seminar proposal (sempro), dan progres bimbingan Anda di sini.
          </p>
        </div>
      </div>

      {/* CARDS STATISTIK MAHASISWA */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {stats.map((item, idx) => {
          const Icon = item.icon;
          return (
            <div key={idx} className="bg-white p-5 rounded-2xl border border-slate-100 shadow-sm hover:border-emerald-200 transition duration-200 flex items-center justify-between">
              <div>
                <p className="text-xs text-slate-400 font-medium">{item.title}</p>
                <h3 className="text-sm font-bold text-slate-800 mt-1 truncate max-w-[150px]">{item.count}</h3>
                <p className="text-[11px] text-slate-500 mt-0.5">{item.desc}</p>
              </div>
              <div className={`p-3 rounded-2xl ${item.color}`}>
                <Icon className="w-6 h-6" />
              </div>
            </div>
          );
        })}
      </div>

      {/* GRID UTAMA MAHASISWA */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {/* RIWAYAT BIMBINGAN TERBARU */}
        <div className="lg:col-span-2 bg-white p-6 rounded-3xl border border-slate-100 shadow-sm space-y-4">
          <div className="flex items-center justify-between border-b border-slate-100 pb-3">
            <div>
              <h2 className="font-bold text-slate-800 text-sm">Riwayat Bimbingan Skripsi</h2>
              <p className="text-xs text-slate-400">Catatan konsultasi bersama dosen pembimbing</p>
            </div>
          </div>
          <div className="py-8 text-center text-xs text-slate-400">
            Belum ada riwayat bimbingan tercatat.
          </div>
        </div>

        {/* INFORMASI DOSEN PEMBIMBING AKADEMIK (PA) */}
        <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm space-y-4">
          <div className="border-b border-slate-100 pb-3">
            <h2 className="font-bold text-slate-800 text-sm flex items-center gap-1.5">
              <UserCheck className="w-4 h-4 text-emerald-600" /> Dosen Pembimbing Akademik (PA)
            </h2>
            <p className="text-xs text-slate-400">Dosen penanggung jawab verifikasi judul</p>
          </div>
          <div className="space-y-3">
            {renderDosenPACard()}
          </div>
        </div>

      </div>

    </div>
  );
}