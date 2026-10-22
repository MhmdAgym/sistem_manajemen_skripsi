"use client";

import React, { useState, useEffect, useMemo, useCallback } from "react";
import axios from "@/lib/axios";
import { useAuth } from "@/hooks/auth";
import {
  Award,
  Search,
  RefreshCw,
  Plus,
  Edit3,
  Eye,
  PowerOff,
  CheckCircle2,
  XCircle,
  AlertCircle,
  Calendar,
  GraduationCap,
  Clock,
  X,
  Filter,
  ShieldAlert,
  AlertTriangle,
  HelpCircle,
  Check,
  SearchX,
  UserCheck
} from "lucide-react";

// Tipe Data Sesuai Kontrak Backend
interface UserAccount {
  id: number;
  name?: string;
  email: string;
}

interface DosenDetail {
  id_dosen: number;
  nama_dosen: string;
  NIDN: string;
  user?: UserAccount | null;
}

interface DosenOption {
  id_dosen: number;
  nama_dosen: string;
  NIDN: string;
}

interface PenugasanKaprodi {
  id: number;
  dosen_id: number;
  prodi: string;
  tanggal_mulai: string;
  tanggal_selesai: string | null;
  is_aktif: boolean;
  status_operasional?: "Aktif" | "Belum Berlaku" | "Kedaluwarsa" | "Nonaktif" | string;
  created_at?: string;
  updated_at?: string;
  dosen?: DosenDetail | null;
}

// 11 Program Studi Resmi Universitas Nurul Huda (AcademicConstant)
const DAFTAR_PRODI = [
  "Pendidikan Agama Islam",
  "Pendidikan Guru Madrasah Ibtidaiyah",
  "Pendidikan Bahasa Arab",
  "Pendidikan Fisika",
  "Pendidikan Ekonomi",
  "Pendidikan Bahasa Inggris",
  "Pendidikan Bahasa dan Sastra Indonesia",
  "Pendidikan Teknologi Informasi",
  "Informatika",
  "Matematika",
  "Sains Pertanian",
] as const;

export default function PenugasanKaprodiPage() {
  const { user } = useAuth({ middleware: "auth" });
  const isAdmin = user?.role === "admin";

  // Data State
  const [penugasanList, setPenugasanList] = useState<PenugasanKaprodi[]>([]);
  const [dosenOptions, setDosenOptions] = useState<DosenOption[]>([]);
  const [loading, setLoading] = useState(true);
  const [optionsLoading, setOptionsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  // Search & Filter State
  const [searchQuery, setSearchQuery] = useState("");
  const [filterProdi, setFilterProdi] = useState("all");
  const [filterAktif, setFilterAktif] = useState("all");
  const [filterOperasional, setFilterOperasional] = useState("all");

  // Modal State
  const [isAddModalOpen, setIsAddModalOpen] = useState(false);
  const [detailItem, setDetailItem] = useState<PenugasanKaprodi | null>(null);
  const [editItem, setEditItem] = useState<PenugasanKaprodi | null>(null);
  const [deactivateItem, setDeactivateItem] = useState<PenugasanKaprodi | null>(null);

  // Form State
  const initialForm = {
    dosen_id: "",
    prodi: "",
    tanggal_mulai: new Date().toISOString().split("T")[0],
    tanggal_selesai: "",
    is_aktif: true,
  };
  const [formData, setFormData] = useState(initialForm);
  const [formErrors, setFormErrors] = useState<Record<string, string[]>>({});
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isDeactivating, setIsDeactivating] = useState(false);

  // Modal Konfirmasi Konflik 409
  const [conflictModal, setConflictModal] = useState<{
    isOpen: boolean;
    message: string;
    mode: "add" | "edit";
  } | null>(null);

  // 1. Fetch Daftar Penugasan Kaprodi
  const fetchPenugasan = useCallback(async () => {
    try {
      setLoading(true);
      setErrorMessage(null);
      const res = await axios.get("/api/admin/penugasan-kaprodi");
      if (res.data?.status === "success" && Array.isArray(res.data?.data)) {
        setPenugasanList(res.data.data);
      } else {
        setPenugasanList([]);
      }
    } catch (err: any) {
      if (err.response?.status === 403) {
        setErrorMessage("Akses ditolak: Anda tidak memiliki wewenang administrator.");
      } else if (err.response?.status === 503) {
        setErrorMessage("Sistem sedang memproses data lain. Silakan coba beberapa saat lagi.");
      } else {
        setErrorMessage(
          err.response?.data?.message || "Gagal memuat data penugasan Kaprodi dari server."
        );
      }
    } finally {
      setLoading(false);
    }
  }, []);

  // 2. Fetch Opsi Dosen
  const fetchDosenOptions = useCallback(async () => {
    try {
      setOptionsLoading(true);
      const res = await axios.get("/api/admin/penugasan-kaprodi/dosen-options");
      if (res.data?.status === "success" && Array.isArray(res.data?.data)) {
        setDosenOptions(res.data.data);
      }
    } catch {
      // Abaikan kendala silent fetch opsi dosen
    } finally {
      setOptionsLoading(false);
    }
  }, []);

  useEffect(() => {
    if (isAdmin) {
      fetchPenugasan();
      fetchDosenOptions();
    }
  }, [isAdmin, fetchPenugasan, fetchDosenOptions]);

  // Filtered List
  const filteredList = useMemo(() => {
    return penugasanList.filter((item) => {
      // Filter Search: Nama dosen atau NIDN
      const q = searchQuery.toLowerCase().trim();
      const matchSearch =
        !q ||
        (item.dosen?.nama_dosen && item.dosen.nama_dosen.toLowerCase().includes(q)) ||
        (item.dosen?.NIDN && item.dosen.NIDN.toLowerCase().includes(q)) ||
        (item.prodi && item.prodi.toLowerCase().includes(q));

      // Filter Prodi
      const matchProdi = filterProdi === "all" || item.prodi === filterProdi;

      // Filter Status Aktif (boolean flag)
      const matchAktif =
        filterAktif === "all" ||
        (filterAktif === "aktif" && item.is_aktif) ||
        (filterAktif === "nonaktif" && !item.is_aktif);

      // Filter Status Operasional (berdasarkan tanggal & is_aktif dari backend)
      const matchOperasional =
        filterOperasional === "all" || item.status_operasional === filterOperasional;

      return matchSearch && matchProdi && matchAktif && matchOperasional;
    });
  }, [penugasanList, searchQuery, filterProdi, filterAktif, filterOperasional]);

  // Ringkasan Statistik
  const stats = useMemo(() => {
    const total = penugasanList.length;
    const aktifOperasional = penugasanList.filter((p) => p.status_operasional === "Aktif").length;
    const nonaktif = penugasanList.filter((p) => !p.is_aktif).length;
    const kedaluwarsa = penugasanList.filter((p) => p.status_operasional === "Kedaluwarsa").length;
    return { total, aktifOperasional, nonaktif, kedaluwarsa };
  }, [penugasanList]);

  // Helper Notifikasi
  const notifySuccess = (msg: string) => {
    setSuccessMessage(msg);
    setTimeout(() => setSuccessMessage(null), 5000);
  };

  // Helper Buka Modal Tambah
  const handleOpenAdd = () => {
    setFormData({
      dosen_id: "",
      prodi: "",
      tanggal_mulai: new Date().toISOString().split("T")[0],
      tanggal_selesai: "",
      is_aktif: true,
    });
    setFormErrors({});
    setIsAddModalOpen(true);
  };

  // Helper Buka Modal Edit (Fetch detail terbaru)
  const handleOpenEdit = async (item: PenugasanKaprodi) => {
    try {
      setErrorMessage(null);
      // Ambil data fresh dari endpoint detail sebelum mengisi form
      const res = await axios.get(`/api/admin/penugasan-kaprodi/${item.id}`);
      const freshData: PenugasanKaprodi = res.data?.data || item;

      setEditItem(freshData);
      setFormData({
        dosen_id: String(freshData.dosen_id || ""),
        prodi: freshData.prodi || "",
        tanggal_mulai: freshData.tanggal_mulai ? freshData.tanggal_mulai.split("T")[0] : "",
        tanggal_selesai: freshData.tanggal_selesai ? freshData.tanggal_selesai.split("T")[0] : "",
        is_aktif: Boolean(freshData.is_aktif),
      });
      setFormErrors({});
    } catch {
      // Fallback menggunakan data list yang sudah ada jika detail fetch terkendala
      setEditItem(item);
      setFormData({
        dosen_id: String(item.dosen_id || ""),
        prodi: item.prodi || "",
        tanggal_mulai: item.tanggal_mulai ? item.tanggal_mulai.split("T")[0] : "",
        tanggal_selesai: item.tanggal_selesai ? item.tanggal_selesai.split("T")[0] : "",
        is_aktif: Boolean(item.is_aktif),
      });
      setFormErrors({});
    }
  };

  // Eksekusi Submit Tambah Penugasan
  const submitAdd = async (replaceActive = false) => {
    setIsSubmitting(true);
    setFormErrors({});
    setErrorMessage(null);

    const payload: any = {
      dosen_id: Number(formData.dosen_id),
      prodi: formData.prodi,
      tanggal_mulai: formData.tanggal_mulai,
      tanggal_selesai: formData.tanggal_selesai || null,
      is_aktif: formData.is_aktif,
    };

    if (replaceActive) {
      payload.gantikan_penugasan_aktif = true;
    }

    try {
      const res = await axios.post("/api/admin/penugasan-kaprodi", payload);
      notifySuccess(res.data?.message || "Penugasan Kaprodi berhasil ditambahkan.");
      setIsAddModalOpen(false);
      setConflictModal(null);
      fetchPenugasan();
    } catch (err: any) {
      const status = err.response?.status;
      const data = err.response?.data;

      if (status === 409) {
        // Konflik Kaprodi Aktif: Tampilkan dialog konfirmasi eksplisit
        setConflictModal({
          isOpen: true,
          message: data?.message || `Program studi ${formData.prodi} telah memiliki Kaprodi aktif.`,
          mode: "add",
        });
      } else if (status === 422 && data?.errors) {
        setFormErrors(data.errors);
      } else if (status === 503) {
        setFormErrors({
          general: [
            data?.message || "Sistem sedang memproses penugasan untuk prodi ini. Silakan coba beberapa saat lagi.",
          ],
        });
      } else {
        setFormErrors({
          general: [data?.message || "Terjadi kesalahan saat menambahkan penugasan Kaprodi."],
        });
      }
    } finally {
      setIsSubmitting(false);
    }
  };

  // Eksekusi Submit Edit Penugasan
  const submitEdit = async (replaceActive = false) => {
    if (!editItem) return;

    setIsSubmitting(true);
    setFormErrors({});
    setErrorMessage(null);

    const payload: any = {
      dosen_id: Number(formData.dosen_id),
      prodi: formData.prodi,
      tanggal_mulai: formData.tanggal_mulai,
      tanggal_selesai: formData.tanggal_selesai || null,
      is_aktif: formData.is_aktif,
    };

    if (replaceActive) {
      payload.gantikan_penugasan_aktif = true;
    }

    try {
      const res = await axios.patch(`/api/admin/penugasan-kaprodi/${editItem.id}`, payload);
      notifySuccess(res.data?.message || "Penugasan Kaprodi berhasil diperbarui.");
      setEditItem(null);
      setConflictModal(null);
      fetchPenugasan();
    } catch (err: any) {
      const status = err.response?.status;
      const data = err.response?.data;

      if (status === 409) {
        // Konflik Kaprodi Aktif di prodi tujuan
        setConflictModal({
          isOpen: true,
          message: data?.message || `Program studi ${formData.prodi} telah memiliki Kaprodi aktif lain.`,
          mode: "edit",
        });
      } else if (status === 422 && data?.errors) {
        setFormErrors(data.errors);
      } else if (status === 503) {
        setFormErrors({
          general: [
            data?.message || "Sistem sedang memproses penugasan untuk prodi ini. Silakan coba beberapa saat lagi.",
          ],
        });
      } else {
        setFormErrors({
          general: [data?.message || "Terjadi kesalahan saat memperbarui penugasan Kaprodi."],
        });
      }
    } finally {
      setIsSubmitting(false);
    }
  };

  // Eksekusi Nonaktifkan Penugasan
  const handleNonaktifkan = async () => {
    if (!deactivateItem) return;

    setIsDeactivating(true);
    setErrorMessage(null);

    try {
      const res = await axios.patch(
        `/api/admin/penugasan-kaprodi/${deactivateItem.id}/nonaktifkan`
      );
      notifySuccess(res.data?.message || "Penugasan Kaprodi berhasil dinonaktifkan.");
      setDeactivateItem(null);
      fetchPenugasan();
    } catch (err: any) {
      const status = err.response?.status;
      const data = err.response?.data;

      if (status === 404) {
        setErrorMessage("Data penugasan tidak ditemukan atau sudah diperbarui sebelumnya.");
      } else if (status === 503) {
        setErrorMessage(
          data?.message || "Sistem sedang sibuk memproses penugasan ini. Silakan coba beberapa saat lagi."
        );
      } else {
        setErrorMessage(data?.message || "Gagal menonaktifkan penugasan Kaprodi.");
      }
      setDeactivateItem(null);
    } finally {
      setIsDeactivating(false);
    }
  };

  // Jika Bukan Admin, Tampilkan Layar Terproteksi
  if (user && !isAdmin) {
    return (
      <div className="p-8 max-w-2xl mx-auto my-12 bg-white rounded-3xl border border-red-100 shadow-xl text-center space-y-4">
        <div className="w-16 h-16 bg-red-50 text-red-600 rounded-2xl flex items-center justify-center mx-auto shadow-inner">
          <ShieldAlert className="w-8 h-8" />
        </div>
        <h1 className="text-xl font-bold text-slate-800 tracking-tight">Akses Terbatas: Administrator Saja</h1>
        <p className="text-xs text-slate-500 leading-relaxed">
          Halaman penugasan Ketua Program Studi (Kaprodi) hanya dapat diakses dan dikelola oleh akun dengan peran Administrator Sistem.
        </p>
      </div>
    );
  }

  return (
    <div className="space-y-6 font-sans text-slate-800">
      {/* 1. BANNER HEADER */}
      <div className="p-6 bg-gradient-to-r from-emerald-700 via-emerald-600 to-teal-700 rounded-3xl text-white shadow-lg flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <div>
          <span className="px-3 py-1 bg-white/20 backdrop-blur-md rounded-full text-[11px] font-semibold uppercase tracking-wider text-emerald-50 border border-white/10 flex items-center gap-1.5 w-fit">
            <Award className="w-3.5 h-3.5" /> Tata Kelola Program Studi
          </span>
          <h1 className="text-2xl font-bold tracking-tight mt-2">Penugasan Kaprodi</h1>
          <p className="text-xs text-emerald-100 mt-1 max-w-2xl leading-relaxed">
            Kelola penetapan dan masa aktif Ketua Program Studi (Kaprodi) per program studi. Sistem secara otomatis menerapkan serialisasi antrean untuk mencegah tumpang tindih Kaprodi aktif.
          </p>
        </div>

        <div className="flex items-center gap-2.5 w-full md:w-auto">
          <button
            onClick={fetchPenugasan}
            disabled={loading}
            className="px-4 py-2 bg-white/15 hover:bg-white/25 text-white font-bold text-xs rounded-xl shadow border border-white/20 transition flex items-center justify-center gap-1.5 disabled:opacity-50"
            title="Muat ulang data penugasan"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${loading ? "animate-spin" : ""}`} />
            <span>Muat Ulang</span>
          </button>

          <button
            onClick={handleOpenAdd}
            className="px-4 py-2 bg-white text-[#15803D] hover:bg-emerald-50 font-bold text-xs rounded-xl shadow transition flex items-center justify-center gap-1.5 shrink-0"
          >
            <Plus className="w-4 h-4" />
            <span>Tambah Penugasan</span>
          </button>
        </div>
      </div>

      {/* 2. STATISTIK RINGKAS */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 sm:gap-4">
        <div className="bg-white p-4 rounded-2xl border border-slate-100 shadow-sm">
          <div className="text-[11px] font-medium text-slate-400 uppercase tracking-wider">Total Riwayat</div>
          <div className="text-xl font-bold text-slate-800 mt-1">{stats.total}</div>
          <div className="text-[10px] text-slate-400 mt-0.5">Penugasan tercatat</div>
        </div>
        <div className="bg-white p-4 rounded-2xl border border-slate-100 shadow-sm">
          <div className="text-[11px] font-medium text-emerald-600 uppercase tracking-wider">Berlaku Aktif</div>
          <div className="text-xl font-bold text-emerald-700 mt-1">{stats.aktifOperasional}</div>
          <div className="text-[10px] text-emerald-600/80 mt-0.5">Kaprodi aktif bertugas</div>
        </div>
        <div className="bg-white p-4 rounded-2xl border border-slate-100 shadow-sm">
          <div className="text-[11px] font-medium text-slate-500 uppercase tracking-wider">Dinonaktifkan</div>
          <div className="text-xl font-bold text-slate-700 mt-1">{stats.nonaktif}</div>
          <div className="text-[10px] text-slate-400 mt-0.5">Status flag nonaktif</div>
        </div>
        <div className="bg-white p-4 rounded-2xl border border-slate-100 shadow-sm">
          <div className="text-[11px] font-medium text-amber-600 uppercase tracking-wider">Kedaluwarsa</div>
          <div className="text-xl font-bold text-amber-700 mt-1">{stats.kedaluwarsa}</div>
          <div className="text-[10px] text-amber-600/80 mt-0.5">Masa jabatan selesai</div>
        </div>
      </div>

      {/* 3. ALERTS */}
      {successMessage && (
        <div className="p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-2xl flex items-center justify-between text-xs shadow-sm animate-in fade-in">
          <div className="flex items-center gap-3">
            <CheckCircle2 className="w-5 h-5 text-emerald-600 shrink-0" />
            <span className="font-semibold">{successMessage}</span>
          </div>
          <button
            onClick={() => setSuccessMessage(null)}
            className="text-emerald-500 hover:text-emerald-700 p-1"
          >
            <X className="w-4 h-4" />
          </button>
        </div>
      )}

      {errorMessage && (
        <div className="p-4 bg-red-50 border border-red-200 text-red-800 rounded-2xl flex items-center justify-between text-xs shadow-sm animate-in fade-in">
          <div className="flex items-center gap-3">
            <AlertCircle className="w-5 h-5 text-red-600 shrink-0" />
            <span>{errorMessage}</span>
          </div>
          <button
            onClick={() => setErrorMessage(null)}
            className="text-red-500 hover:text-red-700 p-1"
          >
            <X className="w-4 h-4" />
          </button>
        </div>
      )}

      {/* 4. KARTU DAFTAR PENUGASAN KAPRODI */}
      <div className="bg-white p-5 sm:p-6 rounded-3xl border border-slate-100 shadow-sm space-y-5">
        {/* Kontrol Search & Filters */}
        <div className="flex flex-col lg:flex-row justify-between items-stretch lg:items-center gap-3">
          <div className="flex items-center gap-2">
            <Award className="w-5 h-5 text-emerald-600" />
            <h2 className="font-bold text-slate-800 text-sm">
              Daftar Penugasan ({filteredList.length} dari {penugasanList.length})
            </h2>
          </div>

          <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-2.5">
            {/* Search Input */}
            <div className="relative w-full sm:w-64">
              <Search className="absolute left-3 top-2.5 w-4 h-4 text-slate-400" />
              <input
                type="text"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                placeholder="Cari dosen, NIDN, prodi..."
                className="w-full pl-9 pr-8 py-2 rounded-xl border border-slate-200 text-xs focus:outline-none focus:border-emerald-600 transition"
              />
              {searchQuery && (
                <button
                  onClick={() => setSearchQuery("")}
                  className="absolute right-2.5 top-2.5 text-slate-400 hover:text-slate-600"
                >
                  <X className="w-3.5 h-3.5" />
                </button>
              )}
            </div>

            {/* Filter Program Studi */}
            <div className="relative w-full sm:w-52">
              <select
                value={filterProdi}
                onChange={(e) => setFilterProdi(e.target.value)}
                className="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:outline-none focus:border-emerald-600 transition bg-white text-slate-700 truncate"
              >
                <option value="all">Semua Program Studi</option>
                {DAFTAR_PRODI.map((prodi) => (
                  <option key={prodi} value={prodi}>
                    {prodi}
                  </option>
                ))}
              </select>
            </div>

            {/* Filter Status Operasional */}
            <div className="relative w-full sm:w-40">
              <select
                value={filterOperasional}
                onChange={(e) => setFilterOperasional(e.target.value)}
                className="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:outline-none focus:border-emerald-600 transition bg-white text-slate-700"
              >
                <option value="all">Semua Status</option>
                <option value="Aktif">Berlaku Aktif</option>
                <option value="Belum Berlaku">Belum Berlaku</option>
                <option value="Kedaluwarsa">Kedaluwarsa</option>
                <option value="Nonaktif">Nonaktif</option>
              </select>
            </div>

            {/* Tombol Reset Filter */}
            {(searchQuery || filterProdi !== "all" || filterOperasional !== "all" || filterAktif !== "all") && (
              <button
                onClick={() => {
                  setSearchQuery("");
                  setFilterProdi("all");
                  setFilterAktif("all");
                  setFilterOperasional("all");
                }}
                className="px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl text-xs font-semibold transition shrink-0 flex items-center justify-center gap-1"
                title="Reset seluruh filter"
              >
                <X className="w-3.5 h-3.5" />
                <span>Reset</span>
              </button>
            )}
          </div>
        </div>

        {/* TABEL PENUGASAN */}
        <div className="overflow-x-auto">
          {loading ? (
            <div className="py-16 text-center text-slate-400 space-y-3">
              <RefreshCw className="w-8 h-8 animate-spin mx-auto text-emerald-600" />
              <p className="text-xs">Memuat daftar penugasan Kaprodi...</p>
            </div>
          ) : filteredList.length === 0 ? (
            <div className="py-16 text-center text-slate-400 space-y-3">
              {searchQuery || filterProdi !== "all" || filterOperasional !== "all" ? (
                <>
                  <SearchX className="w-10 h-10 mx-auto text-slate-300" />
                  <p className="text-sm font-semibold text-slate-600">Tidak ada data penugasan yang sesuai</p>
                  <p className="text-xs text-slate-400">
                    Coba ubah kata kunci pencarian atau sesuaikan opsi filter program studi.
                  </p>
                  <button
                    onClick={() => {
                      setSearchQuery("");
                      setFilterProdi("all");
                      setFilterAktif("all");
                      setFilterOperasional("all");
                    }}
                    className="mt-2 px-3 py-1.5 bg-slate-100 text-slate-600 hover:bg-slate-200 text-xs font-semibold rounded-xl transition inline-flex items-center gap-1"
                  >
                    <RefreshCw className="w-3 h-3" /> Bersihkan Filter
                  </button>
                </>
              ) : (
                <>
                  <Award className="w-10 h-10 mx-auto text-slate-300" />
                  <p className="text-sm font-semibold text-slate-600">Belum ada penugasan Kaprodi</p>
                  <p className="text-xs text-slate-400">
                    Tetapkan dosen sebagai Ketua Program Studi untuk mengaktifkan wewenang persetujuan judul skripsi.
                  </p>
                  <button
                    onClick={handleOpenAdd}
                    className="mt-2 px-4 py-2 bg-[#15803D] text-white hover:bg-emerald-800 text-xs font-bold rounded-xl transition inline-flex items-center gap-1.5 shadow"
                  >
                    <Plus className="w-4 h-4" /> Tambah Penugasan Sekarang
                  </button>
                </>
              )}
            </div>
          ) : (
            <table className="w-full text-left text-xs">
              <thead className="bg-slate-50 text-slate-600 uppercase font-semibold text-[11px] tracking-wider">
                <tr>
                  <th className="p-3.5 rounded-l-xl">Nama Dosen & NIDN</th>
                  <th className="p-3.5">Program Studi</th>
                  <th className="p-3.5">Masa Penugasan</th>
                  <th className="p-3.5 text-center">Status Flag</th>
                  <th className="p-3.5 text-center">Status Operasional</th>
                  <th className="p-3.5 rounded-r-xl text-center">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {filteredList.map((item) => {
                  const isCurrentActive = item.is_aktif && item.status_operasional === "Aktif";

                  return (
                    <tr key={item.id} className="hover:bg-slate-50/75 transition">
                      {/* Dosen & NIDN */}
                      <td className="p-3.5">
                        <div className="font-bold text-slate-800 text-xs">
                          {item.dosen?.nama_dosen || `Dosen ID: ${item.dosen_id}`}
                        </div>
                        <div className="text-[11px] text-slate-400 flex items-center gap-1.5 mt-0.5">
                          <span>NIDN: {item.dosen?.NIDN || "-"}</span>
                          {item.dosen?.user?.email && (
                            <>
                              <span>•</span>
                              <span className="truncate max-w-[140px]">{item.dosen.user.email}</span>
                            </>
                          )}
                        </div>
                      </td>

                      {/* Program Studi */}
                      <td className="p-3.5">
                        <span className="font-semibold text-slate-700 bg-slate-100 px-2.5 py-1 rounded-lg text-xs border border-slate-200/60 inline-block">
                          {item.prodi}
                        </span>
                      </td>

                      {/* Masa Penugasan */}
                      <td className="p-3.5 text-slate-600">
                        <div className="flex items-center gap-1 font-medium text-slate-700">
                          <Calendar className="w-3.5 h-3.5 text-emerald-600" />
                          <span>{item.tanggal_mulai ? item.tanggal_mulai.split("T")[0] : "-"}</span>
                        </div>
                        <div className="text-[11px] text-slate-400 mt-0.5">
                          s/d {item.tanggal_selesai ? item.tanggal_selesai.split("T")[0] : "Sekarang (Aktif)"}
                        </div>
                      </td>

                      {/* Status Flag is_aktif */}
                      <td className="p-3.5 text-center">
                        {item.is_aktif ? (
                          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                            <CheckCircle2 className="w-3 h-3" /> Aktif
                          </span>
                        ) : (
                          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-slate-100 text-slate-500 border border-slate-200">
                            <XCircle className="w-3 h-3" /> Nonaktif
                          </span>
                        )}
                      </td>

                      {/* Status Operasional */}
                      <td className="p-3.5 text-center">
                        {item.status_operasional === "Aktif" && (
                          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-100/70 text-emerald-800 border border-emerald-300">
                            Berlaku Aktif
                          </span>
                        )}
                        {item.status_operasional === "Belum Berlaku" && (
                          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-blue-50 text-blue-700 border border-blue-200">
                            <Clock className="w-3 h-3" /> Belum Berlaku
                          </span>
                        )}
                        {item.status_operasional === "Kedaluwarsa" && (
                          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-amber-50 text-amber-700 border border-amber-200">
                            <AlertTriangle className="w-3 h-3" /> Kedaluwarsa
                          </span>
                        )}
                        {item.status_operasional === "Nonaktif" && (
                          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-slate-100 text-slate-600 border border-slate-200">
                            Nonaktif
                          </span>
                        )}
                        {!item.status_operasional && (
                          <span className="text-slate-400 text-[11px]">-</span>
                        )}
                      </td>

                      {/* Aksi */}
                      <td className="p-3.5 text-center">
                        <div className="flex items-center justify-center gap-1.5">
                          <button
                            onClick={() => setDetailItem(item)}
                            className="p-1.5 text-slate-600 hover:text-emerald-700 hover:bg-emerald-50 rounded-lg transition"
                            title="Lihat Detail Penugasan"
                          >
                            <Eye className="w-4 h-4" />
                          </button>

                          <button
                            onClick={() => handleOpenEdit(item)}
                            className="p-1.5 text-slate-600 hover:text-[#15803D] hover:bg-emerald-50 rounded-lg transition"
                            title="Edit Data Penugasan"
                          >
                            <Edit3 className="w-4 h-4" />
                          </button>

                          {item.is_aktif && (
                            <button
                              onClick={() => setDeactivateItem(item)}
                              className="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition"
                              title="Nonaktifkan Penugasan Kaprodi"
                            >
                              <PowerOff className="w-4 h-4" />
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          )}
        </div>
      </div>

      {/* 5. MODAL TAMBAH PENUGASAN */}
      {isAddModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm animate-in fade-in">
          <div className="bg-white rounded-3xl max-w-lg w-full p-6 shadow-2xl border border-slate-100 space-y-5 max-h-[90vh] overflow-y-auto">
            <div className="flex justify-between items-start pb-3 border-b border-slate-100">
              <div>
                <span className="text-[10px] uppercase font-bold text-emerald-700 bg-emerald-50 px-2.5 py-0.5 rounded-full border border-emerald-200">
                  Formulir Penugasan Baru
                </span>
                <h3 className="text-base font-bold text-slate-800 mt-1">Tambah Penugasan Kaprodi</h3>
                <p className="text-xs text-slate-500">
                  Tetapkan dosen yang bertindak sebagai Ketua Program Studi resmi.
                </p>
              </div>
              <button
                onClick={() => setIsAddModalOpen(false)}
                className="p-1 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {formErrors.general && (
              <div className="p-3 bg-red-50 border border-red-200 text-red-700 rounded-xl text-xs flex items-center gap-2">
                <AlertCircle className="w-4 h-4 shrink-0" />
                <span>{formErrors.general[0]}</span>
              </div>
            )}

            <form
              onSubmit={(e) => {
                e.preventDefault();
                submitAdd(false);
              }}
              className="space-y-4"
            >
              {/* Opsi Dosen */}
              <div className="space-y-1">
                <label className="text-xs font-bold text-slate-700 flex items-center gap-1">
                  <span>Dosen Terpilih</span>
                  <span className="text-red-500">*</span>
                </label>
                <select
                  value={formData.dosen_id}
                  onChange={(e) => setFormData({ ...formData, dosen_id: e.target.value })}
                  disabled={optionsLoading || isSubmitting}
                  className="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:outline-none focus:border-emerald-600 transition bg-white"
                  required
                >
                  <option value="">-- Pilih Dosen Pengampu --</option>
                  {dosenOptions.map((dsn) => (
                    <option key={dsn.id_dosen} value={dsn.id_dosen}>
                      {dsn.nama_dosen} (NIDN: {dsn.NIDN})
                    </option>
                  ))}
                </select>
                {formErrors.dosen_id && (
                  <p className="text-[11px] text-red-500 mt-0.5">{formErrors.dosen_id[0]}</p>
                )}
              </div>

              {/* Program Studi */}
              <div className="space-y-1">
                <label className="text-xs font-bold text-slate-700 flex items-center gap-1">
                  <span>Program Studi</span>
                  <span className="text-red-500">*</span>
                </label>
                <select
                  value={formData.prodi}
                  onChange={(e) => setFormData({ ...formData, prodi: e.target.value })}
                  disabled={isSubmitting}
                  className="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:outline-none focus:border-emerald-600 transition bg-white"
                  required
                >
                  <option value="">-- Pilih Program Studi Resmi --</option>
                  {DAFTAR_PRODI.map((prodi) => (
                    <option key={prodi} value={prodi}>
                      {prodi}
                    </option>
                  ))}
                </select>
                {formErrors.prodi && (
                  <p className="text-[11px] text-red-500 mt-0.5">{formErrors.prodi[0]}</p>
                )}
              </div>

              {/* Tanggal Mulai & Tanggal Selesai */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div className="space-y-1">
                  <label className="text-xs font-bold text-slate-700 flex items-center gap-1">
                    <span>Tanggal Mulai</span>
                    <span className="text-red-500">*</span>
                  </label>
                  <input
                    type="date"
                    value={formData.tanggal_mulai}
                    onChange={(e) => setFormData({ ...formData, tanggal_mulai: e.target.value })}
                    disabled={isSubmitting}
                    className="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:outline-none focus:border-emerald-600 transition"
                    required
                  />
                  {formErrors.tanggal_mulai && (
                    <p className="text-[11px] text-red-500 mt-0.5">{formErrors.tanggal_mulai[0]}</p>
                  )}
                </div>

                <div className="space-y-1">
                  <label className="text-xs font-bold text-slate-700">
                    <span>Tanggal Selesai</span>
                    <span className="text-[10px] text-slate-400 font-normal ml-1">(Opsional)</span>
                  </label>
                  <input
                    type="date"
                    value={formData.tanggal_selesai}
                    onChange={(e) => setFormData({ ...formData, tanggal_selesai: e.target.value })}
                    disabled={isSubmitting}
                    className="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:outline-none focus:border-emerald-600 transition"
                  />
                  {formErrors.tanggal_selesai && (
                    <p className="text-[11px] text-red-500 mt-0.5">{formErrors.tanggal_selesai[0]}</p>
                  )}
                </div>
              </div>

              {/* Status Aktif */}
              <div className="pt-2">
                <label className="flex items-center gap-2 cursor-pointer select-none">
                  <input
                    type="checkbox"
                    checked={formData.is_aktif}
                    onChange={(e) => setFormData({ ...formData, is_aktif: e.target.checked })}
                    disabled={isSubmitting}
                    className="w-4 h-4 rounded text-emerald-600 focus:ring-emerald-500 border-slate-300"
                  />
                  <span className="text-xs font-bold text-slate-700">Tetapkan Sebagai Penugasan Aktif</span>
                </label>
                <p className="text-[11px] text-slate-400 mt-0.5 pl-6">
                  Jika dicentang, dosen terpilih akan langsung memegang hak akses Kaprodi untuk prodi tersebut.
                </p>
              </div>

              {/* Tombol Aksi */}
              <div className="flex items-center justify-end gap-2 pt-4 border-t border-slate-100">
                <button
                  type="button"
                  onClick={() => setIsAddModalOpen(false)}
                  disabled={isSubmitting}
                  className="px-4 py-2 rounded-xl border border-slate-200 text-xs font-semibold text-slate-600 hover:bg-slate-50 transition"
                >
                  Batal
                </button>
                <button
                  type="submit"
                  disabled={isSubmitting}
                  className="px-4 py-2 bg-[#15803D] hover:bg-emerald-800 text-white rounded-xl text-xs font-bold shadow transition flex items-center gap-1.5 disabled:opacity-50"
                >
                  {isSubmitting && <RefreshCw className="w-3.5 h-3.5 animate-spin" />}
                  <span>Simpan Penugasan</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* 6. MODAL EDIT PENUGASAN */}
      {editItem && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm animate-in fade-in">
          <div className="bg-white rounded-3xl max-w-lg w-full p-6 shadow-2xl border border-slate-100 space-y-5 max-h-[90vh] overflow-y-auto">
            <div className="flex justify-between items-start pb-3 border-b border-slate-100">
              <div>
                <span className="text-[10px] uppercase font-bold text-emerald-700 bg-emerald-50 px-2.5 py-0.5 rounded-full border border-emerald-200">
                  Pembaruan Data
                </span>
                <h3 className="text-base font-bold text-slate-800 mt-1">Edit Penugasan Kaprodi</h3>
                <p className="text-xs text-slate-500">
                  Ubah data masa jabatan atau program studi penugasan #{editItem.id}.
                </p>
              </div>
              <button
                onClick={() => setEditItem(null)}
                className="p-1 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {formErrors.general && (
              <div className="p-3 bg-red-50 border border-red-200 text-red-700 rounded-xl text-xs flex items-center gap-2">
                <AlertCircle className="w-4 h-4 shrink-0" />
                <span>{formErrors.general[0]}</span>
              </div>
            )}

            {formData.prodi !== editItem.prodi && (
              <div className="p-3 bg-amber-50 border border-amber-200 text-amber-800 rounded-xl text-xs flex items-start gap-2">
                <AlertTriangle className="w-4 h-4 text-amber-600 shrink-0 mt-0.5" />
                <span>
                  <strong>Perhatian:</strong> Mengubah program studi dari <em>{editItem.prodi}</em> ke <em>{formData.prodi}</em> akan memindahkan wewenang persetujuan Kaprodi ke program studi yang baru.
                </span>
              </div>
            )}

            <form
              onSubmit={(e) => {
                e.preventDefault();
                submitEdit(false);
              }}
              className="space-y-4"
            >
              {/* Opsi Dosen */}
              <div className="space-y-1">
                <label className="text-xs font-bold text-slate-700 flex items-center gap-1">
                  <span>Dosen Pengampu</span>
                  <span className="text-red-500">*</span>
                </label>
                <select
                  value={formData.dosen_id}
                  onChange={(e) => setFormData({ ...formData, dosen_id: e.target.value })}
                  disabled={optionsLoading || isSubmitting}
                  className="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:outline-none focus:border-emerald-600 transition bg-white"
                  required
                >
                  {dosenOptions.map((dsn) => (
                    <option key={dsn.id_dosen} value={dsn.id_dosen}>
                      {dsn.nama_dosen} (NIDN: {dsn.NIDN})
                    </option>
                  ))}
                </select>
                {formErrors.dosen_id && (
                  <p className="text-[11px] text-red-500 mt-0.5">{formErrors.dosen_id[0]}</p>
                )}
              </div>

              {/* Program Studi */}
              <div className="space-y-1">
                <label className="text-xs font-bold text-slate-700 flex items-center gap-1">
                  <span>Program Studi</span>
                  <span className="text-red-500">*</span>
                </label>
                <select
                  value={formData.prodi}
                  onChange={(e) => setFormData({ ...formData, prodi: e.target.value })}
                  disabled={isSubmitting}
                  className="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:outline-none focus:border-emerald-600 transition bg-white"
                  required
                >
                  {DAFTAR_PRODI.map((prodi) => (
                    <option key={prodi} value={prodi}>
                      {prodi}
                    </option>
                  ))}
                </select>
                {formErrors.prodi && (
                  <p className="text-[11px] text-red-500 mt-0.5">{formErrors.prodi[0]}</p>
                )}
              </div>

              {/* Tanggal Mulai & Tanggal Selesai */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div className="space-y-1">
                  <label className="text-xs font-bold text-slate-700 flex items-center gap-1">
                    <span>Tanggal Mulai</span>
                    <span className="text-red-500">*</span>
                  </label>
                  <input
                    type="date"
                    value={formData.tanggal_mulai}
                    onChange={(e) => setFormData({ ...formData, tanggal_mulai: e.target.value })}
                    disabled={isSubmitting}
                    className="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:outline-none focus:border-emerald-600 transition"
                    required
                  />
                  {formErrors.tanggal_mulai && (
                    <p className="text-[11px] text-red-500 mt-0.5">{formErrors.tanggal_mulai[0]}</p>
                  )}
                </div>

                <div className="space-y-1">
                  <label className="text-xs font-bold text-slate-700">
                    <span>Tanggal Selesai</span>
                    <span className="text-[10px] text-slate-400 font-normal ml-1">(Opsional)</span>
                  </label>
                  <input
                    type="date"
                    value={formData.tanggal_selesai}
                    onChange={(e) => setFormData({ ...formData, tanggal_selesai: e.target.value })}
                    disabled={isSubmitting}
                    className="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs focus:outline-none focus:border-emerald-600 transition"
                  />
                  {formErrors.tanggal_selesai && (
                    <p className="text-[11px] text-red-500 mt-0.5">{formErrors.tanggal_selesai[0]}</p>
                  )}
                </div>
              </div>

              {/* Status Aktif */}
              <div className="pt-2">
                <label className="flex items-center gap-2 cursor-pointer select-none">
                  <input
                    type="checkbox"
                    checked={formData.is_aktif}
                    onChange={(e) => setFormData({ ...formData, is_aktif: e.target.checked })}
                    disabled={isSubmitting}
                    className="w-4 h-4 rounded text-emerald-600 focus:ring-emerald-500 border-slate-300"
                  />
                  <span className="text-xs font-bold text-slate-700">Status Aktif Penugasan</span>
                </label>
                <p className="text-[11px] text-slate-400 mt-0.5 pl-6">
                  Jika dinonaktifkan, dosen ini tidak lagi dapat menandatangani atau menyetujui judul mahasiswa prodi ini.
                </p>
              </div>

              {/* Tombol Aksi */}
              <div className="flex items-center justify-end gap-2 pt-4 border-t border-slate-100">
                <button
                  type="button"
                  onClick={() => setEditItem(null)}
                  disabled={isSubmitting}
                  className="px-4 py-2 rounded-xl border border-slate-200 text-xs font-semibold text-slate-600 hover:bg-slate-50 transition"
                >
                  Batal
                </button>
                <button
                  type="submit"
                  disabled={isSubmitting}
                  className="px-4 py-2 bg-[#15803D] hover:bg-emerald-800 text-white rounded-xl text-xs font-bold shadow transition flex items-center gap-1.5 disabled:opacity-50"
                >
                  {isSubmitting && <RefreshCw className="w-3.5 h-3.5 animate-spin" />}
                  <span>Simpan Perubahan</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* 7. MODAL DETAIL PENUGASAN */}
      {detailItem && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm animate-in fade-in">
          <div className="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl border border-slate-100 space-y-5">
            <div className="flex justify-between items-start pb-3 border-b border-slate-100">
              <div className="flex items-center gap-2.5">
                <div className="w-9 h-9 rounded-xl bg-emerald-50 text-emerald-700 flex items-center justify-center border border-emerald-200">
                  <Award className="w-5 h-5" />
                </div>
                <div>
                  <h3 className="text-sm font-bold text-slate-800">Detail Penugasan Kaprodi</h3>
                  <p className="text-[11px] text-slate-400">ID Penugasan: #{detailItem.id}</p>
                </div>
              </div>
              <button
                onClick={() => setDetailItem(null)}
                className="p-1 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="space-y-3 text-xs">
              <div className="p-3 bg-slate-50 rounded-2xl border border-slate-100 space-y-1">
                <span className="text-[10px] uppercase font-bold text-slate-400">Dosen Pengampu</span>
                <p className="font-bold text-slate-800 text-sm">
                  {detailItem.dosen?.nama_dosen || `Dosen ID: ${detailItem.dosen_id}`}
                </p>
                <p className="text-slate-500 text-[11px]">NIDN: {detailItem.dosen?.NIDN || "-"}</p>
                {detailItem.dosen?.user?.email && (
                  <p className="text-slate-500 text-[11px]">Akun: {detailItem.dosen.user.email}</p>
                )}
              </div>

              <div className="grid grid-cols-2 gap-2.5">
                <div className="p-3 bg-slate-50 rounded-2xl border border-slate-100">
                  <span className="text-[10px] uppercase font-bold text-slate-400">Program Studi</span>
                  <p className="font-bold text-slate-800 mt-0.5">{detailItem.prodi}</p>
                </div>
                <div className="p-3 bg-slate-50 rounded-2xl border border-slate-100">
                  <span className="text-[10px] uppercase font-bold text-slate-400">Status Operasional</span>
                  <p className="font-bold text-emerald-700 mt-0.5">{detailItem.status_operasional || "-"}</p>
                </div>
              </div>

              <div className="grid grid-cols-2 gap-2.5">
                <div className="p-3 bg-slate-50 rounded-2xl border border-slate-100">
                  <span className="text-[10px] uppercase font-bold text-slate-400">Tanggal Mulai</span>
                  <p className="font-semibold text-slate-800 mt-0.5">
                    {detailItem.tanggal_mulai ? detailItem.tanggal_mulai.split("T")[0] : "-"}
                  </p>
                </div>
                <div className="p-3 bg-slate-50 rounded-2xl border border-slate-100">
                  <span className="text-[10px] uppercase font-bold text-slate-400">Tanggal Selesai</span>
                  <p className="font-semibold text-slate-800 mt-0.5">
                    {detailItem.tanggal_selesai ? detailItem.tanggal_selesai.split("T")[0] : "Masih Berlaku"}
                  </p>
                </div>
              </div>

              <div className="p-3 bg-slate-50 rounded-2xl border border-slate-100 flex items-center justify-between">
                <div>
                  <span className="text-[10px] uppercase font-bold text-slate-400">Status Flag Database</span>
                  <p className="text-[11px] text-slate-500">Nilai kolom `is_aktif`</p>
                </div>
                {detailItem.is_aktif ? (
                  <span className="px-2.5 py-0.5 rounded-full font-bold bg-emerald-100 text-emerald-800 text-[11px]">
                    Aktif
                  </span>
                ) : (
                  <span className="px-2.5 py-0.5 rounded-full font-bold bg-slate-200 text-slate-600 text-[11px]">
                    Nonaktif
                  </span>
                )}
              </div>
            </div>

            <div className="pt-2 flex justify-end">
              <button
                onClick={() => setDetailItem(null)}
                className="w-full py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-xl text-xs transition"
              >
                Tutup
              </button>
            </div>
          </div>
        </div>
      )}

      {/* 8. MODAL KONFIRMASI NONAKTIFKAN PENUGASAN */}
      {deactivateItem && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm animate-in fade-in">
          <div className="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl border border-slate-100 space-y-4">
            <div className="w-12 h-12 bg-red-50 text-red-600 rounded-2xl flex items-center justify-center mx-auto shadow-inner">
              <PowerOff className="w-6 h-6" />
            </div>

            <div className="text-center space-y-1">
              <h3 className="text-base font-bold text-slate-800">Nonaktifkan Penugasan Kaprodi?</h3>
              <p className="text-xs text-slate-500 leading-relaxed">
                Tindakan ini akan mengakhiri masa aktif Kaprodi hari ini dan mencabut hak akses dosen terkait ke antrean pengesahan judul skripsi program studi ini.
              </p>
            </div>

            <div className="p-3 bg-slate-50 rounded-2xl border border-slate-100 text-xs space-y-1">
              <div className="flex justify-between">
                <span className="text-slate-400">Nama Dosen:</span>
                <span className="font-bold text-slate-700 text-right">
                  {deactivateItem.dosen?.nama_dosen || `Dosen ID ${deactivateItem.dosen_id}`}
                </span>
              </div>
              <div className="flex justify-between">
                <span className="text-slate-400">Program Studi:</span>
                <span className="font-bold text-slate-700">{deactivateItem.prodi}</span>
              </div>
            </div>

            <div className="flex items-center gap-2 pt-2">
              <button
                onClick={() => setDeactivateItem(null)}
                disabled={isDeactivating}
                className="flex-1 py-2 rounded-xl border border-slate-200 text-xs font-semibold text-slate-600 hover:bg-slate-50 transition"
              >
                Batal
              </button>
              <button
                onClick={handleNonaktifkan}
                disabled={isDeactivating}
                className="flex-1 py-2 bg-red-600 hover:bg-red-700 text-white rounded-xl text-xs font-bold shadow transition flex items-center justify-center gap-1.5 disabled:opacity-50"
              >
                {isDeactivating && <RefreshCw className="w-3.5 h-3.5 animate-spin" />}
                <span>Ya, Nonaktifkan</span>
              </button>
            </div>
          </div>
        </div>
      )}

      {/* 9. MODAL KONFIRMASI KONFLIK 409 (PENGGANTIAN KAPRODI AKTIF) */}
      {conflictModal?.isOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm animate-in fade-in">
          <div className="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl border border-amber-100 space-y-4">
            <div className="w-12 h-12 bg-amber-50 text-amber-600 rounded-2xl flex items-center justify-center mx-auto shadow-inner">
              <AlertTriangle className="w-6 h-6" />
            </div>

            <div className="text-center space-y-1">
              <span className="text-[10px] uppercase font-bold text-amber-700 bg-amber-50 px-2.5 py-0.5 rounded-full border border-amber-200">
                Konflik Kaprodi Aktif
              </span>
              <h3 className="text-base font-bold text-slate-800 mt-1">Konfirmasi Penggantian Kaprodi</h3>
              <p className="text-xs text-slate-600 leading-relaxed">{conflictModal.message}</p>
            </div>

            <div className="p-3 bg-amber-50/60 rounded-2xl border border-amber-200 text-xs text-amber-900 space-y-1.5">
              <p className="font-semibold flex items-center gap-1.5">
                <Check className="w-4 h-4 text-emerald-600 shrink-0" />
                <span>Opsi Penggantian Otomatis:</span>
              </p>
              <p className="text-[11px] leading-relaxed text-amber-800">
                Jika Anda menyetujui pergantian, sistem akan menonaktifkan Kaprodi yang sedang bertugas hari ini dan secara aman mengalihkan wewenang ke penugasan baru ini tanpa menghapus riwayat sebelumnya.
              </p>
            </div>

            <div className="flex items-center gap-2 pt-2">
              <button
                onClick={() => setConflictModal(null)}
                disabled={isSubmitting}
                className="flex-1 py-2 rounded-xl border border-slate-200 text-xs font-semibold text-slate-600 hover:bg-slate-50 transition"
              >
                Batal
              </button>
              <button
                onClick={() => {
                  if (conflictModal.mode === "add") {
                    submitAdd(true);
                  } else {
                    submitEdit(true);
                  }
                }}
                disabled={isSubmitting}
                className="flex-1 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-xl text-xs font-bold shadow transition flex items-center justify-center gap-1.5 disabled:opacity-50"
              >
                {isSubmitting && <RefreshCw className="w-3.5 h-3.5 animate-spin" />}
                <span>Gantikan & Lanjutkan</span>
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
