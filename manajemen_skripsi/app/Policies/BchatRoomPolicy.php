<?php

namespace App\Policies;

use App\Models\BchatRoom;
use App\Models\User;

class BchatRoomPolicy
{
    /**
     * Menentukan apakah pengguna dapat melihat daftar room.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'dosen', 'mahasiswa'], true);
    }

    /**
     * Menentukan apakah pengguna dapat melihat detail room dan membaca histori percakapan.
     *
     * Invarian Keamanan:
     * 1. Pengguna yang bukan anggota room DITOLAK MUTLAK.
     * 2. Room TERMINATED atau SUSPENDED DITOLAK MUTLAK.
     * 3. Anggota yang penugasan akademiknya berstatus 'DIBATALKAN' DITOLAK MUTLAK.
     * 4. Jika room berstatus 'ARCHIVED_READONLY', anggota yang sah diizinkan membaca arsip
     *    (termasuk jika penugasan berstatus 'DIGANTI' untuk kebutuhan rekam jejak akademik).
     * 5. Jika room berstatus 'ACTIVE', penugasan pembimbing WAJIB 'AKTIF' dan anggota WAJIB is_active.
     * 6. Jika room berstatus 'PENDING_WELCOME_ACK' atau 'PENDING_REKEYING', anggota terdaftar
     *    diizinkan melihat metadata room untuk keperluan inisiasi/handshake selama penugasan 'AKTIF'.
     */
    public function view(User $user, BchatRoom $room): bool
    {
        // 1. Verifikasi keanggotaan aktual pengguna pada tabel bchat_room_members
        $member = $room->members()->where('user_id', $user->id)->first();
        if (!$member) {
            return false;
        }

        // 2. Verifikasi status room: TERMINATED dan SUSPENDED memblokir seluruh pembacaan
        if (in_array($room->status, ['TERMINATED', 'SUSPENDED'], true)) {
            return false;
        }

        // 3. Verifikasi penugasan akademik induk
        $penugasan = $room->penugasanPembimbing;
        if (!$penugasan) {
            return false;
        }

        // Penugasan yang dibatalkan kehilangan seluruh hak akses
        if ($penugasan->status_penugasan === 'DIBATALKAN') {
            return false;
        }

        // 4. Room berstatus ARCHIVED_READONLY memberikan hak baca bagi anggota yang sah
        if ($room->status === 'ARCHIVED_READONLY') {
            return true;
        }

        // 5. Room berstatus ACTIVE mensyaratkan penugasan AKTIF dan anggota is_active
        if ($room->status === 'ACTIVE') {
            return $penugasan->status_penugasan === 'AKTIF' && (bool) $member->is_active;
        }

        // 6. Room fase handshake (PENDING_WELCOME_ACK / PENDING_REKEYING) mengizinkan view untuk koordinasi handshake
        if (in_array($room->status, ['PENDING_WELCOME_ACK', 'PENDING_REKEYING'], true)) {
            return $penugasan->status_penugasan === 'AKTIF';
        }

        return false;
    }

    /**
     * Menentukan apakah pengguna dapat membaca histori pesan dalam room.
     *
     * Invarian Keamanan:
     * - HANYA room berstatus 'ACTIVE' (anggota aktif & penugasan aktif) dan
     *   'ARCHIVED_READONLY' (anggota lama yang sah) yang mengizinkan pembacaan pesan.
     * - Room berstatus 'PENDING_WELCOME_ACK', 'PENDING_REKEYING', 'SUSPENDED',
     *   dan 'TERMINATED' DITOLAK MUTLAK dari pembacaan pesan.
     * - Penugasan pembimbing yang berstatus 'DIBATALKAN' DITOLAK MUTLAK.
     */
    public function readMessages(User $user, BchatRoom $room): bool
    {
        // 1. Verifikasi keanggotaan aktual pengguna pada tabel bchat_room_members
        $member = $room->members()->where('user_id', $user->id)->first();
        if (!$member) {
            return false;
        }

        // 2. Room TERMINATED dan SUSPENDED memblokir seluruh pembacaan pesan
        if (in_array($room->status, ['TERMINATED', 'SUSPENDED'], true)) {
            return false;
        }

        // 3. Verifikasi ketersediaan dan keabsahan penugasan akademik induk
        $penugasan = $room->penugasanPembimbing;
        if (!$penugasan || $penugasan->status_penugasan === 'DIBATALKAN') {
            return false;
        }

        // 4. Room ARCHIVED_READONLY mengizinkan pembacaan histori pesan bagi anggota yang sah
        if ($room->status === 'ARCHIVED_READONLY') {
            return true;
        }

        // 5. Room ACTIVE mensyaratkan penugasan AKTIF dan anggota is_active = TRUE
        if ($room->status === 'ACTIVE') {
            return $penugasan->status_penugasan === 'AKTIF' && (bool) $member->is_active;
        }

        // 6. Seluruh status lain (PENDING_OFFICIAL_SK, PENDING_WELCOME_ACK, PENDING_REKEYING) DITOLAK
        return false;
    }

    /**
     * Menentukan apakah pengguna dapat mengirimkan pesan ke dalam room.
     *
     * Invarian Keamanan:
     * - HANYA room berstatus 'ACTIVE' yang mengizinkan pengiriman pesan.
     * - Room 'ARCHIVED_READONLY', 'PENDING_WELCOME_ACK', 'PENDING_REKEYING', 'SUSPENDED',
     *   dan 'TERMINATED' DITOLAK MUTLAK.
     * - Penugasan pembimbing WAJIB berstatus 'AKTIF'.
     * - Anggota WAJIB terdaftar pada bchat_room_members dan memiliki is_active = TRUE.
     */
    public function sendMessage(User $user, BchatRoom $room): bool
    {
        // Room harus aktif
        if ($room->status !== 'ACTIVE') {
            return false;
        }

        // Penugasan akademik harus aktif
        $penugasan = $room->penugasanPembimbing;
        if (!$penugasan || $penugasan->status_penugasan !== 'AKTIF') {
            return false;
        }

        // Anggota harus terdaftar dan berstatus aktif
        $member = $room->members()->where('user_id', $user->id)->first();
        if (!$member || !(bool) $member->is_active) {
            return false;
        }

        return true;
    }

    /**
     * Menentukan apakah pengguna dapat berpartisipasi dalam handshake Welcome/ACK.
     * Diizinkan hanya pada room PENDING_WELCOME_ACK / PENDING_REKEYING bagi anggota yang penugasannya aktif.
     */
    public function handshake(User $user, BchatRoom $room): bool
    {
        if (!in_array($room->status, ['PENDING_WELCOME_ACK', 'PENDING_REKEYING'], true)) {
            return false;
        }

        $penugasan = $room->penugasanPembimbing;
        if (!$penugasan || $penugasan->status_penugasan !== 'AKTIF') {
            return false;
        }

        return $room->members()->where('user_id', $user->id)->exists();
    }

    /**
     * Menghapus room secara fisik DITOLAK MUTLAK untuk menjaga integritas rekam jejak akademik.
     */
    public function delete(User $user, BchatRoom $room): bool
    {
        return false;
    }
}

