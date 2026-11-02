"use client";

import React, { useState, useEffect, useCallback, useMemo, useRef } from "react";
import {
  UserCheck,
  Search,
  RefreshCw,
  Eye,
  CheckCircle2,
  XCircle,
  AlertCircle,
  Clock,
  X,
  Filter,
  GraduationCap,
  MessageSquare,
  Users,
  Loader2,
  SearchX,
  AlertTriangle,
  FileText
} from "lucide-react";
import axios from "@/lib/axios";
import { AxiosError } from "axios";
import { useAuth } from "@/hooks/auth";

interface DosenOption {
  id_dosen: number;
  nama_dosen: string;
  NIDN: string;
  jumlah_penugasan_teramati: number;
  keterangan_indikator?: string;
}

interface MahasiswaDetail {
  id_mahasiswa: number;
  NIM: string;
  nama_mahasiswa: string;
  prodi?: string;
  user?: {
    id: number;
    name?: string;
    email?: string;
  } | null;
}

interface SkripsiDetail {
  id_skripsi: number;
  judul: string;
  status_judul: string;
}

interface DosenDetail {
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

interface PengajuanDospemAdmin {
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
  mahasiswa?: MahasiswaDetail | null;
  skripsi?: SkripsiDetail | null;
  dosen?: DosenDetail | null;
  logs?: PengajuanLogItem[];
}

export default function PenetapanDospemAdminPage() {
  const { user } = useAuth({ middleware: "auth" });
  const isAdmin = user?.role === "admin";

  // Data Antrean State
  const [antrean, setAntrean] = useState<PengajuanDospemAdmin[]>([]);
  const [loadingAntrean, setLoadingAntrean] = useState<boolean>(true);
  const [errorAntrean, setErrorAntrean] = useState<string | null>(null);

  // Filter & Pencarian State
  const [searchQuery, setSearchQuery] = useState<string>("");
  const [debouncedSearchQuery, setDebouncedSearchQuery] = useState<string>("");
  const [filterStatus, setFilterStatus] = useState<string>("all");
  const [filterTahap, setFilterTahap] = useState<string>("proposal");

  // Monotonic Request Sequence ID untuk perlindungan respons out-of-order
  const latestRequestIdRef = useRef<number>(0);

  // Debounce input pencarian teks sebesar 300ms
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearchQuery(searchQuery);
    }, 300);

    return () => {
      clearTimeout(timer);
    };
  }, [searchQuery]);

  // Opsi Dosen State
  const [dosenOptions, setDosenOptions] = useState<DosenOption[]>([]);
  const [loadingOptions, setLoadingOptions] = useState<boolean>(false);
  const [errorOptions, setErrorOptions] = useState<string | null>(null);

  // Modal Penetapan State
  const [selectedTetapkan, setSelectedTetapkan] = useState<PengajuanDospemAdmin | null>(null);
  const [selectedDosenId, setSelectedDosenId] = useState<string>("");
  const [catatanTetapkan, setCatatanTetapkan] = useState<string>("");
  const [isSubmittingTetapkan, setIsSubmittingTetapkan] = useState<boolean>(false);
  const [errorTetapkan, setErrorTetapkan] = useState<string | null>(null);

  // Modal Penolakan State
  const [selectedTolak, setSelectedTolak] = useState<PengajuanDospemAdmin | null>(null);
  const [catatanTolak, setCatatanTolak] = useState<string>("");
  const [isSubmittingTolak, setIsSubmittingTolak] = useState<boolean>(false);
  const [errorTolak, setErrorTolak] = useState<string | null>(null);

  // Modal Detail & Riwayat Audit State
  const [selectedDetail, setSelectedDetail] = useState<PengajuanDospemAdmin | null>(null);
  const [loadingDetail, setLoadingDetail] = useState<boolean>(false);
  const [errorDetail, setErrorDetail] = useState<string | null>(null);

  // Feedback Notification Banner
  const [feedbackSuccess, setFeedbackSuccess] = useState<string | null>(null);
  // Perubahan 4 (F-10): State untuk banner error pemuatan detail.
  const [feedbackError, setFeedbackError] = useState<string | null>(null);

  const MAX_CHAR = 1000;

  // 1. Fetch Antrean Pengajuan Dospem
  const fetchAntrean = useCallback(async () => {
    const requestId = ++latestRequestIdRef.current;

    try {
      setLoadingAntrean(true);
      setErrorAntrean(null);

      const params: Record<string, string> = { tahap: filterTahap };
      if (filterStatus !== "all") {
        params.status = filterStatus;
      }
      if (debouncedSearchQuery.trim()) {
        params.search = debouncedSearchQuery.trim();
      }

      const res = await axios.get("/api/admin/pengajuan-dospem", { params });

      // Abaikan respons jika request ini sudah usang (out-of-order)
      if (requestId !== latestRequestIdRef.current) {
        return;
      }

      if (res.data?.status === "success" && Array.isArray(res.data?.data)) {
        setAntrean(res.data.data);
      } else {
        setAntrean([]);
      }
    } catch (err) {
      // Abaikan error jika request ini sudah usang
      if (requestId !== latestRequestIdRef.current) {
        return;
      }

      const error = err as AxiosError<{ message?: string }>;
      const status = error.response?.status;
      if (status === 401) {
        setErrorAntrean("Sesi login Anda telah berakhir. Silakan login kembali.");
      } else if (status === 403) {
        setErrorAntrean("Akses ditolak: Fitur ini hanya dapat diakses oleh Administrator.");
      } else {
        setErrorAntrean(error.response?.data?.message || "Gagal memuat daftar antrean pengajuan pembimbing.");
      }
    } finally {
      // Hanya matikan loading jika request ini adalah request terkini
      if (requestId === latestRequestIdRef.current) {
        setLoadingAntrean(false);
      }
    }
  }, [filterTahap, filterStatus, debouncedSearchQuery]);

  useEffect(() => {
    fetchAntrean();

    return () => {
      // Invalidate request yang sedang berjalan saat unmount atau dependency berubah
      latestRequestIdRef.current++;
    };
  }, [fetchAntrean]);

  // 2. Fetch Opsi Dosen
  const fetchDosenOptions = useCallback(async () => {
    try {
      setLoadingOptions(true);
      setErrorOptions(null);
      const res = await axios.get("/api/admin/pengajuan-dospem/dosen-options");
      if (res.data?.status === "success" && Array.isArray(res.data?.data)) {
        setDosenOptions(res.data.data);
      } else {
        setDosenOptions([]);
      }
    } catch (err) {
      const error = err as AxiosError<{ message?: string }>;
      setErrorOptions(error.response?.data?.message || "Gagal memuat opsi dosen dari server.");
    } finally {
      setLoadingOptions(false);
    }
  }, []);

  // 3. Fetch Detail Tunggal untuk Modal Detail
  const fetchDetailItem = async (id: number) => {
    try {
      // Perubahan 5 (F-10): Kosongkan detail lama dan error sebelumnya
      // agar modal tidak menampilkan data usang dan banner error terhapus saat retry.
      setSelectedDetail(null);
      setFeedbackError(null);
      setLoadingDetail(true);
      setErrorDetail(null);
      const res = await axios.get(`/api/admin/pengajuan-dospem/${id}`);
      if (res.data?.status === "success" && res.data?.data) {
        setSelectedDetail(res.data.data);
      }
    } catch (err) {
      const error = err as AxiosError<{ message?: string }>;
      // Perubahan 6 (F-10): Simpan pesan error ke feedbackError agar terlihat
      // oleh pengguna meskipun modal tidak terbuka.
      const errMsg = error.response?.data?.message || "Gagal memuat detail pengajuan dari server.";
      setErrorDetail(errMsg);
      setFeedbackError(errMsg);
    } finally {
      setLoadingDetail(false);
    }
  };

  // Handler Buka Modal Tetapkan
  const handleOpenTetapkanModal = (item: PengajuanDospemAdmin) => {
    setSelectedTetapkan(item);
    setSelectedDosenId("");
    setCatatanTetapkan("");
    setErrorTetapkan(null);
    fetchDosenOptions();
  };

  // Submit Penetapan Dosen
  const handleSubmitTetapkan = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedTetapkan) return;

    if (!selectedDosenId) {
      setErrorTetapkan("Silakan pilih Dosen Pembimbing terlebih dahulu.");
      return;
    }

    try {
      setIsSubmittingTetapkan(true);
      setErrorTetapkan(null);

      const res = await axios.post(`/api/admin/pengajuan-dospem/${selectedTetapkan.id}/tetapkan`, {
        id_dosen: parseInt(selectedDosenId, 10),
        catatan_admin: catatanTetapkan.trim() ? catatanTetapkan.trim() : null,
      });

      if (res.data?.status === "success") {
        setFeedbackSuccess(res.data?.message || "Dosen Pembimbing Proposal berhasil ditetapkan.");
        setSelectedTetapkan(null);
        fetchAntrean();
      }
    } catch (err) {
      const error = err as AxiosError<{ message?: string }>;
      const status = error.response?.status;
      if (status === 409) {
        setErrorTetapkan(
          error.response?.data?.message ||
            "Konflik: Pengajuan telah berubah status atau skripsi sudah memiliki Dosen Pembimbing."
        );
        fetchAntrean();
      } else if (status === 422) {
        setErrorTetapkan(error.response?.data?.message || "Validasi gagal: Pilihan dosen tidak sah.");
      } else {
        setErrorTetapkan(error.response?.data?.message || "Terjadi kesalahan pada server saat menetapkan dosen.");
      }
    } finally {
      setIsSubmittingTetapkan(false);
    }
  };

  // Handler Buka Modal Tolak
  const handleOpenTolakModal = (item: PengajuanDospemAdmin) => {
    setSelectedTolak(item);
    setCatatanTolak("");
    setErrorTolak(null);
  };

  // Submit Penolakan
  const handleSubmitTolak = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedTolak) return;

    if (!catatanTolak.trim()) {
      setErrorTolak("Alasan penolakan wajib diisi untuk memberikan instruksi kepada mahasiswa.");
      return;
    }

    try {
      setIsSubmittingTolak(true);
      setErrorTolak(null);

      const res = await axios.patch(`/api/admin/pengajuan-dospem/${selectedTolak.id}/status`, {
        status: "ditolak",
        catatan_admin: catatanTolak.trim(),
      });

      if (res.data?.status === "success") {
        setFeedbackSuccess(res.data?.message || "Pengajuan Dosen Pembimbing berhasil ditolak.");
        setSelectedTolak(null);
        fetchAntrean();
      }
    } catch (err) {
      const error = err as AxiosError<{ message?: string }>;
      const status = error.response?.status;
      if (status === 409) {
        setErrorTolak(
          error.response?.data?.message ||
            "Konflik: Hanya pengajuan berstatus menunggu penetapan yang dapat ditolak."
        );
        fetchAntrean();
      } else if (status === 422) {
        setErrorTolak(error.response?.data?.message || "Validasi gagal: Alasan penolakan wajib diisi.");
      } else {
        setErrorTolak(error.response?.data?.message || "Terjadi kesalahan pada server saat memproses penolakan.");
      }
    } finally {
      setIsSubmittingTolak(false);
    }
  };

  // Statistik Cepat dari Antrean yang Dimuat
  const stats = useMemo(() => {
    const total = antrean.length;
    const menunggu = antrean.filter((item) => item.status === "menunggu_penetapan").length;
    const ditetapkan = antrean.filter((item) => item.status === "ditetapkan").length;
    const ditolak = antrean.filter((item) => item.status === "ditolak").length;
    return { total, menunggu, ditetapkan, ditolak };
  }, [antrean]);

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
          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-50 text-amber-800 border border-amber-200">
            <Clock className="w-3 h-3 text-amber-600" />
            Menunggu Penetapan
          </span>
        );
      case "ditetapkan":
        return (
          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-800 border border-emerald-200">
            <CheckCircle2 className="w-3 h-3 text-emerald-600" />
            Ditetapkan
          </span>
        );
      case "ditolak":
        return (
          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-rose-50 text-rose-800 border border-rose-200">
            <XCircle className="w-3 h-3 text-rose-600" />
            Ditolak
          </span>
        );
      default:
        return (
          <span className="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-slate-100 text-slate-700">
            {status}
          </span>
        );
    }
  };

  return (
    <div className="space-y-6 font-sans text-slate-800">
      {/* 1. WELCOME BANNER ADMIN */}
      <div className="p-6 bg-[#15803D] rounded-3xl text-white shadow-sm flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
        <div>
          <span className="px-3 py-1 bg-white/20 backdrop-blur-md rounded-full text-[11px] font-semibold uppercase tracking-wider text-emerald-50 border border-white/10">
            Panel Administrator Prodi
          </span>
          <h1 className="text-2xl font-bold mt-2 tracking-tight">Penetapan Dosen Pembimbing Proposal</h1>
          <p className="text-xs text-emerald-100 mt-1 max-w-xl leading-relaxed">
            Kelola antrean permohonan pembimbing proposal, pantau indikator beban bimbingan dosen, dan tetapkan satu dosen pembimbing secara objektif.
          </p>
        </div>
        <button
          onClick={fetchAntrean}
          disabled={loadingAntrean}
          className="inline-flex items-center gap-2 px-4 py-2 bg-white/15 hover:bg-white/25 border border-white/20 text-white rounded-2xl text-xs font-medium transition disabled:opacity-50"
        >
          <RefreshCw className={`w-3.5 h-3.5 ${loadingAntrean ? "animate-spin" : ""}`} />
          Segarkan Antrean
        </button>
      </div>

      {/* FEEDBACK SUCCESS BANNER */}
      {feedbackSuccess && (
        <div className="p-4 bg-emerald-50 border border-emerald-200 rounded-2xl text-xs text-emerald-800 flex items-center justify-between gap-3 shadow-sm">
          <div className="flex items-center gap-2.5">
            <CheckCircle2 className="w-5 h-5 text-emerald-600 shrink-0" />
            <p className="font-semibold">{feedbackSuccess}</p>
          </div>
          <button
            onClick={() => setFeedbackSuccess(null)}
            className="p-1 hover:bg-emerald-100 rounded-lg text-emerald-700 transition"
          >
            <X className="w-4 h-4" />
          </button>
        </div>
      )}

      {/* Perubahan 7 (F-10): FEEDBACK ERROR BANNER — error pemuatan detail */}
      {feedbackError && (
        <div className="p-4 bg-rose-50 border border-rose-200 rounded-2xl text-xs text-rose-800 flex items-center justify-between gap-3 shadow-sm">
          <div className="flex items-center gap-2.5">
            <AlertCircle className="w-5 h-5 text-rose-600 shrink-0" />
            <p className="font-semibold">{feedbackError}</p>
          </div>
          <button
            onClick={() => setFeedbackError(null)}
            className="p-1 hover:bg-rose-100 rounded-lg text-rose-700 transition"
          >
            <X className="w-4 h-4" />
          </button>
        </div>
      )}

      {/* 2. KARTU STATISTIK CEPAT (BERDASARKAN DATA ANTREAN TERMUAT) */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div className="bg-white p-5 rounded-3xl border border-slate-100 shadow-sm flex items-center justify-between">
          <div>
            <p className="text-xs text-slate-400 font-medium">Total Data Termuat</p>
            <h3 className="text-2xl font-bold text-slate-800 mt-1">{stats.total}</h3>
            <p className="text-[10px] text-slate-400 mt-0.5">Seluruh pengajuan</p>
          </div>
          <div className="p-3 rounded-2xl bg-slate-50 text-slate-700 border border-slate-100">
            <Users className="w-5 h-5" />
          </div>
        </div>

        <div className="bg-white p-5 rounded-3xl border border-slate-100 shadow-sm flex items-center justify-between">
          <div>
            <p className="text-xs text-slate-400 font-medium">Menunggu Penetapan</p>
            <h3 className="text-2xl font-bold text-amber-600 mt-1">{stats.menunggu}</h3>
            <p className="text-[10px] text-amber-700 font-medium mt-0.5">Perlu tindakan admin</p>
          </div>
          <div className="p-3 rounded-2xl bg-amber-50 text-amber-700 border border-amber-100">
            <Clock className="w-5 h-5" />
          </div>
        </div>

        <div className="bg-white p-5 rounded-3xl border border-slate-100 shadow-sm flex items-center justify-between">
          <div>
            <p className="text-xs text-slate-400 font-medium">Telah Ditetapkan</p>
            <h3 className="text-2xl font-bold text-[#15803D] mt-1">{stats.ditetapkan}</h3>
            <p className="text-[10px] text-emerald-700 font-medium mt-0.5">Pembimbing aktif</p>
          </div>
          <div className="p-3 rounded-2xl bg-emerald-50 text-[#15803D] border border-emerald-100">
            <CheckCircle2 className="w-5 h-5" />
          </div>
        </div>

        <div className="bg-white p-5 rounded-3xl border border-slate-100 shadow-sm flex items-center justify-between">
          <div>
            <p className="text-xs text-slate-400 font-medium">Pengajuan Ditolak</p>
            <h3 className="text-2xl font-bold text-rose-600 mt-1">{stats.ditolak}</h3>
            <p className="text-[10px] text-rose-700 font-medium mt-0.5">Dapat diajukan ulang</p>
          </div>
          <div className="p-3 rounded-2xl bg-rose-50 text-rose-700 border border-rose-100">
            <XCircle className="w-5 h-5" />
          </div>
        </div>
      </div>

      {/* 3. BILAH PENCARIAN & FILTER */}
      <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm space-y-4">
        <div className="flex flex-col md:flex-row items-stretch md:items-center justify-between gap-3">
          {/* Input Search */}
          <div className="relative flex-1">
            <Search className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
            <input
              type="text"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              placeholder="Cari berdasarkan nama mahasiswa, NIM, atau judul skripsi..."
              className="w-full pl-10 pr-4 py-2.5 rounded-2xl border border-slate-200 text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-[#15803D]/20 focus:border-[#15803D] transition placeholder:text-slate-400"
            />
          </div>

          {/* Filter Status */}
          <div className="flex items-center gap-2">
            <Filter className="w-4 h-4 text-slate-400 shrink-0" />
            <select
              value={filterStatus}
              onChange={(e) => setFilterStatus(e.target.value)}
              className="px-3 py-2.5 rounded-2xl border border-slate-200 text-xs font-semibold text-slate-700 bg-white focus:outline-none focus:ring-2 focus:ring-[#15803D]/20 focus:border-[#15803D] transition"
            >
              <option value="all">Semua Status</option>
              <option value="menunggu_penetapan">Menunggu Penetapan</option>
              <option value="ditetapkan">Ditetapkan</option>
              <option value="ditolak">Ditolak</option>
            </select>
          </div>
        </div>

        {/* 4. TABEL ANTREAN PENGAJUAN */}
        {loadingAntrean ? (
          <div className="py-12 flex flex-col items-center justify-center text-slate-400 gap-2">
            <Loader2 className="w-8 h-8 animate-spin text-[#15803D]" />
            <p className="text-xs">Memuat data antrean pengajuan...</p>
          </div>
        ) : errorAntrean ? (
          <div className="p-4 bg-rose-50 border border-rose-200 rounded-2xl text-xs text-rose-700 flex items-start gap-3">
            <AlertCircle className="w-5 h-5 text-rose-600 shrink-0 mt-0.5" />
            <div>
              <p className="font-semibold">Gagal Memuat Antrean</p>
              <p className="text-rose-600 mt-0.5">{errorAntrean}</p>
            </div>
          </div>
        ) : antrean.length === 0 ? (
          <div className="py-12 flex flex-col items-center justify-center text-center space-y-2">
            <div className="w-12 h-12 bg-slate-100 text-slate-400 rounded-full flex items-center justify-center">
              <SearchX className="w-6 h-6" />
            </div>
            <p className="text-xs font-bold text-slate-700">Tidak Ditemukan Pengajuan</p>
            <p className="text-[11px] text-slate-400 max-w-sm">
              Tidak ada data pengajuan pembimbing yang cocok dengan kriteria pencarian atau filter yang dipilih.
            </p>
          </div>
        ) : (
          <div className="overflow-x-auto -mx-6 px-6">
            <table className="w-full text-left border-collapse text-xs">
              <thead>
                <tr className="border-b border-slate-100 text-[11px] font-bold uppercase tracking-wider text-slate-400">
                  <th className="py-3 px-3">Mahasiswa</th>
                  <th className="py-3 px-3">Judul Skripsi</th>
                  <th className="py-3 px-3">Tanggal Pengajuan</th>
                  <th className="py-3 px-3">Status</th>
                  <th className="py-3 px-3">Dosen Pembimbing</th>
                  <th className="py-3 px-3 text-right">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {antrean.map((item) => (
                  <tr key={item.id} className="hover:bg-slate-50/70 transition">
                    {/* Mahasiswa Info */}
                    <td className="py-3.5 px-3">
                      <div>
                        <p className="font-bold text-slate-800">{item.mahasiswa?.nama_mahasiswa || "-"}</p>
                        <p className="text-[11px] font-mono text-slate-500">NIM: {item.mahasiswa?.NIM || "-"}</p>
                        {item.mahasiswa?.prodi && (
                          <span className="inline-block mt-0.5 text-[10px] text-emerald-700 font-semibold bg-emerald-50 px-2 py-0.2 rounded-full border border-emerald-100">
                            {item.mahasiswa.prodi}
                          </span>
                        )}
                      </div>
                    </td>

                    {/* Judul Skripsi */}
                    <td className="py-3.5 px-3 max-w-xs">
                      <p className="line-clamp-2 text-slate-700 font-medium leading-relaxed" title={item.skripsi?.judul}>
                        {item.skripsi?.judul || "-"}
                      </p>
                    </td>

                    {/* Tanggal */}
                    <td className="py-3.5 px-3 text-slate-500 whitespace-nowrap">
                      {formatDate(item.created_at)}
                    </td>

                    {/* Status Badge */}
                    <td className="py-3.5 px-3 whitespace-nowrap">
                      {renderStatusBadge(item.status)}
                    </td>

                    {/* Dosen Pembimbing */}
                    <td className="py-3.5 px-3">
                      {item.dosen ? (
                        <div>
                          <p className="font-bold text-slate-800">{item.dosen.nama_dosen}</p>
                          <p className="text-[11px] font-mono text-slate-500">NIDN: {item.dosen.NIDN}</p>
                        </div>
                      ) : (
                        <span className="text-slate-400 italic">Belum ditetapkan</span>
                      )}
                    </td>

                    {/* Tombol Aksi */}
                    <td className="py-3.5 px-3 text-right whitespace-nowrap">
                      <div className="inline-flex items-center gap-1.5">
                        {/* Tombol Detail & Log */}
                        <button
                          type="button"
                          onClick={() => fetchDetailItem(item.id)}
                          className="p-1.5 text-slate-600 hover:text-slate-900 bg-slate-100 hover:bg-slate-200 rounded-xl transition"
                          title="Lihat Detail & Log Audit"
                        >
                          <Eye className="w-4 h-4" />
                        </button>

                        {/* Tombol Tetapkan (Hanya status menunggu_penetapan) */}
                        {item.status === "menunggu_penetapan" && (
                          <button
                            type="button"
                            onClick={() => handleOpenTetapkanModal(item)}
                            className="inline-flex items-center gap-1 px-3 py-1.5 bg-[#15803D] hover:bg-emerald-800 text-white rounded-xl text-xs font-semibold shadow-sm transition"
                            title="Tetapkan Pembimbing"
                          >
                            <UserCheck className="w-3.5 h-3.5" />
                            Tetapkan
                          </button>
                        )}

                        {/* Tombol Tolak (Hanya status menunggu_penetapan) */}
                        {item.status === "menunggu_penetapan" && (
                          <button
                            type="button"
                            onClick={() => handleOpenTolakModal(item)}
                            className="p-1.5 text-rose-600 hover:text-white hover:bg-rose-600 bg-rose-50 border border-rose-200 rounded-xl transition"
                            title="Tolak Pengajuan"
                          >
                            <XCircle className="w-4 h-4" />
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* 5. MODAL PENETAPAN DOSEN PEMBIMBING */}
      {selectedTetapkan && (
        <div className="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center p-4">
          <div className="bg-white w-full max-w-lg rounded-3xl shadow-xl border border-slate-100 overflow-hidden space-y-4 animate-in fade-in zoom-in-95 duration-150">
            {/* Modal Header */}
            <div className="p-6 bg-[#15803D] text-white flex items-center justify-between">
              <div className="flex items-center gap-2.5">
                <div className="p-2 bg-white/20 rounded-2xl">
                  <UserCheck className="w-5 h-5 text-white" />
                </div>
                <div>
                  <h3 className="font-bold text-sm">Tetapkan Dosen Pembimbing Proposal</h3>
                  <p className="text-[11px] text-emerald-100">
                    Mahasiswa: {selectedTetapkan.mahasiswa?.nama_mahasiswa} ({selectedTetapkan.mahasiswa?.NIM})
                  </p>
                </div>
              </div>
              <button
                onClick={() => setSelectedTetapkan(null)}
                className="p-1 rounded-full text-white/80 hover:text-white hover:bg-white/10 transition"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {/* Modal Body Form */}
            <form onSubmit={handleSubmitTetapkan} className="p-6 pt-2 space-y-4">
              {/* Ringkasan Skripsi */}
              <div className="p-3.5 bg-slate-50 border border-slate-200/80 rounded-2xl text-xs space-y-1">
                <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400">Judul Skripsi:</span>
                <p className="font-semibold text-slate-800 leading-relaxed">{selectedTetapkan.skripsi?.judul}</p>
                {selectedTetapkan.catatan_mahasiswa && (
                  <p className="text-slate-500 italic mt-1 text-[11px]">
                    Catatan mahasiswa: &ldquo;{selectedTetapkan.catatan_mahasiswa}&rdquo;
                  </p>
                )}
              </div>

              {errorTetapkan && (
                <div className="p-3 bg-rose-50 border border-rose-200 rounded-xl text-xs text-rose-700 flex items-start gap-2">
                  <AlertCircle className="w-4 h-4 shrink-0 text-rose-600 mt-0.5" />
                  <p>{errorTetapkan}</p>
                </div>
              )}

              {/* Pilihan Dosen */}
              <div className="space-y-1.5">
                <label htmlFor="dosen_options" className="text-xs font-bold text-slate-700 block">
                  Pilih Dosen Pembimbing Proposal <span className="text-rose-600">*</span>
                </label>
                {loadingOptions ? (
                  <div className="p-3 bg-slate-50 border border-slate-200 rounded-2xl text-xs text-slate-400 flex items-center gap-2">
                    <Loader2 className="w-4 h-4 animate-spin text-[#15803D]" />
                    Memuat daftar dosen program studi...
                  </div>
                ) : errorOptions ? (
                  <div className="p-3 bg-rose-50 border border-rose-200 rounded-2xl text-xs text-rose-700">
                    {errorOptions}
                  </div>
                ) : (
                  <select
                    id="dosen_options"
                    value={selectedDosenId}
                    onChange={(e) => setSelectedDosenId(e.target.value)}
                    required
                    className="w-full px-4 py-2.5 rounded-2xl border border-slate-200 text-xs font-medium text-slate-800 focus:outline-none focus:ring-2 focus:ring-[#15803D]/20 focus:border-[#15803D] transition bg-white"
                  >
                    <option value="">-- Pilih Dosen Pembimbing --</option>
                    {dosenOptions.map((dsn) => (
                      <option key={dsn.id_dosen} value={dsn.id_dosen}>
                        {dsn.nama_dosen} (NIDN: {dsn.NIDN}) &bull; [Beban: {dsn.jumlah_penugasan_teramati} bimbingan]
                      </option>
                    ))}
                  </select>
                )}
                <p className="text-[10px] text-slate-400 italic">
                  * Indikator beban bimbingan hanya merupakan informasi manajerial dan bukan kuota restriktif.
                </p>
              </div>

              {/* Catatan Admin */}
              <div className="space-y-1.5">
                <div className="flex justify-between items-center">
                  <label htmlFor="catatan_tetapkan" className="text-xs font-bold text-slate-700">
                    Catatan Administrator <span className="font-normal text-slate-400">(Opsional)</span>
                  </label>
                  <span className="text-[10px] font-mono text-slate-400">
                    {catatanTetapkan.length}/{MAX_CHAR}
                  </span>
                </div>
                <textarea
                  id="catatan_tetapkan"
                  rows={3}
                  value={catatanTetapkan}
                  onChange={(e) => setCatatanTetapkan(e.target.value)}
                  maxLength={MAX_CHAR}
                  placeholder="Catatan instruksi atau arahan penetapan untuk mahasiswa dan dosen..."
                  className="w-full px-4 py-2.5 rounded-2xl border border-slate-200 text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-[#15803D]/20 focus:border-[#15803D] transition placeholder:text-slate-400"
                />
              </div>

              {/* Modal Actions */}
              <div className="flex items-center justify-end gap-2.5 pt-2 border-t border-slate-100">
                <button
                  type="button"
                  onClick={() => setSelectedTetapkan(null)}
                  className="px-4 py-2 text-xs font-semibold text-slate-600 hover:text-slate-800 hover:bg-slate-100 rounded-xl transition"
                >
                  Batal
                </button>
                <button
                  type="submit"
                  disabled={isSubmittingTetapkan || !selectedDosenId}
                  className="inline-flex items-center gap-1.5 px-5 py-2 bg-[#15803D] hover:bg-emerald-800 text-white rounded-xl text-xs font-bold shadow transition disabled:opacity-50"
                >
                  {isSubmittingTetapkan ? (
                    <>
                      <Loader2 className="w-3.5 h-3.5 animate-spin" />
                      Menetapkan...
                    </>
                  ) : (
                    <>
                      <CheckCircle2 className="w-3.5 h-3.5" />
                      Konfirmasi Penetapan
                    </>
                  )}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* 6. MODAL PENOLAKAN PENGAJUAN */}
      {selectedTolak && (
        <div className="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center p-4">
          <div className="bg-white w-full max-w-md rounded-3xl shadow-xl border border-slate-100 overflow-hidden space-y-4 animate-in fade-in zoom-in-95 duration-150">
            {/* Modal Header */}
            <div className="p-5 bg-rose-600 text-white flex items-center justify-between">
              <div className="flex items-center gap-2.5">
                <div className="p-2 bg-white/20 rounded-2xl">
                  <XCircle className="w-5 h-5 text-white" />
                </div>
                <div>
                  <h3 className="font-bold text-sm">Tolak Pengajuan Pembimbing</h3>
                  <p className="text-[11px] text-rose-100">
                    Mahasiswa: {selectedTolak.mahasiswa?.nama_mahasiswa} ({selectedTolak.mahasiswa?.NIM})
                  </p>
                </div>
              </div>
              <button
                onClick={() => setSelectedTolak(null)}
                className="p-1 rounded-full text-white/80 hover:text-white hover:bg-white/10 transition"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {/* Modal Body Form */}
            <form onSubmit={handleSubmitTolak} className="p-6 pt-2 space-y-4">
              <div className="p-3.5 bg-rose-50/60 border border-rose-200 rounded-2xl text-xs text-rose-800 space-y-1">
                <p className="font-bold">Konfirmasi Penolakan</p>
                <p className="text-[11px] leading-relaxed">
                  Pengajuan akan ditandai berstatus <span className="font-bold">Ditolak</span>. Mahasiswa dapat melihat
                  alasan penolakan dan diperbolehkan mengirimkan pengajuan ulang setelah memenuhi arahan admin.
                </p>
              </div>

              {errorTolak && (
                <div className="p-3 bg-rose-50 border border-rose-200 rounded-xl text-xs text-rose-700 flex items-start gap-2">
                  <AlertCircle className="w-4 h-4 shrink-0 text-rose-600 mt-0.5" />
                  <p>{errorTolak}</p>
                </div>
              )}

              {/* Alasan Penolakan (Wajib) */}
              <div className="space-y-1.5">
                <div className="flex justify-between items-center">
                  <label htmlFor="catatan_tolak" className="text-xs font-bold text-slate-700">
                    Alasan Penolakan <span className="text-rose-600">*</span>
                  </label>
                  <span className="text-[10px] font-mono text-slate-400">
                    {catatanTolak.length}/{MAX_CHAR}
                  </span>
                </div>
                <textarea
                  id="catatan_tolak"
                  rows={4}
                  required
                  value={catatanTolak}
                  onChange={(e) => setCatatanTolak(e.target.value)}
                  maxLength={MAX_CHAR}
                  placeholder="Jelaskan alasan penolakan dan arahan yang harus dilakukan mahasiswa..."
                  className="w-full px-4 py-2.5 rounded-2xl border border-slate-200 text-xs text-slate-800 focus:outline-none focus:ring-2 focus:ring-rose-500/20 focus:border-rose-500 transition placeholder:text-slate-400"
                />
              </div>

              {/* Modal Actions */}
              <div className="flex items-center justify-end gap-2.5 pt-2 border-t border-slate-100">
                <button
                  type="button"
                  onClick={() => setSelectedTolak(null)}
                  className="px-4 py-2 text-xs font-semibold text-slate-600 hover:text-slate-800 hover:bg-slate-100 rounded-xl transition"
                >
                  Batal
                </button>
                <button
                  type="submit"
                  disabled={isSubmittingTolak || !catatanTolak.trim()}
                  className="inline-flex items-center gap-1.5 px-5 py-2 bg-rose-600 hover:bg-rose-700 text-white rounded-xl text-xs font-bold shadow transition disabled:opacity-50"
                >
                  {isSubmittingTolak ? (
                    <>
                      <Loader2 className="w-3.5 h-3.5 animate-spin" />
                      Memproses...
                    </>
                  ) : (
                    <>
                      <XCircle className="w-3.5 h-3.5" />
                      Konfirmasi Tolak
                    </>
                  )}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* 7. MODAL DETAIL & LOG AUDIT */}
      {selectedDetail && (
        <div className="fixed inset-0 bg-black/40 backdrop-blur-sm z-50 flex items-center justify-center p-4">
          <div className="bg-white w-full max-w-2xl max-h-[90vh] rounded-3xl shadow-xl border border-slate-100 overflow-hidden flex flex-col animate-in fade-in zoom-in-95 duration-150">
            {/* Header */}
            <div className="p-6 bg-slate-800 text-white flex items-center justify-between shrink-0">
              <div className="flex items-center gap-2.5">
                <div className="p-2 bg-white/10 rounded-2xl">
                  <FileText className="w-5 h-5 text-white" />
                </div>
                <div>
                  <h3 className="font-bold text-sm">Detail Pengajuan & Riwayat Audit</h3>
                  <p className="text-[11px] text-slate-300">ID Pengajuan: #{selectedDetail.id}</p>
                </div>
              </div>
              <button
                onClick={() => setSelectedDetail(null)}
                className="p-1 rounded-full text-white/80 hover:text-white hover:bg-white/10 transition"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {/* Scrollable Body */}
            <div className="p-6 overflow-y-auto space-y-5 text-xs">
              {/* Profil Mahasiswa & Skripsi */}
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div className="p-4 bg-slate-50 border border-slate-200/80 rounded-2xl space-y-1.5">
                  <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                    Data Mahasiswa:
                  </span>
                  <p className="font-bold text-sm text-slate-800">{selectedDetail.mahasiswa?.nama_mahasiswa}</p>
                  <p className="font-mono text-slate-600">NIM: {selectedDetail.mahasiswa?.NIM}</p>
                  <p className="text-slate-600">Prodi: {selectedDetail.mahasiswa?.prodi || "-"}</p>
                </div>

                <div className="p-4 bg-slate-50 border border-slate-200/80 rounded-2xl space-y-1.5">
                  <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                    Status Saat Ini:
                  </span>
                  <div>{renderStatusBadge(selectedDetail.status)}</div>
                  <p className="text-slate-500 text-[11px] mt-1">
                    Diajukan pada: {formatDate(selectedDetail.created_at)}
                  </p>
                  {selectedDetail.dosen && (
                    <div className="pt-1">
                      <span className="text-[10px] text-slate-400">Dosen Pembimbing Ditetapkan:</span>
                      <p className="font-bold text-emerald-800">{selectedDetail.dosen.nama_dosen}</p>
                      <p className="font-mono text-[10px] text-slate-500">NIDN: {selectedDetail.dosen.NIDN}</p>
                    </div>
                  )}
                </div>
              </div>

              {/* Judul Skripsi */}
              <div className="p-4 bg-white border border-slate-200 rounded-2xl space-y-1">
                <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400">Judul Skripsi:</span>
                <p className="font-semibold text-slate-800 leading-relaxed">{selectedDetail.skripsi?.judul}</p>
              </div>

              {/* Catatan Mahasiswa & Catatan Admin */}
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div className="p-3.5 bg-slate-50 border border-slate-200 rounded-2xl space-y-1">
                  <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400">
                    Catatan Mahasiswa:
                  </span>
                  <p className="text-slate-700 italic">
                    {selectedDetail.catatan_mahasiswa ? `"${selectedDetail.catatan_mahasiswa}"` : "Tidak ada catatan"}
                  </p>
                </div>

                <div className="p-3.5 bg-slate-50 border border-slate-200 rounded-2xl space-y-1">
                  <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400">Catatan Admin:</span>
                  <p className="text-slate-700 italic">
                    {selectedDetail.catatan_admin ? `"${selectedDetail.catatan_admin}"` : "Tidak ada catatan"}
                  </p>
                </div>
              </div>

              {/* Audit Log Timeline */}
              <div className="space-y-3 pt-2">
                <h4 className="font-bold text-xs text-slate-800 uppercase tracking-wider border-b border-slate-100 pb-2">
                  Riwayat Audit Lengkap (Append-Only)
                </h4>
                {selectedDetail.logs && selectedDetail.logs.length > 0 ? (
                  <div className="relative border-l-2 border-emerald-200 ml-2 pl-4 space-y-4">
                    {selectedDetail.logs.map((log) => (
                      <div key={log.id} className="relative space-y-1">
                        <span className="absolute -left-[21px] top-1.5 w-2 h-2 rounded-full bg-[#15803D]" />
                        <div className="flex flex-wrap items-center gap-2">
                          <span className="font-bold text-slate-800">{log.aktor_nama}</span>
                          <span className="px-2 py-0.5 bg-slate-100 text-slate-600 rounded-full text-[10px] font-semibold uppercase">
                            {log.aktor_role}
                          </span>
                          <span className="text-[10px] text-slate-400">{formatDate(log.created_at)}</span>
                        </div>
                        <p className="text-slate-600 text-xs">
                          Tindakan: <span className="font-semibold text-slate-800">{log.tindakan}</span> &bull; Status
                          Sesudah: <span className="font-semibold text-slate-800">{log.status_sesudah}</span>
                        </p>
                        {log.catatan && (
                          <div className="p-2.5 bg-slate-50 border border-slate-100 rounded-xl text-slate-600 text-[11px] italic">
                            &ldquo;{log.catatan}&rdquo;
                          </div>
                        )}
                        {log.snapshot_dosen_nama && (
                          <p className="text-[11px] text-[#15803D] font-medium">
                            Snapshot Dosen: {log.snapshot_dosen_nama} (ID: {log.snapshot_dosen_id})
                          </p>
                        )}
                      </div>
                    ))}
                  </div>
                ) : (
                  <p className="text-slate-400 italic">Belum ada riwayat audit log.</p>
                )}
              </div>
            </div>

            {/* Footer */}
            <div className="p-4 bg-slate-50 border-t border-slate-100 flex justify-end shrink-0">
              <button
                type="button"
                onClick={() => setSelectedDetail(null)}
                className="px-5 py-2 bg-slate-800 hover:bg-slate-900 text-white rounded-xl text-xs font-semibold transition"
              >
                Tutup
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
