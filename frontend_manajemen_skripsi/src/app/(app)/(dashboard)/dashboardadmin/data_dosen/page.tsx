"use client";

import React, { useState, useEffect, useMemo } from "react";
import axios from "@/lib/axios";
import { 
  UserCheck, 
  Search, 
  RefreshCw, 
  Eye, 
  Edit3, 
  X, 
  CheckCircle2, 
  AlertCircle, 
  Phone, 
  Mail, 
  Users, 
  BookOpen, 
  GraduationCap
} from "lucide-react";

interface UserAccount {
  id: number;
  name: string;
  email: string;
  role: string;
  nomor_induk: string | null;
  email_verified_at: string | null;
}

interface MahasiswaBimbingan {
  id_mahasiswa: number;
  NIM: string;
  nama_mahasiswa: string;
  prodi: string;
  fakultas: string | null;
  no_handphone: string | null;
  judul_skripsi: string;
  status_judul: string;
  id_skripsi: number;
}

interface DosenData {
  id_dosen: number;
  NIDN: string;
  user_id: number;
  nama_dosen: string;
  no_handphone: string | null;
  jenis_kelamin: string | null;
  total_mahasiswa_bimbingan: number;
  mahasiswa_bimbingan: MahasiswaBimbingan[];
  user: UserAccount | null;
}

export default function DataDosenPage() {
  const [dosenList, setDosenList] = useState<DosenData[]>([]);
  const [loading, setLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  // Search
  const [searchQuery, setSearchQuery] = useState("");

  // Modals
  const [selectedDosenForBimbingan, setSelectedDosenForBimbingan] = useState<DosenData | null>(null);
  const [detailModalDosen, setDetailModalDosen] = useState<DosenData | null>(null);
  const [editModalDosen, setEditModalDosen] = useState<DosenData | null>(null);

  // Edit Form
  const [editForm, setEditForm] = useState({
    nama_dosen: "",
    NIDN: "",
    no_handphone: "",
    jenis_kelamin: "",
  });
  const [editErrors, setEditErrors] = useState<Record<string, string[]>>({});
  const [isSaving, setIsSaving] = useState(false);
  const [confirmOpen, setConfirmOpen] = useState(false);

  // Fetch Dosen
  const fetchDosen = async () => {
    try {
      setLoading(true);
      setErrorMessage(null);
      const res = await axios.get("/api/dosen");
      setDosenList(Array.isArray(res.data) ? res.data : []);
    } catch (err: any) {
      console.error("Gagal memuat data dosen:", err);
      setErrorMessage(
        err.response?.data?.message || "Gagal memuat data dosen dari server. Pastikan server aktif."
      );
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchDosen();
  }, []);

  // Filtered List
  const filteredList = useMemo(() => {
    return dosenList.filter((d) => {
      const q = searchQuery.toLowerCase();
      return (
        d.nama_dosen?.toLowerCase().includes(q) ||
        d.NIDN?.toLowerCase().includes(q) ||
        (d.no_handphone && d.no_handphone.toLowerCase().includes(q)) ||
        (d.user?.email && d.user.email.toLowerCase().includes(q))
      );
    });
  }, [dosenList, searchQuery]);

  // Open Edit
  const handleOpenEdit = (d: DosenData) => {
    setEditModalDosen(d);
    setEditForm({
      nama_dosen: d.nama_dosen || "",
      NIDN: d.NIDN || "",
      no_handphone: d.no_handphone || "",
      jenis_kelamin: d.jenis_kelamin || "",
    });
    setEditErrors({});
    setConfirmOpen(false);
  };

  // Save Edit
  const handleSaveEdit = async () => {
    if (!editModalDosen) return;
    setIsSaving(true);
    setEditErrors({});

    try {
      const res = await axios.put(`/api/dosen/${editModalDosen.id_dosen}`, editForm);
      const updatedDosen: DosenData = res.data.data;

      // Update local state directly
      setDosenList((prev) =>
        prev.map((d) => (d.id_dosen === updatedDosen.id_dosen ? updatedDosen : d))
      );

      setSuccessMessage(`Data dosen ${updatedDosen.nama_dosen} berhasil diperbarui.`);
      setEditModalDosen(null);
      setConfirmOpen(false);

      setTimeout(() => {
        setSuccessMessage(null);
      }, 4000);
    } catch (err: any) {
      if (err.response?.status === 422 && err.response?.data?.errors) {
        setEditErrors(err.response.data.errors);
      } else {
        setEditErrors({
          general: [err.response?.data?.message || "Terjadi kesalahan saat menyimpan perubahan."],
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
            Tenaga Pendidik & Penguji
          </span>
          <h1 className="text-2xl font-bold tracking-tight mt-1">Manajemen Data Dosen</h1>
          <p className="text-xs text-emerald-100 mt-1">
            Kelola data dosen pembimbing, penguji, dan PA serta pantau beban bimbingan skripsi mahasiswa.
          </p>
        </div>
        <button 
          onClick={fetchDosen}
          disabled={loading}
          className="px-4 py-2 bg-white text-[#15803D] font-bold text-xs rounded-xl shadow hover:bg-emerald-50 transition flex items-center gap-1.5 disabled:opacity-75"
        >
          <RefreshCw className={`w-3.5 h-3.5 ${loading ? "animate-spin" : ""}`} />
          Muat Ulang
        </button>
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

      {/* KARTU DAFTAR DOSEN */}
      <div className="bg-white p-6 rounded-3xl border border-slate-100 shadow-sm space-y-5">
        <div className="flex flex-col sm:flex-row justify-between items-stretch sm:items-center gap-4">
          <h2 className="font-bold text-slate-800 text-sm flex items-center gap-2">
            <UserCheck className="w-5 h-5 text-emerald-600" /> 
            Daftar Dosen Aktif ({filteredList.length} dari {dosenList.length})
          </h2>

          <div className="relative w-full sm:w-72">
            <Search className="absolute left-3 top-2.5 w-4 h-4 text-slate-400" />
            <input 
              type="text" 
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              placeholder="Cari nama, NIDN, atau email..." 
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

        {/* TABEL DATA DOSEN */}
        <div className="overflow-x-auto">
          {loading ? (
            <div className="py-16 text-center text-slate-400 space-y-3">
              <RefreshCw className="w-8 h-8 animate-spin mx-auto text-emerald-600" />
              <p className="text-xs">Memuat data dosen...</p>
            </div>
          ) : filteredList.length === 0 ? (
            <div className="py-16 text-center text-slate-400 space-y-2">
              <UserCheck className="w-10 h-10 mx-auto text-slate-300" />
              <p className="text-sm font-semibold text-slate-600">Tidak ada data dosen ditemukan</p>
              <p className="text-xs text-slate-400">
                {searchQuery ? "Coba gunakan kata kunci pencarian yang berbeda." : "Belum ada data dosen terdaftar."}
              </p>
            </div>
          ) : (
            <table className="w-full text-left text-xs">
              <thead className="bg-slate-50 text-slate-600 uppercase font-semibold">
                <tr>
                  <th className="p-3.5 rounded-l-xl">Nama Dosen</th>
                  <th className="p-3.5">NIDN</th>
                  <th className="p-3.5">Kontak / Jenis Kelamin</th>
                  <th className="p-3.5 text-center">Bimbingan Skripsi</th>
                  <th className="p-3.5 rounded-r-xl text-center">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {filteredList.map((d) => (
                  <tr key={d.id_dosen} className="hover:bg-slate-50/75 transition">
                    <td className="p-3.5">
                      <div className="font-bold text-slate-800 text-xs">{d.nama_dosen}</div>
                      <div className="text-[11px] text-slate-400">{d.user?.email || "-"}</div>
                    </td>
                    <td className="p-3.5 font-medium text-slate-700">{d.NIDN}</td>
                    <td className="p-3.5">
                      <div className="text-slate-800 font-medium">{d.no_handphone || "-"}</div>
                      <div className="text-[11px] text-slate-500">{d.jenis_kelamin || "Belum diatur"}</div>
                    </td>
                    <td className="p-3.5 text-center">
                      <button 
                        onClick={() => setSelectedDosenForBimbingan(d)}
                        className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold transition shadow-sm ${
                          d.total_mahasiswa_bimbingan > 0 
                            ? "bg-emerald-50 text-[#15803D] hover:bg-emerald-100 border border-emerald-200" 
                            : "bg-slate-100 text-slate-500 hover:bg-slate-200 border border-slate-200"
                        }`}
                        title="Klik untuk melihat daftar mahasiswa bimbingan"
                      >
                        <Users className="w-3.5 h-3.5" />
                        <span>{d.total_mahasiswa_bimbingan} Mahasiswa</span>
                      </button>
                    </td>
                    <td className="p-3.5 text-center">
                      <div className="flex items-center justify-center gap-1.5">
                        <button 
                          onClick={() => setDetailModalDosen(d)}
                          className="p-1.5 text-slate-600 hover:text-emerald-700 hover:bg-emerald-50 rounded-lg transition"
                          title="Lihat Detail Dosen"
                        >
                          <Eye className="w-4 h-4" />
                        </button>
                        <button 
                          onClick={() => handleOpenEdit(d)}
                          className="p-1.5 text-slate-600 hover:text-[#15803D] hover:bg-emerald-50 rounded-lg transition"
                          title="Edit Data Dosen"
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

      {/* MODAL MAHASISWA BIMBINGAN */}
      {selectedDosenForBimbingan && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm animate-in fade-in">
          <div className="bg-white rounded-3xl max-w-2xl w-full p-6 shadow-2xl border border-slate-100 space-y-5 max-h-[90vh] overflow-y-auto">
            <div className="flex justify-between items-start pb-3 border-b border-slate-100">
              <div>
                <span className="text-[10px] uppercase font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-200">
                  Daftar Mahasiswa Bimbingan
                </span>
                <h3 className="text-base font-bold text-slate-800 mt-1">
                  {selectedDosenForBimbingan.nama_dosen}
                </h3>
                <p className="text-xs text-slate-500">
                  NIDN: {selectedDosenForBimbingan.NIDN} • Total: {selectedDosenForBimbingan.total_mahasiswa_bimbingan} Mahasiswa
                </p>
              </div>
              <button 
                onClick={() => setSelectedDosenForBimbingan(null)}
                className="p-1.5 rounded-xl text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {selectedDosenForBimbingan.mahasiswa_bimbingan.length === 0 ? (
              <div className="py-12 text-center text-slate-400 space-y-2">
                <Users className="w-10 h-10 mx-auto text-slate-300" />
                <p className="text-sm font-semibold text-slate-700">Belum Ada Mahasiswa Bimbingan</p>
                <p className="text-xs text-slate-400">
                  Dosen ini belum ditetapkan sebagai dosen pembimbing pada skripsi mahasiswa mana pun.
                </p>
              </div>
            ) : (
              <div className="space-y-3">
                {selectedDosenForBimbingan.mahasiswa_bimbingan.map((m, idx) => (
                  <div 
                    key={idx} 
                    className="p-4 bg-slate-50 hover:bg-slate-100/70 rounded-2xl border border-slate-200/80 transition space-y-2"
                  >
                    <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-1">
                      <div>
                        <h4 className="text-xs font-bold text-slate-800">{m.nama_mahasiswa}</h4>
                        <p className="text-[11px] text-slate-500">
                          NIM: <span className="font-semibold text-slate-700">{m.NIM}</span> • {m.prodi}
                        </p>
                      </div>
                      <span className="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-[#15803D] uppercase">
                        {m.status_judul || "Disetujui"}
                      </span>
                    </div>

                    <div className="pt-1 border-t border-slate-200/60">
                      <p className="text-[11px] text-slate-400 font-medium">Judul Skripsi:</p>
                      <p className="text-xs text-slate-700 font-medium leading-snug">
                        {m.judul_skripsi}
                      </p>
                    </div>

                    {m.fakultas && (
                      <p className="text-[10px] text-slate-400">
                        Fakultas: <span className="text-slate-600 font-semibold">{m.fakultas}</span>
                      </p>
                    )}
                  </div>
                ))}
              </div>
            )}

            <div className="flex justify-end pt-2 border-t border-slate-100">
              <button
                onClick={() => setSelectedDosenForBimbingan(null)}
                className="px-4 py-2 bg-slate-100 text-slate-700 text-xs font-semibold rounded-xl hover:bg-slate-200 transition"
              >
                Tutup
              </button>
            </div>
          </div>
        </div>
      )}

      {/* MODAL DETAIL DOSEN */}
      {detailModalDosen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm animate-in fade-in">
          <div className="bg-white rounded-3xl max-w-lg w-full p-6 shadow-2xl border border-slate-100 space-y-5">
            <div className="flex justify-between items-start pb-3 border-b border-slate-100">
              <div className="flex items-center gap-3">
                <div className="w-12 h-12 rounded-2xl bg-emerald-100 text-[#15803D] flex items-center justify-center font-bold text-lg">
                  {detailModalDosen.nama_dosen?.charAt(0) || "D"}
                </div>
                <div>
                  <h3 className="text-base font-bold text-slate-800">{detailModalDosen.nama_dosen}</h3>
                  <p className="text-xs text-slate-500">NIDN: {detailModalDosen.NIDN}</p>
                </div>
              </div>
              <button 
                onClick={() => setDetailModalDosen(null)}
                className="p-1.5 rounded-xl text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="space-y-3 text-xs">
              <div className="p-4 bg-slate-50 rounded-2xl space-y-2 border border-slate-100">
                <div className="flex items-center gap-2 text-slate-700 font-semibold">
                  <Mail className="w-4 h-4 text-emerald-600" /> Email Akun:
                  <span className="font-normal text-slate-600">{detailModalDosen.user?.email || "-"}</span>
                </div>
                <div className="flex items-center gap-2 text-slate-700 font-semibold">
                  <Phone className="w-4 h-4 text-emerald-600" /> No. Handphone:
                  <span className="font-normal text-slate-600">{detailModalDosen.no_handphone || "-"}</span>
                </div>
                <div className="flex items-center gap-2 text-slate-700 font-semibold">
                  <GraduationCap className="w-4 h-4 text-emerald-600" /> Jenis Kelamin:
                  <span className="font-normal text-slate-600">{detailModalDosen.jenis_kelamin || "-"}</span>
                </div>
              </div>

              <div className="p-4 bg-emerald-50 rounded-2xl border border-emerald-100 flex justify-between items-center">
                <div>
                  <p className="text-[11px] font-bold text-[#15803D] uppercase">Total Mahasiswa Bimbingan</p>
                  <p className="text-xs text-emerald-900 mt-0.5">Dosen pembimbing skripsi aktif</p>
                </div>
                <button
                  onClick={() => {
                    const d = detailModalDosen;
                    setDetailModalDosen(null);
                    setSelectedDosenForBimbingan(d);
                  }}
                  className="px-3 py-1.5 bg-[#15803D] text-white text-xs font-bold rounded-xl shadow hover:bg-emerald-700 transition"
                >
                  {detailModalDosen.total_mahasiswa_bimbingan} Mahasiswa
                </button>
              </div>
            </div>

            <div className="flex justify-end gap-2 pt-2 border-t border-slate-100">
              <button
                onClick={() => {
                  const d = detailModalDosen;
                  setDetailModalDosen(null);
                  handleOpenEdit(d);
                }}
                className="px-4 py-2 bg-[#15803D] text-white text-xs font-bold rounded-xl shadow hover:bg-emerald-700 transition flex items-center gap-1.5"
              >
                <Edit3 className="w-3.5 h-3.5" /> Edit Data Ini
              </button>
              <button
                onClick={() => setDetailModalDosen(null)}
                className="px-4 py-2 bg-slate-100 text-slate-700 text-xs font-semibold rounded-xl hover:bg-slate-200 transition"
              >
                Tutup
              </button>
            </div>
          </div>
        </div>
      )}

      {/* MODAL EDIT DOSEN */}
      {editModalDosen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm animate-in fade-in">
          <div className="bg-white rounded-3xl max-w-lg w-full p-6 shadow-2xl border border-slate-100 space-y-5 max-h-[90vh] overflow-y-auto">
            <div className="flex justify-between items-center pb-3 border-b border-slate-100">
              <h3 className="text-base font-bold text-slate-800 flex items-center gap-2">
                <Edit3 className="w-4 h-4 text-emerald-600" /> Edit Data Dosen
              </h3>
              <button 
                onClick={() => setEditModalDosen(null)}
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
                <label className="font-semibold text-slate-700 block mb-1">Nama Dosen Lengkap & Gelar *</label>
                <input 
                  type="text"
                  value={editForm.nama_dosen}
                  onChange={(e) => setEditForm({ ...editForm, nama_dosen: e.target.value })}
                  className="w-full px-3.5 py-2 rounded-xl border border-slate-200 focus:outline-none focus:border-emerald-600 font-medium text-slate-800"
                  required
                />
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label className="font-semibold text-slate-700 block mb-1">NIDN (Nomor Induk Dosen) *</label>
                  <input 
                    type="text"
                    maxLength={10}
                    value={editForm.NIDN}
                    onChange={(e) => setEditForm({ ...editForm, NIDN: e.target.value })}
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
                <strong>Catatan:</strong> Perubahan nama dosen dan NIDN akan otomatis disinkronkan ke akun user login yang bersangkutan.
              </div>
            </div>

            {/* KONFIRMASI SIMPAN */}
            {confirmOpen ? (
              <div className="p-4 bg-emerald-50 rounded-2xl border border-emerald-200 space-y-3">
                <p className="text-xs font-bold text-emerald-900">
                  Apakah Anda yakin ingin menyimpan perubahan data dosen ini?
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
                  onClick={() => setEditModalDosen(null)}
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