"use client";

import React, { useState, useEffect } from "react";
import { useRouter } from "next/navigation";
import { ClipboardList, MessageSquare, ArrowRight, ShieldCheck } from "lucide-react";
import { fetchRooms } from "@/lib/bchat/api/bchat-api-client";
import type { BchatRoomSummary } from "@/lib/bchat/api/bchat-api-types";

type PartnerWithStudentIdentity = BchatRoomSummary["partner"] & {
  user_id?: number | null;
  mahasiswa_id?: number | null;
  nim?: string | null;
};

export type ProposalRoomSummary = Omit<BchatRoomSummary, "partner"> & {
  partner: PartnerWithStudentIdentity;
};

export interface StudentIdentityTarget {
  user_id?: number | null;
  id_mahasiswa?: number | null;
  mahasiswa_id?: number | null;
  nim?: string | null;
  NIM?: string | null;
}

interface BimbinganMahasiswaItem extends StudentIdentityTarget {
  id: number;
  nama: string;
  bab: string;
  status: string;
  tanggal: string;
}

function matchProposalRoomForStudent(
  rooms: ProposalRoomSummary[],
  student: StudentIdentityTarget | null | undefined
): ProposalRoomSummary | undefined {
  if (!student || rooms.length === 0) return undefined;

  const targetUserId =
    typeof student.user_id === "number" && Number.isInteger(student.user_id) && student.user_id > 0
      ? student.user_id
      : null;

  const rawMahasiswaId = student.id_mahasiswa ?? student.mahasiswa_id;
  const targetMahasiswaId =
    typeof rawMahasiswaId === "number" && Number.isInteger(rawMahasiswaId) && rawMahasiswaId > 0
      ? rawMahasiswaId
      : null;

  const rawNim = student.nim ?? student.NIM;
  const targetNim = typeof rawNim === "string" && rawNim.trim().length > 0 ? rawNim.trim() : null;

  if (targetUserId === null && targetMahasiswaId === null && targetNim === null) {
    return undefined;
  }

  return rooms.find((room) => {
    const partner = room.partner;
    if (!partner || partner.role !== "mahasiswa") {
      return false;
    }

    const partnerUserId =
      typeof partner.user_id === "number" && Number.isInteger(partner.user_id) && partner.user_id > 0
        ? partner.user_id
        : null;
    const partnerMahasiswaId =
      typeof partner.mahasiswa_id === "number" && Number.isInteger(partner.mahasiswa_id) && partner.mahasiswa_id > 0
        ? partner.mahasiswa_id
        : null;
    const partnerNim =
      typeof partner.nim === "string" && partner.nim.trim().length > 0 ? partner.nim.trim() : null;

    if (targetUserId !== null && partnerUserId !== targetUserId) {
      return false;
    }
    if (targetMahasiswaId !== null && partnerMahasiswaId !== targetMahasiswaId) {
      return false;
    }
    if (targetNim !== null && partnerNim !== targetNim) {
      return false;
    }

    return (
      (targetUserId !== null && partnerUserId === targetUserId) ||
      (targetMahasiswaId !== null && partnerMahasiswaId === targetMahasiswaId) ||
      (targetNim !== null && partnerNim === targetNim)
    );
  });
}

export default function PembimbingSkripsiPage() {
  const router = useRouter();
  const [proposalRooms, setProposalRooms] = useState<ProposalRoomSummary[]>([]);

  useEffect(() => {
    let isMounted = true;
    fetchRooms()
      .then((res) => {
        if (!isMounted) return;
        const all = (res.data || []) as ProposalRoomSummary[];
        const filtered = all.filter((r) => r.tahap?.toLowerCase() === "proposal");
        setProposalRooms(filtered);
      })
      .catch(() => {
        // Abaikan error background fetch di halaman ikhtisar
      });

    return () => {
      isMounted = false;
    };
  }, []);

  const getRoomForStudent = (student: StudentIdentityTarget): ProposalRoomSummary | undefined => {
    return matchProposalRoomForStudent(proposalRooms, student);
  };

  const handleOpenGeneralChat = () => {
    if (proposalRooms.length === 1) {
      router.push(`/dashboarddosen/pembimbing_skripsi/chat?room=${proposalRooms[0].room_uuid}`);
    } else {
      router.push("/dashboarddosen/pembimbing_skripsi/chat");
    }
  };

  const bimbinganMahasiswa: BimbinganMahasiswaItem[] = [
    { id: 1, nama: "Budi Santoso", nim: "210401005", bab: "Bab 3 (Metodologi Penelitian)", status: "Menunggu Review", tanggal: "10 Okt 2026" },
    { id: 2, nama: "Ahmad Fauzi", nim: "210401001", bab: "Bab 4 (Hasil & Pembahasan)", status: "ACC", tanggal: "05 Okt 2026" },
  ];

  return (
    <div className="space-y-6 font-sans text-slate-800">
      <div className="p-6 bg-gradient-to-r from-emerald-700 via-emerald-600 to-teal-700 rounded-3xl text-white shadow-lg flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Bimbingan & Persetujuan Skripsi</h1>
          <p className="text-xs text-emerald-100 mt-1">
            Kelola progres bimbingan bab mahasiswa serta berikan persetujuan kelayakan seminar proposal dan sidang skripsi.
          </p>
        </div>
        <button
          onClick={handleOpenGeneralChat}
          className="px-4 py-2 bg-emerald-800/80 hover:bg-emerald-800 text-white font-bold text-xs rounded-xl shadow-xs border border-emerald-500/40 transition flex items-center gap-2 cursor-pointer"
        >
          <MessageSquare className="w-4 h-4 text-emerald-300" /> Buka Chat Proposal E2EE
        </button>
      </div>

      {/* Card Akses Chat E2EE Dosen */}
      <div className="p-5 bg-gradient-to-br from-emerald-50/80 to-teal-50/50 rounded-3xl border border-emerald-100/80 shadow-xs flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
        <div className="flex items-start gap-3.5">
          <div className="w-12 h-12 rounded-2xl bg-emerald-600 text-white flex items-center justify-center shrink-0 shadow-sm">
            <ShieldCheck className="w-6 h-6" />
          </div>
          <div>
            <div className="flex items-center gap-2">
              <h3 className="font-bold text-slate-900 text-sm">Ruang Konsultasi Proposal Mahasiswa (E2EE)</h3>
              <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-200">
                MLS RFC 9420
              </span>
            </div>
            <p className="text-xs text-slate-600 mt-1 max-w-xl leading-relaxed">
              Konsultasi langsung dengan mahasiswa bimbingan proposal secara privat menggunakan protokol enkripsi MLS end-to-end.
            </p>
          </div>
        </div>

        <button
          onClick={handleOpenGeneralChat}
          className="px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs rounded-xl shadow-xs transition flex items-center gap-2 shrink-0 self-end md:self-center cursor-pointer"
        >
          Buka Ruang Konsultasi <ArrowRight className="w-3.5 h-3.5" />
        </button>
      </div>

      <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm space-y-4">
        <h2 className="font-bold text-slate-800 text-sm flex items-center gap-2">
          <ClipboardList className="w-4 h-4 text-emerald-600" /> Daftar Log Bimbingan Mahasiswa Bimbingan
        </h2>

        <div className="divide-y divide-slate-100">
          {bimbinganMahasiswa.map((item) => {
            const matchedRoom = getRoomForStudent({
              user_id: item.user_id,
              id_mahasiswa: item.id_mahasiswa,
              mahasiswa_id: item.mahasiswa_id,
              nim: item.nim ?? item.NIM,
            });

            return (
              <div key={item.id} className="py-4 flex flex-col md:flex-row justify-between items-start md:items-center gap-3">
                <div className="space-y-1">
                  <div className="flex items-center gap-2">
                    <span className="font-bold text-xs text-slate-800">{item.nama}</span>
                    <span className="text-[10px] text-slate-400">({item.nim ?? item.NIM ?? "NIM tidak tersedia"})</span>
                  </div>
                  <p className="text-xs text-emerald-700 font-semibold">{item.bab} <span className="text-slate-400 font-normal">• {item.tanggal}</span></p>
                </div>

                <div className="flex items-center gap-2.5">
                  <span className={`px-2.5 py-1 rounded-full text-[10px] font-bold ${
                    item.status === "ACC" ? "bg-emerald-50 text-emerald-700 border border-emerald-200" : "bg-amber-50 text-amber-700 border border-amber-200"
                  }`}>
                    {item.status}
                  </span>

                  {matchedRoom ? (
                    <button
                      onClick={() => router.push(`/dashboarddosen/pembimbing_skripsi/chat?room=${matchedRoom.room_uuid}`)}
                      className="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-[11px] font-bold rounded-xl transition flex items-center gap-1.5 shadow-2xs cursor-pointer"
                      title={`Buka Chat E2EE dengan ${item.nama} (${item.nim ?? item.NIM})`}
                    >
                      <MessageSquare className="w-3.5 h-3.5" /> Chat E2EE
                    </button>
                  ) : (
                    <span
                      className="px-2.5 py-1.5 rounded-xl text-[10px] font-medium bg-slate-100 text-slate-500 border border-slate-200/80 cursor-default"
                      title="Ruang chat belum dapat dipastikan karena identitas unik mahasiswa belum cocok dengan ruang bimbingan proposal aktif"
                    >
                      Chat Belum Aktif (Belum Dapat Dipastikan)
                    </span>
                  )}

                  <button className="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-[11px] font-bold rounded-xl transition cursor-pointer">
                    Beri Catatan
                  </button>
                </div>
              </div>
            );
          })}
        </div>
      </div>
    </div>
  );
}