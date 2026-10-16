"use client";

import React, { useState } from "react";
import { BookOpen, Send, CheckCircle2, AlertCircle } from "lucide-react";

export default function PengajuanJudulPage() {
  const [judul, setJudul] = useState("");
  const [deskripsi, setDeskripsi] = useState("");
  const [submitted, setSubmitted] = useState(false);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitted(true);
  };

  return (
    <div className="space-y-6 font-sans text-slate-800">
      <div className="p-6 bg-gradient-to-r from-emerald-700 via-emerald-600 to-teal-700 rounded-3xl text-white shadow-lg">
        <h1 className="text-2xl font-bold tracking-tight">Pengajuan Judul Skripsi</h1>
        <p className="text-xs text-emerald-100 mt-1 max-w-xl">
          Ajukan judul penelitian Anda untuk diverifikasi oleh Ketua Program Studi / Tim Akademik[cite: 10].
        </p>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Form Pengajuan */}
        <div className="lg:col-span-2 bg-white p-6 rounded-3xl border border-slate-100 shadow-sm space-y-4">
          <h2 className="font-bold text-slate-800 text-sm flex items-center gap-2">
            <BookOpen className="w-4 h-4 text-emerald-600" /> Form Usulan Judul
          </h2>

          {submitted ? (
            <div className="p-4 bg-emerald-50 border border-emerald-200 rounded-2xl flex items-center gap-3 text-emerald-800">
              <CheckCircle2 className="w-6 h-6 text-emerald-600 shrink-0" />
              <div>
                <p className="font-bold text-xs">Judul Berhasil Diajukan!</p>
                <p className="text-[11px] text-emerald-700">Status saat ini sedang diverifikasi oleh prodi[cite: 10].</p>
              </div>
            </div>
          ) : (
            <form onSubmit={handleSubmit} className="space-y-4">
              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">Judul Skripsi</label>
                <input
                  type="text"
                  required
                  value={judul}
                  onChange={(e) => setJudul(e.target.value)}
                  placeholder="Contoh: Rancang Bangun Sistem Informasi..."
                  className="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-xs focus:outline-none focus:border-emerald-600"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">Deskripsi / Latar Belakang Singkat</label>
                <textarea
                  rows={4}
                  required
                  value={deskripsi}
                  onChange={(e) => setDeskripsi(e.target.value)}
                  placeholder="Jelaskan rumusan masalah dan metode yang digunakan..."
                  className="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-xs focus:outline-none focus:border-emerald-600 resize-none"
                />
              </div>

              <button
                type="submit"
                className="px-5 py-2.5 bg-[#15803D] hover:bg-emerald-700 text-white font-bold text-xs rounded-xl shadow-md transition flex items-center gap-2"
              >
                <Send className="w-4 h-4" /> Kirim Pengajuan
              </button>
            </form>
          )}
        </div>

        {/* Status Informasi */}
        <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm space-y-4">
          <h2 className="font-bold text-slate-800 text-sm">Status Pengajuan</h2>
          <div className="p-4 bg-slate-50 rounded-2xl border border-slate-100 space-y-2">
            <div className="flex items-center justify-between">
              <span className="text-[10px] font-bold px-2 py-0.5 bg-amber-100 text-amber-800 rounded-md">Diverifikasi</span>
              <span className="text-[10px] text-slate-400">10 Okt 2026</span>
            </div>
            <p className="font-semibold text-xs text-slate-800">Rancang Bangun Sistem Informasi...</p>
            <p className="text-[10px] text-slate-500">Menunggu pengecekan kesamaan judul oleh prodi.</p>
          </div>
        </div>
      </div>
    </div>
  );
}