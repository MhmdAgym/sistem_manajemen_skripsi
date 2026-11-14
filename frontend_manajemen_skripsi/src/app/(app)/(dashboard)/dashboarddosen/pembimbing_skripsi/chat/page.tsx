'use client';

/**
 * Halaman Chat Bimbingan Proposal Dosen (E2EE B-CHAT)
 * Route: /dashboarddosen/pembimbing_skripsi/chat
 */

import React, { Suspense } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { ArrowLeft, Loader2 } from 'lucide-react';
import { BchatProposalChat } from '@/components/bchat/BchatProposalChat';

function DosenProposalChatContent() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const rawRoom = searchParams.get('room');
  const roomUuid = rawRoom ? rawRoom.trim() : undefined;

  return (
    <div className="space-y-4 font-sans text-slate-800">
      {/* Tombol Navigasi Kembali */}
      <div className="flex items-center justify-between">
        <button
          onClick={() => router.push('/dashboarddosen/pembimbing_skripsi')}
          className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 text-xs font-bold rounded-xl shadow-xs transition"
        >
          <ArrowLeft className="w-3.5 h-3.5" /> Kembali ke Log Bimbingan
        </button>

        <span className="text-[11px] text-slate-400 font-medium">
          Ruang Konsultasi Proposal Mahasiswa • E2EE MLS
        </span>
      </div>

      {/* Komponen Chat Utama */}
      <BchatProposalChat
        initialRoomUuid={roomUuid}
        onBack={() => router.push('/dashboarddosen/pembimbing_skripsi')}
      />
    </div>
  );
}

export default function DosenProposalChatPage() {
  return (
    <Suspense
      fallback={
        <div className="flex items-center justify-center min-h-[400px] text-slate-400 gap-2">
          <Loader2 className="w-5 h-5 animate-spin text-emerald-600" />
          <span className="text-xs">Memuat ruang bimbingan...</span>
        </div>
      }
    >
      <DosenProposalChatContent />
    </Suspense>
  );
}

