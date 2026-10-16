"use client";

import React from "react";
import { FileCheck, Calendar, Award } from "lucide-react";

export default function SemproPage() {
  return (
    <div className="space-y-6 font-sans text-slate-800">
      <div className="p-6 bg-gradient-to-r from-emerald-700 via-emerald-600 to-teal-700 rounded-3xl text-white shadow-lg">
        <h1 className="text-2xl font-bold tracking-tight">Seminar Proposal (Sempro)</h1>
        <p className="text-xs text-emerald-100 mt-1">
          Pendaftaran ujian seminar proposal setelah dinyatakan layak oleh dosen pembimbing[cite: 10].
        </p>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm space-y-4">
          <h2 className="font-bold text-slate-800 text-sm flex items-center gap-2">
            <Award className="w-4 h-4 text-emerald-600" /> Syarat Pendaftaran Sempro
          </h2>
          <ul className="space-y-2 text-xs text-slate-600">
            <li className="flex items-center gap-2">✅ ACC Bab 1 s.d. Bab 3 oleh Pembimbing</li>
            <li className="flex items-center gap-2">✅ Lunas Administrasi Pembayaran SPP</li>
            <li className="flex items-center gap-2">✅ Sertifikat Kegiatan Akademik / Seminar</li>
          </ul>
        </div>

        <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm space-y-4">
          <h2 className="font-bold text-slate-800 text-sm flex items-center gap-2">
            <Calendar className="w-4 h-4 text-teal-600" /> Jadwal Sempro Anda
          </h2>
          <div className="p-4 bg-slate-50 rounded-2xl border border-slate-100 space-y-1">
            <span className="text-[10px] font-bold px-2 py-0.5 bg-teal-100 text-teal-800 rounded-md">Terjadwal</span>
            <h3 className="font-bold text-xs text-slate-800 pt-1">Rabu, 21 Oktober 2026</h3>
            <p className="text-[11px] text-slate-500">Ruang Sidang 2 • Pukul 10.00 WIB</p>
          </div>
        </div>
      </div>
    </div>
  );
}