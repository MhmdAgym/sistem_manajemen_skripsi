<?php

namespace App\Policies;

use App\Models\SkSkripsiDokumen;
use App\Models\User;

class SkSkripsiDokumenPolicy
{
    /**
     * Menentukan apakah pengguna dapat melihat daftar dokumen SK.
     * Filter data spesifik (scope) tetap dilakukan pada lapisan Controller/Query.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'dosen', 'mahasiswa'], true);
    }

    /**
     * Menentukan apakah pengguna dapat melihat detail dokumen SK tertentu.
     * - Admin: Diizinkan untuk seluruh dokumen.
     * - Mahasiswa: Hanya diizinkan jika berkas SK milik mahasiswa yang bersangkutan.
     * - Dosen: Hanya diizinkan jika terdaftar sebagai usulan pembimbing atau penugasan pembimbing terkait.
     */
    public function view(User $user, SkSkripsiDokumen $dokumen): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        if ($user->role === 'mahasiswa') {
            $mahasiswa = $user->mahasiswa;
            if (!$mahasiswa) {
                return false;
            }

            return (int) $dokumen->mahasiswa_id === (int) $mahasiswa->id_mahasiswa;
        }

        if ($user->role === 'dosen') {
            $dosen = $user->dosen;
            if (!$dosen) {
                return false;
            }

            $idDosen = (int) $dosen->id_dosen;

            // Periksa apakah dosen diusulkan dalam dokumen ini
            if ((int) $dokumen->dosen_1_usulan_id === $idDosen || (int) $dokumen->dosen_2_usulan_id === $idDosen) {
                return true;
            }

            // Periksa apakah dosen telah ditugaskan resmi berdasarkan dokumen SK ini
            return $dokumen->penugasanPembimbing()
                ->where('dosen_id', $idDosen)
                ->exists();
        }

        return false;
    }

    /**
     * Menentukan apakah pengguna dapat mengunggah dokumen SK baru.
     * Hanya Mahasiswa aktif yang memiliki profil mahasiswa sah yang diizinkan mengunggah.
     * Dosen dan Admin tidak mengunggah SK (alur offline ke TU fakultas).
     */
    public function create(User $user): bool
    {
        return $user->role === 'mahasiswa' && $user->mahasiswa !== null;
    }

    /**
     * Menentukan apakah pengguna dapat memperbarui dokumen SK.
     * - Mahasiswa: Hanya dapat memperbarui metadata/revisi berkas jika dokumen miliknya
     *   dan status dokumen masih 'MENUNGGU_PEMERIKSAAN' atau 'PERLU_PERBAIKAN'.
     * - Admin: Diizinkan untuk koreksi administratif.
     * - Dosen: Ditolak.
     */
    public function update(User $user, SkSkripsiDokumen $dokumen): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        if ($user->role === 'mahasiswa') {
            $mahasiswa = $user->mahasiswa;
            if (!$mahasiswa) {
                return false;
            }

            $isOwner = (int) $dokumen->mahasiswa_id === (int) $mahasiswa->id_mahasiswa;
            $isEditableStatus = in_array($dokumen->status_dokumen, ['MENUNGGU_PEMERIKSAAN', 'PERLU_PERBAIKAN'], true);

            return $isOwner && $isEditableStatus;
        }

        return false;
    }

    /**
     * Menentukan apakah pengguna dapat memverifikasi atau menolak dokumen SK.
     * HANYA Administrator yang berwenang. Mahasiswa dan Dosen DITOLAK MUTLAK.
     */
    public function verify(User $user, SkSkripsiDokumen $dokumen): bool
    {
        if ($user->role !== 'admin') {
            return false;
        }

        // Verifikasi hanya sah dilakukan jika dokumen sedang menunggu pemeriksaan atau dalam revisi
        return in_array($dokumen->status_dokumen, ['MENUNGGU_PEMERIKSAAN', 'PERLU_PERBAIKAN'], true);
    }

    /**
     * Menentukan apakah pengguna dapat menghapus dokumen SK secara fisik.
     * Default: DITOLAK MUTLAK (dokumen SK merupakan arsip fisik terdaftar).
     */
    public function delete(User $user, SkSkripsiDokumen $dokumen): bool
    {
        return false;
    }
}

