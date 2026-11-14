<?php

namespace App\Policies;

use App\Models\BchatMessage;
use App\Models\BchatRoom;
use App\Models\User;

class BchatMessagePolicy
{
    /**
     * Menentukan apakah pengguna dapat melihat daftar pesan pada suatu room.
     * Menggunakan aturan readMessages dari BchatRoomPolicy:
     * - HANYA diizinkan pada status 'ACTIVE' dan 'ARCHIVED_READONLY'.
     * - Room 'PENDING_WELCOME_ACK', 'PENDING_REKEYING', 'SUSPENDED', dan 'TERMINATED' DITOLAK MUTLAK.
     */
    public function viewAny(User $user, BchatRoom $room): bool
    {
        return (new BchatRoomPolicy())->readMessages($user, $room);
    }

    /**
     * Menentukan apakah pengguna dapat melihat detail suatu pesan ciphertext.
     * Akses ditolak jika relasi room pada pesan tidak tersedia atau tidak diizinkan dibaca.
     */
    public function view(User $user, BchatMessage $message): bool
    {
        $room = $message->room;
        if (!$room) {
            return false;
        }

        return (new BchatRoomPolicy())->readMessages($user, $room);
    }

    /**
     * Menentukan apakah pengguna dapat mengirimkan pesan baru ke dalam room.
     * Menggunakan aturan sendMessage dari BchatRoomPolicy:
     * - Room wajib berstatus 'ACTIVE'.
     * - Penugasan pembimbing wajib 'AKTIF'.
     * - Pengirim wajib anggota room dan memiliki is_active = TRUE.
     * - Room berstatus 'ARCHIVED_READONLY', 'PENDING_WELCOME_ACK', dll. DITOLAK MUTLAK.
     */
    public function create(User $user, BchatRoom $room): bool
    {
        return (new BchatRoomPolicy())->sendMessage($user, $room);
    }

    /**
     * Pembaruan pesan E2EE DITOLAK MUTLAK (pesan bersifat immutable dan terikat TreeKEM ratchet).
     */
    public function update(User $user, BchatMessage $message): bool
    {
        return false;
    }

    /**
     * Penghapusan pesan E2EE DITOLAK MUTLAK untuk menjaga integritas sekuens dan audit akademik.
     */
    public function delete(User $user, BchatMessage $message): bool
    {
        return false;
    }
}

