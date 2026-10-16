"use client";

import React from "react";
import { FileCheck, Calendar } from "lucide-react";

export default function ManajemenSemproPage() {
  return (
    <div className="space-y-6 font-sans text-slate-800">
      <div className="p-6 bg-gradient-to-r from-emerald-700 via-emerald-600 to-teal-700 rounded-3xl text-white shadow-lg">
        <h1 className="text-2xl font-bold tracking-tight">Manajemen Seminar Proposal</h1>
        <p className="text-xs text-emerald-100 mt-1">Pengaturan jadwal, ruangan, dan penugasan dosen penguji sempro.</p>
      </div>

      <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm space-y-4">
        <h2 className="font-bold text-slate-800 text-sm flex items-center gap-2">
          <Calendar className="w-4 h-4 text-emerald-600" /> Jadwal Sempro Terdaftar
        </h2>
        <p className="text-xs text-slate-500">Belum ada jadwal seminar proposal yang perlu divalidasi hari ini.</p>
      </div>
    </div>
  );
}