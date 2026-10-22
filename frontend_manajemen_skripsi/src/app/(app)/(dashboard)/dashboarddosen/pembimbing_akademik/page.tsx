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
  ShieldAlert,
} from "lucide-react";
import axios from "@/lib/axios";
import { AxiosError } from "axios";

export type StatusPengajuan =
  | "Menunggu Verifikasi Dosen PA"
  | "Menunggu Persetujuan Kaprodi"
  | "Disetujui Kaprodi"
  | "Revisi Dosen PA"
  | "Revisi Kaprodi"
  | "Ditolak Dosen PA"
  | "Ditolak Kaprodi"
  | "Disetujui Dosen PA"
  | "Revisi"
  | "Ditolak";

interface PengajuanLog {
  id: number;
  aktor_nama?: string;
  aktor_role?: string;
  tahap?: string;
  tindakan?: string;
  status_sebelum?: string | null;
  status_sesudah?: string | null;
  catatan?: string | null;
  created_at?: string;
}

interface PengajuanMahasiswa {
  id: number;
  mahasiswa?: {
    id_mahasiswa?: number;
    nama_mahasiswa?: string;
    NIM?: string;
    prodi?: string | null;
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
  status: StatusPengajuan;
  catatan_dosen?: string | null;
  catatan_kaprodi?: string | null;
  logs?: PengajuanLog[];
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
    statusBaru: "Menunggu Persetujuan Kaprodi" | "Revisi Dosen PA" | "Ditolak Dosen PA"
  ) => {
    const catatan = catatanInput[id]?.trim() || "";

    // Validasi catatan wajib untuk revisi atau penolakan
    if ((statusBaru === "Revisi Dosen PA" || statusBaru === "Ditolak Dosen PA") && !catatan) {
      setVerifikasiError("Catatan dosen wajib diisi untuk permintaan revisi atau penolakan usulan.");
      return;
    }

    if (catatan.length > 1000) {
      setVerifikasiError("Catatan dosen maksimal 1000 karakter.");
      return;
    }

    setLoadingId(id);
    setVerifikasiError(null);

    try {
      // Kirim PATCH dengan axios — session cookie + CSRF otomatis
      // Backend memverifikasi kepemilikan via dosen_pa_id = user yang login
      await axios.patch(`/api/dosen/pengajuan-judul/${id}/verifikasi`, {
        status: statusBaru,
        catatan_dosen: catatan || null,
      });

      await fetchPengajuanMahasiswa();
      setActiveId(null);
    } catch (err) {
      const error = err as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
      const status = error.response?.status;

      if (status === 404) {
        setVerifikasiError("Pengajuan tidak ditemukan atau Anda tidak memiliki akses untuk memverifikasinya.");
      } else if (status === 422) {
        const errors = error.response?.data?.errors;
        const firstError = errors ? Object.values(errors)[0]?.[0] : null;
        setVerifikasiError(firstError || error.response?.data?.message || "Data verifikasi tidak valid.");
      } else if (status === 403) {
        setVerifikasiError("Akses ditolak. Anda tidak berhak memverifikasi pengajuan ini.");
      } else {
        setVerifikasiError("Terjadi kesalahan saat menyimpan verifikasi ke server. Coba lagi.");
      }
    } finally {
      setLoadingId(null);
    }
  };

  const getBadgeStyle = (status: StatusPengajuan) => {
    switch (status) {
      // Persetujuan Final
      case "Disetujui Kaprodi":
        return "bg-emerald-50 text-emerald-700 border border-emerald-300 font-bold";

      // Antrean Kaprodi
      case "Menunggu Persetujuan Kaprodi":
        return "bg-indigo-50 text-indigo-700 border border-indigo-200 font-semibold";

      // Antrean Dosen PA
      case "Menunggu Verifikasi Dosen PA":
        return "bg-sky-50 text-sky-700 border border-sky-200 font-semibold";

      // Revisi
      case "Revisi Dosen PA":
      case "Revisi Kaprodi":
        return "bg-amber-50 text-amber-700 border border-amber-200 font-medium";
      case "Revisi":
        return "bg-amber-50/80 text-amber-700 border border-amber-200/60 font-medium";

      // Penolakan
      case "Ditolak Dosen PA":
      case "Ditolak Kaprodi":
        return "bg-rose-50 text-rose-700 border border-rose-200 font-medium";
      case "Ditolak":
        return "bg-rose-50/80 text-rose-700 border border-rose-200/60 font-medium";

      // Status Legacy PA
      case "Disetujui Dosen PA":
        return "bg-teal-50 text-teal-700 border border-teal-200 font-medium";

      default:
        return "bg-slate-50 text-slate-700 border border-slate-200 font-medium";
    }
  };

  const getCatatanKaprodi = (item: PengajuanMahasiswa): string | null => {
    // 1. Jika ada di field catatan_kaprodi eksplisit
    if (item.catatan_kaprodi && item.catatan_kaprodi.trim() !== "") {
      return item.catatan_kaprodi.trim();
    }
    // 2. Ambil dari relasi logs yang terbukti dari tindakan Kaprodi (aktor_role 'kaprodi' atau tahap 'persetujuan_kaprodi')
    if (item.logs && Array.isArray(item.logs)) {
      const kaprodiLogs = item.logs.filter(
        (l) =>
          (l.aktor_role === "kaprodi" || l.tahap === "persetujuan_kaprodi") &&
          l.catatan &&
          l.catatan.trim() !== ""
      );
      if (kaprodiLogs.length > 0) {
        const latest = kaprodiLogs[kaprodiLogs.length - 1];
        return latest.catatan?.trim() || null;
      }
    }
    return null;
  };

  const getCatatanDosenPA = (item: PengajuanMahasiswa): string | null => {
    // Cari di logs untuk verifikasi Dosen PA
    if (item.logs && Array.isArray(item.logs)) {
      const paLogs = item.logs.filter(
        (l) =>
          (l.aktor_role === "dosen_pa" || l.tahap === "verifikasi_dosen_pa") &&
          l.catatan &&
          l.catatan.trim() !== ""
      );
      if (paLogs.length > 0) {
        const latest = paLogs[paLogs.length - 1];
        return latest.catatan?.trim() || null;
      }
    }
    // Fallback ke catatan_dosen pada record utama
    return item.catatan_dosen?.trim() || null;
  };

  // Hitung statistik dari data yang sudah difilter backend (hanya milik dosen ini)
  const statsMenunggu = daftarJudul.filter((i) => i.status === "Menunggu Verifikasi Dosen PA").length;

  const statsFinal = daftarJudul.filter((i) => i.status === "Disetujui Kaprodi").length;
  const statsKaprodi = daftarJudul.filter((i) => i.status === "Menunggu Persetujuan Kaprodi").length;
  const statsLegacyPA = daftarJudul.filter((i) => i.status === "Disetujui Dosen PA").length;
  const statsDisetujui = statsFinal + statsKaprodi + statsLegacyPA;

  const statsTotalRevisi = daftarJudul.filter((i) =>
    ["Revisi Dosen PA", "Revisi Kaprodi", "Revisi"].includes(i.status)
  ).length;
  const statsTotalTolak = daftarJudul.filter((i) =>
    ["Ditolak Dosen PA", "Ditolak Kaprodi", "Ditolak"].includes(i.status)
  ).length;
  const statsRevisi = statsTotalRevisi + statsTotalTolak;

  return (
    <div className="space-y-6 font-sans text-slate-800">
      <div className="p-6 bg-gradient-to-r from-emerald-700 via-emerald-600 to-teal-700 rounded-3xl text-white shadow-lg">
        <h1 className="text-2xl font-bold tracking-tight">Verifikasi Judul (Dosen PA)</h1>
        <p className="text-xs text-emerald-100 mt-1 max-w-2xl leading-relaxed">
          Validasi usulan judul skripsi mahasiswa bimbingan akademik Anda. Judul yang disetujui Dosen PA akan diteruskan ke antrean Persetujuan Kaprodi untuk pengesahan final.
        </p>
      </div>

      {/* STATISTIK Dosen PA */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        {/* Card 1: Menunggu Verifikasi */}
        <div className="bg-white p-4 rounded-2xl border border-slate-100 shadow-sm flex items-center justify-between">
          <div>
            <p className="text-[11px] text-slate-500 font-medium">Menunggu Verifikasi PA</p>
            <p className="text-xl font-bold text-slate-800">{isLoading ? "..." : statsMenunggu}</p>
            <p className="text-[10px] text-slate-400 mt-0.5">Perlu ditindaklanjuti Dosen PA</p>
          </div>
          <div className="p-3 bg-sky-50 text-sky-600 rounded-xl">
            <Clock className="w-5 h-5" />
          </div>
        </div>

        {/* Card 2: Diteruskan / Disetujui */}
        <div className="bg-white p-4 rounded-2xl border border-slate-100 shadow-sm flex items-center justify-between">
          <div>
            <p className="text-[11px] text-slate-500 font-medium">Diteruskan / Disetujui</p>
            <p className="text-xl font-bold text-emerald-700">{isLoading ? "..." : statsDisetujui}</p>
            <p className="text-[10px] text-slate-400 mt-0.5">
              Final: {statsFinal} | Antrean Kaprodi: {statsKaprodi}
              {statsLegacyPA > 0 ? ` | Legacy: ${statsLegacyPA}` : ""}
            </p>
          </div>
          <div className="p-3 bg-emerald-50 text-emerald-600 rounded-xl">
            <CheckCircle2 className="w-5 h-5" />
          </div>
        </div>

        {/* Card 3: Perlu Revisi / Ditolak */}
        <div className="bg-white p-4 rounded-2xl border border-slate-100 shadow-sm flex items-center justify-between">
          <div>
            <p className="text-[11px] text-slate-500 font-medium">Perlu Revisi / Ditolak</p>
            <p className="text-xl font-bold text-amber-600">{isLoading ? "..." : statsRevisi}</p>
            <p className="text-[10px] text-slate-400 mt-0.5">
              Revisi: {statsTotalRevisi} | Ditolak: {statsTotalTolak}
            </p>
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
          <div className="p-3.5 bg-red-50 border border-red-200 rounded-2xl text-xs text-red-700 flex items-start gap-2">
            <ShieldAlert className="w-4 h-4 text-red-600 shrink-0 mt-0.5" />
            <span>{verifikasiError}</span>
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
                      {item.mahasiswa?.prodi && (
                        <span className="text-[11px] text-emerald-700 font-medium bg-emerald-50 border border-emerald-200/60 px-2 py-0.5 rounded-md">
                          Prodi: {item.mahasiswa.prodi}
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

                  <span className={`px-3 py-1 rounded-full text-[11px] ${getBadgeStyle(item.status)}`}>
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
                    {/* Tombol verifikasi hanya aktif untuk status Menunggu Verifikasi Dosen PA */}
                    {item.status === "Menunggu Verifikasi Dosen PA" ? (
                      activeId !== item.id ? (
                        <button
                          type="button"
                          onClick={() => {
                            setActiveId(item.id);
                            setVerifikasiError(null);
                            setCatatanInput((prev) => ({
                              ...prev,
                              [item.id]: getCatatanDosenPA(item) || "",
                            }));
                          }}
                          className="px-4 py-2 bg-slate-800 hover:bg-slate-900 text-white text-xs font-bold rounded-xl shadow transition flex items-center gap-1.5"
                        >
                          <MessageSquare className="w-3.5 h-3.5" />
                          Proses Verifikasi
                        </button>
                      ) : (
                        <button
                          type="button"
                          onClick={() => setActiveId(null)}
                          className="px-3 py-1.5 text-xs font-semibold text-slate-500 hover:text-slate-700"
                        >
                          Batal
                        </button>
                      )
                    ) : (
                      <span className="text-[11px] text-slate-500 bg-slate-100 px-3 py-1.5 rounded-xl font-medium flex items-center gap-1">
                        <CheckCircle2 className="w-3.5 h-3.5 text-slate-400" />
                        Sudah Diverifikasi PA
                      </span>
                    )}
                  </div>
                </div>

                {/* Tampilkan Catatan Dosen PA jika ada */}
                {getCatatanDosenPA(item) && activeId !== item.id && (
                  <div className="p-3 bg-amber-50/60 border border-amber-200/80 rounded-xl text-xs text-slate-700 flex items-start gap-2">
                    <MessageSquare className="w-4 h-4 text-amber-600 shrink-0 mt-0.5" />
                    <div>
                      <span className="font-bold text-slate-800">Catatan Dosen PA: </span>
                      <span>{getCatatanDosenPA(item)}</span>
                    </div>
                  </div>
                )}

                {/* Tampilkan Catatan Kaprodi jika ada dari log tindakan Kaprodi */}
                {getCatatanKaprodi(item) && (
                  <div className="p-3 bg-indigo-50/60 border border-indigo-200/80 rounded-xl text-xs text-slate-700 flex items-start gap-2">
                    <MessageSquare className="w-4 h-4 text-indigo-600 shrink-0 mt-0.5" />
                    <div>
                      <span className="font-bold text-indigo-900">Catatan Kaprodi: </span>
                      <span>{getCatatanKaprodi(item)}</span>
                    </div>
                  </div>
                )}

                {/* Form Verifikasi Aktif (hanya untuk status Menunggu Verifikasi Dosen PA) */}
                {activeId === item.id && item.status === "Menunggu Verifikasi Dosen PA" && (
                  <div className="p-4 bg-slate-50 border border-slate-200 rounded-2xl space-y-3">
                    <div>
                      <div className="flex justify-between items-center mb-1">
                        <label className="block text-xs font-bold text-slate-700">
                          Catatan / Masukan Dosen PA
                        </label>
                        <span className="text-[10px] text-slate-400">
                          {(catatanInput[item.id] || "").length}/1000 karakter
                        </span>
                      </div>
                      <textarea
                        rows={3}
                        maxLength={1000}
                        value={catatanInput[item.id] || ""}
                        onChange={(e) =>
                          setCatatanInput((prev) => ({ ...prev, [item.id]: e.target.value }))
                        }
                        placeholder="Tuliskan catatan perbaikan judul, masukan metodologi, atau alasan keputusan (wajib diisi untuk revisi/penolakan)..."
                        className="w-full px-3.5 py-2 rounded-xl border border-slate-200 bg-white text-xs focus:outline-none focus:border-emerald-600 resize-none"
                      />
                    </div>

                    <div className="flex flex-wrap items-center justify-end gap-2">
                      <button
                        type="button"
                        disabled={loadingId === item.id}
                        onClick={() => handleVerifikasi(item.id, "Menunggu Persetujuan Kaprodi")}
                        className="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white text-xs font-bold rounded-xl shadow transition flex items-center gap-1.5"
                      >
                        <CheckCircle2 className="w-3.5 h-3.5" /> Setujui &amp; Teruskan ke Kaprodi
                      </button>

                      <button
                        type="button"
                        disabled={loadingId === item.id}
                        onClick={() => handleVerifikasi(item.id, "Revisi Dosen PA")}
                        className="px-4 py-2 bg-amber-500 hover:bg-amber-600 disabled:opacity-50 text-white text-xs font-bold rounded-xl shadow transition flex items-center gap-1.5"
                      >
                        <RotateCcw className="w-3.5 h-3.5" /> Minta Revisi
                      </button>

                      <button
                        type="button"
                        disabled={loadingId === item.id}
                        onClick={() => handleVerifikasi(item.id, "Ditolak Dosen PA")}
                        className="px-4 py-2 bg-rose-600 hover:bg-rose-700 disabled:opacity-50 text-white text-xs font-bold rounded-xl shadow transition flex items-center gap-1.5"
                      >
                        <XCircle className="w-3.5 h-3.5" /> Tolak Usulan
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