"use client";

import React from "react";
import { ClipboardList } from "lucide-react";

export default function PembimbingSkripsiPage() {
  const bimbinganMahasiswa = [
    { id: 1, nama: "Budi Santoso", nim: "210401005", bab: "Bab 3 (Metodologi Penelitian)", status: "Menunggu Review", tanggal: "10 Okt 2026" },
    { id: 2, nama: "Ahmad Fauzi", nim: "210401001", bab: "Bab 4 (Hasil & Pembahasan)", status: "ACC", tanggal: "05 Okt 2026" },
  ];

  return (
    <div className="space-y-6 font-sans text-slate-800">
      <div className="p-6 bg-gradient-to-r from-emerald-700 via-emerald-600 to-teal-700 rounded-3xl text-white shadow-lg">
        <h1 className="text-2xl font-bold tracking-tight">Bimbingan & Persetujuan Skripsi</h1>
        <p className="text-xs text-emerald-100 mt-1">
          Kelola progres bimbingan bab mahasiswa serta berikan persetujuan kelayakan seminar proposal dan sidang skripsi[cite: 12].
        </p>
      </div>

      <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm space-y-4">
        <h2 className="font-bold text-slate-800 text-sm flex items-center gap-2">
          <ClipboardList className="w-4 h-4 text-emerald-600" /> Daftar Log Bimbingan Mahasiswa Bimbingan
        </h2>

        <div className="divide-y divide-slate-100">
          {bimbinganMahasiswa.map((item) => (
            <div key={item.id} className="py-4 flex flex-col md:flex-row justify-between items-start md:items-center gap-3">
              <div className="space-y-1">
                <div className="flex items-center gap-2">
                  <span className="font-bold text-xs text-slate-800">{item.nama}</span>
                  <span className="text-[10px] text-slate-400">({item.nim})</span>
                </div>
                <p className="text-xs text-emerald-700 font-semibold">{item.bab} <span className="text-slate-400 font-normal">• {item.tanggal}</span></p>
              </div>

              <div className="flex items-center gap-3">
                <span className={`px-2.5 py-1 rounded-full text-[10px] font-bold ${
                  item.status === "ACC" ? "bg-emerald-50 text-emerald-700 border border-emerald-200" : "bg-amber-50 text-amber-700 border border-amber-200"
                }`}>
                  {item.status}
                </span>
                <button className="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-[11px] font-bold rounded-xl transition">
                  Beri Catatan
                </button>
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}