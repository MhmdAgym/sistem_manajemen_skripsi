"use client";

import React from "react";
import { GraduationCap, Calendar } from "lucide-react";

export default function ManajemenUjianPage() {
  return (
    <div className="space-y-6 font-sans text-slate-800">
      <div className="p-6 bg-gradient-to-r from-emerald-700 via-emerald-600 to-teal-700 rounded-3xl text-white shadow-lg">
        <h1 className="text-2xl font-bold tracking-tight">Manajemen Ujian Skripsi</h1>
        <p className="text-xs text-emerald-100 mt-1">Pengaturan sidang meja hijau, rekapitulasi nilai, dan penentuan penguji akhir.</p>
      </div>

      <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm space-y-4">
        <h2 className="font-bold text-slate-800 text-sm flex items-center gap-2">
          <GraduationCap className="w-4 h-4 text-emerald-600" /> Jadwal Sidang Komprehensif
        </h2>
        <p className="text-xs text-slate-500">Panel kontrol penjadwalan ujian sidang skripsi akhir mahasiswa.</p>
      </div>
    </div>
  );
}