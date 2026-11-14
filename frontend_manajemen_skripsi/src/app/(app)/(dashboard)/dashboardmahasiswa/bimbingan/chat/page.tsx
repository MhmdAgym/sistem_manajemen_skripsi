'use client';

/**
 * Halaman Chat Bimbingan Proposal Mahasiswa (E2EE B-CHAT)
 * Route: /dashboardmahasiswa/bimbingan/chat
 */

import React from 'react';
import { useRouter } from 'next/navigation';
import { ArrowLeft } from 'lucide-react';
import { BchatProposalChat } from '@/components/bchat/BchatProposalChat';

export default function MahasiswaProposalChatPage() {
  const router = useRouter();

  return (
    <div className="space-y-4 font-sans text-slate-800">
      {/* Tombol Navigasi Kembali */}
      <div className="flex items-center justify-between">
        <button
          onClick={() => router.push('/dashboardmahasiswa/bimbingan')}
          className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 text-xs font-bold rounded-xl shadow-xs transition"
        >
          <ArrowLeft className="w-3.5 h-3.5" /> Kembali ke Riwayat Bimbingan
        </button>

        <span className="text-[11px] text-slate-400 font-medium">
          Ruang Konsultasi Proposal • E2EE MLS
        </span>
      </div>

      {/* Komponen Chat Utama */}
      <BchatProposalChat onBack={() => router.push('/dashboardmahasiswa/bimbingan')} />
    </div>
  );
}

