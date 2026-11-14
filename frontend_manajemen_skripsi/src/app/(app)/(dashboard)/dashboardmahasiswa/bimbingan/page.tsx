"use client";

import React from "react";
import { useRouter } from "next/navigation";
import { Plus, FileText, MessageSquare, ShieldCheck, ArrowRight } from "lucide-react";

export default function BimbinganPage() {
  const router = useRouter();

  const riwayatBimbingan = [
    { id: 1, bab: "Bab 1 (Pendahuluan)", catatan: "Perbaiki rumusan masalah agar lebih spesifik.", status: "ACC", tanggal: "08 Okt 2026" },
    { id: 2, bab: "Bab 2 (Tinjauan Pustaka)", catatan: "Tambahkan referensi jurnal 5 tahun terakhir.", status: "Revisi", tanggal: "01 Okt 2026" },
  ];

  return (
    <div className="space-y-6 font-sans text-slate-800">
      <div className="p-6 bg-gradient-to-r from-emerald-700 via-emerald-600 to-teal-700 rounded-3xl text-white shadow-lg flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Bimbingan Skripsi</h1>
          <p className="text-xs text-emerald-100 mt-1">
            Pantau catatan konsultasi dan progres bimbingan bersama Dosen Pembimbing.
          </p>
        </div>
        <div className="flex items-center gap-2.5">
          <button
            onClick={() => router.push("/dashboardmahasiswa/bimbingan/chat")}
            className="px-4 py-2 bg-emerald-800/80 hover:bg-emerald-800 text-white font-bold text-xs rounded-xl shadow-xs border border-emerald-500/40 transition flex items-center gap-2"
          >
            <MessageSquare className="w-4 h-4 text-emerald-300" /> Buka Chat Proposal E2EE
          </button>
          <button className="px-4 py-2 bg-white text-[#15803D] font-bold text-xs rounded-xl shadow hover:bg-emerald-50 transition flex items-center gap-1.5">
            <Plus className="w-4 h-4" /> Ajukan Bimbingan Baru
          </button>
        </div>
      </div>

      {/* Card Akses Chat E2EE */}
      <div className="p-5 bg-gradient-to-br from-emerald-50/80 to-teal-50/50 rounded-3xl border border-emerald-100/80 shadow-xs flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
        <div className="flex items-start gap-3.5">
          <div className="w-12 h-12 rounded-2xl bg-emerald-600 text-white flex items-center justify-center shrink-0 shadow-sm">
            <ShieldCheck className="w-6 h-6" />
          </div>
          <div>
            <div className="flex items-center gap-2">
              <h3 className="font-bold text-slate-900 text-sm">Ruang Konsultasi Proposal E2EE (B-CHAT)</h3>
              <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-200">
                MLS RFC 9420
              </span>
            </div>
            <p className="text-xs text-slate-600 mt-1 max-w-xl leading-relaxed">
              Komunikasi langsung dan privat dengan Dosen Pembimbing Proposal. Pesan dilindungi dengan enkripsi end-to-end bertingkat tinggi dan tersimpan aman di perangkat Anda.
            </p>
          </div>
        </div>

        <button
          onClick={() => router.push("/dashboardmahasiswa/bimbingan/chat")}
          className="px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs rounded-xl shadow-xs transition flex items-center gap-2 shrink-0 self-end md:self-center"
        >
          Masuk ke Ruang Chat <ArrowRight className="w-3.5 h-3.5" />
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