"use client";

import React from "react";
import {
  FileText,
  Users,
  Calendar,
  GraduationCap,
  CheckCircle2,
  XCircle,
  Clock,
  ChevronRight,
} from "lucide-react";

export function MainDashboard() {
  return (
    <div className="p-6 space-y-6 bg-slate-50/50 min-h-screen font-sans text-slate-800">
      
      {/* 1. HERO BANNER & STATS COUNTER */}
      <div className="bg-gradient-to-r from-purple-600 via-indigo-600 to-purple-700 rounded-3xl p-6 lg:p-8 text-white shadow-xl shadow-purple-200 flex flex-col lg:flex-row items-center justify-between gap-6">
        <div className="space-y-2 max-w-xl text-center lg:text-left">
          <span className="inline-block px-3 py-1 bg-white/20 backdrop-blur-md text-xs font-medium rounded-full text-purple-100">
            Sistem Manajemen Skripsi V2.0
          </span>
          <h1 className="text-2xl lg:text-3xl font-bold tracking-tight">
            Selamat Datang, Admin!
          </h1>
          <p className="text-purple-100 text-xs lg:text-sm leading-relaxed">
            Kelola verifikasi judul, plot dosen pembimbing, verifikasi berkas, hingga penjadwalan sempro & ujian skripsi dalam satu dashboard terpadu.
          </p>
        </div>

        {/* RINGKASAN METRIK AKADEMIK */}
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 w-full lg:w-auto">
          {/* Card Stat 1 */}
          <div className="bg-white/10 backdrop-blur-md rounded-2xl p-4 text-center border border-white/10 shadow-inner">
            <div className="flex justify-center mb-1.5 text-purple-200">
              <FileText className="w-5 h-5" />
            </div>
            <span className="block text-2xl font-bold">12</span>
            <span className="text-[11px] text-purple-200 font-medium">Judul Pending</span>
          </div>

          {/* Card Stat 2 */}
          <div className="bg-white/10 backdrop-blur-md rounded-2xl p-4 text-center border border-white/10 shadow-inner">
            <div className="flex justify-center mb-1.5 text-purple-200">
              <Users className="w-5 h-5" />
            </div>
            <span className="block text-2xl font-bold">28</span>
            <span className="text-[11px] text-purple-200 font-medium">Dosen Pembimbing</span>
          </div>

          {/* Card Stat 3 */}
          <div className="bg-white/10 backdrop-blur-md rounded-2xl p-4 text-center border border-white/10 shadow-inner">
            <div className="flex justify-center mb-1.5 text-purple-200">
              <Calendar className="w-5 h-5" />
            </div>
            <span className="block text-2xl font-bold">8</span>
            <span className="text-[11px] text-purple-200 font-medium">Sempro Bulan Ini</span>
          </div>

          {/* Card Stat 4 */}
          <div className="bg-white/10 backdrop-blur-md rounded-2xl p-4 text-center border border-white/10 shadow-inner">
            <div className="flex justify-center mb-1.5 text-purple-200">
              <GraduationCap className="w-5 h-5" />
            </div>
            <span className="block text-2xl font-bold">5</span>
            <span className="text-[11px] text-purple-200 font-medium">Ujian Skripsi</span>
          </div>
        </div>
      </div>

      {/* 2. VERIFIKASI JUDUL TERBARU (ANTEAN PRIORITAS ADMIN) */}
      <div className="bg-white rounded-3xl p-6 shadow-sm border border-slate-100 space-y-4">
        <div className="flex items-center justify-between">
          <div>
            <h2 className="text-base lg:text-lg font-bold text-slate-800">
              Verifikasi Judul Baru (Antrean Prioritas)
            </h2>
            <p className="text-xs text-slate-500">
              Pengajuan judul dari mahasiswa yang membutuhkan persetujuan & penetapan dosen pembimbing.
            </p>
          </div>
          <button className="text-xs font-semibold text-purple-600 hover:text-purple-700 flex items-center gap-1 transition">
            Lihat Semua <ChevronRight className="w-4 h-4" />
          </button>
        </div>

        {/* LIST DAFTAR PENGAJUAN */}
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          
          {/* ITEM PENGAJUAN 1 */}
          <div className="p-4 rounded-2xl border border-slate-100 bg-slate-50/50 hover:bg-white hover:border-purple-200 hover:shadow-md transition duration-200 flex flex-col justify-between space-y-3">
            <div>
              <div className="flex items-center justify-between">
                <span className="text-[11px] font-semibold px-2.5 py-1 bg-amber-100 text-amber-700 rounded-full flex items-center gap-1">
                  <Clock className="w-3 h-3" /> Menunggu Verifikasi
                </span>
                <span className="text-[11px] text-slate-400">10 Menit lalu</span>
              </div>
              <h3 className="font-semibold text-slate-800 mt-3 text-sm leading-snug">
                Rancang Bangun Sistem Informasi Manajemen Skripsi Berbasis Web
              </h3>
              <p className="text-xs text-slate-500 mt-1">
                Mahasiswa: <span className="font-medium text-slate-700">Ahmad Fauzi</span> (NIM: 210401001)
              </p>
            </div>
            <div className="flex items-center gap-2 pt-3 border-t border-slate-200/60">
              <button className="flex-1 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-xl text-xs font-medium flex items-center justify-center gap-1.5 shadow-sm transition">
                <CheckCircle2 className="w-3.5 h-3.5" /> Verifikasi & Plotting Dospem
              </button>
              <button className="px-3.5 py-2 bg-rose-50 hover:bg-rose-100 text-rose-600 rounded-xl text-xs font-medium flex items-center justify-center gap-1 transition">
                <XCircle className="w-3.5 h-3.5" /> Tolak
              </button>
            </div>
          </div>

          {/* ITEM PENGAJUAN 2 */}
          <div className="p-4 rounded-2xl border border-slate-100 bg-slate-50/50 hover:bg-white hover:border-purple-200 hover:shadow-md transition duration-200 flex flex-col justify-between space-y-3">
            <div>
              <div className="flex items-center justify-between">
                <span className="text-[11px] font-semibold px-2.5 py-1 bg-amber-100 text-amber-700 rounded-full flex items-center gap-1">
                  <Clock className="w-3 h-3" /> Menunggu Verifikasi
                </span>
                <span className="text-[11px] text-slate-400">1 Jam lalu</span>
              </div>
              <h3 className="font-semibold text-slate-800 mt-3 text-sm leading-snug">
                Penerapan Algoritma C4.5 Untuk Prediksi Kelulusan Tepat Waktu Mahasiswa
              </h3>
              <p className="text-xs text-slate-500 mt-1">
                Mahasiswa: <span className="font-medium text-slate-700">Siti Nurhaliza</span> (NIM: 210401015)
              </p>
            </div>
            <div className="flex items-center gap-2 pt-3 border-t border-slate-200/60">
              <button className="flex-1 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-xl text-xs font-medium flex items-center justify-center gap-1.5 shadow-sm transition">
                <CheckCircle2 className="w-3.5 h-3.5" /> Verifikasi & Plotting Dospem
              </button>
              <button className="px-3.5 py-2 bg-rose-50 hover:bg-rose-100 text-rose-600 rounded-xl text-xs font-medium flex items-center justify-center gap-1 transition">
                <XCircle className="w-3.5 h-3.5" /> Tolak
              </button>
            </div>
          </div>

        </div>
      </div>

      {/* 3. SECTION DUA KOLOM: JADWAL AGENDA TERDEKAT & MONITOR KUOTA DOSEN */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        {/* KOLOM KIRI: JADWAL SEMPRO & UJIAN SKRIPSI MENDATANG */}
        <div className="lg:col-span-2 bg-white rounded-3xl p-6 shadow-sm border border-slate-100 space-y-4">
          <div className="flex items-center justify-between">
            <div>
              <h2 className="text-base lg:text-lg font-bold text-slate-800">
                Jadwal Agenda Terdekat
              </h2>
              <p className="text-xs text-slate-500">
                Seminar proposal dan ujian sidang skripsi yang terjadwal minggu ini.
              </p>
            </div>
            <span className="text-xs bg-purple-50 text-purple-600 font-semibold px-3 py-1 rounded-full border border-purple-100">
              Minggu Ini
            </span>
          </div>

          <div className="space-y-3">
            {/* ITEM JADWAL 1 */}
            <div className="flex items-center justify-between p-4 rounded-2xl border border-slate-100 hover:border-purple-200 transition bg-slate-50/30">
              <div className="flex items-center gap-3">
                <div className="w-10 h-10 rounded-2xl bg-purple-100 text-purple-700 flex items-center justify-center font-bold text-[11px]">
                  SEMPRO
                </div>
                <div>
                  <h4 className="text-sm font-semibold text-slate-800">Budi Santoso</h4>
                  <p className="text-xs text-slate-500">
                    Penguji: <span className="font-medium text-slate-700">Dr. Eng. Supriyadi, M.T.</span>
                  </p>
                </div>
              </div>
              <div className="text-right">
                <div className="text-xs font-semibold text-slate-700 flex items-center gap-1 justify-end">
                  <Clock className="w-3.5 h-3.5 text-purple-600" /> 09:00 WIB
                </div>
                <span className="text-[11px] text-slate-400 block mt-0.5">Ruang 302 (Lantai 3)</span>
              </div>
            </div>

            {/* ITEM JADWAL 2 */}
            <div className="flex items-center justify-between p-4 rounded-2xl border border-slate-100 hover:border-purple-200 transition bg-slate-50/30">
              <div className="flex items-center gap-3">
                <div className="w-10 h-10 rounded-2xl bg-indigo-100 text-indigo-700 flex items-center justify-center font-bold text-[11px]">
                  SIDANG
                </div>
                <div>
                  <h4 className="text-sm font-semibold text-slate-800">Dewi Rahmawati</h4>
                  <p className="text-xs text-slate-500">
                    Penguji: <span className="font-medium text-slate-700">Prof. Dr. Hendra, M.Kom.</span>
                  </p>
                </div>
              </div>
              <div className="text-right">
                <div className="text-xs font-semibold text-slate-700 flex items-center gap-1 justify-end">
                  <Clock className="w-3.5 h-3.5 text-indigo-600" /> 13:00 WIB
                </div>
                <span className="text-[11px] text-slate-400 block mt-0.5">Ruang Sidang Utama</span>
              </div>
            </div>
          </div>
        </div>

        {/* KOLOM KANAN: STATUS KUOTA DOSEN PEMBIMBING */}
        <div className="bg-white rounded-3xl p-6 shadow-sm border border-slate-100 space-y-4">
          <div>
            <h2 className="text-base lg:text-lg font-bold text-slate-800">
              Kuota Dosen Pembimbing
            </h2>
            <p className="text-xs text-slate-500">
              Beban bimbingan dosen aktif saat ini.
            </p>
          </div>

          <div className="space-y-4">
            {/* DOSEN 1 */}
            <div className="space-y-1.5">
              <div className="flex justify-between text-xs">
                <span className="font-semibold text-slate-700">Dr. Ir. Bambang, M.T.</span>
                <span className="text-purple-600 font-semibold">8 / 10 Mahasiswa</span>
              </div>
              <div className="w-full bg-slate-100 h-2 rounded-full overflow-hidden">
                <div className="bg-purple-600 h-full rounded-full transition-all duration-500" style={{ width: "80%" }}></div>
              </div>
            </div>

            {/* DOSEN 2 */}
            <div className="space-y-1.5">
              <div className="flex justify-between text-xs">
                <span className="font-semibold text-slate-700">Anita R., S.T., M.Kom.</span>
                <span className="text-rose-500 font-semibold">10 / 10 (Penuh)</span>
              </div>
              <div className="w-full bg-slate-100 h-2 rounded-full overflow-hidden">
                <div className="bg-rose-500 h-full rounded-full transition-all duration-500" style={{ width: "100%" }}></div>
              </div>
            </div>

            {/* DOSEN 3 */}
            <div className="space-y-1.5">
              <div className="flex justify-between text-xs">
                <span className="font-semibold text-slate-700">Rian Hidayat, M.Sc.</span>
                <span className="text-emerald-600 font-semibold">4 / 10 Mahasiswa</span>
              </div>
              <div className="w-full bg-slate-100 h-2 rounded-full overflow-hidden">
                <div className="bg-emerald-500 h-full rounded-full transition-all duration-500" style={{ width: "40%" }}></div>
              </div>
            </div>
          </div>
        </div>

      </div>

    </div>
  );
}