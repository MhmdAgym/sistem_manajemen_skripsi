"use client";

import React, { useState, useRef, useEffect } from "react";
import {
  BookOpen,
  Send,
  CheckCircle2,
  AlertCircle,
  UploadCloud,
  FileText,
  X,
  Loader2,
  UserCheck,
  ExternalLink,
  RotateCcw,
  ShieldCheck,
  Clock,
  MessageSquare,
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

interface RiwayatPengajuan {
  id: number;
  judul: string;
  deskripsi: string;
  nama_file: string;
  ukuran_file: string;
  file_url?: string;
  created_at: string;
  status: StatusPengajuan;
  catatan_dosen?: string | null;
  catatan_kaprodi?: string | null;
  logs?: PengajuanLog[];
}

export default function PengajuanJudulPage() {
  // State Pengajuan Baru
  const [judul, setJudul] = useState("");
  const [deskripsi, setDeskripsi] = useState("");
  const [file, setFile] = useState<File | null>(null);
  const [isDragging, setIsDragging] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [submitted, setSubmitted] = useState(false);
  const [errorMsg, setErrorMsg] = useState<string | null>(null);

  // State Riwayat
  const [riwayat, setRiwayat] = useState<RiwayatPengajuan[]>([]);
  const [loadingRiwayat, setLoadingRiwayat] = useState(true);
  const [fetchRiwayatError, setFetchRiwayatError] = useState<string | null>(null);

  // State Form Revisi
  const [revisiActiveId, setRevisiActiveId] = useState<number | null>(null);
  const [revisiJudul, setRevisiJudul] = useState("");
  const [revisiDeskripsi, setRevisiDeskripsi] = useState("");
  const [revisiCatatanMahasiswa, setRevisiCatatanMahasiswa] = useState("");
  const [revisiFile, setRevisiFile] = useState<File | null>(null);
  const [isSubmittingRevisi, setIsSubmittingRevisi] = useState(false);
  const [revisiErrorMsg, setRevisiErrorMsg] = useState<string | null>(null);
  const [revisiSuccessMsg, setRevisiSuccessMsg] = useState<string | null>(null);

  const fileInputRef = useRef<HTMLInputElement | null>(null);
  const revisiFileInputRef = useRef<HTMLInputElement | null>(null);

  const MAX_FILE_SIZE = 5 * 1024 * 1024;
  const ALLOWED_TYPES = [
    "application/pdf",
    "application/msword",
    "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
  ];

  const fetchRiwayat = async () => {
    try {
      setLoadingRiwayat(true);
      setFetchRiwayatError(null);
      // Menggunakan axios instance dengan session cookie Sanctum (withCredentials: true)
      const res = await axios.get("/api/mahasiswa/pengajuan-judul");
      setRiwayat(res.data?.data || []);
    } catch (err) {
      const error = err as AxiosError<{ message?: string }>;
      const status = error.response?.status;
      if (status === 401) {
        setFetchRiwayatError("Sesi Anda telah berakhir. Silakan login kembali.");
      } else if (status === 403) {
        setFetchRiwayatError("Akses ditolak. Halaman ini hanya untuk mahasiswa.");
      } else if (status && status >= 500) {
        setFetchRiwayatError("Terjadi kesalahan pada server saat memproses data riwayat pengajuan. Silakan coba lagi.");
      } else if (!error.response) {
        setFetchRiwayatError("Gagal terhubung ke server. Periksa koneksi jaringan Anda lalu coba lagi.");
      } else {
        setFetchRiwayatError(error.response?.data?.message || "Gagal memuat data riwayat pengajuan judul.");
      }
      console.error("Gagal memuat riwayat pengajuan:", err);
    } finally {
      setLoadingRiwayat(false);
    }
  };

  useEffect(() => {
    fetchRiwayat();
  }, []);

  const formatFileSize = (bytes: number) => {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
  };

  const validateAndSetFile = (selectedFile: File | undefined, isRevision = false) => {
    if (isRevision) {
      setRevisiErrorMsg(null);
    } else {
      setErrorMsg(null);
    }

    if (!selectedFile) return;

    if (!ALLOWED_TYPES.includes(selectedFile.type)) {
      const msg = "Format file tidak didukung. Harap unggah file PDF, DOC, atau DOCX.";
      if (isRevision) setRevisiErrorMsg(msg);
      else setErrorMsg(msg);
      return;
    }

    if (selectedFile.size > MAX_FILE_SIZE) {
      const msg = "Ukuran file terlalu besar. Maksimal ukuran file adalah 5 MB.";
      if (isRevision) setRevisiErrorMsg(msg);
      else setErrorMsg(msg);
      return;
    }

    if (isRevision) {
      setRevisiFile(selectedFile);
    } else {
      setFile(selectedFile);
    }
  };

  // Submit Pengajuan Baru
  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrorMsg(null);

    if (!file) {
      setErrorMsg("Harap lampirkan file dokumen pengajuan judul (PDF/DOCX).");
      return;
    }

    setIsSubmitting(true);

    try {
      const formData = new FormData();
      formData.append("judul", judul.trim());
      formData.append("deskripsi", deskripsi.trim());
      formData.append("file_judul", file);

      await axios.post("/api/mahasiswa/pengajuan-judul", formData);

      await fetchRiwayat();
      setSubmitted(true);
    } catch (err) {
      const error = err as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
      const status = error.response?.status;

      if (status === 401) {
        setErrorMsg("Sesi Anda telah berakhir. Silakan login kembali.");
      } else if (status === 403) {
        setErrorMsg("Akses ditolak. Halaman ini hanya untuk mahasiswa.");
      } else if (status === 422) {
        const serverMsg = error.response?.data?.message;
        const validationErrors = error.response?.data?.errors;
        if (validationErrors) {
          const firstError = Object.values(validationErrors)[0]?.[0];
          setErrorMsg(firstError || serverMsg || "Data yang dikirim tidak valid.");
        } else {
          setErrorMsg(serverMsg || "Data yang dikirim tidak valid.");
        }
      } else {
        setErrorMsg("Gagal terhubung ke server. Pastikan server aktif dan coba lagi.");
      }
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleResetForm = () => {
    setJudul("");
    setDeskripsi("");
    setFile(null);
    setSubmitted(false);
    setErrorMsg(null);
  };

  // Buka Form Revisi untuk item tertentu
  const handleOpenRevisi = (item: RiwayatPengajuan) => {
    setRevisiActiveId(item.id);
    setRevisiJudul(item.judul);
    setRevisiDeskripsi(item.deskripsi);
    setRevisiCatatanMahasiswa("");
    setRevisiFile(null);
    setRevisiErrorMsg(null);
    setRevisiSuccessMsg(null);
  };

  const handleCloseRevisi = () => {
    setRevisiActiveId(null);
    setRevisiJudul("");
    setRevisiDeskripsi("");
    setRevisiCatatanMahasiswa("");
    setRevisiFile(null);
    setRevisiErrorMsg(null);
  };

  // Submit Perbaikan Pengajuan (Revisi)
  const handleSubmitRevisi = async (e: React.FormEvent, id: number) => {
    e.preventDefault();
    setRevisiErrorMsg(null);
    setRevisiSuccessMsg(null);

    if (revisiJudul.trim() === "") {
      setRevisiErrorMsg("Judul skripsi tidak boleh kosong.");
      return;
    }

    if (revisiCatatanMahasiswa.length > 1000) {
      setRevisiErrorMsg("Catatan perbaikan mahasiswa maksimal 1000 karakter.");
      return;
    }

    setIsSubmittingRevisi(true);

    try {
      const formData = new FormData();

      // Field opsional hanya dikirim jika diisi / ada perubahan
      if (revisiJudul.trim()) {
        formData.append("judul", revisiJudul.trim());
      }
      if (revisiDeskripsi.trim()) {
        formData.append("deskripsi", revisiDeskripsi.trim());
      }
      // Berkas baru opsional; jika mahasiswa tidak memilih berkas baru, jangan kirim file_judul
      if (revisiFile) {
        formData.append("file_judul", revisiFile);
      }
      if (revisiCatatanMahasiswa.trim()) {
        formData.append("catatan_mahasiswa", revisiCatatanMahasiswa.trim());
      }

      await axios.post(`/api/mahasiswa/pengajuan-judul/${id}/revisi`, formData);

      await fetchRiwayat();
      setRevisiActiveId(null);
      setRevisiSuccessMsg(
        "Perbaikan pengajuan berhasil dikirim kembali ke Dosen PA. Status kini: Menunggu Verifikasi Dosen PA."
      );
    } catch (err) {
      const error = err as AxiosError<{ message?: string; errors?: Record<string, string[]> }>;
      const status = error.response?.status;

      if (status === 401) {
        setRevisiErrorMsg("Sesi Anda telah berakhir. Silakan login kembali.");
      } else if (status === 403) {
        setRevisiErrorMsg("Akses ditolak.");
      } else if (status === 422) {
        const serverMsg = error.response?.data?.message;
        const validationErrors = error.response?.data?.errors;
        if (validationErrors) {
          const firstError = Object.values(validationErrors)[0]?.[0];
          setRevisiErrorMsg(firstError || serverMsg || "Data perbaikan tidak valid.");
        } else {
          setRevisiErrorMsg(serverMsg || "Data perbaikan tidak valid.");
        }
      } else {
        setRevisiErrorMsg("Terjadi kesalahan pada server saat mengirim perbaikan. Coba lagi.");
      }
    } finally {
      setIsSubmittingRevisi(false);
    }
  };

  const getBadgeColor = (status: StatusPengajuan) => {
    switch (status) {
      case "Disetujui Kaprodi":
        return "bg-emerald-100 text-emerald-800 border border-emerald-300 font-bold";
      case "Menunggu Persetujuan Kaprodi":
        return "bg-indigo-100 text-indigo-800 border border-indigo-200 font-semibold";
      case "Menunggu Verifikasi Dosen PA":
        return "bg-sky-100 text-sky-800 border border-sky-200 font-medium";
      case "Revisi Dosen PA":
      case "Revisi Kaprodi":
        return "bg-amber-100 text-amber-800 border border-amber-200 font-medium";
      case "Revisi":
        return "bg-amber-100/80 text-amber-800 border border-amber-200/60 font-medium";
      case "Ditolak Dosen PA":
      case "Ditolak Kaprodi":
        return "bg-rose-100 text-rose-800 border border-rose-200 font-medium";
      case "Ditolak":
        return "bg-rose-100/80 text-rose-800 border border-rose-200/60 font-medium";
      case "Disetujui Dosen PA":
        return "bg-teal-100 text-teal-800 border border-teal-200 font-medium";
      default:
        return "bg-slate-100 text-slate-800 border border-slate-200 font-medium";
    }
  };

  const getCatatanKaprodi = (item: RiwayatPengajuan): string | null => {
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

  const getCatatanDosenPA = (item: RiwayatPengajuan): string | null => {
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

  const hasApprovedFinal = riwayat.some((i) => i.status === "Disetujui Kaprodi");
  const activeProposal = riwayat.find((i) =>
    [
      "Menunggu Verifikasi Dosen PA",
      "Menunggu Persetujuan Kaprodi",
      "Revisi Dosen PA",
      "Revisi Kaprodi",
      "Revisi",
    ].includes(i.status)
  );

  return (
    <div className="space-y-6 font-sans text-slate-800">
      <div className="p-6 bg-gradient-to-r from-emerald-700 via-emerald-600 to-teal-700 rounded-3xl text-white shadow-lg">
        <h1 className="text-2xl font-bold tracking-tight">Pengajuan Judul Skripsi</h1>
        <p className="text-xs text-emerald-100 mt-1 max-w-xl leading-relaxed">
          Ajukan usulan judul beserta dokumen ringkasan penelitian Anda. Usulan akan diverifikasi oleh Dosen Pembimbing Akademik (Dosen PA) dan disahkan secara final oleh Ketua Program Studi (Kaprodi).
        </p>
      </div>

      {revisiSuccessMsg && (
        <div className="p-4 bg-emerald-50 border border-emerald-200 rounded-2xl flex items-start gap-3 text-emerald-800 text-xs">
          <CheckCircle2 className="w-5 h-5 text-emerald-600 shrink-0 mt-0.5" />
          <div>
            <p className="font-bold">Perbaikan Berhasil Dikirim</p>
            <p className="text-emerald-700 mt-0.5">{revisiSuccessMsg}</p>
          </div>
        </div>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Kolom Kiri: Form Pengajuan Baru */}
        <div className="lg:col-span-2 bg-white p-6 rounded-3xl border border-slate-100 shadow-sm space-y-4">
          <h2 className="font-bold text-slate-800 text-sm flex items-center gap-2">
            <BookOpen className="w-4 h-4 text-emerald-600" /> Form Usulan Judul &amp; Lampiran
          </h2>

          {errorMsg && (
            <div className="p-3.5 bg-red-50 border border-red-200 rounded-2xl flex items-center gap-2.5 text-red-700 text-xs">
              <AlertCircle className="w-4 h-4 shrink-0 text-red-600" />
              <span>{errorMsg}</span>
            </div>
          )}

          {hasApprovedFinal ? (
            <div className="p-5 bg-emerald-50/80 border border-emerald-200 rounded-2xl space-y-2 text-emerald-800">
              <div className="flex items-center gap-2.5">
                <ShieldCheck className="w-6 h-6 text-emerald-600 shrink-0" />
                <h3 className="font-bold text-sm">Judul Skripsi Anda Telah Disetujui Final</h3>
              </div>
              <p className="text-xs text-emerald-700 leading-relaxed">
                Pengesahan telah diberikan oleh Ketua Program Studi (Disetujui Kaprodi). Anda tidak perlu mengajukan usulan judul baru lagi. Silakan melanjutkan ke tahapan bimbingan atau seminar proposal.
              </p>
            </div>
          ) : activeProposal && !["Revisi Dosen PA", "Revisi Kaprodi", "Revisi"].includes(activeProposal.status) ? (
            <div className="p-5 bg-sky-50/80 border border-sky-200 rounded-2xl space-y-2 text-sky-900">
              <div className="flex items-center gap-2.5">
                <Clock className="w-5 h-5 text-sky-600 shrink-0" />
                <h3 className="font-bold text-sm">Pengajuan Sedang Dalam Proses</h3>
              </div>
              <p className="text-xs text-sky-800 leading-relaxed">
                Anda memiliki usulan judul yang saat ini berstatus <strong>{activeProposal.status}</strong>. Harap tunggu proses verifikasi selesai sebelum mengajukan judul lain.
              </p>
            </div>
          ) : activeProposal && ["Revisi Dosen PA", "Revisi Kaprodi", "Revisi"].includes(activeProposal.status) ? (
            <div className="p-5 bg-amber-50/80 border border-amber-200 rounded-2xl space-y-2 text-amber-900">
              <div className="flex items-center gap-2.5">
                <RotateCcw className="w-5 h-5 text-amber-600 shrink-0" />
                <h3 className="font-bold text-sm">Pengajuan Memerlukan Perbaikan (Revisi)</h3>
              </div>
              <p className="text-xs text-amber-800 leading-relaxed">
                Usulan judul Anda memerlukan revisi berdasarkan catatan dosen. Silakan gunakan tombol <strong>&quot;Perbaiki Usulan (Revisi)&quot;</strong> pada daftar di sebelah kanan untuk memperbarui judul atau lampiran.
              </p>
            </div>
          ) : submitted ? (
            <div className="p-5 bg-emerald-50 border border-emerald-200 rounded-2xl space-y-3 text-emerald-800">
              <div className="flex items-start gap-3">
                <CheckCircle2 className="w-6 h-6 text-emerald-600 shrink-0 mt-0.5" />
                <div className="space-y-1">
                  <p className="font-bold text-sm">Judul &amp; Berkas Berhasil Diajukan!</p>
                  <p className="text-xs text-emerald-700">
                    Data telah tersimpan di database dan siap diverifikasi oleh Dosen PA Anda.
                  </p>
                </div>
              </div>
              <button
                type="button"
                onClick={handleResetForm}
                className="text-xs font-semibold text-emerald-700 hover:text-emerald-900 underline"
              >
                + Ajukan alternatif judul lainnya
              </button>
            </div>
          ) : (
            <form onSubmit={handleSubmit} className="space-y-4">
              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">
                  Judul Skripsi <span className="text-red-500">*</span>
                </label>
                <input
                  type="text"
                  required
                  value={judul}
                  onChange={(e) => setJudul(e.target.value)}
                  placeholder="Contoh: Rancang Bangun Sistem Informasi..."
                  className="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-xs focus:outline-none focus:border-emerald-600"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">
                  Deskripsi / Latar Belakang Singkat <span className="text-red-500">*</span>
                </label>
                <textarea
                  rows={4}
                  required
                  value={deskripsi}
                  onChange={(e) => setDeskripsi(e.target.value)}
                  placeholder="Jelaskan rumusan masalah dan metode yang digunakan..."
                  className="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-xs focus:outline-none focus:border-emerald-600 resize-none"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">
                  Lampiran File Judul / Draft Proposal <span className="text-red-500">*</span>
                </label>

                {!file ? (
                  <div
                    onDragOver={(e) => {
                      e.preventDefault();
                      setIsDragging(true);
                    }}
                    onDragLeave={(e) => {
                      e.preventDefault();
                      setIsDragging(false);
                    }}
                    onDrop={(e) => {
                      e.preventDefault();
                      setIsDragging(false);
                      validateAndSetFile(e.dataTransfer.files?.[0]);
                    }}
                    onClick={() => fileInputRef.current?.click()}
                    className={`border-2 border-dashed rounded-2xl p-6 text-center cursor-pointer transition flex flex-col items-center justify-center gap-2 ${
                      isDragging
                        ? "border-emerald-600 bg-emerald-50/70"
                        : "border-slate-200 hover:border-emerald-500 hover:bg-slate-50"
                    }`}
                  >
                    <div className="p-3 bg-emerald-50 text-emerald-600 rounded-full">
                      <UploadCloud className="w-6 h-6" />
                    </div>
                    <div>
                      <p className="text-xs font-semibold text-slate-700">
                        Klik untuk memilih file atau seret file ke sini
                      </p>
                      <p className="text-[11px] text-slate-400 mt-0.5">
                        Format: PDF, DOC, DOCX (Maks. 5 MB)
                      </p>
                    </div>
                    <input
                      ref={fileInputRef}
                      type="file"
                      accept=".pdf,.doc,.docx"
                      onChange={(e) => validateAndSetFile(e.target.files?.[0])}
                      className="hidden"
                    />
                  </div>
                ) : (
                  <div className="p-3.5 bg-emerald-50/60 border border-emerald-200 rounded-2xl flex items-center justify-between gap-3">
                    <div className="flex items-center gap-3 min-w-0">
                      <div className="p-2.5 bg-emerald-600 text-white rounded-xl shrink-0">
                        <FileText className="w-5 h-5" />
                      </div>
                      <div className="min-w-0">
                        <p className="text-xs font-bold text-slate-800 truncate">{file.name}</p>
                        <p className="text-[11px] text-slate-500">{formatFileSize(file.size)}</p>
                      </div>
                    </div>
                    <button
                      type="button"
                      onClick={() => {
                        setFile(null);
                        if (fileInputRef.current) fileInputRef.current.value = "";
                      }}
                      className="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition"
                    >
                      <X className="w-4 h-4" />
                    </button>
                  </div>
                )}
              </div>

              <button
                type="submit"
                disabled={isSubmitting}
                className="px-5 py-2.5 bg-[#15803D] hover:bg-emerald-700 disabled:bg-emerald-400 text-white font-bold text-xs rounded-xl shadow-md transition flex items-center gap-2"
              >
                {isSubmitting ? (
                  <>
                    <Loader2 className="w-4 h-4 animate-spin" /> Mengirim Pengajuan...
                  </>
                ) : (
                  <>
                    <Send className="w-4 h-4" /> Kirim ke Dosen PA
                  </>
                )}
              </button>
            </form>
          )}
        </div>

        {/* Kolom Kanan: Status & Riwayat Verifikasi */}
        <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm space-y-4">
          <h2 className="font-bold text-slate-800 text-sm flex items-center gap-2">
            <UserCheck className="w-4 h-4 text-emerald-600" /> Status &amp; Riwayat Usulan
          </h2>

          {loadingRiwayat ? (
            <div className="py-8 flex justify-center text-slate-400 text-xs items-center gap-2">
              <Loader2 className="w-4 h-4 animate-spin" /> Memuat status...
            </div>
          ) : fetchRiwayatError ? (
            <div className="p-4 bg-red-50 border border-red-200 rounded-2xl space-y-3 text-red-700">
              <div className="flex items-start gap-2.5">
                <AlertCircle className="w-5 h-5 text-red-600 shrink-0 mt-0.5" />
                <div className="space-y-1">
                  <p className="font-bold text-xs">Gagal Memuat Riwayat</p>
                  <p className="text-[11px] text-red-600 leading-relaxed">{fetchRiwayatError}</p>
                </div>
              </div>
              <button
                type="button"
                onClick={() => fetchRiwayat()}
                className="w-full py-2 px-3 bg-white hover:bg-red-100/60 border border-red-300 text-red-700 font-semibold text-xs rounded-xl transition flex items-center justify-center gap-1.5 shadow-sm"
              >
                <RotateCcw className="w-3.5 h-3.5" /> Coba Lagi
              </button>
            </div>
          ) : riwayat.length === 0 ? (
            <p className="text-xs text-slate-400 text-center py-6">
              Belum ada judul yang diajukan.
            </p>
          ) : (
            <div className="space-y-4">
              {riwayat.map((item) => {
                const canRevise = ["Revisi Dosen PA", "Revisi Kaprodi", "Revisi"].includes(item.status);
                const isRevisingThis = revisiActiveId === item.id;

                return (
                  <div
                    key={item.id}
                    className="p-4 bg-slate-50 rounded-2xl border border-slate-100 space-y-3"
                  >
                    <div className="flex items-center justify-between gap-2 flex-wrap">
                      <span className={`text-[10px] px-2.5 py-0.5 rounded-md ${getBadgeColor(item.status)}`}>
                        {item.status === "Disetujui Kaprodi" ? "Disetujui Final (Kaprodi)" : item.status}
                      </span>
                      <span className="text-[10px] text-slate-400">
                        {new Date(item.created_at).toLocaleDateString("id-ID", {
                          day: "2-digit",
                          month: "short",
                          year: "numeric",
                        })}
                      </span>
                    </div>

                    <p className="font-semibold text-xs text-slate-800 leading-snug">{item.judul}</p>

                    {item.file_url && (
                      <a
                        href={item.file_url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="p-2 bg-white hover:bg-emerald-50 rounded-xl border border-slate-200/80 flex items-center gap-2 transition"
                      >
                        <FileText className="w-3.5 h-3.5 text-emerald-600 shrink-0" />
                        <span className="text-[11px] font-medium text-slate-700 truncate">
                          {item.nama_file}
                        </span>
                        <ExternalLink className="w-3 h-3 text-slate-400 ml-auto shrink-0" />
                      </a>
                    )}

                    {/* Catatan Dosen PA */}
                    {getCatatanDosenPA(item) && (
                      <div className="text-[10px] text-slate-700 bg-amber-50/70 p-2.5 rounded-xl border border-amber-200/80 space-y-0.5">
                        <div className="flex items-center gap-1 font-bold text-amber-800">
                          <MessageSquare className="w-3 h-3" /> Catatan Dosen PA:
                        </div>
                        <p className="leading-relaxed">{getCatatanDosenPA(item)}</p>
                      </div>
                    )}

                    {/* Catatan Kaprodi */}
                    {getCatatanKaprodi(item) && (
                      <div className="text-[10px] text-slate-700 bg-indigo-50/70 p-2.5 rounded-xl border border-indigo-200/80 space-y-0.5">
                        <div className="flex items-center gap-1 font-bold text-indigo-900">
                          <MessageSquare className="w-3 h-3" /> Catatan Kaprodi:
                        </div>
                        <p className="leading-relaxed">{getCatatanKaprodi(item)}</p>
                      </div>
                    )}

                    {/* Tombol Perbaiki (hanya jika dalam status Revisi) */}
                    {canRevise && !isRevisingThis && (
                      <div className="pt-1">
                        <button
                          type="button"
                          onClick={() => handleOpenRevisi(item)}
                          className="w-full py-2 bg-amber-500 hover:bg-amber-600 text-white rounded-xl text-xs font-bold transition flex items-center justify-center gap-1.5 shadow-sm"
                        >
                          <RotateCcw className="w-3.5 h-3.5" /> Perbaiki Usulan (Revisi)
                        </button>
                      </div>
                    )}

                    {/* Form Revisi Inline */}
                    {isRevisingThis && (
                      <form
                        onSubmit={(e) => handleSubmitRevisi(e, item.id)}
                        className="p-3.5 bg-white border border-amber-200 rounded-2xl space-y-3 pt-3"
                      >
                        <div className="flex items-center justify-between border-b border-slate-100 pb-2">
                          <span className="text-xs font-bold text-amber-800 flex items-center gap-1.5">
                            <RotateCcw className="w-3.5 h-3.5 text-amber-600" /> Form Perbaikan Judul
                          </span>
                          <button
                            type="button"
                            onClick={handleCloseRevisi}
                            className="text-slate-400 hover:text-slate-600 text-xs"
                          >
                            Batal
                          </button>
                        </div>

                        {revisiErrorMsg && (
                          <div className="p-2.5 bg-red-50 border border-red-200 rounded-xl text-[11px] text-red-700">
                            {revisiErrorMsg}
                          </div>
                        )}

                        <div>
                          <label className="block text-[11px] font-semibold text-slate-700 mb-1">
                            Judul Skripsi (Dapat disesuaikan)
                          </label>
                          <input
                            type="text"
                            required
                            value={revisiJudul}
                            onChange={(e) => setRevisiJudul(e.target.value)}
                            className="w-full px-3 py-1.5 rounded-lg border border-slate-200 text-xs focus:outline-none focus:border-emerald-600"
                          />
                        </div>

                        <div>
                          <label className="block text-[11px] font-semibold text-slate-700 mb-1">
                            Deskripsi / Latar Belakang
                          </label>
                          <textarea
                            rows={3}
                            value={revisiDeskripsi}
                            onChange={(e) => setRevisiDeskripsi(e.target.value)}
                            className="w-full px-3 py-1.5 rounded-lg border border-slate-200 text-xs focus:outline-none focus:border-emerald-600 resize-none"
                          />
                        </div>

                        <div>
                          <div className="flex justify-between items-center mb-1">
                            <label className="block text-[11px] font-semibold text-slate-700">
                              Catatan Penjelasan Perbaikan
                            </label>
                            <span className="text-[10px] text-slate-400">
                              {revisiCatatanMahasiswa.length}/1000
                            </span>
                          </div>
                          <textarea
                            rows={2}
                            maxLength={1000}
                            value={revisiCatatanMahasiswa}
                            onChange={(e) => setRevisiCatatanMahasiswa(e.target.value)}
                            placeholder="Jelaskan poin perbaikan yang telah Anda lakukan..."
                            className="w-full px-3 py-1.5 rounded-lg border border-slate-200 text-xs focus:outline-none focus:border-emerald-600 resize-none"
                          />
                        </div>

                        <div>
                          <label className="block text-[11px] font-semibold text-slate-700 mb-1">
                            Berkas Baru (Opsional — kosongkan jika berkas tetap)
                          </label>
                          {!revisiFile ? (
                            <div
                              onClick={() => revisiFileInputRef.current?.click()}
                              className="border border-dashed border-slate-200 rounded-xl p-2.5 text-center cursor-pointer hover:border-emerald-500 hover:bg-slate-50 transition"
                            >
                              <p className="text-[11px] text-slate-500">
                                📎 Klik untuk ganti berkas proposal (Maks. 5 MB)
                              </p>
                              <input
                                ref={revisiFileInputRef}
                                type="file"
                                accept=".pdf,.doc,.docx"
                                onChange={(e) => validateAndSetFile(e.target.files?.[0], true)}
                                className="hidden"
                              />
                            </div>
                          ) : (
                            <div className="p-2 bg-emerald-50 rounded-xl border border-emerald-200 flex items-center justify-between">
                              <span className="text-[11px] text-emerald-800 truncate font-medium">
                                {revisiFile.name} ({formatFileSize(revisiFile.size)})
                              </span>
                              <button
                                type="button"
                                onClick={() => {
                                  setRevisiFile(null);
                                  if (revisiFileInputRef.current) revisiFileInputRef.current.value = "";
                                }}
                                className="text-slate-400 hover:text-red-600 ml-2"
                              >
                                <X className="w-3.5 h-3.5" />
                              </button>
                            </div>
                          )}
                        </div>

                        <div className="flex items-center justify-end gap-2 pt-1">
                          <button
                            type="button"
                            onClick={handleCloseRevisi}
                            className="px-3 py-1.5 text-xs text-slate-500 hover:text-slate-700"
                          >
                            Batal
                          </button>
                          <button
                            type="submit"
                            disabled={isSubmittingRevisi}
                            className="px-4 py-1.5 bg-emerald-600 hover:bg-emerald-700 disabled:bg-emerald-400 text-white rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow"
                          >
                            {isSubmittingRevisi ? (
                              <>
                                <Loader2 className="w-3.5 h-3.5 animate-spin" /> Mengirim...
                              </>
                            ) : (
                              <>
                                <Send className="w-3.5 h-3.5" /> Kirim Revisi ke PA
                              </>
                            )}
                          </button>
                        </div>
                      </form>
                    )}
                  </div>
                );
              })}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
