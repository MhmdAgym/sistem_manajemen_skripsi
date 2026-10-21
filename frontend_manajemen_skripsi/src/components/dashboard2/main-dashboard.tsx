"use client";

import React from "react";
import { 
  Users, 
  FileCheck, 
  Calendar, 
  ArrowUpRight,
  Award
} from "lucide-react";

export function MainDashboard() {
  const stats = [
    { title: "Bimbingan Aktif", count: "8", desc: "Mahasiswa Bimbingan", icon: Users, color: "bg-emerald-50 text-emerald-600 border border-emerald-100" },
    { title: "Sempro Diuji", count: "3", desc: "Jadwal Proposal", icon: Calendar, color: "bg-teal-50 text-teal-600 border border-teal-100" },
    { title: "Sidang Diuji", count: "2", desc: "Ujian Skripsi Akhir", icon: FileCheck, color: "bg-green-50 text-green-600 border border-green-100" },
    { title: "Kuota Tersisa", count: "2 / 10", desc: "Kapasitas Bimbingan", icon: Award, color: "bg-emerald-100/70 text-emerald-700 border border-emerald-200" },
  ];

  const mahasiswaBimbingan = [
    { id: "1", nama: "Ahmad Fauzi", nim: "210401001", judul: "Rancang Bangun Sistem Informasi Manajemen Skripsi", status: "Revisi Bab 2", tanggal: "Hari ini" },
    { id: "2", nama: "Siti Nurhaliza", nim: "210401015", judul: "Penerapan Algoritma C4.5 Untuk Prediksi Kelulusan", status: "ACC Bab 3", tanggal: "Kemarin" },
    { id: "3", nama: "Budi Santoso", nim: "210401005", judul: "Sistem Pendukung Keputusan Pemilihan Dosen Terbaik", status: "Pengajuan Outline", tanggal: "05 Okt" },
  ];

  return (
    <div className="space-y-6 font-sans text-slate-800">
      
      {/* WELCOME BANNER DOSEN */}
      <div className="p-6 bg-gradient-to-r from-emerald-700 via-emerald-600 to-teal-700 rounded-3xl text-white shadow-lg shadow-emerald-900/10 flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
        <div>
          <span className="px-3 py-1 bg-white/25 backdrop-blur-md rounded-full text-[11px] font-semibold uppercase tracking-wider text-emerald-50 border border-white/10">
            Dashboard Dosen
          </span>
          <h1 className="text-2xl font-bold mt-2 tracking-tight">Selamat Datang, Dosen Pembimbing / Penguji</h1>
          <p className="text-xs text-emerald-100 mt-1 max-w-xl leading-relaxed">
            Kelola bimbingan mahasiswa, verifikasi progres laporan skripsi, serta pantau jadwal seminar dan ujian sidang Anda.
          </p>
        </div>
      </div>

      {/* CARDS STATISTIK DOSEN */}
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

      {/* GRID UTAMA DOSEN */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        {/* DAFTAR MAHASISWA BIMBINGAN TERBARU */}
        <div className="lg:col-span-2 bg-white p-6 rounded-3xl border border-slate-100 shadow-sm space-y-4">
          <div className="flex items-center justify-between border-b border-slate-100 pb-3">
            <div>
              <h2 className="font-bold text-slate-800 text-sm">Daftar Mahasiswa Bimbingan</h2>
              <p className="text-xs text-slate-400">Mahasiswa bimbingan aktif yang memerlukan review atau respon</p>
            </div>
            <button className="text-xs text-emerald-600 hover:text-emerald-700 font-semibold flex items-center gap-0.5 transition">
              Lihat Semua <ArrowUpRight className="w-3.5 h-3.5" />
            </button>
          </div>

          <div className="divide-y divide-slate-100">
            {mahasiswaBimbingan.map((item) => (
              <div key={item.id} className="py-3 flex flex-col sm:flex-row sm:items-center justify-between gap-2 hover:bg-emerald-50/30 rounded-xl px-2 transition">
                <div className="space-y-0.5">
                  <div className="flex items-center gap-2">
                    <span className="font-bold text-xs text-slate-800">{item.nama}</span>
                    <span className="text-[10px] text-slate-400">NIM: {item.nim}</span>
                  </div>
                  <p className="text-xs text-slate-600 line-clamp-1">{item.judul}</p>
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

        {/* JADWAL UJIAN / SEMPRO MENDEDATANG */}
        <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm space-y-4">
          <div className="border-b border-slate-100 pb-3">
            <h2 className="font-bold text-slate-800 text-sm">Jadwal Tugas Penguji</h2>
            <p className="text-xs text-slate-400">Agenda seminar & sidang terdekat</p>
          </div>

          <div className="space-y-3">
            <div className="p-3 bg-slate-50/80 rounded-2xl border border-slate-100 space-y-1.5">
              <div className="flex items-center justify-between">
                <span className="text-[10px] font-bold px-2 py-0.5 bg-teal-100 text-teal-800 rounded-md">Sempro</span>
                <span className="text-[10px] text-slate-400">14 Okt 2026</span>
              </div>
              <h4 className="font-semibold text-xs text-slate-800">Seminar Proposal - Rian Utama</h4>
              <p className="text-[10px] text-slate-500">Ruang Sidang 1 • Pukul 09.00 WIB</p>
            </div>

            <div className="p-3 bg-slate-50/80 rounded-2xl border border-slate-100 space-y-1.5">
              <div className="flex items-center justify-between">
                <span className="text-[10px] font-bold px-2 py-0.5 bg-emerald-100 text-emerald-800 rounded-md">Sidang Meja Hijau</span>
                <span className="text-[10px] text-slate-400">18 Okt 2026</span>
              </div>
              <h4 className="font-semibold text-xs text-slate-800">Ujian Skripsi - Diana Putri</h4>
              <p className="text-[10px] text-slate-500">Ruang Sidang Utama • Pukul 13.00 WIB</p>
            </div>
          </div>
        </div>

      </div>

    </div>
  );
}