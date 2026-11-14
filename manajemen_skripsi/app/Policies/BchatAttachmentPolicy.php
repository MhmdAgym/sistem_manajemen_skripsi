<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BchatAttachment;
use App\Models\BchatRoom;
use App\Models\User;

class BchatAttachmentPolicy
{
    /**
     * Menentukan apakah pengguna dapat melihat daftar lampiran pada suatu room.
     * Menggunakan aturan readMessages dari BchatRoomPolicy:
     * - HANYA diizinkan pada room berstatus 'ACTIVE' dan 'ARCHIVED_READONLY'.
     * - Pengguna bukan anggota room DITOLAK MUTLAK.
     * - Admin yang bukan anggota room DITOLAK MUTLAK.
     */
    public function viewAny(User $user, BchatRoom $room): bool
    {
        return (new BchatRoomPolicy())->readMessages($user, $room);
    }

    /**
     * Menentukan apakah pengguna dapat melihat metadata lampiran ciphertext.
     * Terikat ketat pada keanggotaan pengguna di dalam room pemilik lampiran.
     */
    public function view(User $user, BchatAttachment $attachment): bool
    {
        $room = $attachment->room;
        if (!$room) {
            return false;
        }

        return (new BchatRoomPolicy())->readMessages($user, $room);
    }

    /**
     * Menentukan apakah pengguna dapat mengunduh ciphertext lampiran.
     * Hak unduh ciphertext hanya dimiliki oleh anggota sah yang berhak membaca pesan.
     * Admin yang bukan anggota DITOLAK MUTLAK (Zero Plaintext Access).
     */
    public function download(User $user, BchatAttachment $attachment): bool
    {
        return $this->view($user, $attachment);
    }

    /**
     * Menentukan apakah pengguna dapat mengunggah lampiran baru ke dalam room.
     * Menggunakan aturan sendMessage dari BchatRoomPolicy:
     * - Room wajib berstatus 'ACTIVE'.
     * - Penugasan pembimbing wajib 'AKTIF'.
     * - Pengunggah wajib anggota room dan memiliki is_active = TRUE.
     * - Room berstatus 'ARCHIVED_READONLY', handshake, suspended, dll. DITOLAK MUTLAK.
     */
    public function upload(User $user, BchatRoom $room): bool
    {
        return (new BchatRoomPolicy())->sendMessage($user, $room);
    }

    /**
     * Pembaruan lampiran DITOLAK MUTLAK (lampiran E2EE bersifat immutable setelah terbit).
     */
    public function update(User $user, BchatAttachment $attachment): bool
    {
        return false;
    }

    /**
     * Penghapusan fisik lampiran DITOLAK MUTLAK untuk menjaga integritas rekam jejak akademik.
     */
    public function delete(User $user, BchatAttachment $attachment): bool
    {
        return false;
    }
}

