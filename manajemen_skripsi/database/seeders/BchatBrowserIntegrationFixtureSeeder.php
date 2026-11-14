<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BchatRoom;
use App\Models\BchatRoomMember;
use App\Models\Dosen;
use App\Models\Mahasiswa;
use App\Models\PenugasanPembimbing;
use App\Models\Skripsi;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * BchatBrowserIntegrationFixtureSeeder
 *
 * Seeder idempoten terisolasi khusus pengujian integrasi peramban (Browser E2E)
 * untuk protokol B-CHAT I-01 s.d. I-04.
 *
 * Mempersiapkan satu penugasan pembimbing tahap 'proposal' (status AKTIF)
 * serta satu BchatRoom kanonis 'PENDING_WELCOME_ACK' tanpa Welcome Challenge aktif
 * antara Mahasiswa (Inisiator) dan Dosen Pembimbing (Non-Inisiator).
 *
 * DILARANG KERAS DIJALANKAN DI LUAR DATABASE 'manajemen_skripsi_test'.
 */
class BchatBrowserIntegrationFixtureSeeder extends Seeder
{
    /**
     * Identitas UUID deterministik stabil untuk room pengujian integrasi B-CHAT.
     */
    public const FIXTURE_ROOM_UUID = 'b0010000-0000-4000-8000-000000000001';

    /**
     * Email akun acuan dari TestEnvironmentSeeder.
     */
    public const MAHASISWA_EMAIL = 'unuha_mhs_if_test@unuha.ac.id';
    public const DOSEN_EMAIL = 'unuha_dosen_biasa_test@unuha.ac.id';

    /**
     * Jalankan seeder idempoten dengan 4-Tier Preflight Guard & Transaksi Atomik.
     *
     * @throws RuntimeException Jika terjadi pelanggaran lingkungan, konfigurasi, atau konflik integritas data.
     */
    public function run(): void
    {
        // =====================================================================
        // PREFLIGHT TIER 1: Validasi Status Lingkungan Framework
        // =====================================================================
        $isTesting = app()->environment('testing') || defined('STRICT_TEST_DATABASE_ISOLATION');
        if (!$isTesting) {
            throw new RuntimeException(
                'SECURITY VIOLATION: BchatBrowserIntegrationFixtureSeeder can ONLY be executed in testing environment.'
            );
        }

        // =====================================================================
        // PREFLIGHT TIER 2: Validasi Konfigurasi Koneksi Database Klien
        // =====================================================================
        $conn = DB::connection();
        $dbName = (string) $conn->getConfig('database');
        $dbUser = (string) $conn->getConfig('username');
        $dbHost = (string) $conn->getConfig('host');

        if ($dbName !== 'manajemen_skripsi_test') {
            throw new RuntimeException(
                "SECURITY VIOLATION: Seeder configured database is [{$dbName}]. Only 'manajemen_skripsi_test' is permitted."
            );
        }

        if ($dbUser !== 'unuha_tester') {
            throw new RuntimeException(
                "SECURITY VIOLATION: Seeder configured database user is [{$dbUser}]. Only 'unuha_tester' is permitted."
            );
        }

        $allowedHosts = ['127.0.0.1', 'localhost'];
        if (!in_array($dbHost, $allowedHosts, true)) {
            throw new RuntimeException(
                "SECURITY VIOLATION: Seeder configured database host is [{$dbHost}]. Only loopback host is permitted."
            );
        }

        // =====================================================================
        // PREFLIGHT TIER 3: Probe Sesi Aktif MySQL Server Riil (Fail-Closed)
        // =====================================================================
        try {
            $probe = $conn->selectOne('SELECT DATABASE() as active_db, CURRENT_USER() as auth_user');
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'SECURITY VIOLATION: Failed to execute server session probe: ' . $e->getMessage()
            );
        }

        if (!$probe || empty($probe->active_db) || empty($probe->auth_user)) {
            throw new RuntimeException(
                'SECURITY VIOLATION: Server session probe returned empty or invalid results.'
            );
        }

        if ($probe->active_db !== 'manajemen_skripsi_test') {
            throw new RuntimeException(
                "SECURITY VIOLATION: Active server database is [{$probe->active_db}]. Refusing mutation."
            );
        }

        if ($probe->active_db === 'manajemen_skripsi') {
            throw new RuntimeException(
                'FATAL SECURITY VIOLATION: Attempted mutation on operational database [manajemen_skripsi]. Aborting.'
            );
        }

        $allowedAuthUsers = ['unuha_tester@localhost', 'unuha_tester@127.0.0.1'];
        if (!in_array($probe->auth_user, $allowedAuthUsers, true)) {
            throw new RuntimeException(
                "SECURITY VIOLATION: Authenticated server user is [{$probe->auth_user}]. Refusing mutation."
            );
        }

        // =====================================================================
        // PREFLIGHT TIER 4: Verifikasi Ketersediaan & Integritas Akun Induk
        // (Berjalan murni secara read-only sebelum operasi mutasi DML)
        // =====================================================================

        // 1. Verifikasi Akun Mahasiswa
        $mhsUser = User::where('email', self::MAHASISWA_EMAIL)->first();
        if (!$mhsUser) {
            throw new RuntimeException(
                'PRECONDITION FAILED: Mahasiswa user [' . self::MAHASISWA_EMAIL . '] not found. Run TestEnvironmentSeeder first.'
            );
        }

        if ($mhsUser->role !== 'mahasiswa') {
            throw new RuntimeException(
                "PRECONDITION FAILED: User [" . self::MAHASISWA_EMAIL . "] has invalid role [{$mhsUser->role}]. Expected 'mahasiswa'."
            );
        }

        $mahasiswa = Mahasiswa::where('user_id', $mhsUser->id)->first();
        if (!$mahasiswa) {
            throw new RuntimeException(
                "PRECONDITION FAILED: Mahasiswa profile for user ID [{$mhsUser->id}] not found."
            );
        }

        $skripsi = Skripsi::where('id_mahasiswa', $mahasiswa->id_mahasiswa)->first();
        if (!$skripsi) {
            throw new RuntimeException(
                "PRECONDITION FAILED: Skripsi record for mahasiswa ID [{$mahasiswa->id_mahasiswa}] not found."
            );
        }

        // 2. Verifikasi Akun Dosen
        $dosenUser = User::where('email', self::DOSEN_EMAIL)->first();
        if (!$dosenUser) {
            throw new RuntimeException(
                'PRECONDITION FAILED: Dosen user [' . self::DOSEN_EMAIL . '] not found. Run TestEnvironmentSeeder first.'
            );
        }

        if ($dosenUser->role !== 'dosen') {
            throw new RuntimeException(
                "PRECONDITION FAILED: User [" . self::DOSEN_EMAIL . "] has invalid role [{$dosenUser->role}]. Expected 'dosen'."
            );
        }

        $dosen = Dosen::where('user_id', $dosenUser->id)->first();
        if (!$dosen) {
            throw new RuntimeException(
                "PRECONDITION FAILED: Dosen profile for user ID [{$dosenUser->id}] not found."
            );
        }

        // =====================================================================
        // MUTASI TRANSAKSIONAL ATOMIK (IDEMPOTEN)
        // =====================================================================
        DB::transaction(function () use ($skripsi, $dosen, $mhsUser, $dosenUser) {
            // 1. Manajemen Penugasan Pembimbing (Tahap: Proposal, Pembimbing Ke: 1)
            $existingPenugasan = PenugasanPembimbing::where('skripsi_id', $skripsi->id_skripsi)
                ->where('tahap', 'proposal')
                ->where('pembimbing_ke', 1)
                ->where('status_penugasan', 'AKTIF')
                ->first();

            if ($existingPenugasan) {
                if ((int) $existingPenugasan->dosen_id !== (int) $dosen->id_dosen) {
                    throw new RuntimeException(
                        "CONFLICT: Active proposal assignment for skripsi [{$skripsi->id_skripsi}] already assigned to another dosen [{$existingPenugasan->dosen_id}]."
                    );
                }
                $penugasan = $existingPenugasan;
            } else {
                $penugasan = PenugasanPembimbing::create([
                    'skripsi_id'          => $skripsi->id_skripsi,
                    'tahap'               => 'proposal',
                    'pembimbing_ke'       => 1,
                    'dosen_id'            => $dosen->id_dosen,
                    'status_penugasan'    => 'AKTIF',
                    'tanggal_mulai'       => now()->toDateString(),
                    'sk_dokumen_id'       => null,
                    'pengajuan_dospem_id' => null,
                    'digantikan_oleh_id'  => null,
                ]);
            }

            // 2. Manajemen BchatRoom Kanonis
            // Periksa apakah sudah ada room untuk penugasan pembimbing ini (1:1 unik)
            $existingRoomByPenugasan = BchatRoom::where('penugasan_pembimbing_id', $penugasan->id)->first();
            $existingRoomByUuid = BchatRoom::where('room_uuid', self::FIXTURE_ROOM_UUID)->first();

            if ($existingRoomByUuid && $existingRoomByPenugasan && $existingRoomByUuid->id !== $existingRoomByPenugasan->id) {
                throw new RuntimeException(
                    "CONFLICT: Fixture room UUID collision with different penugasan ID [{$existingRoomByUuid->penugasan_pembimbing_id}]."
                );
            }

            if ($existingRoomByPenugasan) {
                $room = $existingRoomByPenugasan;
                // Rekonsiliasi konservatif status room jika bukan ACTIVE
                if ($room->status !== 'ACTIVE' && $room->status !== 'PENDING_WELCOME_ACK') {
                    $room->status = 'PENDING_WELCOME_ACK';
                    $room->save();
                }
            } elseif ($existingRoomByUuid) {
                if ((int) $existingRoomByUuid->penugasan_pembimbing_id !== (int) $penugasan->id) {
                    throw new RuntimeException(
                        "CONFLICT: Fixture room UUID [" . self::FIXTURE_ROOM_UUID . "] is bound to unexpected penugasan ID [{$existingRoomByUuid->penugasan_pembimbing_id}]."
                    );
                }
                $room = $existingRoomByUuid;
            } else {
                $room = BchatRoom::create([
                    'room_uuid'               => self::FIXTURE_ROOM_UUID,
                    'penugasan_pembimbing_id' => $penugasan->id,
                    'tahap'                   => 'proposal',
                    'pembimbing_ke'           => 1,
                    'current_sequence'        => 0,
                    'status'                  => 'PENDING_WELCOME_ACK',
                    'current_epoch'           => 0,
                    'group_id_hash'           => null,
                ]);
            }

            // 3. Validasi Tidak Ada Welcome Challenge Aktif (Fail-Closed)
            // Syarat mutlak agar backend menghasilkan action_hint.action_type = 'PUBLISH_WELCOME'.
            // Jika terdapat challenge aktif, hentikan proses dengan exception tanpa mengubah/menghapus record.
            $hasActiveChallenge = DB::table('bchat_welcome_challenges')
                ->where('room_id', $room->id)
                ->where('operation_status', 'ACTIVE')
                ->exists();

            if ($hasActiveChallenge) {
                throw new RuntimeException(
                    "CONFLICT: Room [" . self::FIXTURE_ROOM_UUID . "] has an active Welcome Challenge. Fixture requires a clean room without active challenge. Refusing to delete or overwrite existing challenge."
                );
            }

            // 4. Manajemen Keanggotaan BchatRoomMember (Tepat 2 anggota pairwise)
            // A. Periksa integritas anggota asing pada room fixture
            $foreignMember = BchatRoomMember::where('room_id', $room->id)
                ->whereNotIn('user_id', [$mhsUser->id, $dosenUser->id])
                ->first();
            if ($foreignMember) {
                throw new RuntimeException(
                    "CONFLICT: Room [{$room->id}] contains unauthorized foreign member with user ID [{$foreignMember->user_id}]."
                );
            }

            // B. Anggota Mahasiswa (Inisiator)
            $existingMhsMember = BchatRoomMember::where('room_id', $room->id)
                ->where('user_id', $mhsUser->id)
                ->first();

            if ($existingMhsMember) {
                $mhsRoleValid = $existingMhsMember->role === 'mahasiswa';
                $mhsInitiatorValid = (bool) $existingMhsMember->is_initiator === true;
                $mhsActiveValid = (bool) $existingMhsMember->is_active === false;
                $mhsEpochValid = (int) $existingMhsMember->joined_at_epoch === 0;
                $mhsAckValid = $existingMhsMember->welcome_ack_at === null;

                if (!$mhsRoleValid || !$mhsInitiatorValid || !$mhsActiveValid || !$mhsEpochValid || !$mhsAckValid) {
                    throw new RuntimeException(
                        "CONFLICT: Existing mahasiswa membership in room [{$room->id}] has conflicting attributes (role: {$existingMhsMember->role}, is_initiator: " . var_export($existingMhsMember->is_initiator, true) . ", is_active: " . var_export($existingMhsMember->is_active, true) . ", joined_at_epoch: {$existingMhsMember->joined_at_epoch}). Refusing to overwrite."
                    );
                }
                // Atribut identik; pertahankan tanpa pembaruan
            } else {
                BchatRoomMember::create([
                    'room_id'         => $room->id,
                    'user_id'         => $mhsUser->id,
                    'role'            => 'mahasiswa',
                    'is_initiator'    => true,
                    'is_active'       => false,
                    'joined_at_epoch' => 0,
                    'welcome_ack_at'  => null,
                ]);
            }

            // C. Anggota Dosen Pembimbing (Non-Inisiator / Penerima Welcome)
            $existingDosMember = BchatRoomMember::where('room_id', $room->id)
                ->where('user_id', $dosenUser->id)
                ->first();

            if ($existingDosMember) {
                $dosRoleValid = $existingDosMember->role === 'dosen_pembimbing';
                $dosInitiatorValid = (bool) $existingDosMember->is_initiator === false;
                $dosActiveValid = (bool) $existingDosMember->is_active === false;
                $dosEpochValid = (int) $existingDosMember->joined_at_epoch === 0;
                $dosAckValid = $existingDosMember->welcome_ack_at === null;

                if (!$dosRoleValid || !$dosInitiatorValid || !$dosActiveValid || !$dosEpochValid || !$dosAckValid) {
                    throw new RuntimeException(
                        "CONFLICT: Existing dosen membership in room [{$room->id}] has conflicting attributes (role: {$existingDosMember->role}, is_initiator: " . var_export($existingDosMember->is_initiator, true) . ", is_active: " . var_export($existingDosMember->is_active, true) . ", joined_at_epoch: {$existingDosMember->joined_at_epoch}). Refusing to overwrite."
                    );
                }
                // Atribut identik; pertahankan tanpa pembaruan
            } else {
                BchatRoomMember::create([
                    'room_id'         => $room->id,
                    'user_id'         => $dosenUser->id,
                    'role'            => 'dosen_pembimbing',
                    'is_initiator'    => false,
                    'is_active'       => false,
                    'joined_at_epoch' => 0,
                    'welcome_ack_at'  => null,
                ]);
            }
        });
    }
}

