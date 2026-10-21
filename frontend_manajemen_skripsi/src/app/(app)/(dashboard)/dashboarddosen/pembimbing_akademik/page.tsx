"use client";

import React, { useState, useEffect } from "react";
import {
  Users,
  CheckCircle2,
  XCircle,
  FileText,
  Download,
  MessageSquare,
  Clock,
  ExternalLink,
  RotateCcw,
  Loader2,
} from "lucide-react";
import axios from "@/lib/axios";
import { AxiosError } from "axios";

interface PengajuanMahasiswa {
  id: number;
  mahasiswa?: {
    id_mahasiswa?: number;
    nama_mahasiswa?: string;
    NIM?: string;
    user?: {
      name?: string;
      email?: string;
    };
  };
  judul: string;
  deskripsi: string;
  nama_file: string;
  ukuran_file: string;
  file_url: string;
  created_at: string;
  status: "Menunggu Verifikasi Dosen PA" | "Disetujui Dosen PA" | "Revisi" | "Ditolak";
  catatan_dosen: string;
}

export default function PembimbingAkademiPage() {
  const [daftarJudul, setDaftarJudul] = useState<PengajuanMahasiswa[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [fetchError, setFetchError] = useState<string | null>(null);
  const [catatanInput, setCatatanInput] = useState<{ [key: number]: string }>({});
  const [activeId, setActiveId] = useState<number | null>(null);
  const [loadingId, setLoadingId] = useState<number | null>(null);
  const [verifikasiError, setVerifikasiError] = useState<string | null>(null);

  const fetchPengajuanMahasiswa = async () => {
    try {
      setIsLoading(true);
      setFetchError(null);

      // Menggunakan axios instance dengan session cookie Sanctum
      // Filter kepemilikan (dosen_pa_id = user yang login) diterapkan di backend
      const res = await axios.get("/api/dosen/pengajuan-judul");
      setDaftarJudul(res.data?.data || []);
    } catch (err) {
      const error = err as AxiosError<{ message?: string }>;
      if (error.response?.status === 401) {
        setFetchError("Sesi Anda telah berakhir. Silakan login kembali.");
      } else if (error.response?.status === 403) {
        setFetchError("Akses ditolak. Halaman ini hanya untuk Dosen PA.");
      } else {
        setFetchError("Gagal memuat data pengajuan. Coba muat ulang halaman.");
        console.error("Gagal mengambil data pengajuan:", err);
      }
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    fetchPengajuanMahasiswa();
  }, []);

  const handleVerifikasi = async (
    id: number,
    statusBaru: "Disetujui Dosen PA" | "Revisi" | "Ditolak"
  ) => {
    setLoadingId(id);
    setVerifikasiError(null);

    const catatan =
      catatanInput[id]?.trim() ||
      (statusBaru === "Disetujui Dosen PA"
        ? "Judul dan lampiran disetujui oleh Dosen PA untuk dilanjutkan ke prodi."
        : "Harap perbaiki dokumen pengajuan sesuai catatan Dosen PA.");

    try {
      // Kirim PATCH dengan axios — session cookie + CSRF otomatis
      // Backend memverifikasi kepemilikan via dosen_pa_id = user yang login
      await axios.patch(`/api/dosen/pengajuan-judul/${id}/verifikasi`, {
        status: statusBaru,
        catatan_dosen: catatan,
      });

      await fetchPengajuanMahasiswa();
      setActiveId(null);
    } catch (err) {
      const error = err as AxiosError<{ message?: string }>;
      const status = error.response?.status;

      if (status === 404) {
        setVerifikasiError("Pengajuan tidak ditemukan atau Anda tidak memiliki akses untuk memverifikasinya.");
      } else if (status === 422) {
        setVerifikasiError(error.response?.data?.message || "Data verifikasi tidak valid.");
      } else if (status === 403) {
        setVerifikasiError("Akses ditolak.");
      } else {
        setVerifikasiError("Terjadi kesalahan saat menyimpan verifikasi ke server. Coba lagi.");
      }
    } finally {
      setLoadingId(null);
    }
  };

  const getBadgeStyle = (status: PengajuanMahasiswa["status"]) => {
    switch (status) {
      case "Disetujui Dosen PA":
        return "bg-emerald-50 text-emerald-700 border border-emerald-200";
      case "Revisi":
        return "bg-amber-50 text-amber-700 border border-amber-200";
      case "Ditolak":
        return "bg-rose-50 text-rose-700 border border-rose-200";
      default:
        return "bg-sky-50 text-sky-700 border border-sky-200";
    }
  };

  // Hitung statistik dari data yang sudah difilter backend (hanya milik dosen ini)
  const statsMenunggu  = daftarJudul.filter((i) => i.status === "Menunggu Verifikasi Dosen PA").length;
  const statsDisetujui = daftarJudul.filter((i) => i.status === "Disetujui Dosen PA").length;
  const statsRevisi    = daftarJudul.filter((i) => i.status === "Revisi" || i.status === "Ditolak").length;

  return (
    <div className="space-y-6 font-sans text-slate-800">
      <div className="p-6 bg-gradient-to-r from-emerald-700 via-emerald-600 to-teal-700 rounded-3xl text-white shadow-lg">
        <h1 className="text-2xl font-bold tracking-tight">Verifikasi Judul (Dosen PA)</h1>
        <p className="text-xs text-emerald-100 mt-1 max-w-2xl">
          Validasi kelayakan usulan judul skripsi, periksa dokumen lampiran mahasiswa bimbingan akademik Anda, dan berikan persetujuan atau catatan revisi.
        </p>
      </div>

      {/* STATISTIK — dihitung dari data yang difilter backend (hanya mahasiswa bimbingan dosen ini) */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div className="bg-white p-4 rounded-2xl border border-slate-100 shadow-sm flex items-center justify-between">
          <div>
            <p className="text-[11px] text-slate-500 font-medium">Menunggu Verifikasi</p>
            <p className="text-xl font-bold text-slate-800">{isLoading ? "..." : statsMenunggu}</p>
          </div>
          <div className="p-3 bg-sky-50 text-sky-600 rounded-xl">
            <Clock className="w-5 h-5" />
          </div>
        </div>

        <div className="bg-white p-4 rounded-2xl border border-slate-100 shadow-sm flex items-center justify-between">
          <div>
            <p className="text-[11px] text-slate-500 font-medium">Disetujui Dosen PA</p>
            <p className="text-xl font-bold text-emerald-700">{isLoading ? "..." : statsDisetujui}</p>
          </div>
          <div className="p-3 bg-emerald-50 text-emerald-600 rounded-xl">
            <CheckCircle2 className="w-5 h-5" />
          </div>
        </div>

        <div className="bg-white p-4 rounded-2xl border border-slate-100 shadow-sm flex items-center justify-between">
          <div>
            <p className="text-[11px] text-slate-500 font-medium">Perlu Revisi / Ditolak</p>
            <p className="text-xl font-bold text-amber-600">{isLoading ? "..." : statsRevisi}</p>
          </div>
          <div className="p-3 bg-amber-50 text-amber-600 rounded-xl">
            <RotateCcw className="w-5 h-5" />
          </div>
        </div>
      </div>

      <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm space-y-4">
        <h2 className="font-bold text-slate-800 text-sm flex items-center gap-2">
          <Users className="w-4 h-4 text-emerald-600" /> Daftar Pengajuan Judul Mahasiswa Bimbingan
        </h2>

        {/* Error global verifikasi */}
        {verifikasiError && (
          <div className="p-3 bg-red-50 border border-red-200 rounded-xl text-xs text-red-700">
            {verifikasiError}
          </div>
        )}

        {isLoading ? (
          <div className="py-12 flex justify-center items-center gap-2 text-xs text-slate-400">
            <Loader2 className="w-5 h-5 animate-spin" /> Memuat daftar pengajuan mahasiswa...
          </div>
        ) : fetchError ? (
          <div className="py-10 text-center text-xs text-red-500">
            {fetchError}
          </div>
        ) : daftarJudul.length === 0 ? (
          <div className="py-10 text-center text-xs text-slate-400">
            Belum ada pengajuan judul dari mahasiswa bimbingan Anda.
          </div>
        ) : (
          <div className="divide-y divide-slate-100">
            {daftarJudul.map((item) => (
              <div key={item.id} className="py-5 space-y-4">
                <div className="flex flex-col md:flex-row justify-between items-start md:items-center gap-3">
                  <div className="space-y-1">
                    <div className="flex items-center flex-wrap gap-2">
                      <span className="font-bold text-sm text-slate-800">
                        {item.mahasiswa?.nama_mahasiswa || item.mahasiswa?.user?.name || "Mahasiswa"}
                      </span>
                      {item.mahasiswa?.NIM && (
                        <span className="text-[11px] text-slate-500 font-medium bg-slate-100 px-2 py-0.5 rounded-md">
                          NIM: {item.mahasiswa.NIM}
                        </span>
                      )}
                      <span className="text-[11px] text-slate-400">
                        • Diajukan{" "}
                        {new Date(item.created_at).toLocaleDateString("id-ID", {
                          day: "2-digit",
                          month: "short",
                          year: "numeric",
                        })}
                      </span>
                    </div>
                    <p className="text-sm text-emerald-800 font-bold leading-snug pt-0.5">
                      {item.judul}
                    </p>
                  </div>

                  <span className={`px-3 py-1 rounded-full text-[11px] font-bold ${getBadgeStyle(item.status)}`}>
                    {item.status}
                  </span>
                </div>

                <div className="p-3.5 bg-slate-50 rounded-2xl border border-slate-100 space-y-1">
                  <p className="text-[11px] font-semibold text-slate-500">
                    Deskripsi / Latar Belakang Singkat:
                  </p>
                  <p className="text-xs text-slate-700 leading-relaxed">{item.deskripsi}</p>
                </div>

                <div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 pt-1">
                  <div className="flex items-center justify-between sm:justify-start gap-3 p-3 bg-emerald-50/60 border border-emerald-200/80 rounded-2xl">
                    <div className="flex items-center gap-2.5 min-w-0">
                      <div className="p-2 bg-emerald-600 text-white rounded-xl shrink-0">
                        <FileText className="w-4 h-4" />
                      </div>
                      <div className="min-w-0">
                        <p className="text-xs font-bold text-slate-800 truncate max-w-[220px] sm:max-w-xs">
                          {item.nama_file}
                        </p>
                        <p className="text-[10px] text-slate-500">Ukuran: {item.ukuran_file}</p>
                      </div>
                    </div>

                    <div className="flex items-center gap-1.5 ml-2">
                      <a
                        href={item.file_url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="px-2.5 py-1.5 bg-white hover:bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-xl text-[11px] font-semibold flex items-center gap-1 transition shadow-sm"
                      >
                        <ExternalLink className="w-3.5 h-3.5" /> Lihat
                      </a>
                      <a
                        href={item.file_url}
                        download={item.nama_file}
                        className="px-2.5 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-[11px] font-semibold flex items-center gap-1 transition shadow-sm"
                      >
                        <Download className="w-3.5 h-3.5" /> Unduh
                      </a>
                    </div>
                  </div>

                  <div className="flex items-center gap-2 self-end sm:self-center">
                    {activeId !== item.id ? (
                      <button
                        type="button"
                        onClick={() => {
                          setActiveId(item.id);
                          setVerifikasiError(null);
                          setCatatanInput((prev) => ({
                            ...prev,
                            [item.id]: item.catatan_dosen || "",
                          }));
                        }}
                        className="px-4 py-2 bg-slate-800 hover:bg-slate-900 text-white text-xs font-bold rounded-xl shadow transition flex items-center gap-1.5"
                      >
                        <MessageSquare className="w-3.5 h-3.5" />
                        {item.status === "Menunggu Verifikasi Dosen PA"
                          ? "Proses Verifikasi"
                          : "Ubah Keputusan / Catatan"}
                      </button>
                    ) : (
                      <button
                        type="button"
                        onClick={() => setActiveId(null)}
                        className="px-3 py-1.5 text-xs font-semibold text-slate-500 hover:text-slate-700"
                      >
                        Batal
                      </button>
                    )}
                  </div>
                </div>

                {item.catatan_dosen && activeId !== item.id && (
                  <div className="p-3 bg-amber-50/60 border border-amber-200/80 rounded-xl text-xs text-slate-700 flex items-start gap-2">
                    <MessageSquare className="w-4 h-4 text-amber-600 shrink-0 mt-0.5" />
                    <div>
                      <span className="font-bold text-slate-800">Catatan Dosen PA: </span>
                      <span>{item.catatan_dosen}</span>
                    </div>
                  </div>
                )}

                {activeId === item.id && (
                  <div className="p-4 bg-slate-50 border border-slate-200 rounded-2xl space-y-3">
                    <div>
                      <label className="block text-xs font-bold text-slate-700 mb-1">
                        Catatan / Masukan Dosen PA
                      </label>
                      <textarea
                        rows={2}
                        value={catatanInput[item.id] || ""}
                        onChange={(e) =>
                          setCatatanInput((prev) => ({ ...prev, [item.id]: e.target.value }))
                        }
                        placeholder="Tuliskan catatan perbaikan judul, masukan metode, atau alasan persetujuan..."
                        className="w-full px-3.5 py-2 rounded-xl border border-slate-200 bg-white text-xs focus:outline-none focus:border-emerald-600 resize-none"
                      />
                    </div>

                    <div className="flex flex-wrap items-center justify-end gap-2">
                      <button
                        type="button"
                        disabled={loadingId === item.id}
                        onClick={() => handleVerifikasi(item.id, "Disetujui Dosen PA")}
                        className="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white text-xs font-bold rounded-xl shadow transition flex items-center gap-1.5"
                      >
                        <CheckCircle2 className="w-3.5 h-3.5" /> Setujui Judul
                      </button>

                      <button
                        type="button"
                        disabled={loadingId === item.id}
                        onClick={() => handleVerifikasi(item.id, "Revisi")}
                        className="px-4 py-2 bg-amber-500 hover:bg-amber-600 disabled:opacity-50 text-white text-xs font-bold rounded-xl shadow transition flex items-center gap-1.5"
                      >
                        <RotateCcw className="w-3.5 h-3.5" /> Minta Revisi
                      </button>

                      <button
                        type="button"
                        disabled={loadingId === item.id}
                        onClick={() => handleVerifikasi(item.id, "Ditolak")}
                        className="px-4 py-2 bg-rose-600 hover:bg-rose-700 disabled:opacity-50 text-white text-xs font-bold rounded-xl shadow transition flex items-center gap-1.5"
                      >
                        <XCircle className="w-3.5 h-3.5" /> Tolak
                      </button>
                    </div>
                  </div>
                )}
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}