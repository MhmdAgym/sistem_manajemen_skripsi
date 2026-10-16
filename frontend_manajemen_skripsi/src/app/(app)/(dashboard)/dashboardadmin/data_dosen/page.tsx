"use client";

import React from "react";
import { UserCheck, Plus } from "lucide-react";

export default function DataDosenPage() {
  const dosenList = [
    { id: 1, nama: "Dr. H. M. Nasir, M.Kom.", nidn: "0212038001", jabatan: "Dosen Pembimbing / Penguji" },
    { id: 2, nama: "Aulia Rahman, M.T.", nidn: "0215088502", jabatan: "Dosen Pembimbing Akademik (PA)" },
  ];

  return (
    <div className="space-y-6 font-sans text-slate-800">
      <div className="p-6 bg-gradient-to-r from-emerald-700 via-emerald-600 to-teal-700 rounded-3xl text-white shadow-lg flex justify-between items-center">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Manajemen Data Dosen</h1>
          <p className="text-xs text-emerald-100 mt-1">Kelola data dosen pembimbing, penguji, dan PA.</p>
        </div>
        <button className="px-4 py-2 bg-white text-[#15803D] font-bold text-xs rounded-xl shadow hover:bg-emerald-50 transition flex items-center gap-1.5">
          <Plus className="w-4 h-4" /> Tambah Dosen
        </button>
      </div>

      <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm space-y-4">
        <h2 className="font-bold text-slate-800 text-sm flex items-center gap-2">
          <UserCheck className="w-4 h-4 text-emerald-600" /> Daftar Dosen Aktif
        </h2>

        <div className="divide-y divide-slate-100">
          {dosenList.map((d) => (
            <div key={d.id} className="py-3 flex justify-between items-center">
              <div>
                <p className="font-bold text-xs text-slate-800">{d.nama}</p>
                <p className="text-[11px] text-slate-500">NIDN: {d.nidn} • <span className="text-emerald-700 font-semibold">{d.jabatan}</span></p>
              </div>
              <button className="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-[11px] font-bold rounded-xl transition">
                Detail
              </button>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}