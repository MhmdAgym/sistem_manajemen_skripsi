"use client";

import React, { useState } from "react";
import { Users, CheckCircle2, XCircle, FileText } from "lucide-react";

export default function PembimbingAkademiPage() {
  const [daftarJudul, setDaftarJudul] = useState([
    { id: 1, mahasiswa: "Ahmad Fauzi", nim: "210401001", judul: "Rancang Bangun Sistem Informasi Manajemen Skripsi", status: "Menunggu" },
    { id: 2, mahasiswa: "Siti Nurhaliza", nim: "210401015", judul: "Penerapan Algoritma C4.5 Untuk Prediksi Kelulusan", status: "Menunggu" },
  ]);

  const handleVerifikasi = (id: number, statusBuku: string) => {
    setDaftarJudul(daftarJudul.map(item => item.id === id ? { ...item, status: statusBuku } : item));
  };

  return (
    <div className="space-y-6 font-sans text-slate-800">
      <div className="p-6 bg-gradient-to-r from-emerald-700 via-emerald-600 to-teal-700 rounded-3xl text-white shadow-lg">
      <h1 className="text-2xl font-bold tracking-tight">Verifikasi Judul (Dosen PA)</h1>
        <p className="text-xs text-emerald-100 mt-1">
          Validasi awal kelayakan judul usulan skripsi mahasiswa bimbingan akademik Anda[cite: 12].
        </p>
      </div>

      <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm space-y-4">
        <h2 className="font-bold text-slate-800 text-sm flex items-center gap-2">
          <Users className="w-4 h-4 text-emerald-600" /> Daftar Pengajuan Judul Mahasiswa
        </h2>

        <div className="divide-y divide-slate-100">
          {daftarJudul.map((item) => (
            <div key={item.id} className="py-4 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
              <div className="space-y-1">
                <div className="flex items-center gap-2">
                  <span className="font-bold text-xs text-slate-800">{item.mahasiswa}</span>
                  <span className="text-[10px] text-slate-400">({item.nim})</span>
                </div>
                <p className="text-xs text-slate-600 font-medium">{item.judul}</p>
              </div>

              <div className="flex items-center gap-3">
                <span className={`px-2.5 py-1 rounded-full text-[10px] font-bold ${
                  item.status === "Disetujui" ? "bg-emerald-50 text-emerald-700 border border-emerald-200" :
                  item.status === "Revisi" ? "bg-rose-50 text-rose-700 border border-rose-200" : "bg-amber-50 text-amber-700 border border-amber-200"
                }`}>
                  {item.status}
                </span>

                {item.status === "Menunggu" && (
                  <div className="flex gap-2">
                    <button 
                      onClick={() => handleVerifikasi(item.id, "Disetujui")}
                      className="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-[11px] font-bold rounded-xl shadow transition flex items-center gap-1"
                    >
                      <CheckCircle2 className="w-3.5 h-3.5" /> Setuju
                    </button>
                    <button 
                      onClick={() => handleVerifikasi(item.id, "Revisi")}
                      className="px-3 py-1.5 bg-rose-600 hover:bg-rose-700 text-white text-[11px] font-bold rounded-xl shadow transition flex items-center gap-1"
                    >
                      <XCircle className="w-3.5 h-3.5" /> Revisi
                    </button>
                  </div>
                )}
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}