"use client";

import React from "react";
import { 
  FileText, 
  GraduationCap, 
  ArrowUpRight,
  Award,
  Calendar
} from "lucide-react";

export function MainDashboard() {
  const stats = [
    { title: "Judul Masuk", count: "24", desc: "Menunggu Verifikasi", icon: FileText, color: "bg-emerald-50 text-[#15803D] border border-emerald-100" },
    { title: "Siap Sempro", count: "12", desc: "Penjadwalan Proposal", icon: Calendar, color: "bg-emerald-50 text-[#15803D] border border-emerald-100" },
    { title: "Siap Sidang", count: "8", desc: "Ujian Skripsi", icon: GraduationCap, color: "bg-emerald-50 text-[#15803D] border border-emerald-100" },
    { title: "Total Lulus", count: "156", desc: "Tahun Akademik Ini", icon: Award, color: "bg-emerald-100 text-[#15803D] border border-emerald-200" },
  ];

  const pengajuanTerbaru = [
    { id: "1", nama: "Ahmad Fauzi", nim: "210401001", judul: "Rancang Bangun Sistem Informasi Manajemen Skripsi", status: "Verifikasi Judul", tanggal: "Hari ini" },
    { id: "2", nama: "Siti Nurhaliza", nim: "210401015", judul: "Penerapan Algoritma C4.5 Untuk Prediksi Kelulusan", status: "Plotting Dospem", tanggal: "Kemarin" },
    { id: "3", nama: "Budi Santoso", nim: "210401005", judul: "Sistem Pendukung Keputusan Pemilihan Dosen Terbaik", status: "Pendaftaran Sempro", tanggal: "05 Okt" },
    { id: "4", nama: "Dewi Rahmawati", nim: "210401002", judul: "Penerapan Deep Learning Untuk Klasifikasi Citra Medis", status: "Siap Ujian Sidang", tanggal: "04 Okt" },
  ];

  return (
    <div className="space-y-6 font-sans text-slate-800">
      {/* WELCOME BANNER ADMIN */}
      <div className="p-6 bg-[#15803D] rounded-3xl text-white shadow-md flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
        <div>
          <span className="px-3 py-1 bg-white/20 backdrop-blur-md rounded-full text-[11px] font-semibold uppercase tracking-wider text-emerald-50 border border-white/10">
            Dashboard Admin Prodi
          </span>
          <h1 className="text-2xl font-bold mt-2 tracking-tight">Selamat Datang di Portal Skripsi</h1>
          <p className="text-xs text-emerald-100 mt-1 max-w-xl leading-relaxed">
            Pantau alur pendaftaran, verifikasi usulan judul, plot dosen pembimbing & penguji, hingga berita acara kelulusan mahasiswa.
          </p>
        </div>
      </div>

      {/* CARDS STATISTIK UTAMA */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {stats.map((item, idx) => {
          const Icon = item.icon;
          return (
            <div key={idx} className="bg-white p-5 rounded-2xl border border-slate-100 shadow-sm hover:border-emerald-200 transition duration-200 flex items-center justify-between">
              <div>
                <p className="text-xs text-slate-400 font-medium">{item.title}</p>
                <h3 className="text-2xl font-bold text-slate-800 mt-1">{item.count}</h3>
                <p className="text-[11px] text-slate-500 mt-0.5">{item.desc}</p>
              </div>
              <div className={`p-3 rounded-2xl ${item.color}`}>
                <Icon className="w-6 h-6" />
              </div>
            </div>
          );
        })}
      </div>

      {/* GRID UTAMA: DASHBOARD CONTENT */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div className="lg:col-span-2 bg-white p-6 rounded-3xl border border-slate-100 shadow-sm space-y-4">
          <div className="flex items-center justify-between border-b border-slate-100 pb-3">
            <div>
              <h2 className="font-bold text-slate-800 text-sm">Pengajuan Skripsi Terbaru</h2>
              <p className="text-xs text-slate-400">Daftar mahasiswa yang membutuhkan respon atau verifikasi admin</p>
            </div>
            <button className="text-xs text-[#15803D] hover:text-emerald-800 font-semibold flex items-center gap-0.5 transition">
              Lihat Semua <ArrowUpRight className="w-3.5 h-3.5" />
            </button>
          </div>

          <div className="divide-y divide-slate-100">
            {pengajuanTerbaru.map((item) => (
              <div key={item.id} className="py-3 flex flex-col sm:flex-row sm:items-center justify-between gap-2 hover:bg-emerald-50/40 rounded-xl px-2 transition">
                <div className="space-y-0.5">
                  <div className="flex items-center gap-2">
                    <span className="font-bold text-xs text-slate-800">{item.nama}</span>
                    <span className="text-[10px] text-slate-400">NIM: {item.nim}</span>
                  </div>
                  <p className="text-xs text-slate-600 line-clamp-1">{item.judul}</p>
                </div>
                <div className="flex items-center justify-between sm:justify-end gap-3 min-w-max">
                  <span className="px-2.5 py-1 bg-emerald-50 text-[#15803D] font-semibold border border-emerald-200/60 rounded-full text-[10px]">
                    {item.status}
                  </span>
                  <span className="text-[10px] text-slate-400">{item.tanggal}</span>
                </div>
              </div>
            ))}
          </div>
        </div>

        {/* STATUS KUOTA DOSEN */}
        <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm space-y-4">
          <div className="border-b border-slate-100 pb-3">
            <h2 className="font-bold text-slate-800 text-sm">Status Kuota Dosen</h2>
            <p className="text-xs text-slate-400">Ringkasan beban bimbingan dosen prodi</p>
          </div>

          <div className="space-y-3">
            <div className="p-3 bg-slate-50/80 rounded-2xl flex items-center justify-between border border-slate-100">
              <div className="flex items-center gap-3">
                <div className="w-9 h-9 bg-emerald-100 text-[#15803D] font-bold rounded-xl flex items-center justify-center text-xs">DB</div>
                <div>
                  <h4 className="font-semibold text-xs text-slate-800">Dr. Ir. Bambang, M.T.</h4>
                  <p className="text-[10px] text-slate-400">Kuota Bimbingan: 8/10</p>
                </div>
              </div>
              <span className="text-xs font-bold text-[#15803D]">80%</span>
            </div>

            <div className="p-3 bg-slate-50/80 rounded-2xl flex items-center justify-between border border-slate-100">
              <div className="flex items-center gap-3">
                <div className="w-9 h-9 bg-emerald-100 text-[#15803D] font-bold rounded-xl flex items-center justify-center text-xs">AR</div>
                <div>
                  <h4 className="font-semibold text-xs text-slate-800">Anita R., S.T., M.Kom.</h4>
                  <p className="text-[10px] text-slate-400">Kuota Bimbingan: 5/10</p>
                </div>
              </div>
              <span className="text-xs font-bold text-[#15803D]">50%</span>
            </div>

            <div className="p-3 bg-slate-50/80 rounded-2xl flex items-center justify-between border border-slate-100">
              <div className="flex items-center gap-3">
                <div className="w-9 h-9 bg-rose-100 text-rose-700 font-bold rounded-xl flex items-center justify-center text-xs">RH</div>
                <div>
                  <h4 className="font-semibold text-xs text-slate-800">Rian Hidayat, M.Sc.</h4>
                  <p className="text-[10px] text-slate-400">Kuota Bimbingan: 10/10</p>
                </div>
              </div>
              <span className="text-xs font-bold text-rose-500">Penuh</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}