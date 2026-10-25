"use client";

import React, { useState, useEffect, useCallback } from "react";
import Link from "next/link";
import {
  ShieldCheck,
  ShieldAlert,
  AlertTriangle,
  Clock,
  CheckCircle2,
  XCircle,
  RotateCcw,
  FileText,
  ExternalLink,
  Download,
  MessageSquare,
  Users,
  Loader2,
  ArrowLeft,
  X,
  History,
  AlertCircle
} from "lucide-react";
import axios from "@/lib/axios";
import { AxiosError } from "axios";

interface PengajuanLog {
  id: number;
  aktor_nama?: string;
  aktor_role?: string;
  tahap?: string;
  tindakan?: string;
  status_sebelum?: string | null;
  status_sesudah?: string | null;
  catatan?: string | null;
  created_at: string;
}

interface PengajuanKaprodi {
  id: number;
  skripsi_id?: number;
  mahasiswa_id?: number;
  dosen_pa_id?: number;
  judul: string;
  deskripsi: string;
  nama_file: string;
  ukuran_file: string;
  file_path?: string;
  file_url?: string | null;
  status: string;
  catatan_dosen?: string | null;
  created_at: string;
  updated_at?: string;
  mahasiswa?: {
    id_mahasiswa?: number;
    NIM?: string;
    nama_mahasiswa?: string;
    prodi?: string;
    user?: {
      name?: string;
      email?: string;
    };
  };
  skripsi?: {
    id_skripsi?: number;
    judul?: string;
    status_judul?: string;
  };
  dosen_pa?: {
    id?: number;
    name?: string;
    email?: string;
  };
  logs?: PengajuanLog[];
}

export default function PersetujuanKaprodiPage() {
  const [antrean, setAntrean] = useState<PengajuanKaprodi[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [errorStatus, setErrorStatus] = useState<number | null>(null);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  // Filter tab aktif: default antrean menunggu persetujuan (tanpa query status)
  const [activeTab, setActiveTab] = useState<"antrean" | "disetujui" | "revisi" | "ditolak">("antrean");

  // Modal review & keputusan
  const [selectedItem, setSelectedItem] = useState<PengajuanKaprodi | null>(null);
  const [catatanKaprodi, setCatatanKaprodi] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [actionSuccess, setActionSuccess] = useState<string | null>(null);
  const [showConfirmModal, setShowConfirmModal] = useState(false);

  const fetchAntrean = useCallback(async (tab: "antrean" | "disetujui" | "revisi" | "ditolak") => {
    try {
      setIsLoading(true);
      setErrorStatus(null);
      setErrorMessage(null);

      // Pemetaan parameter query eksplisit yang didukung backend:
      // Tab antrean memanggil endpoint default (tanpa parameter status) -> otomatis memfilter 'Menunggu Persetujuan Kaprodi'
      let endpoint = "/api/kaprodi/pengajuan-judul";
      if (tab === "disetujui") {
        endpoint = "/api/kaprodi/pengajuan-judul?status=Disetujui%20Kaprodi";
      } else if (tab === "revisi") {
        endpoint = "/api/kaprodi/pengajuan-judul?status=Revisi%20Kaprodi";
      } else if (tab === "ditolak") {
        endpoint = "/api/kaprodi/pengajuan-judul?status=Ditolak%20Kaprodi";
      }

      const res = await axios.get(endpoint);
      setAntrean(res.data?.data || []);
    } catch (err) {
      const error = err as AxiosError<{ message?: string }>;
      const status = error.response?.status ?? 500;
      setErrorStatus(status);

      if (status === 403) {
        setErrorMessage(
          error.response?.data?.message ||
            "Akses ditolak. Anda tidak memiliki penugasan aktif sebagai Ketua Program Studi."
        );
      } else if (status === 409) {
        setErrorMessage(
          error.response?.data?.message ||
            "Terdeteksi konflik penugasan Kaprodi ganda untuk program studi Anda. Silakan hubungi Administrator."
        );
      } else if (status === 401) {
        setErrorMessage("Sesi Anda telah berakhir. Silakan login kembali.");
      } else if (status === 404) {
        setErrorMessage("Data antrean tidak ditemukan.");
      } else {
        setErrorMessage("Terjadi kesalahan pada server saat memproses data. Silakan coba lagi nanti.");
      }
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchAntrean(activeTab);
  }, [activeTab, fetchAntrean]);

  // Ekstrak nama prodi yang unik dari data pengajuan yang berhasil diambil
  const prodiUnik = Array.from(
    new Set(antrean.map((item) => item.mahasiswa?.prodi).filter(Boolean))
  ) as string[];

  const getDosenPAName = (item: PengajuanKaprodi): string => {
    if (item.dosen_pa?.name) return item.dosen_pa.name;
    const paLog = item.logs?.find((l) => l.aktor_role === "dosen_pa" || l.tahap === "verifikasi_dosen_pa");
    return paLog?.aktor_nama || "Dosen Pembimbing Akademik";
  };

  const getDosenPANote = (item: PengajuanKaprodi): string | null => {
    if (item.catatan_dosen) return item.catatan_dosen;
    const paLog = item.logs?.find((l) => l.aktor_role === "dosen_pa" || l.tahap === "verifikasi_dosen_pa");
    return paLog?.catatan || null;
  };

  const getBadgeStyle = (status: string) => {
    switch (status) {
      case "Disetujui Kaprodi":
        return "bg-emerald-50 text-emerald-700 border border-emerald-300 font-bold";
      case "Menunggu Persetujuan Kaprodi":
        return "bg-indigo-50 text-indigo-700 border border-indigo-200 font-semibold";
      case "Revisi Kaprodi":
        return "bg-amber-50 text-amber-700 border border-amber-200 font-semibold";
      case "Ditolak Kaprodi":
        return "bg-rose-50 text-rose-700 border border-rose-200 font-semibold";
      case "Menunggu Verifikasi Dosen PA":
        return "bg-sky-50 text-sky-700 border border-sky-200";
      default:
        return "bg-slate-100 text-slate-700 border border-slate-200";
    }
  };

  const handleOpenReview = (item: PengajuanKaprodi) => {
    setSelectedItem(item);
    setCatatanKaprodi("");
    setActionError(null);
    setActionSuccess(null);
    setShowConfirmModal(false);
  };

  const handleCloseReview = () => {
    if (!isSubmitting) {
      setSelectedItem(null);
      setCatatanKaprodi("");
      setActionError(null);
      setActionSuccess(null);
      setShowConfirmModal(false);
    }
  };

  const handleKirimKeputusan = async (
    targetStatus: "Disetujui Kaprodi" | "Revisi Kaprodi" | "Ditolak Kaprodi"
  ) => {
    if (!selectedPengajuan) return;

    setActionError(null);
    setActionSuccess(null);

    // Validasi catatan wajib di sisi client untuk revisi dan penolakan
    const trimmedCatatan = catatanKaprodi.trim();
    if (
      (targetStatus === "Revisi Kaprodi" || targetStatus === "Ditolak Kaprodi") &&
      trimmedCatatan === ""
    ) {
      setActionError(
        targetStatus === "Revisi Kaprodi"
          ? "Catatan revisi wajib diisi agar mahasiswa dan Dosen PA mengetahui bagian yang harus diperbaiki."
          : "Alasan penolakan wajib diisi untuk tindakan penolakan judul."
      );
      return;
    }

    if (trimmedCatatan.length > 1000) {
      setActionError("Catatan Kaprodi maksimal 1000 karakter.");
      return;
    }

    // Jika setujui, tampilkan konfirmasi terlebih dahulu
    if (targetStatus === "Disetujui Kaprodi" && !showConfirmModal) {
      setShowConfirmModal(true);
      return;
    }

    setIsSubmitting(true);

    try {
      const payload: { status: string; catatan_kaprodi?: string } = {
        status: targetStatus,
      };

      if (trimmedCatatan !== "") {
        payload.catatan_kaprodi = trimmedCatatan;
      }

      const res = await axios.patch(
        `/api/kaprodi/pengajuan-judul/${selectedPengajuan.id}/persetujuan`,
        payload
      );

      setActionSuccess(res.data?.message || "Keputusan berhasil disimpan.");
      setShowConfirmModal(false);

      // Refresh antrean dari server
      await fetchAntrean(activeTab);

      // Tutup modal setelah delay singkat agar user melihat konfirmasi sukses
      setTimeout(() => {
        handleCloseReview();
      }, 1200);
    } catch (err) {
      const error = err as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
      const status = error.response?.status;

      if (status === 422) {
        const errorMsg =
          error.response?.data?.message ||
          error.response?.data?.errors?.catatan_kaprodi?.[0] ||
          error.response?.data?.errors?.status?.[0] ||
          "Data keputusan tidak valid.";
        setActionError(errorMsg);
        // Jika status telah berubah di backend, refresh daftar agar data sinkron
        fetchAntrean(activeTab);
      } else if (status === 404) {
        setActionError("Pengajuan tidak ditemukan atau bukan wewenang program studi Anda.");
        fetchAntrean(activeTab);
      } else if (status === 403) {
        setActionError("Akses ditolak. Anda tidak memiliki wewenang penugasan Kaprodi.");
      } else if (status === 409) {
        setActionError(
          error.response?.data?.message ||
            "Terdeteksi konflik penugasan Kaprodi ganda untuk program studi Anda."
        );
      } else {
        setActionError("Terjadi kesalahan pada server saat memproses persetujuan. Silakan coba lagi.");
      }
      setShowConfirmModal(false);
    } finally {
      setIsSubmitting(false);
    }
  };

  const selectedPengajuan = selectedItem;

  // -------------------------------------------------------------------------
  // TAMPILAN JIKA DOSEN BUKAN KAPRODI AKTIF (HTTP 403)
  // -------------------------------------------------------------------------
  if (errorStatus === 403) {
    return (
      <div className="space-y-6 font-sans text-slate-800">
        <div className="p-6 bg-gradient-to-r from-emerald-700 via-emerald-600 to-teal-700 rounded-3xl text-white shadow-lg">
          <div className="flex items-center gap-3">
            <div className="p-3 bg-white/15 rounded-2xl border border-white/20">
              <ShieldCheck className="w-6 h-6 text-emerald-100" />
            </div>
            <div>
              <h1 className="text-2xl font-bold tracking-tight">Persetujuan Judul Skripsi (Kaprodi)</h1>
              <p className="text-xs text-emerald-100 mt-0.5">
                Portal validasi akhir usulan judul skripsi dan penetapan judul resmi program studi.
              </p>
            </div>
          </div>
        </div>

        <div className="bg-white p-8 rounded-3xl border border-slate-100 shadow-sm max-w-2xl mx-auto text-center space-y-4">
          <div className="w-14 h-14 bg-amber-50 text-amber-600 rounded-2xl flex items-center justify-center mx-auto border border-amber-200">
            <ShieldAlert className="w-8 h-8" />
          </div>

          <div className="space-y-1">
            <h2 className="text-lg font-bold text-slate-800">Akses Terbatas: Bukan Kaprodi Aktif</h2>
            <p className="text-xs text-slate-600 max-w-md mx-auto leading-relaxed">
              {errorMessage || "Anda tidak memiliki penugasan aktif sebagai Ketua Program Studi."}
            </p>
          </div>

          <p className="text-[11px] text-slate-400 max-w-md mx-auto">
            Halaman ini khusus diperuntukkan bagi dosen yang sedang memiliki surat keputusan penugasan aktif sebagai
            Ketua Program Studi. Jika Anda merasa ditugaskan, silakan hubungi bagian Akademik / Administrator.
          </p>

          <div className="pt-2 flex justify-center gap-3">
            <Link
              href="/dashboarddosen"
              className="px-5 py-2.5 bg-slate-800 hover:bg-slate-900 text-white rounded-xl text-xs font-bold transition flex items-center gap-2"
            >
              <ArrowLeft className="w-4 h-4" /> Kembali ke Dashboard Dosen
            </Link>
            <Link
              href="/dashboarddosen/pembimbing_akademik"
              className="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold transition flex items-center gap-2"
            >
              <Users className="w-4 h-4" /> Pembimbing Akademik
            </Link>
          </div>
        </div>
      </div>
    );
  }

  // -------------------------------------------------------------------------
  // TAMPILAN JIKA TERJADI KONFLIK PENUGASAN GANDA (HTTP 409)
  // -------------------------------------------------------------------------
  if (errorStatus === 409) {
    return (
      <div className="space-y-6 font-sans text-slate-800">
        <div className="bg-white p-8 rounded-3xl border border-amber-200 shadow-sm max-w-2xl mx-auto text-center space-y-4">
          <div className="w-14 h-14 bg-rose-50 text-rose-600 rounded-2xl flex items-center justify-center mx-auto border border-rose-200">
            <AlertTriangle className="w-8 h-8" />
          </div>
          <div className="space-y-1">
            <h2 className="text-lg font-bold text-slate-800">Konflik Konfigurasi Penugasan</h2>
            <p className="text-xs text-slate-700 max-w-md mx-auto leading-relaxed">{errorMessage}</p>
          </div>
          <div className="pt-2">
            <Link
              href="/dashboarddosen"
              className="px-5 py-2.5 bg-slate-800 hover:bg-slate-900 text-white rounded-xl text-xs font-bold transition inline-flex items-center gap-2"
            >
              <ArrowLeft className="w-4 h-4" /> Kembali ke Dashboard
            </Link>
          </div>
        </div>
      </div>
    );
  }

  // -------------------------------------------------------------------------
  // TAMPILAN UTAMA ANTREAN KAPRODI
  // -------------------------------------------------------------------------
  return (
    <div className="space-y-6 font-sans text-slate-800">
      {/* HEADER BANNER */}
      <div className="p-6 bg-gradient-to-r from-emerald-700 via-emerald-600 to-teal-700 rounded-3xl text-white shadow-lg flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
        <div className="space-y-1">
          <div className="flex items-center gap-2 flex-wrap">
            <span className="px-3 py-1 bg-white/20 backdrop-blur-md rounded-full text-[10px] font-bold uppercase tracking-wider text-emerald-50 border border-white/10">
              Otoritas Kaprodi
            </span>
            {prodiUnik.length > 0 && (
              <span className="px-3 py-1 bg-emerald-950/40 rounded-full text-[10px] font-bold text-emerald-100 border border-emerald-400/30">
                Program Studi: {prodiUnik.join(", ")}
              </span>
            )}
          </div>
          <h1 className="text-2xl font-bold tracking-tight">Persetujuan Judul Skripsi</h1>
          <p className="text-xs text-emerald-100 max-w-2xl leading-relaxed">
            Tinjau usulan judul yang telah disetujui Dosen Pembimbing Akademik, periksa kesesuaian topik penelitian
            dengan bidang prodi, dan tetapkan persetujuan resmi skripsi.
          </p>
        </div>

        <button
          type="button"
          disabled={isLoading}
          onClick={() => fetchAntrean(activeTab)}
          className="px-4 py-2 bg-white/15 hover:bg-white/25 disabled:opacity-50 text-white rounded-xl text-xs font-bold transition flex items-center gap-2 shrink-0 border border-white/20"
        >
          <RotateCcw className={`w-3.5 h-3.5 ${isLoading ? "animate-spin" : ""}`} /> Muat Ulang Data
        </button>
      </div>

      {/* FILTER TABS */}
      <div className="flex flex-wrap items-center gap-2 border-b border-slate-200 pb-2">
        <button
          type="button"
          onClick={() => setActiveTab("antrean")}
          className={`px-4 py-2 rounded-xl text-xs font-bold transition flex items-center gap-1.5 ${
            activeTab === "antrean"
              ? "bg-emerald-600 text-white shadow-sm"
              : "bg-white text-slate-600 hover:bg-slate-100 border border-slate-200"
          }`}
        >
          <Clock className="w-3.5 h-3.5" /> Antrean Menunggu Persetujuan
        </button>

        <button
          type="button"
          onClick={() => setActiveTab("disetujui")}
          className={`px-4 py-2 rounded-xl text-xs font-bold transition flex items-center gap-1.5 ${
            activeTab === "disetujui"
              ? "bg-emerald-600 text-white shadow-sm"
              : "bg-white text-slate-600 hover:bg-slate-100 border border-slate-200"
          }`}
        >
          <CheckCircle2 className="w-3.5 h-3.5" /> Disetujui Kaprodi (Final)
        </button>

        <button
          type="button"
          onClick={() => setActiveTab("revisi")}
          className={`px-4 py-2 rounded-xl text-xs font-bold transition flex items-center gap-1.5 ${
            activeTab === "revisi"
              ? "bg-amber-600 text-white shadow-sm"
              : "bg-white text-slate-600 hover:bg-slate-100 border border-slate-200"
          }`}
        >
          <RotateCcw className="w-3.5 h-3.5" /> Revisi Kaprodi
        </button>

        <button
          type="button"
          onClick={() => setActiveTab("ditolak")}
          className={`px-4 py-2 rounded-xl text-xs font-bold transition flex items-center gap-1.5 ${
            activeTab === "ditolak"
              ? "bg-rose-600 text-white shadow-sm"
              : "bg-white text-slate-600 hover:bg-slate-100 border border-slate-200"
          }`}
        >
          <XCircle className="w-3.5 h-3.5" /> Ditolak Kaprodi
        </button>
      </div>

      {/* KONTEN DAFTAR PENGUJUAN */}
      <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm space-y-4">
        <div className="flex items-center justify-between">
          <h2 className="font-bold text-slate-800 text-sm flex items-center gap-2">
            <Users className="w-4 h-4 text-emerald-600" />
            {activeTab === "antrean" && "Daftar Usulan Judul Menunggu Keputusan Kaprodi"}
            {activeTab === "disetujui" && "Daftar Judul yang Telah Disetujui Kaprodi"}
            {activeTab === "revisi" && "Daftar Pengajuan yang Diminta Revisi oleh Kaprodi"}
            {activeTab === "ditolak" && "Daftar Pengajuan yang Ditolak oleh Kaprodi"}
          </h2>
          <span className="text-xs font-medium text-slate-400">Total: {antrean.length} usulan</span>
        </div>

        {isLoading ? (
          <div className="py-16 flex flex-col justify-center items-center gap-2.5 text-xs text-slate-400">
            <Loader2 className="w-6 h-6 animate-spin text-emerald-600" />
            <span>Memuat antrean usulan judul skripsi...</span>
          </div>
        ) : errorMessage ? (
          <div className="p-4 bg-red-50 border border-red-200 rounded-2xl text-xs text-red-700 text-center">
            {errorMessage}
          </div>
        ) : antrean.length === 0 ? (
          <div className="py-14 text-center space-y-2">
            <div className="w-12 h-12 bg-slate-50 text-slate-300 rounded-2xl flex items-center justify-center mx-auto border border-slate-100">
              <CheckCircle2 className="w-6 h-6" />
            </div>
            <p className="text-xs font-semibold text-slate-600">Tidak ada pengajuan dalam daftar ini</p>
            <p className="text-[11px] text-slate-400 max-w-sm mx-auto">
              {activeTab === "antrean"
                ? "Semua usulan yang diteruskan Dosen PA telah diproses, atau belum ada usulan baru dari mahasiswa."
                : "Belum ada riwayat pengajuan pada kategori ini."}
            </p>
          </div>
        ) : (
          <div className="divide-y divide-slate-100">
            {antrean.map((item) => (
              <div key={item.id} className="py-5 space-y-4">
                {/* Header Kartu: Mahasiswa, NIM, Prodi, Status */}
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
                        <span className="text-[11px] text-emerald-700 font-semibold bg-emerald-50 border border-emerald-200/60 px-2 py-0.5 rounded-md">
                          {item.mahasiswa.prodi}
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

                    <p className="text-sm text-emerald-900 font-bold leading-snug pt-0.5">{item.judul}</p>
                  </div>

                  <span className={`px-3 py-1 rounded-full text-[11px] shrink-0 ${getBadgeStyle(item.status)}`}>
                    {item.status}
                  </span>
                </div>

                {/* Ringkasan Latar Belakang */}
                <div className="p-3.5 bg-slate-50 rounded-2xl border border-slate-100 space-y-1">
                  <p className="text-[11px] font-semibold text-slate-500">Deskripsi / Latar Belakang Singkat:</p>
                  <p className="text-xs text-slate-700 leading-relaxed">{item.deskripsi}</p>
                </div>

                {/* Verifikasi Dosen PA */}
                <div className="p-3 bg-sky-50/70 border border-sky-100 rounded-2xl flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-xs">
                  <div className="flex items-center gap-2">
                    <ShieldCheck className="w-4 h-4 text-sky-600 shrink-0" />
                    <div>
                      <span className="font-bold text-slate-800">Dosen Pembimbing Akademik: </span>
                      <span className="text-slate-700">{getDosenPAName(item)}</span>
                    </div>
                  </div>
                  {getDosenPANote(item) && (
                    <div className="text-[11px] text-sky-900 bg-white/70 px-2.5 py-1 rounded-lg border border-sky-200/50">
                      <span className="font-semibold">Catatan PA: </span>
                      {getDosenPANote(item)}
                    </div>
                  )}
                </div>

                {/* Baris Dokumen & Tombol Aksi */}
                <div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 pt-1">
                  <div className="flex items-center justify-between sm:justify-start gap-3 p-3 bg-emerald-50/60 border border-emerald-200/80 rounded-2xl">
                    <div className="flex items-center gap-2.5 min-w-0">
                      <div className="p-2 bg-emerald-600 text-white rounded-xl shrink-0">
                        <FileText className="w-4 h-4" />
                      </div>
                      <div className="min-w-0">
                        <p className="text-xs font-bold text-slate-800 truncate max-w-[200px] sm:max-w-xs">
                          {item.nama_file}
                        </p>
                        <p className="text-[10px] text-slate-500">Ukuran: {item.ukuran_file}</p>
                      </div>
                    </div>

                    {item.file_url ? (
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
                    ) : (
                      <span className="text-[11px] text-slate-400 italic">Dokumen belum tersedia</span>
                    )}
                  </div>

                  <div className="self-end sm:self-center">
                    {item.status === "Menunggu Persetujuan Kaprodi" ? (
                      <button
                        type="button"
                        onClick={() => handleOpenReview(item)}
                        className="px-4 py-2 bg-slate-800 hover:bg-slate-900 text-white text-xs font-bold rounded-xl shadow transition flex items-center gap-1.5"
                      >
                        <MessageSquare className="w-3.5 h-3.5" /> Tinjau &amp; Berikan Keputusan
                      </button>
                    ) : (
                      <button
                        type="button"
                        onClick={() => handleOpenReview(item)}
                        className="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold rounded-xl transition flex items-center gap-1.5 border border-slate-200"
                      >
                        <History className="w-3.5 h-3.5" /> Lihat Detail &amp; Log
                      </button>
                    )}
                  </div>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* ------------------------------------------------------------------- */}
      {/* MODAL DETAIL REVIEW & KEPUTUSAN KAPRODI */}
      {/* ------------------------------------------------------------------- */}
      {selectedPengajuan && (
        <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
          <div className="bg-white rounded-3xl max-w-2xl w-full p-6 shadow-2xl border border-slate-100 space-y-5 my-8 max-h-[90vh] overflow-y-auto">
            {/* Header Modal */}
            <div className="flex items-center justify-between border-b border-slate-100 pb-3">
              <div>
                <h3 className="font-bold text-base text-slate-800">
                  {selectedPengajuan.status === "Menunggu Persetujuan Kaprodi"
                    ? "Keputusan Persetujuan Kaprodi"
                    : "Detail Usulan Judul Skripsi"}
                </h3>
                <p className="text-xs text-slate-400">
                  Mahasiswa: {selectedPengajuan.mahasiswa?.nama_mahasiswa} ({selectedPengajuan.mahasiswa?.NIM}) •{" "}
                  {selectedPengajuan.mahasiswa?.prodi}
                </p>
              </div>
              <button
                type="button"
                disabled={isSubmitting}
                onClick={handleCloseReview}
                className="p-1.5 text-slate-400 hover:text-slate-600 rounded-xl hover:bg-slate-100 transition"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {/* Notifikasi Error Aksi */}
            {actionError && (
              <div className="p-3.5 bg-red-50 border border-red-200 rounded-2xl flex items-center gap-2 text-red-700 text-xs">
                <AlertCircle className="w-4 h-4 shrink-0 text-red-600" />
                <span>{actionError}</span>
              </div>
            )}

            {/* Notifikasi Sukses Aksi */}
            {actionSuccess && (
              <div className="p-3.5 bg-emerald-50 border border-emerald-200 rounded-2xl flex items-center gap-2 text-emerald-800 text-xs font-semibold">
                <CheckCircle2 className="w-4 h-4 shrink-0 text-emerald-600" />
                <span>{actionSuccess}</span>
              </div>
            )}

            {/* Ringkasan Data Usulan */}
            <div className="space-y-3">
              <div>
                <label className="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1">
                  Judul Skripsi yang Diajukan
                </label>
                <p className="text-xs font-bold text-emerald-900 bg-emerald-50/60 p-3 rounded-2xl border border-emerald-200/60 leading-relaxed">
                  {selectedPengajuan.judul}
                </p>
              </div>

              <div>
                <label className="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1">
                  Deskripsi / Rumusan Masalah
                </label>
                <p className="text-xs text-slate-700 bg-slate-50 p-3 rounded-2xl border border-slate-100 leading-relaxed">
                  {selectedPengajuan.deskripsi}
                </p>
              </div>

              {/* Berkas Proposal */}
              <div>
                <label className="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1">
                  Lampiran Berkas Proposal
                </label>
                <div className="p-3 bg-slate-50 border border-slate-200 rounded-2xl flex items-center justify-between">
                  <div className="flex items-center gap-2.5">
                    <FileText className="w-4 h-4 text-emerald-600" />
                    <div>
                      <p className="text-xs font-bold text-slate-800">{selectedPengajuan.nama_file}</p>
                      <p className="text-[10px] text-slate-400">{selectedPengajuan.ukuran_file}</p>
                    </div>
                  </div>
                  {selectedPengajuan.file_url && (
                    <div className="flex items-center gap-2">
                      <a
                        href={selectedPengajuan.file_url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="px-3 py-1.5 bg-white text-emerald-700 border border-emerald-200 rounded-xl text-xs font-semibold flex items-center gap-1 shadow-sm hover:bg-emerald-50"
                      >
                        <ExternalLink className="w-3.5 h-3.5" /> Buka Dokumen
                      </a>
                    </div>
                  )}
                </div>
              </div>

              {/* Verifikasi Dosen PA */}
              <div>
                <label className="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1">
                  Verifikasi Dosen PA
                </label>
                <div className="p-3 bg-sky-50/60 border border-sky-100 rounded-2xl space-y-1">
                  <p className="text-xs text-slate-700">
                    <span className="font-bold">Dosen PA: </span>
                    {getDosenPAName(selectedPengajuan)}
                  </p>
                  {getDosenPANote(selectedPengajuan) && (
                    <p className="text-xs text-sky-900 bg-white p-2 rounded-xl border border-sky-100">
                      <span className="font-semibold">Catatan PA: </span>
                      {getDosenPANote(selectedPengajuan)}
                    </p>
                  )}
                </div>
              </div>

              {/* Riwayat Log Audit Trail */}
              {selectedPengajuan.logs && selectedPengajuan.logs.length > 0 && (
                <div>
                  <label className="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1">
                    Riwayat Audit Trail Tahapan
                  </label>
                  <div className="space-y-1.5 max-h-36 overflow-y-auto border border-slate-100 rounded-2xl p-2.5 bg-slate-50/50">
                    {selectedPengajuan.logs.map((log) => (
                      <div key={log.id} className="text-[11px] p-2 bg-white rounded-xl border border-slate-100 space-y-0.5">
                        <div className="flex items-center justify-between text-slate-500">
                          <span className="font-semibold text-slate-700">
                            {log.aktor_nama} ({log.aktor_role})
                          </span>
                          <span className="text-[10px]">
                            {new Date(log.created_at).toLocaleDateString("id-ID", {
                              day: "2-digit",
                              month: "short",
                              hour: "2-digit",
                              minute: "2-digit",
                            })}
                          </span>
                        </div>
                        <p className="text-slate-600">
                          Tindakan: <span className="font-medium text-emerald-800">{log.tindakan}</span>
                          {log.status_sesudah && (
                            <span> &rarr; {log.status_sesudah}</span>
                          )}
                        </p>
                        {log.catatan && (
                          <p className="text-slate-500 italic bg-slate-50 p-1.5 rounded-lg border border-slate-100">
                            &ldquo;{log.catatan}&rdquo;
                          </p>
                        )}
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>

            {/* FORM TINDAKAN KEPUTUSAN KAPRODI */}
            {selectedPengajuan.status === "Menunggu Persetujuan Kaprodi" && (
              <div className="pt-2 border-t border-slate-100 space-y-3">
                <div>
                  <label className="block text-xs font-bold text-slate-700 mb-1">
                    Catatan Kaprodi{" "}
                    <span className="text-slate-400 font-normal">
                      (Wajib diisi jika Minta Revisi atau Tolak, maks. 1000 karakter)
                    </span>
                  </label>
                  <textarea
                    rows={3}
                    maxLength={1000}
                    disabled={isSubmitting}
                    value={catatanKaprodi}
                    onChange={(e) => setCatatanKaprodi(e.target.value)}
                    placeholder="Tuliskan arahan perbaikan topik, catatan metodologi, atau alasan penolakan..."
                    className="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 text-xs focus:outline-none focus:border-emerald-600 resize-none bg-white"
                  />
                  <div className="flex justify-end">
                    <span className="text-[10px] text-slate-400">{catatanKaprodi.length} / 1000</span>
                  </div>
                </div>

                {/* MODAL KONFIRMASI PERSETUJUAN FINAL */}
                {showConfirmModal ? (
                  <div className="p-4 bg-emerald-50 border border-emerald-200 rounded-2xl space-y-3">
                    <div className="flex items-start gap-2.5">
                      <AlertTriangle className="w-5 h-5 text-emerald-700 shrink-0 mt-0.5" />
                      <div>
                        <h4 className="font-bold text-xs text-emerald-900">
                          Konfirmasi Persetujuan Final Judul Skripsi
                        </h4>
                        <p className="text-[11px] text-emerald-800 leading-relaxed mt-0.5">
                          Persetujuan ini adalah <strong>persetujuan resmi final</strong> yang akan memperbarui judul
                          skripsi mahasiswa di sistem secara permanen dan menutup antrean pengajuan judul. Lanjutkan?
                        </p>
                      </div>
                    </div>

                    <div className="flex justify-end gap-2 pt-1">
                      <button
                        type="button"
                        disabled={isSubmitting}
                        onClick={() => setShowConfirmModal(false)}
                        className="px-3.5 py-1.5 bg-white text-slate-700 border border-slate-200 text-xs font-semibold rounded-xl hover:bg-slate-50 transition"
                      >
                        Batal
                      </button>
                      <button
                        type="button"
                        disabled={isSubmitting}
                        onClick={() => handleKirimKeputusan("Disetujui Kaprodi")}
                        className="px-4 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold rounded-xl shadow transition flex items-center gap-1.5"
                      >
                        {isSubmitting ? (
                          <Loader2 className="w-3.5 h-3.5 animate-spin" />
                        ) : (
                          <CheckCircle2 className="w-3.5 h-3.5" />
                        )}
                        Ya, Setujui Final
                      </button>
                    </div>
                  </div>
                ) : (
                  <div className="flex flex-wrap items-center justify-end gap-2 pt-1">
                    <button
                      type="button"
                      disabled={isSubmitting}
                      onClick={() => handleKirimKeputusan("Disetujui Kaprodi")}
                      className="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white text-xs font-bold rounded-xl shadow transition flex items-center gap-1.5"
                    >
                      <CheckCircle2 className="w-3.5 h-3.5" /> Setujui Judul (Final)
                    </button>

                    <button
                      type="button"
                      disabled={isSubmitting}
                      onClick={() => handleKirimKeputusan("Revisi Kaprodi")}
                      className="px-4 py-2 bg-amber-500 hover:bg-amber-600 disabled:opacity-50 text-white text-xs font-bold rounded-xl shadow transition flex items-center gap-1.5"
                    >
                      <RotateCcw className="w-3.5 h-3.5" /> Minta Revisi
                    </button>

                    <button
                      type="button"
                      disabled={isSubmitting}
                      onClick={() => handleKirimKeputusan("Ditolak Kaprodi")}
                      className="px-4 py-2 bg-rose-600 hover:bg-rose-700 disabled:opacity-50 text-white text-xs font-bold rounded-xl shadow transition flex items-center gap-1.5"
                    >
                      <XCircle className="w-3.5 h-3.5" /> Tolak Usulan
                    </button>
                  </div>
                )}
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
