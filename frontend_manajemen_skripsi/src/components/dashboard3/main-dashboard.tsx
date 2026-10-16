"use client";

import React from "react";
import { 
  FileText, 
  GraduationCap, 
  ArrowUpRight,
  Clock,
  CheckCircle2,
  BookOpen
} from "lucide-react";

export function MainDashboard() {
  const stats = [
    { title: "Status Judul", count: "Diverifikasi", desc: "Judul Skripsi Disetujui", icon: CheckCircle2, color: "bg-emerald-50 text-emerald-600 border border-emerald-100" },
    { title: "Jadwal Sempro", count: "Menunggu", desc: "Penjadwalan Proposal", icon: Clock, color: "bg-teal-50 text-teal-600 border border-teal-100" },
    { title: "Ujian Sidang", count: "Belum", desc: "Syarat Belum Terpenuhi", icon: GraduationCap, color: "bg-green-50 text-green-600 border border-green-100" },
    { title: "Bimbingan", count: "6 x", desc: "Total Konsultasi Dospem", icon: BookOpen, color: "bg-emerald-100/70 text-emerald-700 border border-emerald-200" },
  ];

  const riwayatBimbingan = [
    { id: "1", pembimbing: "Dr. Ir. Bambang, M.T.", topik: "Revisi Bab 1 & Perumusan Masalah", status: "Disetujui", tanggal: "10 Okt 2026" },
    { id: "2", pembimbing: "Dr. Ir. Bambang, M.T.", topik: "Konsultasi Judul dan Outline", status: "Disetujui", tanggal: "02 Okt 2026" },
  ];

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
                <h3 className="text-xl font-bold text-slate-800 mt-1">{item.count}</h3>
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
            <button className="text-xs text-emerald-600 hover:text-emerald-700 font-semibold flex items-center gap-0.5 transition">
              Detail Bimbingan <ArrowUpRight className="w-3.5 h-3.5" />
            </button>
          </div>

          <div className="divide-y divide-slate-100">
            {riwayatBimbingan.map((item) => (
              <div key={item.id} className="py-3 flex flex-col sm:flex-row sm:items-center justify-between gap-2 hover:bg-emerald-50/30 rounded-xl px-2 transition">
                <div className="space-y-0.5">
                  <div className="flex items-center gap-2">
                    <span className="font-bold text-xs text-slate-800">{item.pembimbing}</span>
                  </div>
                  <p className="text-xs text-slate-600 line-clamp-1">{item.topik}</p>
                </div>
                <div className="flex items-center justify-between sm:justify-end gap-3 min-w-max">
                  <span className="px-2.5 py-1 bg-emerald-50 text-emerald-700 font-semibold border border-emerald-200/50 rounded-full text-[10px]">
                    {item.status}
                  </span>
                  <span className="text-[10px] text-slate-400">{item.tanggal}</span>
                </div>
              </div>
            ))}
          </div>
        </div>

        {/* INFORMASI DOSEN PEMBIMBING */}
        <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm space-y-4">
          <div className="border-b border-slate-100 pb-3">
            <h2 className="font-bold text-slate-800 text-sm">Dosen Pembimbing Anda</h2>
            <p className="text-xs text-slate-400">Informasi pembimbing skripsi terplot</p>
          </div>

          <div className="space-y-3">
            <div className="p-3 bg-slate-50/80 rounded-2xl border border-slate-100 space-y-2">
              <div className="flex items-center gap-3">
                <div className="w-10 h-10 bg-emerald-100 text-emerald-800 font-bold rounded-xl flex items-center justify-center text-xs">
                  PB1
                </div>
                <div>
                  <h4 className="font-semibold text-xs text-slate-800">Dr. Ir. Bambang, M.T.</h4>
                  <p className="text-[10px] text-emerald-700 font-medium">Pembimbing Utama</p>
                </div>
              </div>
              <p className="text-[11px] text-slate-500 pt-1 border-t border-slate-200/60">
                Status: Aktif Membimbing
              </p>
            </div>
          </div>
        </div>

      </div>

    </div>
  );
}