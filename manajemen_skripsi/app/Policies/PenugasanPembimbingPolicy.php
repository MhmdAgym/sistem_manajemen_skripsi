<?php

namespace App\Policies;

use App\Models\PenugasanPembimbing;
use App\Models\User;

class PenugasanPembimbingPolicy
{
    /**
     * Menentukan apakah pengguna dapat melihat daftar penugasan pembimbing.
     * Filter data spesifik (scope) tetap dilakukan pada lapisan Controller/Query.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'dosen', 'mahasiswa'], true);
    }

    /**
     * Menentukan apakah pengguna dapat melihat detail penugasan pembimbing tertentu.
     * - Admin: Diizinkan untuk seluruh penugasan.
     * - Mahasiswa: Hanya diizinkan jika penugasan tersebut terkait dengan skripsi miliknya.
     * - Dosen: Hanya diizinkan jika penugasan tersebut ditugaskan ke dirinya (berdasarkan dosen.id_dosen).
     */
    public function view(User $user, PenugasanPembimbing $penugasan): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        if ($user->role === 'mahasiswa') {
            $mahasiswa = $user->mahasiswa;
            if (!$mahasiswa) {
                return false;
            }

            // Validasi kepemilikan skripsi induk
            $skripsi = $penugasan->skripsi;
            if (!$skripsi) {
                return false;
            }

            return (int) $skripsi->id_mahasiswa === (int) $mahasiswa->id_mahasiswa;
        }

        if ($user->role === 'dosen') {
            $dosen = $user->dosen;
            if (!$dosen) {
                return false;
            }

            // Validasi penugasan ke dosen terkait (FK: dosen_id -> dosen.id_dosen)
            return (int) $penugasan->dosen_id === (int) $dosen->id_dosen;
        }

        return false;
    }

    /**
     * Menentukan apakah pengguna dapat menerbitkan penugasan pembimbing baru.
     * HANYA Administrator yang berwenang menetapkan pembimbing.
     * Mahasiswa dan Dosen DITOLAK MUTLAK.
     */
    public function create(User $user): bool
    {
        return $user->role === 'admin';
    }

    /**
     * Menentukan apakah pengguna dapat memperbarui penugasan pembimbing (misal penonaktifan status).
     * HANYA Administrator yang berwenang.
     */
    public function update(User $user, PenugasanPembimbing $penugasan): bool
    {
        return $user->role === 'admin';
    }

    /**
     * Menentukan apakah pengguna dapat melakukan pergantian pembimbing.
     * HANYA Administrator dan hanya jika status penugasan saat ini masih 'AKTIF'.
     */
    public function gantiPembimbing(User $user, PenugasanPembimbing $penugasan): bool
    {
        return $user->role === 'admin' && $penugasan->status_penugasan === 'AKTIF';
    }

    /**
     * Menentukan apakah pengguna dapat menghapus penugasan pembimbing secara fisik.
     * Default: DITOLAK MUTLAK (penugasan pembimbing adalah data audit akademik yang tidak boleh di-hard delete).
     */
    public function delete(User $user, PenugasanPembimbing $penugasan): bool
    {
        return false;
    }
}

