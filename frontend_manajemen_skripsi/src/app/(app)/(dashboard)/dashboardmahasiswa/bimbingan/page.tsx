"use client";

import React from "react";
import { ClipboardList, Plus, FileText, CheckCircle } from "lucide-react";

export default function BimbinganPage() {
  const riwayatBimbingan = [
    { id: 1, bab: "Bab 1 (Pendahuluan)", catatan: "Perbaiki rumusan masalah agar lebih spesifik.", status: "ACC", tanggal: "08 Okt 2026" },
    { id: 2, bab: "Bab 2 (Tinjauan Pustaka)", catatan: "Tambahkan referensi jurnal 5 tahun terakhir.", status: "Revisi", tanggal: "01 Okt 2026" },
  ];

  return (
    <div className="space-y-6 font-sans text-slate-800">
      <div className="p-6 bg-gradient-to-r from-emerald-700 via-emerald-600 to-teal-700 rounded-3xl text-white shadow-lg flex justify-between items-center">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Bimbingan Skripsi</h1>
          <p className="text-xs text-emerald-100 mt-1">
            Pantau catatan konsultasi dan progres bimbingan bersama Dosen Pembimbing[cite: 10].
          </p>
        </div>
        <button className="px-4 py-2 bg-white text-[#15803D] font-bold text-xs rounded-xl shadow hover:bg-emerald-50 transition flex items-center gap-1.5">
          <Plus className="w-4 h-4" /> Ajukan Bimbingan Baru
        </button>
      </div>

      <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm space-y-4">
        <h2 className="font-bold text-slate-800 text-sm">Riwayat Bimbingan Dosen Pembimbing</h2>
        
        <div className="divide-y divide-slate-100">
          {riwayatBimbingan.map((item) => (
            <div key={item.id} className="py-3.5 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2">
              <div className="space-y-1">
                <div className="flex items-center gap-2">
                  <FileText className="w-4 h-4 text-emerald-600" />
                  <span className="font-bold text-xs text-slate-800">{item.bab}</span>
                  <span className="text-[10px] text-slate-400">({item.tanggal})</span>
                </div>
                <p className="text-xs text-slate-600 pl-6">{item.catatan}</p>
              </div>
              <span className={`px-2.5 py-1 rounded-full text-[10px] font-bold ${
                item.status === "ACC" ? "bg-emerald-50 text-emerald-700 border border-emerald-200" : "bg-amber-50 text-amber-700 border border-amber-200"
              }`}>
                {item.status}
              </span>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}