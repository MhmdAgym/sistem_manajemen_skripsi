"use client";

import React, { useState, useEffect, useMemo } from "react";
import axios from "@/lib/axios";
import { 
  ShieldCheck, 
  Search, 
  RefreshCw, 
  Eye, 
  Edit3, 
  X, 
  CheckCircle2, 
  AlertCircle, 
  Mail, 
  UserCheck, 
  Users, 
  GraduationCap, 
  Filter, 
  Shield, 
  KeyRound, 
  Calendar, 
  Check, 
  Clock 
} from "lucide-react";

interface ProfilAkademik {
  tipe: "mahasiswa" | "dosen" | "admin";
  id_mahasiswa?: number;
  id_dosen?: number;
  id_admin?: number;
  nomor_induk?: string;
  prodi?: string;
  fakultas?: string | null;
  no_handphone?: string | null;
  jenis_kelamin?: string | null;
  nama_admin?: string;
}

interface UserAccount {
  id: number;
  name: string;
  email: string;
  role: "admin" | "dosen" | "mahasiswa";
  nomor_induk: string | null;
  email_verified_at: string | null;
  created_at: string;
  profil_akademik: ProfilAkademik | null;
}

export default function ManajemenAkunPage() {
  const [users, setUsers] = useState<UserAccount[]>([]);
  const [loading, setLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  // Search & Filters
  const [searchQuery, setSearchQuery] = useState("");
  const [filterRole, setFilterRole] = useState("all");
  const [filterVerified, setFilterVerified] = useState("all");

  // Modals
  const [detailUser, setDetailUser] = useState<UserAccount | null>(null);
  const [editUser, setEditUser] = useState<UserAccount | null>(null);

  // Edit Form
  const [editForm, setEditForm] = useState({
    name: "",
    nomor_induk: "",
    email_verified: false,
  });
  const [editErrors, setEditErrors] = useState<Record<string, string[]>>({});
  const [isSaving, setIsSaving] = useState(false);
  const [confirmOpen, setConfirmOpen] = useState(false);

  // Fetch Users
  const fetchUsers = async () => {
    try {
      setLoading(true);
      setErrorMessage(null);
      const res = await axios.get("/api/users");
      setUsers(Array.isArray(res.data) ? res.data : []);
    } catch (err: any) {
      console.error("Gagal memuat data akun:", err);
      setErrorMessage(
        err.response?.data?.message || "Gagal memuat data pengguna. Pastikan Anda masuk sebagai Administrator."
      );
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchUsers();
  }, []);

  // Filtered List
  const filteredUsers = useMemo(() => {
    return users.filter((u) => {
      const q = searchQuery.toLowerCase();
      const matchSearch =
        u.name?.toLowerCase().includes(q) ||
        u.email?.toLowerCase().includes(q) ||
        (u.nomor_induk && u.nomor_induk.toLowerCase().includes(q));

      const matchRole = filterRole === "all" || u.role === filterRole;

      const matchVerified =
        filterVerified === "all" ||
        (filterVerified === "verified" && !!u.email_verified_at) ||
        (filterVerified === "unverified" && !u.email_verified_at);

      return matchSearch && matchRole && matchVerified;
    });
  }, [users, searchQuery, filterRole, filterVerified]);

  // Statistics
  const stats = useMemo(() => {
    const total = users.length;
    const mahasiswa = users.filter((u) => u.role === "mahasiswa").length;
    const dosen = users.filter((u) => u.role === "dosen").length;
    const admin = users.filter((u) => u.role === "admin").length;
    const verified = users.filter((u) => !!u.email_verified_at).length;
    return { total, mahasiswa, dosen, admin, verified };
  }, [users]);

  // Open Edit
  const handleOpenEdit = (u: UserAccount) => {
    setEditUser(u);
    setEditForm({
      name: u.name || "",
      nomor_induk: u.nomor_induk || "",
      email_verified: !!u.email_verified_at,
    });
    setEditErrors({});
    setConfirmOpen(false);
  };

  // Save Edit
  const handleSaveEdit = async () => {
    if (!editUser) return;
    setIsSaving(true);
    setEditErrors({});

    try {
      const res = await axios.put(`/api/users/${editUser.id}`, editForm);
      const updated: UserAccount = res.data.data;

      // Update state
      setUsers((prev) => prev.map((item) => (item.id === updated.id ? updated : item)));
      setSuccessMessage(`Data akun ${updated.name} (${updated.email}) berhasil diperbarui.`);
      setEditUser(null);
      setConfirmOpen(false);

      setTimeout(() => {
        setSuccessMessage(null);
      }, 4000);
    } catch (err: any) {
      if (err.response?.status === 422 && err.response?.data?.errors) {
        setEditErrors(err.response.data.errors);
      } else {
        setEditErrors({
          general: [err.response?.data?.message || "Gagal menyimpan perubahan akun."],
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
            Sistem Autentikasi & Keamanan
          </span>
          <h1 className="text-2xl font-bold tracking-tight mt-1">Manajemen Akun Pengguna</h1>
          <p className="text-xs text-emerald-100 mt-1">
            Kelola data akun autentikasi, status verifikasi email, serta relasi ke profil akademik mahasiswa dan dosen.
          </p>
        </div>
        <button 
          onClick={fetchUsers}
          disabled={loading}
          className="px-4 py-2 bg-white text-[#15803D] font-bold text-xs rounded-xl shadow hover:bg-emerald-50 transition flex items-center gap-1.5 disabled:opacity-75"
        >
          <RefreshCw className={`w-3.5 h-3.5 ${loading ? "animate-spin" : ""}`} />
          Muat Ulang
        </button>
      </div>

      {/* STATISTIK CARDS */}
      <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3.5">
        <div className="bg-white p-4 rounded-2xl border border-slate-100 shadow-sm">
          <p className="text-[11px] text-slate-400 font-semibold uppercase">Total Pengguna</p>
          <h3 className="text-xl font-bold text-slate-800 mt-1">{stats.total}</h3>
          <p className="text-[10px] text-slate-500 mt-0.5">Semua Akun</p>
        </div>
        <div className="bg-white p-4 rounded-2xl border border-slate-100 shadow-sm">
          <p className="text-[11px] text-slate-400 font-semibold uppercase">Mahasiswa</p>
          <h3 className="text-xl font-bold text-emerald-700 mt-1">{stats.mahasiswa}</h3>
          <p className="text-[10px] text-slate-500 mt-0.5">Akun Mahasiswa</p>
        </div>
        <div className="bg-white p-4 rounded-2xl border border-slate-100 shadow-sm">
          <p className="text-[11px] text-slate-400 font-semibold uppercase">Dosen</p>
          <h3 className="text-xl font-bold text-teal-700 mt-1">{stats.dosen}</h3>
          <p className="text-[10px] text-slate-500 mt-0.5">Akun Dosen</p>
        </div>
        <div className="bg-white p-4 rounded-2xl border border-slate-100 shadow-sm">
          <p className="text-[11px] text-slate-400 font-semibold uppercase">Administrator</p>
          <h3 className="text-xl font-bold text-purple-700 mt-1">{stats.admin}</h3>
          <p className="text-[10px] text-slate-500 mt-0.5">Hak Akses Penuh</p>
        </div>
        <div className="bg-white p-4 rounded-2xl border border-slate-100 shadow-sm col-span-2 sm:col-span-1">
          <p className="text-[11px] text-slate-400 font-semibold uppercase">Terverifikasi</p>
          <h3 className="text-xl font-bold text-[#15803D] mt-1">{stats.verified}</h3>
          <p className="text-[10px] text-slate-500 mt-0.5">Email Aktif</p>
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

      {/* KARTU DAFTAR AKUN */}
      <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm space-y-5">
        <div className="flex flex-col md:flex-row justify-between items-stretch md:items-center gap-4">
          <div className="flex items-center gap-2">
            <ShieldCheck className="w-5 h-5 text-emerald-600" />
            <h2 className="font-bold text-slate-800 text-sm">
              Daftar Akun Pengguna ({filteredUsers.length} dari {users.length})
            </h2>
          </div>

          <div className="flex flex-wrap items-center gap-3">
            {/* Filter Role */}
            <div className="relative">
              <select
                value={filterRole}
                onChange={(e) => setFilterRole(e.target.value)}
                className="pl-8 pr-4 py-2 rounded-xl border border-slate-200 text-xs bg-slate-50 focus:bg-white focus:outline-none focus:border-emerald-600 text-slate-700"
              >
                <option value="all">Semua Role</option>
                <option value="mahasiswa">Mahasiswa</option>
                <option value="dosen">Dosen</option>
                <option value="admin">Administrator</option>
              </select>
              <Filter className="absolute left-2.5 top-2.5 w-3.5 h-3.5 text-slate-400 pointer-events-none" />
            </div>

            {/* Filter Status Verifikasi */}
            <div className="relative">
              <select
                value={filterVerified}
                onChange={(e) => setFilterVerified(e.target.value)}
                className="pl-8 pr-4 py-2 rounded-xl border border-slate-200 text-xs bg-slate-50 focus:bg-white focus:outline-none focus:border-emerald-600 text-slate-700"
              >
                <option value="all">Semua Status Email</option>
                <option value="verified">Terverifikasi</option>
                <option value="unverified">Belum Verifikasi</option>
              </select>
              <Check className="absolute left-2.5 top-2.5 w-3.5 h-3.5 text-slate-400 pointer-events-none" />
            </div>

            {/* Input Pencarian */}
            <div className="relative w-full sm:w-64">
              <Search className="absolute left-3 top-2.5 w-4 h-4 text-slate-400" />
              <input 
                type="text" 
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                placeholder="Cari nama, email, nomor induk..." 
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

        {/* TABEL PENGGUNA */}
        <div className="overflow-x-auto">
          {loading ? (
            <div className="py-16 text-center text-slate-400 space-y-3">
              <RefreshCw className="w-8 h-8 animate-spin mx-auto text-emerald-600" />
              <p className="text-xs">Memuat data akun pengguna...</p>
            </div>
          ) : filteredUsers.length === 0 ? (
            <div className="py-16 text-center text-slate-400 space-y-2">
              <ShieldCheck className="w-10 h-10 mx-auto text-slate-300" />
              <p className="text-sm font-semibold text-slate-600">Tidak ada akun ditemukan</p>
              <p className="text-xs text-slate-400">
                {searchQuery || filterRole !== "all" || filterVerified !== "all"
                  ? "Coba sesuaikan kata kunci pencarian atau filter yang dipilih."
                  : "Belum ada akun pengguna terdaftar."}
              </p>
            </div>
          ) : (
            <table className="w-full text-left text-xs">
              <thead className="bg-slate-50 text-slate-600 uppercase font-semibold">
                <tr>
                  <th className="p-3.5 rounded-l-xl">Nama & Email</th>
                  <th className="p-3.5">Nomor Induk</th>
                  <th className="p-3.5">Role</th>
                  <th className="p-3.5">Verifikasi Email</th>
                  <th className="p-3.5">Profil Terkait</th>
                  <th className="p-3.5 rounded-r-xl text-center">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {filteredUsers.map((u) => {
                  return (
                    <tr key={u.id} className="hover:bg-slate-50/75 transition">
                      <td className="p-3.5">
                        <div className="font-bold text-slate-800 text-xs">{u.name}</div>
                        <div className="text-[11px] text-slate-400 flex items-center gap-1 mt-0.5">
                          <Mail className="w-3 h-3 text-slate-400" />
                          <span>{u.email}</span>
                        </div>
                      </td>
                      <td className="p-3.5 font-medium text-slate-700">
                        {u.nomor_induk || <span className="text-slate-400 italic">-</span>}
                      </td>
                      <td className="p-3.5">
                        {u.role === "admin" && (
                          <span className="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-purple-50 text-purple-700 border border-purple-200 uppercase tracking-wide">
                            Admin
                          </span>
                        )}
                        {u.role === "dosen" && (
                          <span className="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-teal-50 text-teal-700 border border-teal-200 uppercase tracking-wide">
                            Dosen
                          </span>
                        )}
                        {u.role === "mahasiswa" && (
                          <span className="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-[#15803D] border border-emerald-200 uppercase tracking-wide">
                            Mahasiswa
                          </span>
                        )}
                      </td>
                      <td className="p-3.5">
                        {u.email_verified_at ? (
                          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-50 text-emerald-800 border border-emerald-200">
                            <CheckCircle2 className="w-3 h-3 text-emerald-600" />
                            Terverifikasi
                          </span>
                        ) : (
                          <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-semibold bg-amber-50 text-amber-800 border border-amber-200">
                            <Clock className="w-3 h-3 text-amber-600" />
                            Belum Verifikasi
                          </span>
                        )}
                      </td>
                      <td className="p-3.5">
                        {u.profil_akademik ? (
                          <div className="space-y-0.5">
                            <span className="inline-block px-2 py-0.5 rounded-md text-[10px] font-semibold bg-slate-100 text-slate-700 border border-slate-200">
                              {u.profil_akademik.tipe === "mahasiswa" && `Mahasiswa: ${u.profil_akademik.prodi || "Aktif"}`}
                              {u.profil_akademik.tipe === "dosen" && `Dosen: NIDN ${u.profil_akademik.nomor_induk || "-"}`}
                              {u.profil_akademik.tipe === "admin" && "Admin Institusi"}
                            </span>
                          </div>
                        ) : (
                          <span className="text-[11px] text-slate-400 italic">Profil belum terhubung</span>
                        )}
                      </td>
                      <td className="p-3.5 text-center">
                        <div className="flex items-center justify-center gap-1.5">
                          <button 
                            onClick={() => setDetailUser(u)}
                            className="p-1.5 text-slate-600 hover:text-emerald-700 hover:bg-emerald-50 rounded-lg transition"
                            title="Lihat Detail Akun"
                          >
                            <Eye className="w-4 h-4" />
                          </button>
                          <button 
                            onClick={() => handleOpenEdit(u)}
                            className="p-1.5 text-slate-600 hover:text-[#15803D] hover:bg-emerald-50 rounded-lg transition"
                            title="Edit Akun"
                          >
                            <Edit3 className="w-4 h-4" />
                          </button>
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

      {/* MODAL DETAIL AKUN */}
      {detailUser && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm animate-in fade-in">
          <div className="bg-white rounded-3xl max-w-xl w-full p-6 shadow-2xl border border-slate-100 space-y-5 max-h-[90vh] overflow-y-auto">
            <div className="flex justify-between items-start pb-3 border-b border-slate-100">
              <div className="flex items-center gap-3">
                <div className="w-12 h-12 rounded-2xl bg-emerald-100 text-[#15803D] flex items-center justify-center font-bold text-lg">
                  {detailUser.name?.charAt(0) || "U"}
                </div>
                <div>
                  <h3 className="text-base font-bold text-slate-800">{detailUser.name}</h3>
                  <p className="text-xs text-slate-500">{detailUser.email}</p>
                </div>
              </div>
              <button 
                onClick={() => setDetailUser(null)}
                className="p-1.5 rounded-xl text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {/* INFORMASI AUTENTIKASI */}
            <div className="p-4 bg-slate-50 rounded-2xl border border-slate-100 space-y-2 text-xs">
              <h4 className="font-bold text-slate-700 flex items-center gap-1.5">
                <KeyRound className="w-4 h-4 text-emerald-600" /> Detail Autentikasi Akun
              </h4>
              <div className="grid grid-cols-2 gap-2 pt-1 text-slate-600">
                <div><span className="text-slate-400">ID Pengguna:</span> <span className="font-semibold text-slate-800">#{detailUser.id}</span></div>
                <div><span className="text-slate-400">Role:</span> <span className="font-semibold text-slate-800 uppercase">{detailUser.role}</span></div>
                <div><span className="text-slate-400">Nomor Induk:</span> <span className="font-semibold text-slate-800">{detailUser.nomor_induk || "-"}</span></div>
                <div>
                  <span className="text-slate-400">Status Email:</span>{" "}
                  {detailUser.email_verified_at ? (
                    <span className="text-emerald-700 font-bold">Terverifikasi</span>
                  ) : (
                    <span className="text-amber-600 font-bold">Belum Verifikasi</span>
                  )}
                </div>
                {detailUser.email_verified_at && (
                  <div className="col-span-2 text-[11px] text-slate-500">
                    <span className="text-slate-400">Waktu Verifikasi:</span> {new Date(detailUser.email_verified_at).toLocaleString("id-ID")}
                  </div>
                )}
              </div>
            </div>

            {/* PROFIL AKADEMIK TERKAIT */}
            <div className="p-4 bg-emerald-50/50 rounded-2xl border border-emerald-100 space-y-2 text-xs">
              <h4 className="font-bold text-[#15803D] flex items-center gap-1.5">
                <GraduationCap className="w-4 h-4 text-[#15803D]" /> Profil Akademik Terhubung
              </h4>
              {detailUser.profil_akademik ? (
                <div className="space-y-1.5 text-slate-700 pt-1">
                  {detailUser.profil_akademik.tipe === "mahasiswa" && (
                    <>
                      <div><span className="text-slate-500">NIM:</span> <span className="font-bold">{detailUser.profil_akademik.nomor_induk}</span></div>
                      <div><span className="text-slate-500">Program Studi:</span> <span className="font-semibold">{detailUser.profil_akademik.prodi}</span></div>
                      <div><span className="text-slate-500">Fakultas:</span> <span className="font-semibold">{detailUser.profil_akademik.fakultas || "-"}</span></div>
                      <div><span className="text-slate-500">No. Handphone:</span> <span className="font-semibold">{detailUser.profil_akademik.no_handphone || "-"}</span></div>
                      <div><span className="text-slate-500">Jenis Kelamin:</span> <span className="font-semibold">{detailUser.profil_akademik.jenis_kelamin || "-"}</span></div>
                    </>
                  )}
                  {detailUser.profil_akademik.tipe === "dosen" && (
                    <>
                      <div><span className="text-slate-500">NIDN:</span> <span className="font-bold">{detailUser.profil_akademik.nomor_induk}</span></div>
                      <div><span className="text-slate-500">No. Handphone:</span> <span className="font-semibold">{detailUser.profil_akademik.no_handphone || "-"}</span></div>
                      <div><span className="text-slate-500">Jenis Kelamin:</span> <span className="font-semibold">{detailUser.profil_akademik.jenis_kelamin || "-"}</span></div>
                    </>
                  )}
                  {detailUser.profil_akademik.tipe === "admin" && (
                    <div><span className="text-slate-500">Nama Admin:</span> <span className="font-bold">{detailUser.profil_akademik.nama_admin}</span></div>
                  )}
                </div>
              ) : (
                <p className="text-slate-500 italic">Belum ada profil akademik yang terhubung pada tabel mahasiswa/dosen.</p>
              )}
            </div>

            {/* KEAMANAN PASSWORD NOTICE */}
            <div className="p-3 bg-slate-50 rounded-2xl border border-slate-200 text-slate-600 text-[11px] leading-relaxed">
              <span className="font-bold text-slate-700">Keamanan Sandi:</span> Kata sandi disimpan menggunakan enkripsi hashing aman dan tidak ditampilkan. Apabila pengguna lupa sandi, minta pengguna menggunakan alur resmi <em>Lupa Password</em> pada halaman login.
            </div>

            <div className="flex justify-end gap-2 pt-2 border-t border-slate-100">
              <button
                onClick={() => {
                  const u = detailUser;
                  setDetailUser(null);
                  handleOpenEdit(u);
                }}
                className="px-4 py-2 bg-[#15803D] text-white text-xs font-bold rounded-xl shadow hover:bg-emerald-700 transition flex items-center gap-1.5"
              >
                <Edit3 className="w-3.5 h-3.5" /> Edit Akun Ini
              </button>
              <button
                onClick={() => setDetailUser(null)}
                className="px-4 py-2 bg-slate-100 text-slate-700 text-xs font-semibold rounded-xl hover:bg-slate-200 transition"
              >
                Tutup
              </button>
            </div>
          </div>
        </div>
      )}

      {/* MODAL EDIT AKUN */}
      {editUser && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm animate-in fade-in">
          <div className="bg-white rounded-3xl max-w-lg w-full p-6 shadow-2xl border border-slate-100 space-y-5 max-h-[90vh] overflow-y-auto">
            <div className="flex justify-between items-center pb-3 border-b border-slate-100">
              <h3 className="text-base font-bold text-slate-800 flex items-center gap-2">
                <Edit3 className="w-4 h-4 text-emerald-600" /> Edit Akun Pengguna
              </h3>
              <button 
                onClick={() => setEditUser(null)}
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
                <label className="font-semibold text-slate-700 block mb-1">Nama Lengkap Akun *</label>
                <input 
                  type="text"
                  value={editForm.name}
                  onChange={(e) => setEditForm({ ...editForm, name: e.target.value })}
                  className="w-full px-3.5 py-2 rounded-xl border border-slate-200 focus:outline-none focus:border-emerald-600 font-medium text-slate-800"
                  required
                />
              </div>

              <div>
                <label className="font-semibold text-slate-700 block mb-1">Email Pengguna (Login)</label>
                <input 
                  type="email"
                  disabled
                  value={editUser.email}
                  className="w-full px-3.5 py-2 rounded-xl border border-slate-200 bg-slate-100 font-medium text-slate-500 cursor-not-allowed"
                />
                <span className="text-[10px] text-slate-400 mt-0.5 block">Email digunakan untuk kredensial login dan verifikasi.</span>
              </div>

              <div>
                <label className="font-semibold text-slate-700 block mb-1">Nomor Induk (NIM / NIDN)</label>
                <input 
                  type="text"
                  value={editForm.nomor_induk}
                  onChange={(e) => setEditForm({ ...editForm, nomor_induk: e.target.value })}
                  placeholder="Masukkan NIM atau NIDN..."
                  className="w-full px-3.5 py-2 rounded-xl border border-slate-200 focus:outline-none focus:border-emerald-600 font-medium text-slate-800"
                />
              </div>

              <div>
                <label className="font-semibold text-slate-700 block mb-1">Status Verifikasi Email</label>
                <label className="flex items-center gap-2 cursor-pointer p-3 rounded-xl border border-slate-200 bg-slate-50/50 hover:bg-slate-50 transition">
                  <input 
                    type="checkbox"
                    checked={editForm.email_verified}
                    onChange={(e) => setEditForm({ ...editForm, email_verified: e.target.checked })}
                    className="w-4 h-4 text-[#15803D] focus:ring-emerald-600 rounded"
                  />
                  <div>
                    <span className="font-semibold text-slate-800">Tandai Email Terverifikasi</span>
                    <p className="text-[10px] text-slate-500">Mengizinkan pengguna langsung mengakses fitur sistem tanpa menunggu klik tautan email.</p>
                  </div>
                </label>
              </div>

              <div className="p-3 bg-amber-50 rounded-xl border border-amber-200 text-amber-800 text-[11px] leading-relaxed">
                <strong>Catatan Sinkronisasi:</strong> Perubahan nama dan nomor induk akan disinkronkan secara otomatis ke tabel profil akademik yang terhubung.
              </div>
            </div>

            {/* KONFIRMASI SIMPAN */}
            {confirmOpen ? (
              <div className="p-4 bg-emerald-50 rounded-2xl border border-emerald-200 space-y-3">
                <p className="text-xs font-bold text-emerald-900">
                  Apakah Anda yakin ingin menyimpan perubahan akun pengguna ini?
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
                  onClick={() => setEditUser(null)}
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
