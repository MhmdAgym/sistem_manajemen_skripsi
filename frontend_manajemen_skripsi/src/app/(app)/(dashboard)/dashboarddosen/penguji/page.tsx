"use client";

import React from "react";
import { Calendar } from "lucide-react";

export default function PengujiPage() {
  const jadwalUjian = [
    { id: 1, jenis: "Seminar Proposal", mahasiswa: "Rian Utama", nim: "210401020", waktu: "14 Okt 2026, 09:00 WIB", ruang: "Ruang Sidang 1" },
    { id: 2, jenis: "Ujian Skripsi (Sidang)", mahasiswa: "Diana Putri", nim: "210401032", waktu: "18 Okt 2026, 13:00 WIB", ruang: "Ruang Sidang Utama" },
  ];

  return (
    <div className="space-y-6 font-sans text-slate-800">
      <div className="p-6 bg-gradient-to-r from-emerald-700 via-emerald-600 to-teal-700 rounded-3xl text-white shadow-lg">
        <h1 className="text-2xl font-bold tracking-tight">Jadwal & Penilaian Penguji</h1>
        <p className="text-xs text-emerald-100 mt-1">
          Agenda seminar proposal dan ujian sidang skripsi mahasiswa yang diuji serta input nilai akhir[cite: 12].
        </p>
      </div>

      <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm space-y-4">
        <h2 className="font-bold text-slate-800 text-sm flex items-center gap-2">
          <Calendar className="w-4 h-4 text-emerald-600" /> Agenda Tugas Penguji Terdekat
        </h2>

        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {jadwalUjian.map((item) => (
            <div key={item.id} className="p-4 bg-slate-50 border border-slate-100 rounded-2xl space-y-3">
              <div className="flex justify-between items-center">
                <span className="text-[10px] font-bold px-2 py-0.5 bg-emerald-100 text-emerald-800 rounded-md">{item.jenis}</span>
                <span className="text-[11px] font-semibold text-slate-500">{item.ruang}</span>
              </div>
              <div>
                <p className="font-bold text-xs text-slate-800">{item.mahasiswa} ({item.nim})</p>
                <p className="text-[11px] text-slate-600 mt-0.5">🕒 {item.waktu}</p>
              </div>
              <button className="w-full py-2 bg-[#15803D] hover:bg-emerald-700 text-white font-bold text-[11px] rounded-xl shadow transition">
                Beri Nilai & Catatan Revisi
              </button>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}