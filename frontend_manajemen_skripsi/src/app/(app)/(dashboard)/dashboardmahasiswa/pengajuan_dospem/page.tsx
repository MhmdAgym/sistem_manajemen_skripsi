"use client";

import React, { useState, useEffect, useCallback } from "react";
import Link from "next/link";
import {
  UserCheck,
  Send,
  CheckCircle2,
  AlertCircle,
  Clock,
  FileText,
  Loader2,
  RefreshCw,
  ChevronDown,
  ChevronUp,
  AlertTriangle,
  BookOpen,
  XCircle,
  GraduationCap,
  X
} from "lucide-react";
import axios from "@/lib/axios";
import { AxiosError } from "axios";

interface SkripsiInfo {
  id_skripsi: number;
  judul: string;
  status_judul: string;
}

interface DosenInfo {
  id_dosen: number;
  nama_dosen: string;
  NIDN: string;
}

interface PengajuanLogItem {
  id: number;
  pengajuan_dospem_id: number;
  user_id?: number | null;
  aktor_nama: string;
  aktor_role: string;
  aktor_nomor_induk?: string | null;
  tahap: string;
  tindakan: string;
  status_sebelum?: string | null;
  status_sesudah: string;
  catatan?: string | null;
  snapshot_dosen_id?: number | null;
  snapshot_dosen_nama?: string | null;
  created_at: string;
}

interface PengajuanDospemItem {
  id: number;
  skripsi_id: number;
  mahasiswa_id: number;
  tahap: "proposal" | "skripsi";
  status: "menunggu_penetapan" | "ditetapkan" | "ditolak";
  dosen_id?: number | null;
  catatan_mahasiswa?: string | null;
  catatan_admin?: string | null;
  created_at: string;
  updated_at?: string;
  dosen?: DosenInfo | null;
  skripsi?: SkripsiInfo | null;
  logs?: PengajuanLogItem[];
}

interface StatusKelayakanResponse {
  layak_mengajukan: boolean;
  pesan?: string;
  alasan?: string;
  detail_alasan?: string;
  skripsi?: SkripsiInfo | null;
  status_pengajuan_terkini?: "menunggu_penetapan" | "ditetapkan" | "ditolak" | null;
  pengajuan?: PengajuanDospemItem | null;
}

export default function PengajuanDospemMahasiswaPage() {
  // State Status Kelayakan
  const [kelayakan, setKelayakan] = useState<StatusKelayakanResponse | null>(null);
  const [loadingKelayakan, setLoadingKelayakan] = useState<boolean>(true);
  const [errorKelayakan, setErrorKelayakan] = useState<string | null>(null);

  // State Riwayat Pengajuan
  const [riwayat, setRiwayat] = useState<PengajuanDospemItem[]>([]);
  const [loadingRiwayat, setLoadingRiwayat] = useState<boolean>(true);
  const [errorRiwayat, setErrorRiwayat] = useState<string | null>(null);

  // State Form Pengajuan Baru
  const [catatanMahasiswa, setCatatanMahasiswa] = useState<string>("");
  const [isSubmitting, setIsSubmitting] = useState<boolean>(false);
  const [submitError, setSubmitError] = useState<string | null>(null);
  const [submitSuccess, setSubmitSuccess] = useState<string | null>(null);

  // State Accordion Log Audit
  const [expandedLogId, setExpandedLogId] = useState<number | null>(null);

  const MAX_CHAR = 1000;

  // 1. Fetch Status Kelayakan
  const fetchStatusKelayakan = useCallback(async () => {
    try {
      setLoadingKelayakan(true);
      setErrorKelayakan(null);
      const res = await axios.get("/api/mahasiswa/pengajuan-dospem/status-kelayakan");
      if (res.data?.status === "success" && res.data?.data) {
        setKelayakan(res.data.data);
      } else {
        setKelayakan(null);
      }
    } catch (err) {
      const error = err as AxiosError<{ message?: string }>;
      const status = error.response?.status;
      if (status === 401) {
        setErrorKelayakan("Sesi Anda telah berakhir. Silakan login kembali.");
      } else if (status === 403) {
        setErrorKelayakan("Akses ditolak. Fitur ini hanya dapat diakses oleh Mahasiswa.");
      } else if (status === 404) {
        setErrorKelayakan(error.response?.data?.message || "Profil mahasiswa tidak ditemukan. Hubungi Administrator.");
      } else if (status === 422) {
        setErrorKelayakan(error.response?.data?.message || "Data skripsi Anda belum tersedia di sistem. Hubungi Administrator.");
      } else {
        setErrorKelayakan(error.response?.data?.message || "Gagal memuat status kelayakan akademik dari server.");
      }
    } finally {
      setLoadingKelayakan(false);
    }
  }, []);

  // 2. Fetch Riwayat Pengajuan
  const fetchRiwayat = useCallback(async () => {
    try {
      setLoadingRiwayat(true);
      setErrorRiwayat(null);
      const res = await axios.get("/api/mahasiswa/pengajuan-dospem");
      if (res.data?.status === "success" && Array.isArray(res.data?.data)) {
        setRiwayat(res.data.data);
      } else {
        setRiwayat([]);
      }
    } catch (err) {
      const error = err as AxiosError<{ message?: string }>;
      const status = error.response?.status;
      if (status === 401) {
        setErrorRiwayat("Sesi Anda telah berakhir. Silakan login kembali.");
      } else if (status === 403) {
        setErrorRiwayat("Akses ditolak. Halaman ini khusus untuk akun mahasiswa.");
      } else {
        setErrorRiwayat(error.response?.data?.message || "Gagal memuat riwayat pengajuan Dosen Pembimbing.");
      }
    } finally {
      setLoadingRiwayat(false);
    }
  }, []);

  useEffect(() => {
    fetchStatusKelayakan();
    fetchRiwayat();
  }, [fetchStatusKelayakan, fetchRiwayat]);

  // Handle Form Submit
  const handleSubmitPengajuan = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitError(null);
    setSubmitSuccess(null);

    if (catatanMahasiswa.length > MAX_CHAR) {
      setSubmitError(`Catatan permohonan melebihi batas maksimal ${MAX_CHAR} karakter.`);
      return;
    }

    try {
      setIsSubmitting(true);
      const res = await axios.post("/api/mahasiswa/pengajuan-dospem", {
        catatan_mahasiswa: catatanMahasiswa.trim() ? catatanMahasiswa.trim() : null,
      });

      if (res.data?.status === "success") {
        setSubmitSuccess(res.data?.message || "Pengajuan Dosen Pembimbing Proposal berhasil dikirim.");
        // Perubahan F-03-B: Set layak_mengajukan false dan status_pengajuan_terkini "menunggu_penetapan"
        // secara optimistis agar UI langsung menampilkan status Sedang Dalam Proses (Case C)
        // tanpa sempat mengevaluasi status Belum Memenuhi Syarat (Case B).
        setKelayakan((prev) =>
          prev
            ? {
                ...prev,
                layak_mengajukan: false,
                status_pengajuan_terkini: "menunggu_penetapan",
              }
            : prev
        );
        setCatatanMahasiswa("");
        // Muat ulang kelayakan dan riwayat pengajuan dari server
        await Promise.all([fetchStatusKelayakan(), fetchRiwayat()]);
      }
    } catch (err) {
      const error = err as AxiosError<{ message?: string }>;
      const status = error.response?.status;
      if (status === 401) {
        setSubmitError("Sesi Anda telah kedaluwarsa. Silakan login ulang.");
      } else if (status === 403) {
        setSubmitError("Akses ditolak. Anda tidak berwenang mengirim pengajuan.");
      } else if (status === 409) {
        setSubmitError(error.response?.data?.message || "Masih terdapat pengajuan aktif atau pembimbing telah ditetapkan.");
        fetchStatusKelayakan();
      } else if (status === 422) {
        setSubmitError(error.response?.data?.message || "Persyaratan pengajuan belum terpenuhi (judul belum disetujui Kaprodi).");
      } else {
        setSubmitError(error.response?.data?.message || "Terjadi kesalahan pada server saat mengirim pengajuan. Coba beberapa saat lagi.");
      }
    } finally {
      setIsSubmitting(false);
    }
  };

  const toggleAccordionLog = (id: number) => {
    setExpandedLogId((prev) => (prev === id ? null : id));
  };

  const formatDate = (dateStr?: string) => {
    if (!dateStr) return "-";
    try {
      return new Date(dateStr).toLocaleString("id-ID", {
        day: "numeric",
        month: "short",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
      });
    } catch {
      return dateStr;
    }
  };

  const renderStatusBadge = (status: "menunggu_penetapan" | "ditetapkan" | "ditolak") => {
    switch (status) {
      case "menunggu_penetapan":
        return (
          <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-amber-50 text-amber-800 border border-amber-200">
            <Clock className="w-3.5 h-3.5 text-amber-600" />
            Menunggu Penetapan
          </span>
        );
      case "ditetapkan":
        return (
          <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-800 border border-emerald-200">
            <CheckCircle2 className="w-3.5 h-3.5 text-emerald-600" />
            Ditetapkan
          </span>
        );
      case "ditolak":
        return (
          <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-rose-50 text-rose-800 border border-rose-200">
            <XCircle className="w-3.5 h-3.5 text-rose-600" />
            Ditolak
          </span>
        );
      default:
        return (
          <span className="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-slate-100 text-slate-700">
            {status}
          </span>
        );
    }
  };

  return (
    <div className="space-y-6 font-sans text-slate-800">
      {/* 1. WELCOME BANNER */}
      <div className="p-6 bg-[#15803D] rounded-3xl text-white shadow-sm flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
        <div>
          <span className="px-3 py-1 bg-white/20 backdrop-blur-md rounded-full text-[11px] font-semibold uppercase tracking-wider text-emerald-50 border border-white/10">
            Portal Tugas Akhir
          </span>
          <h1 className="text-2xl font-bold mt-2 tracking-tight">Pengajuan Dosen Pembimbing Proposal</h1>
          <p className="text-xs text-emerald-100 mt-1 max-w-xl leading-relaxed">
            Permohonan penetapan Dosen Pembimbing Proposal kepada Administrator Program Studi setelah usulan judul skripsi resmi disetujui oleh Kaprodi.
          </p>
        </div>
        <button
          onClick={() => {
            fetchStatusKelayakan();
            fetchRiwayat();
          }}
          disabled={loadingKelayakan || loadingRiwayat}
          className="inline-flex items-center gap-2 px-4 py-2 bg-white/15 hover:bg-white/25 border border-white/20 text-white rounded-2xl text-xs font-medium transition disabled:opacity-50"
        >
          <RefreshCw className={`w-3.5 h-3.5 ${loadingKelayakan || loadingRiwayat ? "animate-spin" : ""}`} />
          Perbarui Data
        </button>
      </div>

      {/* Perubahan F-03-A: FEEDBACK SUCCESS BANNER — diletakkan di luar conditional form */}
      {submitSuccess && (
        <div className="p-4 bg-emerald-50 border border-emerald-200 rounded-2xl text-xs text-emerald-800 flex items-center justify-between gap-3 shadow-sm">
          <div className="flex items-center gap-2.5">
            <CheckCircle2 className="w-5 h-5 text-emerald-600 shrink-0" />
            <div>
              <p className="font-semibold text-emerald-900">Pengajuan Berhasil Dikirim</p>
              <p className="mt-0.5 text-emerald-700">{submitSuccess}</p>
            </div>
          </div>
          <button
            type="button"
            onClick={() => setSubmitSuccess(null)}
            className="p-1 hover:bg-emerald-100 rounded-lg text-emerald-700 transition"
            title="Tutup pemberitahuan"
          >
            <X className="w-4 h-4" />
          </button>
        </div>
      )}

      {/* 2. KARTU STATUS KELAYAKAN AKADEMIK */}
      <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm space-y-4">
        <div className="flex items-center justify-between border-b border-slate-100 pb-3">
          <div className="flex items-center gap-2.5">
            <div className="p-2 bg-emerald-50 text-[#15803D] rounded-2xl border border-emerald-100">
              <BookOpen className="w-5 h-5" />
            </div>
            <div>
              <h2 className="font-bold text-slate-800 text-sm">Status Kelayakan Akademik</h2>
              <p className="text-xs text-slate-400">Verifikasi otoritatif persyaratan pengajuan pembimbing proposal</p>
            </div>
          </div>
        </div>

        {loadingKelayakan ? (
          <div className="py-8 flex flex-col items-center justify-center text-slate-400 gap-2">
            <Loader2 className="w-7 h-7 animate-spin text-[#15803D]" />
            <p className="text-xs">Memeriksa status kelayakan akademik...</p>
          </div>
        ) : errorKelayakan ? (
          <div className="p-4 bg-rose-50 border border-rose-200 rounded-2xl text-xs text-rose-700 flex items-start gap-3">
            <AlertCircle className="w-5 h-5 shrink-0 text-rose-600 mt-0.5" />
            <div>
              <p className="font-semibold">Pemeriksaan Kelayakan Gagal</p>
              <p className="mt-0.5 text-rose-600">{errorKelayakan}</p>
            </div>
          </div>
        ) : kelayakan ? (
          <div className="space-y-4">
            {/* Informasi Judul Skripsi */}
            {kelayakan.skripsi ? (
              <div className="p-4 bg-slate-50 border border-slate-200/80 rounded-2xl space-y-2">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                  <span className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                    Judul Skripsi Terdaftar
                  </span>
                  <span
                    className={`inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-semibold w-max ${
                      kelayakan.skripsi.status_judul === "disetujui"
                        ? "bg-emerald-100 text-emerald-800 border border-emerald-200"
                        : "bg-amber-100 text-amber-800 border border-amber-200"
                    }`}
                  >
                    {kelayakan.skripsi.status_judul === "disetujui" ? (
                      <>
                        <CheckCircle2 className="w-3 h-3 text-emerald-600" />
                        Judul Disetujui
                      </>
                    ) : (
                      <>
                        <AlertTriangle className="w-3 h-3 text-amber-600" />
                        Status Judul: {kelayakan.skripsi.status_judul}
                      </>
                    )}
                  </span>
                </div>
                <p className="font-bold text-sm text-slate-800 leading-snug">{kelayakan.skripsi.judul}</p>
              </div>
            ) : null}

            {/* Status Case A: Memenuhi Syarat & Layak Mengajukan */}
            {kelayakan.layak_mengajukan && (
              <div className="p-4 bg-emerald-50 border border-emerald-200 rounded-2xl flex items-start gap-3">
                <CheckCircle2 className="w-5 h-5 text-[#15803D] shrink-0 mt-0.5" />
                <div className="text-xs text-emerald-900 space-y-1">
                  <p className="font-bold text-sm text-[#15803D]">Memenuhi Syarat Pengajuan</p>
                  <p className="text-emerald-800">
                    {kelayakan.pesan || "Judul skripsi Anda telah resmi disetujui oleh Kaprodi. Anda dapat mengirimkan pengajuan Dosen Pembimbing Proposal melalui formulir di bawah."}
                  </p>
                </div>
              </div>
            )}

            {/* Status Case B: Judul Belum Disetujui Kaprodi */}
            {!kelayakan.layak_mengajukan && !kelayakan.status_pengajuan_terkini && (
              <div className="p-4 bg-amber-50 border border-amber-200 rounded-2xl flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                <div className="flex items-start gap-3">
                  <AlertTriangle className="w-5 h-5 text-amber-600 shrink-0 mt-0.5" />
                  <div className="text-xs text-amber-900 space-y-0.5">
                    <p className="font-bold text-sm text-amber-800">Belum Memenuhi Syarat</p>
                    <p className="text-amber-800">{kelayakan.alasan}</p>
                    {kelayakan.detail_alasan && (
                      <p className="text-[11px] text-amber-700 italic">Keterangan: {kelayakan.detail_alasan}</p>
                    )}
                  </div>
                </div>
                <Link
                  href="/dashboardmahasiswa/pengajuan_judul"
                  className="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-xl text-xs font-semibold whitespace-nowrap transition shadow-sm"
                >
                  Cek Pengajuan Judul
                </Link>
              </div>
            )}

            {/* Status Case C: Sedang Menunggu Penetapan Admin */}
            {!kelayakan.layak_mengajukan && kelayakan.status_pengajuan_terkini === "menunggu_penetapan" && (
              <div className="p-4 bg-amber-50/80 border border-amber-200 rounded-2xl flex items-start gap-3">
                <Clock className="w-5 h-5 text-amber-600 shrink-0 mt-0.5" />
                <div className="text-xs text-amber-900 space-y-1">
                  <p className="font-bold text-sm text-amber-800">Pengajuan Sedang Dalam Proses</p>
                  <p className="text-amber-800 leading-relaxed">
                    Pengajuan Dosen Pembimbing Proposal Anda saat ini berada dalam status{" "}
                    <span className="font-semibold">Menunggu Penetapan</span> oleh Administrator Program Studi. Mohon
                    pantau pembaruan riwayat di bawah ini secara berkala.
                  </p>
                </div>
              </div>
            )}

            {/* Status Case D: Dosen Pembimbing Sudah Ditetapkan */}
            {!kelayakan.layak_mengajukan && kelayakan.status_pengajuan_terkini === "ditetapkan" && (
              <div className="p-5 bg-emerald-50/90 border border-emerald-200 rounded-2xl space-y-3">
                <div className="flex items-center gap-2.5">
                  <CheckCircle2 className="w-5 h-5 text-[#15803D]" />
                  <p className="font-bold text-sm text-[#15803D]">Dosen Pembimbing Proposal Telah Ditetapkan</p>
                </div>
                {kelayakan.pengajuan?.dosen ? (
                  <div className="p-3.5 bg-white border border-emerald-200 rounded-xl flex items-center justify-between">
                    <div>
                      <p className="text-xs text-slate-400 font-medium">Dosen Pembimbing Proposal:</p>
                      <p className="font-bold text-sm text-slate-800 mt-0.5">{kelayakan.pengajuan.dosen.nama_dosen}</p>
                      <p className="text-[11px] text-slate-500 font-mono">NIDN: {kelayakan.pengajuan.dosen.NIDN}</p>
                    </div>
                    <GraduationCap className="w-8 h-8 text-[#15803D]/30" />
                  </div>
                ) : (
                  <p className="text-xs text-emerald-800">
                    Penetapan dosen pembimbing proposal telah tercatat di basis data operasional.
                  </p>
                )}
              </div>
            )}
          </div>
        ) : null}
      </div>

      {/* 3. FORMULIR PENGAJUAN (HANYA DIRENDER JIKA LAYAK MENGAJUKAN) */}
      {kelayakan && kelayakan.layak_mengajukan && (
        <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm space-y-5">
          <div className="flex items-center gap-2.5 border-b border-slate-100 pb-3">
            <div className="p-2 bg-emerald-50 text-[#15803D] rounded-2xl border border-emerald-100">
              <Send className="w-5 h-5" />
            </div>
            <div>
              <h2 className="font-bold text-slate-800 text-sm">Formulir Pengajuan Pembimbing Proposal</h2>
              <p className="text-xs text-slate-400">Kirimkan permohonan penetapan pembimbing proposal ke Administrator</p>
            </div>
          </div>

          {submitError && (
            <div className="p-4 bg-rose-50 border border-rose-200 rounded-2xl text-xs text-rose-700 flex items-start gap-3">
              <AlertCircle className="w-4 h-4 shrink-0 text-rose-600 mt-0.5" />
              <div>
                <p className="font-semibold">Pengajuan Gagal Dikirim</p>
                <p className="mt-0.5 text-rose-600">{submitError}</p>
              </div>
            </div>
          )}

          <form onSubmit={handleSubmitPengajuan} className="space-y-4">
            {/* Penegasan Desain Bisnis: Mahasiswa Tidak Memilih Dosen */}
            <div className="p-3.5 bg-slate-50 border border-slate-200/80 rounded-2xl text-xs text-slate-600 flex items-start gap-2.5">
              <UserCheck className="w-4 h-4 text-[#15803D] shrink-0 mt-0.5" />
              <p className="leading-relaxed">
                <span className="font-bold text-slate-700">Kebijakan Penetapan:</span> Mahasiswa tidak memilih dosen
                pembimbing secara mandiri. Dosen Pembimbing Proposal akan ditetapkan secara objektif oleh Administrator
                Program Studi dengan mempertimbangkan relevansi bidang keilmuan dan ketersediaan kuota bimbingan.
              </p>
            </div>

            {/* Input Catatan Mahasiswa */}
            <div className="space-y-1.5">
              <div className="flex justify-between items-center">
                <label htmlFor="catatan_mahasiswa" className="text-xs font-bold text-slate-700">
                  Catatan Tambahan untuk Administrator <span className="font-normal text-slate-400">(Opsional)</span>
                </label>
                <span
                  className={`text-[11px] font-mono ${
                    catatanMahasiswa.length > MAX_CHAR ? "text-rose-600 font-bold" : "text-slate-400"
                  }`}
                >
                  {catatanMahasiswa.length}/{MAX_CHAR}
                </span>
              </div>
              <textarea
                id="catatan_mahasiswa"
                rows={4}
                value={catatanMahasiswa}
                onChange={(e) => setCatatanMahasiswa(e.target.value)}
                maxLength={MAX_CHAR}
                placeholder="Contoh: Menyampaikan permohonan penetapan dosen pembimbing proposal yang fokus pada rekayasa perangkat lunak / AI..."
                className="w-full px-4 py-3 rounded-2xl border border-slate-200 text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-[#15803D]/20 focus:border-[#15803D] transition placeholder:text-slate-400"
              />
            </div>

            <div className="flex justify-end pt-2">
              <button
                type="submit"
                disabled={isSubmitting}
                className="inline-flex items-center gap-2 px-6 py-2.5 bg-[#15803D] hover:bg-emerald-800 text-white rounded-2xl text-xs font-bold shadow-md hover:shadow transition disabled:opacity-50"
              >
                {isSubmitting ? (
                  <>
                    <Loader2 className="w-4 h-4 animate-spin" />
                    Mengirimkan Pengajuan...
                  </>
                ) : (
                  <>
                    <Send className="w-4 h-4" />
                    Kirim Pengajuan Pembimbing
                  </>
                )}
              </button>
            </div>
          </form>
        </div>
      )}

      {/* 4. RIWAYAT PENGAJUAN & AUDIT LOG */}
      <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm space-y-4">
        <div className="flex items-center justify-between border-b border-slate-100 pb-3">
          <div className="flex items-center gap-2.5">
            <div className="p-2 bg-emerald-50 text-[#15803D] rounded-2xl border border-emerald-100">
              <FileText className="w-5 h-5" />
            </div>
            <div>
              <h2 className="font-bold text-slate-800 text-sm">Riwayat Pengajuan Pembimbing</h2>
              <p className="text-xs text-slate-400">Daftar historis pengajuan dan catatan audit dari administrator</p>
            </div>
          </div>
          <span className="text-xs font-semibold text-slate-400">Total: {riwayat.length} Pengajuan</span>
        </div>

        {loadingRiwayat ? (
          <div className="py-8 flex flex-col items-center justify-center text-slate-400 gap-2">
            <Loader2 className="w-7 h-7 animate-spin text-[#15803D]" />
            <p className="text-xs">Memuat data riwayat pengajuan...</p>
          </div>
        ) : errorRiwayat ? (
          <div className="p-4 bg-rose-50 border border-rose-200 rounded-2xl text-xs text-rose-700">
            {errorRiwayat}
          </div>
        ) : riwayat.length === 0 ? (
          <div className="py-12 flex flex-col items-center justify-center text-center space-y-2">
            <div className="w-12 h-12 bg-slate-100 text-slate-400 rounded-full flex items-center justify-center">
              <FileText className="w-6 h-6" />
            </div>
            <p className="text-xs font-bold text-slate-700">Belum Ada Riwayat Pengajuan</p>
            <p className="text-[11px] text-slate-400 max-w-sm">
              Anda belum pernah mengirimkan permohonan penetapan Dosen Pembimbing Proposal.
            </p>
          </div>
        ) : (
          <div className="space-y-4">
            {riwayat.map((item) => (
              <div
                key={item.id}
                className="p-5 rounded-2xl border border-slate-100 hover:border-emerald-200/80 transition bg-slate-50/50 space-y-4"
              >
                {/* Header Kartu Riwayat */}
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-slate-100 pb-3">
                  <div className="space-y-0.5">
                    <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                      Tahap: {item.tahap?.toUpperCase() || "PROPOSAL"}
                    </span>
                    <p className="text-xs text-slate-500">
                      Diajukan pada: <span className="font-semibold text-slate-700">{formatDate(item.created_at)}</span>
                    </p>
                  </div>
                  <div>{renderStatusBadge(item.status)}</div>
                </div>

                {/* Detail Bimbingan & Catatan */}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                  <div className="space-y-1">
                    <p className="text-slate-400 font-medium">Dosen Pembimbing Ditetapkan:</p>
                    {item.dosen ? (
                      <div className="p-2.5 bg-white border border-slate-200 rounded-xl">
                        <p className="font-bold text-slate-800">{item.dosen.nama_dosen}</p>
                        <p className="text-[11px] text-slate-500 font-mono">NIDN: {item.dosen.NIDN}</p>
                      </div>
                    ) : (
                      <p className="text-slate-500 italic">Belum ada dosen yang ditetapkan</p>
                    )}
                  </div>

                  <div className="space-y-1">
                    <p className="text-slate-400 font-medium">Catatan Pengajuan Anda:</p>
                    <div className="p-2.5 bg-white border border-slate-200 rounded-xl min-h-[46px] text-slate-700">
                      {item.catatan_mahasiswa || <span className="text-slate-400 italic">Tidak ada catatan</span>}
                    </div>
                  </div>
                </div>

                {item.catatan_admin && (
                  <div className="p-3 bg-white border border-slate-200 rounded-xl text-xs space-y-1">
                    <p className="font-bold text-slate-700 flex items-center gap-1.5">
                      <UserCheck className="w-3.5 h-3.5 text-[#15803D]" />
                      Catatan Administrator:
                    </p>
                    <p className="text-slate-600 leading-relaxed pl-5">{item.catatan_admin}</p>
                  </div>
                )}

                {/* Accordion Audit Trail */}
                {item.logs && item.logs.length > 0 && (
                  <div className="pt-1">
                    <button
                      type="button"
                      onClick={() => toggleAccordionLog(item.id)}
                      className="inline-flex items-center gap-1.5 text-xs font-semibold text-[#15803D] hover:text-emerald-800 transition"
                    >
                      {expandedLogId === item.id ? (
                        <>
                          <ChevronUp className="w-4 h-4" />
                          Sembunyikan Log Audit ({item.logs.length})
                        </>
                      ) : (
                        <>
                          <ChevronDown className="w-4 h-4" />
                          Lihat Log Audit ({item.logs.length})
                        </>
                      )}
                    </button>

                    {expandedLogId === item.id && (
                      <div className="mt-3 p-4 bg-white border border-slate-200 rounded-2xl space-y-3">
                        <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">
                          Catatan Aktivitas Audit (Append-Only):
                        </p>
                        <div className="relative border-l-2 border-emerald-100 ml-2 pl-4 space-y-3">
                          {item.logs.map((log) => (
                            <div key={log.id} className="relative space-y-0.5 text-xs">
                              <span className="absolute -left-[21px] top-1.5 w-2 h-2 rounded-full bg-[#15803D]" />
                              <div className="flex flex-wrap items-center gap-2">
                                <span className="font-bold text-slate-800">{log.aktor_nama}</span>
                                <span className="px-2 py-0.2 bg-slate-100 text-slate-600 rounded-full text-[10px] uppercase font-semibold">
                                  {log.aktor_role}
                                </span>
                                <span className="text-[10px] text-slate-400">{formatDate(log.created_at)}</span>
                              </div>
                              <p className="text-slate-600">
                                Tindakan: <span className="font-semibold">{log.tindakan}</span> &bull; Status:{" "}
                                <span className="font-semibold">{log.status_sesudah}</span>
                              </p>
                              {log.catatan && (
                                <p className="text-slate-500 italic text-[11px]">&ldquo;{log.catatan}&rdquo;</p>
                              )}
                              {log.snapshot_dosen_nama && (
                                <p className="text-[11px] text-[#15803D] font-medium">
                                  Dosen Ditetapkan: {log.snapshot_dosen_nama}
                                </p>
                              )}
                            </div>
                          ))}
                        </div>
                      </div>
                    )}
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
