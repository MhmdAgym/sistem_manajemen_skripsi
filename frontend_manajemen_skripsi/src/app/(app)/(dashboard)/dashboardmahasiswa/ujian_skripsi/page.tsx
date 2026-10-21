"use client";

import React from "react";
import { GraduationCap, CheckCircle } from "lucide-react";

export default function UjianPage() {
  return (
    <div className="space-y-6 font-sans text-slate-800">
      <div className="p-6 bg-gradient-to-r from-emerald-700 via-emerald-600 to-teal-700 rounded-3xl text-white shadow-lg">
        <h1 className="text-2xl font-bold tracking-tight">Ujian Skripsi (Sidang Meja Hijau)</h1>
        <p className="text-xs text-emerald-100 mt-1">
          Pendaftaran dan informasi hasil ujian komprehensif / sidang skripsi akhir[cite: 10].
        </p>
      </div>

      <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm space-y-4">
        <h2 className="font-bold text-slate-800 text-sm flex items-center gap-2">
          <GraduationCap className="w-4 h-4 text-emerald-600" /> Status Kelayakan Sidang
        </h2>
        <div className="p-4 bg-emerald-50 border border-emerald-200 rounded-2xl flex items-center gap-3">
          <CheckCircle className="w-6 h-6 text-emerald-600 shrink-0" />
          <div>
            <p className="font-bold text-xs text-emerald-900">Semua Tahapan Sempro Selesai</p>
            <p className="text-[11px] text-emerald-700">Anda memenuhi syarat untuk mendaftarkan jadwal ujian sidang skripsi[cite: 10].</p>
          </div>
        </div>

        <button className="px-5 py-2.5 bg-[#15803D] hover:bg-emerald-700 text-white font-bold text-xs rounded-xl shadow-md transition">
          Daftar Ujian Sidang Skripsi
        </button>
      </div>
    </div>
  );
}