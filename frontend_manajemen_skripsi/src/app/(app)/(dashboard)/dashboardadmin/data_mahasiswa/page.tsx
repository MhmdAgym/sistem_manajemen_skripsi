"use client";

import React, { useState, useEffect, useMemo } from "react";
import axios from "@/lib/axios";
import { 
  Users, 
  Search, 
  RefreshCw, 
  Eye, 
  Edit3, 
  X, 
  CheckCircle2, 
  AlertCircle, 
  GraduationCap, 
  UserCheck, 
  Phone, 
  BookOpen, 
  Filter
} from "lucide-react";

interface UserProfile {
  id: number;
  name: string;
  email: string;
  role: string;
  nomor_induk: string | null;
  email_verified_at: string | null;
}

interface ActiveSkripsi {
  id_skripsi: number;
  judul: string;
  status_judul: string;
}

interface MahasiswaData {
  id_mahasiswa: number;
  NIM: string;
  user_id: number;
  nama_mahasiswa: string;
  prodi: string;
  fakultas: string | null;
  no_handphone: string | null;
  jenis_kelamin: string | null;
  active_skripsi: ActiveSkripsi | null;
  dosen_pa_list: string[];
  dosen_pembimbing_list: string[];
  user: UserProfile | null;
}

export default function DataMahasiswaPage() {
  const [mahasiswaList, setMahasiswaList] = useState<MahasiswaData[]>([]);
  const [loading, setLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  // Search & Filter
  const [searchQuery, setSearchQuery] = useState("");
  const [filterProdi, setFilterProdi] = useState("all");

  // Modal Detail
  const [detailModalData, setDetailModalData] = useState<MahasiswaData | null>(null);

  // Modal Edit
  const [editModalData, setEditModalData] = useState<MahasiswaData | null>(null);
  const [editForm, setEditForm] = useState({
    nama_mahasiswa: "",
    NIM: "",
    fakultas: "",
    prodi: "",
    no_handphone: "",
    jenis_kelamin: "",
  });
  const [editErrors, setEditErrors] = useState<Record<string, string[]>>({});
  const [isSaving, setIsSaving] = useState(false);
  const [confirmOpen, setConfirmOpen] = useState(false);

  // Fetch data
  const fetchMahasiswa = async () => {
    try {
      setLoading(true);
      setErrorMessage(null);
      const res = await axios.get("/api/mahasiswa");
      setMahasiswaList(Array.isArray(res.data) ? res.data : []);
    } catch (err: any) {
      console.error("Gagal mengambil data mahasiswa:", err);
      setErrorMessage(
        err.response?.data?.message || "Gagal memuat data mahasiswa dari server. Pastikan server aktif."
      );
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchMahasiswa();
  }, []);

  // Filter list
  const filteredList = useMemo(() => {
    return mahasiswaList.filter((m) => {
      const matchSearch =
        m.nama_mahasiswa?.toLowerCase().includes(searchQuery.toLowerCase()) ||
        m.NIM?.toLowerCase().includes(searchQuery.toLowerCase()) ||
        m.prodi?.toLowerCase().includes(searchQuery.toLowerCase()) ||
        (m.fakultas && m.fakultas.toLowerCase().includes(searchQuery.toLowerCase()));

      const matchProdi = filterProdi === "all" || m.prodi === filterProdi;

      return matchSearch && matchProdi;
    });
  }, [mahasiswaList, searchQuery, filterProdi]);

  // Unique prodi list for filter dropdown
  const uniqueProdiList = useMemo(() => {
    const setProdi = new Set<string>();
    mahasiswaList.forEach((m) => {
      if (m.prodi) setProdi.add(m.prodi);
    });
    return Array.from(setProdi);
  }, [mahasiswaList]);

  // Handle open edit modal
  const handleOpenEdit = (m: MahasiswaData) => {
    setEditModalData(m);
    setEditForm({
      nama_mahasiswa: m.nama_mahasiswa || "",
      NIM: m.NIM || "",
      fakultas: m.fakultas || "",
      prodi: m.prodi || "",
      no_handphone: m.no_handphone || "",
      jenis_kelamin: m.jenis_kelamin || "",
    });
    setEditErrors({});
    setConfirmOpen(false);
  };

  // Submit edit
  const handleSaveEdit = async () => {
    if (!editModalData) return;
    setIsSaving(true);
    setEditErrors({});

    try {
      const res = await axios.put(`/api/mahasiswa/${editModalData.id_mahasiswa}`, editForm);
      const updatedData: MahasiswaData = res.data.data;

      // Update state immediately
      setMahasiswaList((prev) =>
        prev.map((item) => (item.id_mahasiswa === updatedData.id_mahasiswa ? updatedData : item))
      );

      setSuccessMessage(`Data mahasiswa ${updatedData.nama_mahasiswa} berhasil diperbarui.`);
      setEditModalData(null);
      setConfirmOpen(false);

      setTimeout(() => {
        setSuccessMessage(null);
      }, 4000);
    } catch (err: any) {
      if (err.response?.status === 422 && err.response?.data?.errors) {
        setEditErrors(err.response.data.errors);
      } else {
        setEditErrors({
          general: [err.response?.data?.message || "Terjadi kesalahan saat menyimpan data."],
        });
      }
      setConfirmOpen(false);
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <div className="space-y-6 font-sans text-slate-800">
      {/* BANNER HEADER */}
      <div className="p-6 bg-gradient-to-r from-emerald-700 via-emerald-600 to-teal-700 rounded-3xl text-white shadow-lg flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <div>
          <span className="px-3 py-1 bg-white/20 backdrop-blur-md rounded-full text-[11px] font-semibold uppercase tracking-wider text-emerald-50 border border-white/10">
            Database Akademik
          </span>
          <h1 className="text-2xl font-bold tracking-tight mt-1">Manajemen Data Mahasiswa</h1>
          <p className="text-xs text-emerald-100 mt-1">
            Pantau dan kelola data mahasiswa, pembimbing akademik (PA), serta pembimbing skripsi secara terintegrasi.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <button 
            onClick={fetchMahasiswa}
            disabled={loading}
            className="px-4 py-2 bg-white text-[#15803D] font-bold text-xs rounded-xl shadow hover:bg-emerald-50 transition flex items-center gap-1.5 disabled:opacity-75"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${loading ? "animate-spin" : ""}`} />
            Muat Ulang
          </button>
        </div>
      </div>

      {/* ALERT SUCCESS */}
      {successMessage && (
        <div className="p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-2xl flex items-center gap-3 text-xs shadow-sm">
          <CheckCircle2 className="w-5 h-5 text-emerald-600 shrink-0" />
          <span className="font-semibold">{successMessage}</span>
        </div>
      )}

      {/* ALERT ERROR */}
      {errorMessage && (
        <div className="p-4 bg-red-50 border border-red-200 text-red-800 rounded-2xl flex items-center gap-3 text-xs shadow-sm">
          <AlertCircle className="w-5 h-5 text-red-600 shrink-0" />
          <span>{errorMessage}</span>
        </div>
      )}

      {/* KARTU TABEL DATA */}
      <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm space-y-5">
        <div className="flex flex-col md:flex-row justify-between items-stretch md:items-center gap-4">
          <div className="flex items-center gap-2">
            <Users className="w-5 h-5 text-emerald-600" />
            <h2 className="font-bold text-slate-800 text-sm">
              Daftar Mahasiswa ({filteredList.length} dari {mahasiswaList.length})
            </h2>
          </div>

          <div className="flex flex-wrap items-center gap-3">
            {/* Filter Prodi */}
            <div className="relative">
              <select
                value={filterProdi}
                onChange={(e) => setFilterProdi(e.target.value)}
                className="pl-8 pr-4 py-2 rounded-xl border border-slate-200 text-xs bg-slate-50 focus:bg-white focus:outline-none focus:border-emerald-600 text-slate-700"
              >
                <option value="all">Semua Program Studi</option>
                {uniqueProdiList.map((p) => (
                  <option key={p} value={p}>{p}</option>
                ))}
              </select>
              <Filter className="absolute left-2.5 top-2.5 w-3.5 h-3.5 text-slate-400 pointer-events-none" />
            </div>

            {/* Input Pencarian */}
            <div className="relative w-full sm:w-64">
              <Search className="absolute left-3 top-2.5 w-4 h-4 text-slate-400" />
              <input 
                type="text" 
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                placeholder="Cari nama, NIM, prodi..." 
                className="w-full pl-9 pr-4 py-2 rounded-xl border border-slate-200 text-xs focus:outline-none focus:border-emerald-600 transition"
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
          </div>
        </div>

        {/* TABEL DATA */}
        <div className="overflow-x-auto">
          {loading ? (
            <div className="py-16 text-center text-slate-400 space-y-3">
              <RefreshCw className="w-8 h-8 animate-spin mx-auto text-emerald-600" />
              <p className="text-xs">Memuat data mahasiswa...</p>
            </div>
          ) : filteredList.length === 0 ? (
            <div className="py-16 text-center text-slate-400 space-y-2">
              <Users className="w-10 h-10 mx-auto text-slate-300" />
              <p className="text-sm font-semibold text-slate-600">Tidak ada data mahasiswa ditemukan</p>
              <p className="text-xs text-slate-400">
                {searchQuery || filterProdi !== "all" 
                  ? "Coba gunakan kata kunci pencarian atau filter yang berbeda."
                  : "Belum ada data mahasiswa terdaftar di sistem."}
              </p>
            </div>
          ) : (
            <table className="w-full text-left text-xs">
              <thead className="bg-slate-50 text-slate-600 uppercase font-semibold">
                <tr>
                  <th className="p-3.5 rounded-l-xl">Mahasiswa</th>
                  <th className="p-3.5">NIM</th>
                  <th className="p-3.5">Fakultas / Prodi</th>
                  <th className="p-3.5">Dosen PA</th>
                  <th className="p-3.5">Dosen Pembimbing</th>
                  <th className="p-3.5">Status Skripsi</th>
                  <th className="p-3.5 rounded-r-xl text-center">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {filteredList.map((m) => (
                  <tr key={m.id_mahasiswa} className="hover:bg-slate-50/75 transition">
                    <td className="p-3.5">
                      <div className="font-bold text-slate-800">{m.nama_mahasiswa}</div>
                      <div className="text-[11px] text-slate-400">{m.user?.email || "-"}</div>
                    </td>
                    <td className="p-3.5 font-medium text-slate-700">{m.NIM}</td>
                    <td className="p-3.5">
                      <div className="font-medium text-slate-800">{m.prodi}</div>
                      <div className="text-[11px] text-slate-500">{m.fakultas || "Fakultas belum diatur"}</div>
                    </td>
                    <td className="p-3.5">
                      {m.dosen_pa_list && m.dosen_pa_list.length > 0 ? (
                        <div className="space-y-1">
                          {m.dosen_pa_list.map((pa, idx) => (
                            <span 
                              key={idx} 
                              className="inline-block px-2 py-0.5 rounded-lg text-[11px] font-medium bg-emerald-50 text-emerald-800 border border-emerald-200"
                            >
                              {pa}
                            </span>
                          ))}
                        </div>
                      ) : (
                        <span className="text-[11px] text-slate-400 italic">Belum ditentukan</span>
                      )}
                    </td>
                    <td className="p-3.5">
                      {m.dosen_pembimbing_list && m.dosen_pembimbing_list.length > 0 ? (
                        <div className="space-y-1">
                          {m.dosen_pembimbing_list.map((dp, idx) => (
                            <span 
                              key={idx} 
                              className="inline-block px-2 py-0.5 rounded-lg text-[11px] font-medium bg-teal-50 text-teal-800 border border-teal-200 mr-1"
                            >
                              {dp}
                            </span>
                          ))}
                        </div>
                      ) : (
                        <span className="text-[11px] text-slate-400 italic">Belum ditentukan</span>
                      )}
                    </td>
                    <td className="p-3.5">
                      {m.active_skripsi ? (
                        <span className="px-2.5 py-1 rounded-full text-[10px] font-bold bg-emerald-100 text-[#15803D] border border-emerald-200 uppercase tracking-wide">
                          {m.active_skripsi.status_judul || "Aktif"}
                        </span>
                      ) : (
                        <span className="px-2.5 py-1 rounded-full text-[10px] font-semibold bg-slate-100 text-slate-500 border border-slate-200">
                          Belum Ada Skripsi
                        </span>
                      )}
                    </td>
                    <td className="p-3.5 text-center">
                      <div className="flex items-center justify-center gap-1.5">
                        <button 
                          onClick={() => setDetailModalData(m)}
                          className="p-1.5 text-slate-600 hover:text-emerald-700 hover:bg-emerald-50 rounded-lg transition"
                          title="Lihat Detail Mahasiswa"
                        >
                          <Eye className="w-4 h-4" />
                        </button>
                        <button 
                          onClick={() => handleOpenEdit(m)}
                          className="p-1.5 text-slate-600 hover:text-[#15803D] hover:bg-emerald-50 rounded-lg transition"
                          title="Edit Data Mahasiswa"
                        >
                          <Edit3 className="w-4 h-4" />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </div>

      {/* MODAL DETAIL */}
      {detailModalData && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm animate-in fade-in">
          <div className="bg-white rounded-3xl max-w-2xl w-full p-6 shadow-2xl border border-slate-100 space-y-6 max-h-[90vh] overflow-y-auto">
            <div className="flex justify-between items-start pb-4 border-b border-slate-100">
              <div className="flex items-center gap-3">
                <div className="w-12 h-12 rounded-2xl bg-emerald-100 text-[#15803D] flex items-center justify-center font-bold text-lg">
                  {detailModalData.nama_mahasiswa?.charAt(0) || "M"}
                </div>
                <div>
                  <h3 className="text-lg font-bold text-slate-800">{detailModalData.nama_mahasiswa}</h3>
                  <p className="text-xs text-slate-500">NIM: {detailModalData.NIM} • {detailModalData.prodi}</p>
                </div>
              </div>
              <button 
                onClick={() => setDetailModalData(null)}
                className="p-1.5 rounded-xl text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="p-4 bg-slate-50 rounded-2xl space-y-2 border border-slate-100">
                <h4 className="text-xs font-bold text-slate-700 flex items-center gap-1.5">
                  <GraduationCap className="w-4 h-4 text-emerald-600" /> Informasi Akademik
                </h4>
                <div className="text-xs space-y-1.5 text-slate-600 pt-1">
                  <div><span className="text-slate-400">Fakultas:</span> <span className="font-semibold text-slate-800">{detailModalData.fakultas || "-"}</span></div>
                  <div><span className="text-slate-400">Program Studi:</span> <span className="font-semibold text-slate-800">{detailModalData.prodi || "-"}</span></div>
                  <div><span className="text-slate-400">Jenis Kelamin:</span> <span className="font-semibold text-slate-800">{detailModalData.jenis_kelamin || "-"}</span></div>
                </div>
              </div>

              <div className="p-4 bg-slate-50 rounded-2xl space-y-2 border border-slate-100">
                <h4 className="text-xs font-bold text-slate-700 flex items-center gap-1.5">
                  <Phone className="w-4 h-4 text-emerald-600" /> Kontak & Akun
                </h4>
                <div className="text-xs space-y-1.5 text-slate-600 pt-1">
                  <div><span className="text-slate-400">Email:</span> <span className="font-semibold text-slate-800">{detailModalData.user?.email || "-"}</span></div>
                  <div><span className="text-slate-400">No. Handphone:</span> <span className="font-semibold text-slate-800">{detailModalData.no_handphone || "-"}</span></div>
                  <div>
                    <span className="text-slate-400">Status Verifikasi:</span>{" "}
                    {detailModalData.user?.email_verified_at ? (
                      <span className="text-emerald-700 font-bold">Terverifikasi</span>
                    ) : (
                      <span className="text-amber-600 font-bold">Belum Verifikasi</span>
                    )}
                  </div>
                </div>
              </div>
            </div>

            {/* INFORMASI SKRIPSI & DOSEN */}
            <div className="p-4 bg-emerald-50/50 rounded-2xl border border-emerald-100 space-y-3">
              <h4 className="text-xs font-bold text-[#15803D] flex items-center gap-1.5">
                <BookOpen className="w-4 h-4 text-[#15803D]" /> Usulan & Pembimbing Skripsi
              </h4>

              {detailModalData.active_skripsi ? (
                <div className="space-y-2">
                  <div>
                    <p className="text-[11px] text-slate-500">Judul Skripsi Aktif:</p>
                    <p className="text-xs font-bold text-slate-800 mt-0.5">{detailModalData.active_skripsi.judul}</p>
                    <div className="mt-1">
                      <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-[#15803D] uppercase">
                        Status: {detailModalData.active_skripsi.status_judul}
                      </span>
                    </div>
                  </div>
                </div>
              ) : (
                <p className="text-xs text-slate-500 italic">Mahasiswa belum memiliki catatan judul skripsi di database.</p>
              )}

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2">
                <div>
                  <p className="text-[11px] font-semibold text-slate-500 flex items-center gap-1">
                    <UserCheck className="w-3.5 h-3.5 text-emerald-600" /> Dosen Pembimbing Akademik (PA):
                  </p>
                  <div className="mt-1 space-y-1">
                    {detailModalData.dosen_pa_list && detailModalData.dosen_pa_list.length > 0 ? (
                      detailModalData.dosen_pa_list.map((pa, i) => (
                        <div key={i} className="text-xs font-semibold text-slate-800 bg-white p-2 rounded-xl border border-slate-200">
                          {pa}
                        </div>
                      ))
                    ) : (
                      <p className="text-xs text-slate-400 italic">Belum ditetapkan</p>
                    )}
                  </div>
                </div>

                <div>
                  <p className="text-[11px] font-semibold text-slate-500 flex items-center gap-1">
                    <UserCheck className="w-3.5 h-3.5 text-teal-600" /> Dosen Pembimbing Skripsi:
                  </p>
                  <div className="mt-1 space-y-1">
                    {detailModalData.dosen_pembimbing_list && detailModalData.dosen_pembimbing_list.length > 0 ? (
                      detailModalData.dosen_pembimbing_list.map((dp, i) => (
                        <div key={i} className="text-xs font-semibold text-slate-800 bg-white p-2 rounded-xl border border-slate-200">
                          {dp}
                        </div>
                      ))
                    ) : (
                      <p className="text-xs text-slate-400 italic">Belum ditetapkan</p>
                    )}
                  </div>
                </div>
              </div>
            </div>

            <div className="flex justify-end gap-2 pt-2">
              <button
                onClick={() => {
                  const m = detailModalData;
                  setDetailModalData(null);
                  handleOpenEdit(m);
                }}
                className="px-4 py-2 bg-[#15803D] text-white text-xs font-bold rounded-xl shadow hover:bg-emerald-700 transition flex items-center gap-1.5"
              >
                <Edit3 className="w-3.5 h-3.5" /> Edit Data Ini
              </button>
              <button
                onClick={() => setDetailModalData(null)}
                className="px-4 py-2 bg-slate-100 text-slate-700 text-xs font-semibold rounded-xl hover:bg-slate-200 transition"
              >
                Tutup
              </button>
            </div>
          </div>
        </div>
      )}

      {/* MODAL EDIT */}
      {editModalData && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm animate-in fade-in">
          <div className="bg-white rounded-3xl max-w-xl w-full p-6 shadow-2xl border border-slate-100 space-y-5 max-h-[90vh] overflow-y-auto">
            <div className="flex justify-between items-center pb-3 border-b border-slate-100">
              <h3 className="text-base font-bold text-slate-800 flex items-center gap-2">
                <Edit3 className="w-4 h-4 text-emerald-600" /> Edit Data Mahasiswa
              </h3>
              <button 
                onClick={() => setEditModalData(null)}
                className="p-1.5 rounded-xl text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {/* Error box */}
            {Object.keys(editErrors).length > 0 && (
              <div className="p-3 bg-red-50 border border-red-200 text-red-700 rounded-2xl text-xs space-y-1">
                <div className="font-bold flex items-center gap-1">
                  <AlertCircle className="w-4 h-4" /> Periksa input berikut:
                </div>
                <ul className="list-disc list-inside pl-1 space-y-0.5">
                  {Object.entries(editErrors).map(([field, errs]) => (
                    <li key={field}>{errs.join(", ")}</li>
                  ))}
                </ul>
              </div>
            )}

            <div className="space-y-4 text-xs">
              <div>
                <label className="font-semibold text-slate-700 block mb-1">Nama Mahasiswa *</label>
                <input 
                  type="text"
                  value={editForm.nama_mahasiswa}
                  onChange={(e) => setEditForm({ ...editForm, nama_mahasiswa: e.target.value })}
                  className="w-full px-3.5 py-2 rounded-xl border border-slate-200 focus:outline-none focus:border-emerald-600 font-medium text-slate-800"
                  required
                />
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label className="font-semibold text-slate-700 block mb-1">NIM (Nomor Induk Mahasiswa) *</label>
                  <input 
                    type="text"
                    maxLength={10}
                    value={editForm.NIM}
                    onChange={(e) => setEditForm({ ...editForm, NIM: e.target.value })}
                    className="w-full px-3.5 py-2 rounded-xl border border-slate-200 focus:outline-none focus:border-emerald-600 font-medium text-slate-800"
                    required
                  />
                  <span className="text-[10px] text-slate-400 mt-0.5 block">Maksimal 10 karakter numerik.</span>
                </div>

                <div>
                  <label className="font-semibold text-slate-700 block mb-1">Jenis Kelamin</label>
                  <select
                    value={editForm.jenis_kelamin}
                    onChange={(e) => setEditForm({ ...editForm, jenis_kelamin: e.target.value })}
                    className="w-full px-3.5 py-2 rounded-xl border border-slate-200 focus:outline-none focus:border-emerald-600 text-slate-800"
                  >
                    <option value="">-- Pilih Jenis Kelamin --</option>
                    <option value="Laki-laki">Laki-laki</option>
                    <option value="Perempuan">Perempuan</option>
                  </select>
                </div>
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label className="font-semibold text-slate-700 block mb-1">Fakultas</label>
                  <select
                    value={editForm.fakultas}
                    onChange={(e) => setEditForm({ ...editForm, fakultas: e.target.value })}
                    className="w-full px-3.5 py-2 rounded-xl border border-slate-200 focus:outline-none focus:border-emerald-600 text-slate-800"
                  >
                    <option value="">-- Pilih Fakultas --</option>
                    <option value="Fakultas Sains dan Teknologi">Fakultas Sains dan Teknologi</option>
                    <option value="Fakultas Ilmu Pendidikan">Fakultas Ilmu Pendidikan</option>
                    <option value="Fakultas Agama Islam">Fakultas Agama Islam</option>
                  </select>
                </div>

                <div>
                  <label className="font-semibold text-slate-700 block mb-1">Program Studi *</label>
                  <input 
                    type="text"
                    value={editForm.prodi}
                    onChange={(e) => setEditForm({ ...editForm, prodi: e.target.value })}
                    className="w-full px-3.5 py-2 rounded-xl border border-slate-200 focus:outline-none focus:border-emerald-600 font-medium text-slate-800"
                    required
                  />
                </div>
              </div>

              <div>
                <label className="font-semibold text-slate-700 block mb-1">No. Handphone / WhatsApp</label>
                <input 
                  type="text"
                  value={editForm.no_handphone}
                  onChange={(e) => setEditForm({ ...editForm, no_handphone: e.target.value })}
                  placeholder="08xxxxxxxxxx"
                  className="w-full px-3.5 py-2 rounded-xl border border-slate-200 focus:outline-none focus:border-emerald-600 text-slate-800"
                />
              </div>

              <div className="p-3 bg-amber-50 rounded-xl border border-amber-200 text-amber-800 text-[11px] leading-relaxed">
                <strong>Catatan:</strong> Perubahan nama mahasiswa dan NIM akan otomatis disinkronkan ke akun user login yang bersangkutan.
              </div>
            </div>

            {/* KONFIRMASI SIMPAN */}
            {confirmOpen ? (
              <div className="p-4 bg-emerald-50 rounded-2xl border border-emerald-200 space-y-3">
                <p className="text-xs font-bold text-emerald-900">
                  Apakah Anda yakin ingin menyimpan perubahan data mahasiswa ini?
                </p>
                <div className="flex justify-end gap-2">
                  <button
                    onClick={() => setConfirmOpen(false)}
                    disabled={isSaving}
                    className="px-3.5 py-1.5 bg-white border border-slate-200 text-slate-700 text-xs font-semibold rounded-xl hover:bg-slate-50 transition"
                  >
                    Batal
                  </button>
                  <button
                    onClick={handleSaveEdit}
                    disabled={isSaving}
                    className="px-4 py-1.5 bg-[#15803D] text-white text-xs font-bold rounded-xl shadow hover:bg-emerald-700 transition flex items-center gap-1.5"
                  >
                    {isSaving && <RefreshCw className="w-3.5 h-3.5 animate-spin" />}
                    Ya, Simpan Perubahan
                  </button>
                </div>
              </div>
            ) : (
              <div className="flex justify-end gap-2 pt-2 border-t border-slate-100">
                <button
                  onClick={() => setEditModalData(null)}
                  className="px-4 py-2 bg-slate-100 text-slate-700 text-xs font-semibold rounded-xl hover:bg-slate-200 transition"
                >
                  Batal
                </button>
                <button
                  onClick={() => setConfirmOpen(true)}
                  className="px-4 py-2 bg-[#15803D] text-white text-xs font-bold rounded-xl shadow hover:bg-emerald-700 transition flex items-center gap-1.5"
                >
                  Simpan Perubahan
                </button>
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
}