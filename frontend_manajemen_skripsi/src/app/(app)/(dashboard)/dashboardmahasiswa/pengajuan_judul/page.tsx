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
} from "lucide-react";
import axios from "@/lib/axios";
import { AxiosError } from "axios";

interface RiwayatPengajuan {
  id: number;
  judul: string;
  deskripsi: string;
  nama_file: string;
  ukuran_file: string;
  file_url?: string;
  created_at: string;
  status: "Menunggu Verifikasi Dosen PA" | "Disetujui Dosen PA" | "Revisi" | "Ditolak";
  catatan_dosen?: string;
}

export default function PengajuanJudulPage() {
  const [judul, setJudul] = useState("");
  const [deskripsi, setDeskripsi] = useState("");
  const [file, setFile] = useState<File | null>(null);
  const [isDragging, setIsDragging] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [submitted, setSubmitted] = useState(false);
  const [errorMsg, setErrorMsg] = useState<string | null>(null);
  const [riwayat, setRiwayat] = useState<RiwayatPengajuan[]>([]);
  const [loadingRiwayat, setLoadingRiwayat] = useState(true);

  const fileInputRef = useRef<HTMLInputElement | null>(null);

  const MAX_FILE_SIZE = 5 * 1024 * 1024;
  const ALLOWED_TYPES = [
    "application/pdf",
    "application/msword",
    "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
  ];

  const fetchRiwayat = async () => {
    try {
      setLoadingRiwayat(true);
      // Menggunakan axios instance dengan session cookie Sanctum (withCredentials: true)
      const res = await axios.get("/api/mahasiswa/pengajuan-judul");
      setRiwayat(res.data?.data || []);
    } catch (err) {
      const error = err as AxiosError<{ message?: string }>;
      if (error.response?.status === 401) {
        // Sesi habis — biarkan middleware auth.js yang menangani redirect
        setErrorMsg("Sesi Anda telah berakhir. Silakan login kembali.");
      } else if (error.response?.status === 403) {
        setErrorMsg("Akses ditolak. Halaman ini hanya untuk mahasiswa.");
      } else {
        console.error("Gagal memuat riwayat:", err);
      }
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

  const validateAndSetFile = (selectedFile: File | undefined) => {
    setErrorMsg(null);
    if (!selectedFile) return;

    if (!ALLOWED_TYPES.includes(selectedFile.type)) {
      setErrorMsg("Format file tidak didukung. Harap unggah file PDF, DOC, atau DOCX.");
      return;
    }

    if (selectedFile.size > MAX_FILE_SIZE) {
      setErrorMsg("Ukuran file terlalu besar. Maksimal ukuran file adalah 5 MB.");
      return;
    }

    setFile(selectedFile);
  };

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
      formData.append("judul", judul);
      formData.append("deskripsi", deskripsi);
      formData.append("file_judul", file);

      // Axios mengirim session cookie otomatis (withCredentials: true dari lib/axios)
      // Tidak perlu set Content-Type — axios mendeteksi FormData secara otomatis
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
        // Pesan validasi dari server (termasuk Dosen PA belum ditetapkan)
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

  const getBadgeColor = (status: string) => {
    if (status === "Disetujui Dosen PA") return "bg-emerald-100 text-emerald-800";
    if (status === "Revisi") return "bg-amber-100 text-amber-800";
    if (status === "Ditolak") return "bg-rose-100 text-rose-800";
    return "bg-sky-100 text-sky-800";
  };

  return (
    <div className="space-y-6 font-sans text-slate-800">
      <div className="p-6 bg-gradient-to-r from-emerald-700 via-emerald-600 to-teal-700 rounded-3xl text-white shadow-lg">
        <h1 className="text-2xl font-bold tracking-tight">Pengajuan Judul Skripsi</h1>
        <p className="text-xs text-emerald-100 mt-1 max-w-xl">
          Ajukan usulan judul beserta dokumen ringkasan penelitian Anda untuk diverifikasi oleh Dosen Pembimbing Akademik (Dosen PA).
        </p>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
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

          {submitted ? (
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
                    onDragOver={(e) => { e.preventDefault(); setIsDragging(true); }}
                    onDragLeave={(e) => { e.preventDefault(); setIsDragging(false); }}
                    onDrop={(e) => { e.preventDefault(); setIsDragging(false); validateAndSetFile(e.dataTransfer.files?.[0]); }}
                    onClick={() => fileInputRef.current?.click()}
                    className={`border-2 border-dashed rounded-2xl p-6 text-center cursor-pointer transition flex flex-col items-center justify-center gap-2 ${isDragging ? "border-emerald-600 bg-emerald-50/70" : "border-slate-200 hover:border-emerald-500 hover:bg-slate-50"}`}
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
                      onClick={() => { setFile(null); if (fileInputRef.current) fileInputRef.current.value = ""; }}
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

        {/* Status Informasi Verifikasi Dosen PA */}
        <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm space-y-4">
          <h2 className="font-bold text-slate-800 text-sm flex items-center gap-2">
            <UserCheck className="w-4 h-4 text-emerald-600" /> Status Verifikasi Dosen PA
          </h2>

          {loadingRiwayat ? (
            <div className="py-8 flex justify-center text-slate-400 text-xs items-center gap-2">
              <Loader2 className="w-4 h-4 animate-spin" /> Memuat status...
            </div>
          ) : riwayat.length === 0 ? (
            <p className="text-xs text-slate-400 text-center py-6">
              Belum ada judul yang diajukan.
            </p>
          ) : (
            <div className="space-y-3">
              {riwayat.map((item) => (
                <div key={item.id} className="p-4 bg-slate-50 rounded-2xl border border-slate-100 space-y-2.5">
                  <div className="flex items-center justify-between gap-2">
                    <span className={`text-[10px] font-bold px-2.5 py-0.5 rounded-md ${getBadgeColor(item.status)}`}>
                      {item.status}
                    </span>
                    <span className="text-[10px] text-slate-400">
                      {new Date(item.created_at).toLocaleDateString("id-ID", { day: "2-digit", month: "short", year: "numeric" })}
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
                      <span className="text-[11px] font-medium text-slate-700 truncate">{item.nama_file}</span>
                      <ExternalLink className="w-3 h-3 text-slate-400 ml-auto shrink-0" />
                    </a>
                  )}

                  {item.catatan_dosen && (
                    <p className="text-[10px] text-slate-600 bg-white p-2 rounded-lg border border-slate-100">
                      <span className="font-bold">Catatan Dosen PA:</span> {item.catatan_dosen}
                    </p>
                  )}
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}