"use client";

import React from "react";
import { Users, Search, Plus } from "lucide-react";

export default function DataMahasiswaPage() {
  const mahasiswaList = [
    { id: 1, nama: "Ahmad Fauzi", nim: "210401001", prodi: "Teknik Informatika", status: "Aktif" },
    { id: 2, nama: "Siti Nurhaliza", nim: "210401015", prodi: "Teknik Informatika", status: "Aktif" },
    { id: 3, nama: "Budi Santoso", nim: "210401005", prodi: "Sistem Informasi", status: "Sidang" },
  ];

  return (
    <div className="space-y-6 font-sans text-slate-800">
      <div className="p-6 bg-gradient-to-r from-emerald-700 via-emerald-600 to-teal-700 rounded-3xl text-white shadow-lg flex justify-between items-center">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Manajemen Data Mahasiswa</h1>
          <p className="text-xs text-emerald-100 mt-1">Kelola data seluruh mahasiswa aktif yang memprogram skripsi.</p>
        </div>
        <button className="px-4 py-2 bg-white text-[#15803D] font-bold text-xs rounded-xl shadow hover:bg-emerald-50 transition flex items-center gap-1.5">
          <Plus className="w-4 h-4" /> Tambah Mahasiswa
        </button>
      </div>

      <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm space-y-4">
        <div className="flex justify-between items-center">
          <h2 className="font-bold text-slate-800 text-sm flex items-center gap-2">
            <Users className="w-4 h-4 text-emerald-600" /> Daftar Mahasiswa Skripsi
          </h2>
          <div className="relative w-64">
            <Search className="absolute left-3 top-2.5 w-4 h-4 text-slate-400" />
            <input 
              type="text" 
              placeholder="Cari nama atau NIM..." 
              className="w-full pl-9 pr-4 py-2 rounded-xl border border-slate-200 text-xs focus:outline-none focus:border-emerald-600"
            />
          </div>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full text-left text-xs">
            <thead className="bg-slate-50 text-slate-600 uppercase font-semibold">
              <tr>
                <th className="p-3 rounded-l-xl">Nama Mahasiswa</th>
                <th className="p-3">NIM</th>
                <th className="p-3">Program Studi</th>
                <th className="p-3">Status</th>
                <th className="p-3 rounded-r-xl text-center">Aksi</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {mahasiswaList.map((m) => (
                <tr key={m.id} className="hover:bg-slate-50/50">
                  <td className="p-3 font-bold text-slate-800">{m.nama}</td>
                  <td className="p-3 text-slate-600">{m.nim}</td>
                  <td className="p-3 text-slate-600">{m.prodi}</td>
                  <td className="p-3">
                    <span className="px-2.5 py-1 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                      {m.status}
                    </span>
                  </td>
                  <td className="p-3 text-center">
                    <button className="text-emerald-600 font-bold hover:underline">Edit</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}