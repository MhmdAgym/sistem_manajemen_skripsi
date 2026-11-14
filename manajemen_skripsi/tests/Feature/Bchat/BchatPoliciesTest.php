<?php

namespace Tests\Feature\Bchat;

use App\Constants\AcademicConstant;
use App\Models\BchatMessage;
use App\Models\BchatRoom;
use App\Models\BchatRoomMember;
use App\Models\Dosen;
use App\Models\Mahasiswa;
use App\Models\PenugasanPembimbing;
use App\Models\Skripsi;
use App\Models\User;
use App\Policies\BchatMessagePolicy;
use App\Policies\BchatRoomPolicy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;

class BchatPoliciesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $activeDb = DB::connection()->getDatabaseName();
        $this->assertEquals(
            'manajemen_skripsi_test',
            $activeDb,
            'CRITICAL SECURITY: Test suite B-CHAT HANYA boleh berjalan pada database manajemen_skripsi_test.'
        );
    }

    // -----------------------------------------------------------------------
    // FIXTURE HELPERS
    // -----------------------------------------------------------------------

    private function buatMahasiswa(?string $nim = null): array
    {
        $nim = $nim ?? (string) rand(1000000000, 9999999999);
        $user = User::factory()->create([
            'role'        => 'mahasiswa',
            'nomor_induk' => $nim,
            'name'        => 'Mahasiswa ' . $nim,
        ]);

        $prodi = 'Informatika';
        $fakultas = AcademicConstant::getFakultasForProdi($prodi) ?? 'Fakultas Sains dan Teknologi';

        $mahasiswa = Mahasiswa::create([
            'user_id'        => $user->id,
            'NIM'            => $nim,
            'nama_mahasiswa' => 'Mahasiswa ' . $nim,
            'prodi'          => $prodi,
            'fakultas'       => $fakultas,
        ]);

        return compact('user', 'mahasiswa');
    }

    private function buatDosen(?string $nidn = null): array
    {
        $nidn = $nidn ?? (string) rand(1000000000, 9999999999);
        $user = User::factory()->create([
            'role'        => 'dosen',
            'nomor_induk' => $nidn,
            'name'        => 'Dosen ' . $nidn,
        ]);

        $dosen = Dosen::create([
            'user_id'    => $user->id,
            'NIDN'       => $nidn,
            'nama_dosen' => 'Dosen ' . $nidn,
        ]);

        return compact('user', 'dosen');
    }

    private function buatSkripsi(Mahasiswa $mahasiswa): Skripsi
    {
        return Skripsi::create([
            'id_mahasiswa' => $mahasiswa->id_mahasiswa,
            'judul'        => 'Judul Skripsi B-CHAT Policy Test ' . uniqid(),
            'status_judul' => 'disetujui',
        ]);
    }

    private function buatPenugasan(
        Skripsi $skripsi,
        Dosen $dosen,
        string $tahap = 'proposal',
        int $pembimbingKe = 1,
        string $status = 'AKTIF'
    ): PenugasanPembimbing {
        return PenugasanPembimbing::create([
            'skripsi_id'       => $skripsi->id_skripsi,
            'tahap'            => $tahap,
            'pembimbing_ke'    => $pembimbingKe,
            'dosen_id'         => $dosen->id_dosen,
            'sk_dokumen_id'    => null,
            'status_penugasan' => $status,
            'tanggal_mulai'    => '2026-11-01',
        ]);
    }

    private function buatRoom(PenugasanPembimbing $penugasan, string $status = 'ACTIVE'): BchatRoom
    {
        return BchatRoom::create([
            'room_uuid'               => Str::uuid()->toString(),
            'penugasan_pembimbing_id' => $penugasan->id,
            'tahap'                   => $penugasan->tahap,
            'pembimbing_ke'           => $penugasan->pembimbing_ke,
            'current_sequence'        => 0,
            'status'                  => $status,
            'current_epoch'           => 0,
            'group_id_hash'           => hash('sha256', Str::uuid()->toString()),
        ]);
    }

    private function tambahAnggota(
        BchatRoom $room,
        User $user,
        string $role = 'mahasiswa',
        bool $isActive = true,
        bool $isInitiator = false
    ): BchatRoomMember {
        return BchatRoomMember::create([
            'room_id'         => $room->id,
            'user_id'         => $user->id,
            'role'            => $role,
            'is_initiator'    => $isInitiator,
            'is_active'       => $isActive,
            'joined_at_epoch' => 0,
            'welcome_ack_at'  => now(),
        ]);
    }

    private function buatPesan(BchatRoom $room, User $sender, int $sequence = 1): BchatMessage
    {
        return BchatMessage::create([
            'room_id'             => $room->id,
            'server_sequence'     => $sequence,
            'message_uuid'        => Str::uuid()->toString(),
            'sender_id'           => $sender->id,
            'epoch'               => 0,
            'generation'          => 1,
            'content_type'        => 1,
            'ciphertext_payload'  => 'mock_ciphertext_bytes',
            'payload_hash_sha256' => hash('sha256', 'mock_hash_' . $sequence),
            'client_sent_at'      => now(),
        ]);
    }

    // -----------------------------------------------------------------------
    // TEST CASES
    // -----------------------------------------------------------------------

    /**
     * Skenario 1: Verifikasi Isolasi Lingkungan Database Testing.
     */
    public function test_database_isolation_environment(): void
    {
        $this->assertEquals('manajemen_skripsi_test', DB::connection()->getDatabaseName());
    }

    /**
     * Skenario 2: Gate Auto-Discovery mengenali BchatRoomPolicy dan BchatMessagePolicy.
     */
    public function test_gate_auto_discovery_resolves_bchat_policies(): void
    {
        $roomPolicy = Gate::getPolicyFor(BchatRoom::class);
        $this->assertNotNull($roomPolicy, 'Gate wajib mengenali Policy untuk BchatRoom.');
        $this->assertEquals(
            BchatRoomPolicy::class,
            is_object($roomPolicy) ? get_class($roomPolicy) : $roomPolicy
        );

        $messagePolicy = Gate::getPolicyFor(BchatMessage::class);
        $this->assertNotNull($messagePolicy, 'Gate wajib mengenali Policy untuk BchatMessage.');
        $this->assertEquals(
            BchatMessagePolicy::class,
            is_object($messagePolicy) ? get_class($messagePolicy) : $messagePolicy
        );
    }

    /**
     * Skenario 3: BchatRoomPolicy::view() - Akses anggota vs non-anggota pada room ACTIVE.
     */
    public function test_bchat_room_policy_view_active_room_and_membership(): void
    {
        ['user' => $mhsUser, 'mahasiswa' => $mhs] = $this->buatMahasiswa();
        ['user' => $dsnUser, 'dosen' => $dsn] = $this->buatDosen();
        ['user' => $outsiderUser] = $this->buatMahasiswa();

        $skripsi = $this->buatSkripsi($mhs);
        $penugasan = $this->buatPenugasan($skripsi, $dsn, 'proposal', 1, 'AKTIF');
        $room = $this->buatRoom($penugasan, 'ACTIVE');

        $this->tambahAnggota($room, $mhsUser, 'mahasiswa', true, true);
        $this->tambahAnggota($room, $dsnUser, 'dosen_pembimbing', true, false);

        // Anggota sah dengan is_active = true dan penugasan AKTIF diizinkan view
        $this->assertTrue(Gate::forUser($mhsUser)->allows('view', $room));
        $this->assertTrue(Gate::forUser($dsnUser)->allows('view', $room));

        // Pengguna luar (bukan anggota) DITOLAK MUTLAK
        $this->assertFalse(Gate::forUser($outsiderUser)->allows('view', $room));

        // Anggota dengan is_active = false pada room ACTIVE DITOLAK
        ['user' => $inactiveUser] = $this->buatMahasiswa();
        $this->tambahAnggota($room, $inactiveUser, 'mahasiswa', false, false);
        $this->assertFalse(Gate::forUser($inactiveUser)->allows('view', $room));
    }

    /**
     * Skenario 4: BchatRoomPolicy::view() - Fase handshake PENDING_WELCOME_ACK & PENDING_REKEYING.
     */
    public function test_bchat_room_policy_view_handshake_phase(): void
    {
        ['user' => $mhsUser, 'mahasiswa' => $mhs] = $this->buatMahasiswa();
        ['user' => $dsnUser, 'dosen' => $dsn] = $this->buatDosen();
        ['user' => $outsiderUser] = $this->buatMahasiswa();

        $skripsi = $this->buatSkripsi($mhs);
        $penugasan = $this->buatPenugasan($skripsi, $dsn, 'proposal', 1, 'AKTIF');

        // Room PENDING_WELCOME_ACK
        $roomPending = $this->buatRoom($penugasan, 'PENDING_WELCOME_ACK');
        $this->tambahAnggota($roomPending, $mhsUser, 'mahasiswa', false, true);

        $this->assertTrue(Gate::forUser($mhsUser)->allows('view', $roomPending));
        $this->assertFalse(Gate::forUser($outsiderUser)->allows('view', $roomPending));

        // Room PENDING_REKEYING
        $penugasanRekey = $this->buatPenugasan($this->buatSkripsi($mhs), $dsn, 'proposal', 1, 'AKTIF');
        $roomRekey = $this->buatRoom($penugasanRekey, 'PENDING_REKEYING');
        $this->tambahAnggota($roomRekey, $mhsUser, 'mahasiswa', true, false);

        $this->assertTrue(Gate::forUser($mhsUser)->allows('view', $roomRekey));
        $this->assertFalse(Gate::forUser($outsiderUser)->allows('view', $roomRekey));
    }

    /**
     * Skenario 5: BchatRoomPolicy::view() - Room ARCHIVED_READONLY mengizinkan pembacaan arsip.
     */
    public function test_bchat_room_policy_view_archived_readonly(): void
    {
        ['user' => $mhsUser, 'mahasiswa' => $mhs] = $this->buatMahasiswa();
        ['user' => $dsnUser, 'dosen' => $dsn] = $this->buatDosen();
        ['user' => $outsiderUser] = $this->buatMahasiswa();

        $skripsi = $this->buatSkripsi($mhs);
        // Penugasan berstatus DIGANTI tetapi room ARCHIVED_READONLY tetap dapat dibaca untuk rekam jejak
        $penugasan = $this->buatPenugasan($skripsi, $dsn, 'proposal', 1, 'DIGANTI');
        $room = $this->buatRoom($penugasan, 'ARCHIVED_READONLY');

        $this->tambahAnggota($room, $mhsUser, 'mahasiswa', false, true);
        $this->tambahAnggota($room, $dsnUser, 'dosen_pembimbing', false, false);

        $this->assertTrue(Gate::forUser($mhsUser)->allows('view', $room));
        $this->assertTrue(Gate::forUser($dsnUser)->allows('view', $room));
        $this->assertFalse(Gate::forUser($outsiderUser)->allows('view', $room));
    }

    /**
     * Skenario 6: BchatRoomPolicy::view() - Ditolak mutlak pada status TERMINATED dan SUSPENDED.
     */
    public function test_bchat_room_policy_view_rejected_on_terminated_and_suspended(): void
    {
        ['user' => $mhsUser, 'mahasiswa' => $mhs] = $this->buatMahasiswa();
        ['user' => $dsnUser, 'dosen' => $dsn] = $this->buatDosen();

        $skripsi = $this->buatSkripsi($mhs);
        $penugasan = $this->buatPenugasan($skripsi, $dsn, 'proposal', 1, 'AKTIF');

        $roomTerminated = $this->buatRoom($penugasan, 'TERMINATED');
        $this->tambahAnggota($roomTerminated, $mhsUser, 'mahasiswa', true, true);

        $this->assertFalse(Gate::forUser($mhsUser)->allows('view', $roomTerminated));

        $penugasanSuspended = $this->buatPenugasan($this->buatSkripsi($mhs), $dsn, 'proposal', 1, 'AKTIF');
        $roomSuspended = $this->buatRoom($penugasanSuspended, 'SUSPENDED');
        $this->tambahAnggota($roomSuspended, $mhsUser, 'mahasiswa', true, true);

        $this->assertFalse(Gate::forUser($mhsUser)->allows('view', $roomSuspended));
    }

    /**
     * Skenario 7: BchatRoomPolicy::view() - Ditolak mutlak ketika penugasan berstatus DIBATALKAN.
     */
    public function test_bchat_room_policy_view_rejected_when_penugasan_dibatalkan(): void
    {
        ['user' => $mhsUser, 'mahasiswa' => $mhs] = $this->buatMahasiswa();
        ['user' => $dsnUser, 'dosen' => $dsn] = $this->buatDosen();

        // ACTIVE room tapi penugasan DIBATALKAN
        $penugasanActive = $this->buatPenugasan($this->buatSkripsi($mhs), $dsn, 'proposal', 1, 'DIBATALKAN');
        $roomActive = $this->buatRoom($penugasanActive, 'ACTIVE');
        $this->tambahAnggota($roomActive, $mhsUser, 'mahasiswa', true, true);
        $this->assertFalse(Gate::forUser($mhsUser)->allows('view', $roomActive));

        // ARCHIVED_READONLY room tapi penugasan DIBATALKAN
        $penugasanArchived = $this->buatPenugasan($this->buatSkripsi($mhs), $dsn, 'proposal', 1, 'DIBATALKAN');
        $roomArchived = $this->buatRoom($penugasanArchived, 'ARCHIVED_READONLY');
        $this->tambahAnggota($roomArchived, $mhsUser, 'mahasiswa', true, true);
        $this->assertFalse(Gate::forUser($mhsUser)->allows('view', $roomArchived));

        // PENDING_WELCOME_ACK room tapi penugasan DIBATALKAN
        $penugasanPending = $this->buatPenugasan($this->buatSkripsi($mhs), $dsn, 'proposal', 1, 'DIBATALKAN');
        $roomPending = $this->buatRoom($penugasanPending, 'PENDING_WELCOME_ACK');
        $this->tambahAnggota($roomPending, $mhsUser, 'mahasiswa', true, true);
        $this->assertFalse(Gate::forUser($mhsUser)->allows('view', $roomPending));
    }

    /**
     * Skenario 8: BchatRoomPolicy::readMessages() - Hanya ACTIVE dan ARCHIVED_READONLY.
     */
    public function test_bchat_room_policy_read_messages_active_and_archived_only(): void
    {
        ['user' => $mhsUser, 'mahasiswa' => $mhs] = $this->buatMahasiswa();
        ['user' => $dsnUser, 'dosen' => $dsn] = $this->buatDosen();
        ['user' => $outsiderUser] = $this->buatMahasiswa();

        $skripsi = $this->buatSkripsi($mhs);
        $penugasan = $this->buatPenugasan($skripsi, $dsn, 'proposal', 1, 'AKTIF');

        // Room ACTIVE
        $roomActive = $this->buatRoom($penugasan, 'ACTIVE');
        $this->tambahAnggota($roomActive, $mhsUser, 'mahasiswa', true, true);
        $this->assertTrue(Gate::forUser($mhsUser)->allows('readMessages', $roomActive));
        $this->assertFalse(Gate::forUser($outsiderUser)->allows('readMessages', $roomActive));

        // Room ACTIVE dengan anggota is_active = false ditolak
        ['user' => $inactiveUser] = $this->buatMahasiswa();
        $this->tambahAnggota($roomActive, $inactiveUser, 'mahasiswa', false, false);
        $this->assertFalse(Gate::forUser($inactiveUser)->allows('readMessages', $roomActive));

        // Room ARCHIVED_READONLY
        $penugasanArchived = $this->buatPenugasan($this->buatSkripsi($mhs), $dsn, 'proposal', 1, 'AKTIF');
        $roomArchived = $this->buatRoom($penugasanArchived, 'ARCHIVED_READONLY');
        $this->tambahAnggota($roomArchived, $mhsUser, 'mahasiswa', false, true);
        $this->assertTrue(Gate::forUser($mhsUser)->allows('readMessages', $roomArchived));
        $this->assertFalse(Gate::forUser($outsiderUser)->allows('readMessages', $roomArchived));
    }

    /**
     * Skenario 9: BchatRoomPolicy::readMessages() - Ditolak mutlak pada seluruh status pending.
     */
    public function test_bchat_room_policy_read_messages_blocked_on_all_pending_statuses(): void
    {
        ['user' => $mhsUser, 'mahasiswa' => $mhs] = $this->buatMahasiswa();
        ['user' => $dsnUser, 'dosen' => $dsn] = $this->buatDosen();

        $pendingStatuses = ['PENDING_WELCOME_ACK', 'PENDING_REKEYING', 'PENDING_OFFICIAL_SK'];

        foreach ($pendingStatuses as $status) {
            $penugasanPending = $this->buatPenugasan($this->buatSkripsi($mhs), $dsn, 'proposal', 1, 'AKTIF');
            $room = $this->buatRoom($penugasanPending, $status);
            $this->tambahAnggota($room, $mhsUser, 'mahasiswa', true, true);

            $this->assertFalse(
                Gate::forUser($mhsUser)->allows('readMessages', $room),
                "Pembacaan pesan WAJIB ditolak saat room berstatus {$status}."
            );
        }
    }

    /**
     * Skenario 10: BchatRoomPolicy::readMessages() - Ditolak pada TERMINATED, SUSPENDED, dan DIBATALKAN.
     */
    public function test_bchat_room_policy_read_messages_blocked_on_terminated_suspended_and_dibatalkan(): void
    {
        ['user' => $mhsUser, 'mahasiswa' => $mhs] = $this->buatMahasiswa();
        ['user' => $dsnUser, 'dosen' => $dsn] = $this->buatDosen();

        $skripsi = $this->buatSkripsi($mhs);
        $penugasanAktif = $this->buatPenugasan($skripsi, $dsn, 'proposal', 1, 'AKTIF');

        $roomTerminated = $this->buatRoom($penugasanAktif, 'TERMINATED');
        $this->tambahAnggota($roomTerminated, $mhsUser, 'mahasiswa', true, true);
        $this->assertFalse(Gate::forUser($mhsUser)->allows('readMessages', $roomTerminated));

        $penugasanSuspended = $this->buatPenugasan($this->buatSkripsi($mhs), $dsn, 'proposal', 1, 'AKTIF');
        $roomSuspended = $this->buatRoom($penugasanSuspended, 'SUSPENDED');
        $this->tambahAnggota($roomSuspended, $mhsUser, 'mahasiswa', true, true);
        $this->assertFalse(Gate::forUser($mhsUser)->allows('readMessages', $roomSuspended));

        $penugasanDibatalkan = $this->buatPenugasan($this->buatSkripsi($mhs), $dsn, 'proposal', 1, 'DIBATALKAN');
        $roomDibatalkan = $this->buatRoom($penugasanDibatalkan, 'ACTIVE');
        $this->tambahAnggota($roomDibatalkan, $mhsUser, 'mahasiswa', true, true);
        $this->assertFalse(Gate::forUser($mhsUser)->allows('readMessages', $roomDibatalkan));
    }

    /**
     * Skenario 11: BchatRoomPolicy::sendMessage() - Invarian ketat pengiriman pesan.
     */
    public function test_bchat_room_policy_send_message_strict_active_only(): void
    {
        ['user' => $mhsUser, 'mahasiswa' => $mhs] = $this->buatMahasiswa();
        ['user' => $dsnUser, 'dosen' => $dsn] = $this->buatDosen();
        ['user' => $outsiderUser] = $this->buatMahasiswa();

        $skripsi = $this->buatSkripsi($mhs);
        $penugasan = $this->buatPenugasan($skripsi, $dsn, 'proposal', 1, 'AKTIF');

        $roomActive = $this->buatRoom($penugasan, 'ACTIVE');
        $this->tambahAnggota($roomActive, $mhsUser, 'mahasiswa', true, true);

        // Hanya diizinkan pada ACTIVE dengan is_active = true dan penugasan AKTIF
        $this->assertTrue(Gate::forUser($mhsUser)->allows('sendMessage', $roomActive));

        // Anggota non-aktif DITOLAK
        ['user' => $inactiveUser] = $this->buatMahasiswa();
        $this->tambahAnggota($roomActive, $inactiveUser, 'mahasiswa', false, false);
        $this->assertFalse(Gate::forUser($inactiveUser)->allows('sendMessage', $roomActive));

        // Pengguna luar DITOLAK
        $this->assertFalse(Gate::forUser($outsiderUser)->allows('sendMessage', $roomActive));

        // ARCHIVED_READONLY DITOLAK
        $penugasanArchived = $this->buatPenugasan($this->buatSkripsi($mhs), $dsn, 'proposal', 1, 'AKTIF');
        $roomArchived = $this->buatRoom($penugasanArchived, 'ARCHIVED_READONLY');
        $this->tambahAnggota($roomArchived, $mhsUser, 'mahasiswa', true, true);
        $this->assertFalse(Gate::forUser($mhsUser)->allows('sendMessage', $roomArchived));

        // PENDING_WELCOME_ACK DITOLAK
        $penugasanPending = $this->buatPenugasan($this->buatSkripsi($mhs), $dsn, 'proposal', 1, 'AKTIF');
        $roomPending = $this->buatRoom($penugasanPending, 'PENDING_WELCOME_ACK');
        $this->tambahAnggota($roomPending, $mhsUser, 'mahasiswa', true, true);
        $this->assertFalse(Gate::forUser($mhsUser)->allows('sendMessage', $roomPending));

        // PENDING_REKEYING DITOLAK
        $penugasanRekey = $this->buatPenugasan($this->buatSkripsi($mhs), $dsn, 'proposal', 1, 'AKTIF');
        $roomRekey = $this->buatRoom($penugasanRekey, 'PENDING_REKEYING');
        $this->tambahAnggota($roomRekey, $mhsUser, 'mahasiswa', true, true);
        $this->assertFalse(Gate::forUser($mhsUser)->allows('sendMessage', $roomRekey));

        // SUSPENDED & TERMINATED DITOLAK
        $penugasanSuspended = $this->buatPenugasan($this->buatSkripsi($mhs), $dsn, 'proposal', 1, 'AKTIF');
        $roomSuspended = $this->buatRoom($penugasanSuspended, 'SUSPENDED');
        $this->tambahAnggota($roomSuspended, $mhsUser, 'mahasiswa', true, true);
        $this->assertFalse(Gate::forUser($mhsUser)->allows('sendMessage', $roomSuspended));

        $penugasanTerminated = $this->buatPenugasan($this->buatSkripsi($mhs), $dsn, 'proposal', 1, 'AKTIF');
        $roomTerminated = $this->buatRoom($penugasanTerminated, 'TERMINATED');
        $this->tambahAnggota($roomTerminated, $mhsUser, 'mahasiswa', true, true);
        $this->assertFalse(Gate::forUser($mhsUser)->allows('sendMessage', $roomTerminated));

        // Penugasan DIBATALKAN DITOLAK
        $penugasanBatal = $this->buatPenugasan($this->buatSkripsi($mhs), $dsn, 'proposal', 1, 'DIBATALKAN');
        $roomBatal = $this->buatRoom($penugasanBatal, 'ACTIVE');
        $this->tambahAnggota($roomBatal, $mhsUser, 'mahasiswa', true, true);
        $this->assertFalse(Gate::forUser($mhsUser)->allows('sendMessage', $roomBatal));
    }

    /**
     * Skenario 12: BchatRoomPolicy::handshake() - Hak koordinasi Welcome/ACK dan Rekeying.
     */
    public function test_bchat_room_policy_handshake(): void
    {
        ['user' => $mhsUser, 'mahasiswa' => $mhs] = $this->buatMahasiswa();
        ['user' => $dsnUser, 'dosen' => $dsn] = $this->buatDosen();
        ['user' => $outsiderUser] = $this->buatMahasiswa();

        // PENDING_WELCOME_ACK: diizinkan bagi anggota terdaftar
        $penugasanPending = $this->buatPenugasan($this->buatSkripsi($mhs), $dsn, 'proposal', 1, 'AKTIF');
        $roomPending = $this->buatRoom($penugasanPending, 'PENDING_WELCOME_ACK');
        $this->tambahAnggota($roomPending, $mhsUser, 'mahasiswa', false, true);

        $this->assertTrue(Gate::forUser($mhsUser)->allows('handshake', $roomPending));
        $this->assertFalse(Gate::forUser($outsiderUser)->allows('handshake', $roomPending));

        // PENDING_REKEYING: diizinkan bagi anggota terdaftar
        $penugasanRekey = $this->buatPenugasan($this->buatSkripsi($mhs), $dsn, 'proposal', 1, 'AKTIF');
        $roomRekey = $this->buatRoom($penugasanRekey, 'PENDING_REKEYING');
        $this->tambahAnggota($roomRekey, $mhsUser, 'mahasiswa', true, false);

        $this->assertTrue(Gate::forUser($mhsUser)->allows('handshake', $roomRekey));

        // ACTIVE dan ARCHIVED_READONLY: DITOLAK
        $penugasanActive = $this->buatPenugasan($this->buatSkripsi($mhs), $dsn, 'proposal', 1, 'AKTIF');
        $roomActive = $this->buatRoom($penugasanActive, 'ACTIVE');
        $this->tambahAnggota($roomActive, $mhsUser, 'mahasiswa', true, true);
        $this->assertFalse(Gate::forUser($mhsUser)->allows('handshake', $roomActive));

        $penugasanArchived = $this->buatPenugasan($this->buatSkripsi($mhs), $dsn, 'proposal', 1, 'AKTIF');
        $roomArchived = $this->buatRoom($penugasanArchived, 'ARCHIVED_READONLY');
        $this->tambahAnggota($roomArchived, $mhsUser, 'mahasiswa', true, true);
        $this->assertFalse(Gate::forUser($mhsUser)->allows('handshake', $roomArchived));

        // Penugasan DIBATALKAN: DITOLAK
        $penugasanBatal = $this->buatPenugasan($this->buatSkripsi($mhs), $dsn, 'proposal', 1, 'DIBATALKAN');
        $roomPendingBatal = $this->buatRoom($penugasanBatal, 'PENDING_WELCOME_ACK');
        $this->tambahAnggota($roomPendingBatal, $mhsUser, 'mahasiswa', true, true);
        $this->assertFalse(Gate::forUser($mhsUser)->allows('handshake', $roomPendingBatal));
    }

    /**
     * Skenario 13: BchatRoomPolicy::delete() - Selalu false tanpa pengecualian.
     */
    public function test_bchat_room_policy_delete_always_false(): void
    {
        ['user' => $mhsUser, 'mahasiswa' => $mhs] = $this->buatMahasiswa();
        ['user' => $dsnUser, 'dosen' => $dsn] = $this->buatDosen();

        $adminUser = User::factory()->create(['role' => 'admin']);

        $skripsi = $this->buatSkripsi($mhs);
        $penugasan = $this->buatPenugasan($skripsi, $dsn, 'proposal', 1, 'AKTIF');
        $room = $this->buatRoom($penugasan, 'ACTIVE');

        $this->tambahAnggota($room, $mhsUser, 'mahasiswa', true, true);

        $policy = new BchatRoomPolicy();

        $this->assertFalse(Gate::forUser($mhsUser)->allows('delete', $room));
        $this->assertFalse(Gate::forUser($dsnUser)->allows('delete', $room));
        $this->assertFalse(Gate::forUser($adminUser)->allows('delete', $room));
        $this->assertFalse($policy->delete($mhsUser, $room));
    }

    /**
     * Skenario 14: BchatMessagePolicy::viewAny() - Delegasi ke readMessages().
     */
    public function test_bchat_message_policy_view_any_delegation(): void
    {
        ['user' => $mhsUser, 'mahasiswa' => $mhs] = $this->buatMahasiswa();
        ['user' => $dsnUser, 'dosen' => $dsn] = $this->buatDosen();
        ['user' => $outsiderUser] = $this->buatMahasiswa();

        $penugasanActive = $this->buatPenugasan($this->buatSkripsi($mhs), $dsn, 'proposal', 1, 'AKTIF');
        $roomActive = $this->buatRoom($penugasanActive, 'ACTIVE');
        $this->tambahAnggota($roomActive, $mhsUser, 'mahasiswa', true, true);

        $penugasanArchived = $this->buatPenugasan($this->buatSkripsi($mhs), $dsn, 'proposal', 1, 'AKTIF');
        $roomArchived = $this->buatRoom($penugasanArchived, 'ARCHIVED_READONLY');
        $this->tambahAnggota($roomArchived, $mhsUser, 'mahasiswa', true, true);

        $penugasanPending = $this->buatPenugasan($this->buatSkripsi($mhs), $dsn, 'proposal', 1, 'AKTIF');
        $roomPending = $this->buatRoom($penugasanPending, 'PENDING_WELCOME_ACK');
        $this->tambahAnggota($roomPending, $mhsUser, 'mahasiswa', true, true);

        // Via Gate
        $this->assertTrue(Gate::forUser($mhsUser)->allows('viewAny', [BchatMessage::class, $roomActive]));
        $this->assertTrue(Gate::forUser($mhsUser)->allows('viewAny', [BchatMessage::class, $roomArchived]));
        $this->assertFalse(Gate::forUser($mhsUser)->allows('viewAny', [BchatMessage::class, $roomPending]));
        $this->assertFalse(Gate::forUser($outsiderUser)->allows('viewAny', [BchatMessage::class, $roomActive]));

        // Direct policy invocation
        $msgPolicy = new BchatMessagePolicy();
        $this->assertTrue($msgPolicy->viewAny($mhsUser, $roomActive));
        $this->assertTrue($msgPolicy->viewAny($mhsUser, $roomArchived));
        $this->assertFalse($msgPolicy->viewAny($mhsUser, $roomPending));
        $this->assertFalse($msgPolicy->viewAny($outsiderUser, $roomActive));
    }

    /**
     * Skenario 15: BchatMessagePolicy::view() - Delegasi ke readMessages() via $message->room.
     */
    public function test_bchat_message_policy_view_delegation(): void
    {
        ['user' => $mhsUser, 'mahasiswa' => $mhs] = $this->buatMahasiswa();
        ['user' => $dsnUser, 'dosen' => $dsn] = $this->buatDosen();
        ['user' => $outsiderUser] = $this->buatMahasiswa();

        // Pesan pada room ACTIVE
        $penugasanActive = $this->buatPenugasan($this->buatSkripsi($mhs), $dsn, 'proposal', 1, 'AKTIF');
        $roomActive = $this->buatRoom($penugasanActive, 'ACTIVE');
        $this->tambahAnggota($roomActive, $mhsUser, 'mahasiswa', true, true);
        $messageActive = $this->buatPesan($roomActive, $mhsUser, 1);

        // Pesan pada room ARCHIVED_READONLY
        $penugasanArchived = $this->buatPenugasan($this->buatSkripsi($mhs), $dsn, 'proposal', 1, 'AKTIF');
        $roomArchived = $this->buatRoom($penugasanArchived, 'ARCHIVED_READONLY');
        $this->tambahAnggota($roomArchived, $mhsUser, 'mahasiswa', true, true);
        $messageArchived = $this->buatPesan($roomArchived, $mhsUser, 1);

        // Pesan pada room SUSPENDED
        $penugasanSuspended = $this->buatPenugasan($this->buatSkripsi($mhs), $dsn, 'proposal', 1, 'AKTIF');
        $roomSuspended = $this->buatRoom($penugasanSuspended, 'SUSPENDED');
        $this->tambahAnggota($roomSuspended, $mhsUser, 'mahasiswa', true, true);
        $messageSuspended = $this->buatPesan($roomSuspended, $mhsUser, 1);

        // Via Gate
        $this->assertTrue(Gate::forUser($mhsUser)->allows('view', $messageActive));
        $this->assertTrue(Gate::forUser($mhsUser)->allows('view', $messageArchived));
        $this->assertFalse(Gate::forUser($mhsUser)->allows('view', $messageSuspended));
        $this->assertFalse(Gate::forUser($outsiderUser)->allows('view', $messageActive));

        // Direct policy invocation
        $msgPolicy = new BchatMessagePolicy();
        $this->assertTrue($msgPolicy->view($mhsUser, $messageActive));
        $this->assertTrue($msgPolicy->view($mhsUser, $messageArchived));
        $this->assertFalse($msgPolicy->view($mhsUser, $messageSuspended));
        $this->assertFalse($msgPolicy->view($outsiderUser, $messageActive));
    }

    /**
     * Skenario 16: BchatMessagePolicy::create() - Delegasi ke sendMessage().
     */
    public function test_bchat_message_policy_create_delegation(): void
    {
        ['user' => $mhsUser, 'mahasiswa' => $mhs] = $this->buatMahasiswa();
        ['user' => $dsnUser, 'dosen' => $dsn] = $this->buatDosen();
        ['user' => $outsiderUser] = $this->buatMahasiswa();

        $penugasanActive = $this->buatPenugasan($this->buatSkripsi($mhs), $dsn, 'proposal', 1, 'AKTIF');
        $roomActive = $this->buatRoom($penugasanActive, 'ACTIVE');
        $this->tambahAnggota($roomActive, $mhsUser, 'mahasiswa', true, true);

        $penugasanArchived = $this->buatPenugasan($this->buatSkripsi($mhs), $dsn, 'proposal', 1, 'AKTIF');
        $roomArchived = $this->buatRoom($penugasanArchived, 'ARCHIVED_READONLY');
        $this->tambahAnggota($roomArchived, $mhsUser, 'mahasiswa', true, true);

        // Via Gate
        $this->assertTrue(Gate::forUser($mhsUser)->allows('create', [BchatMessage::class, $roomActive]));
        $this->assertFalse(Gate::forUser($mhsUser)->allows('create', [BchatMessage::class, $roomArchived]));
        $this->assertFalse(Gate::forUser($outsiderUser)->allows('create', [BchatMessage::class, $roomActive]));

        // Direct policy invocation
        $msgPolicy = new BchatMessagePolicy();
        $this->assertTrue($msgPolicy->create($mhsUser, $roomActive));
        $this->assertFalse($msgPolicy->create($mhsUser, $roomArchived));
        $this->assertFalse($msgPolicy->create($outsiderUser, $roomActive));
    }

    /**
     * Skenario 17: BchatMessagePolicy::update() & delete() - Selalu false (Immutabilitas Pesan E2EE).
     */
    public function test_bchat_message_policy_update_and_delete_always_false(): void
    {
        ['user' => $mhsUser, 'mahasiswa' => $mhs] = $this->buatMahasiswa();
        ['user' => $dsnUser, 'dosen' => $dsn] = $this->buatDosen();

        $adminUser = User::factory()->create(['role' => 'admin']);

        $skripsi = $this->buatSkripsi($mhs);
        $penugasan = $this->buatPenugasan($skripsi, $dsn, 'proposal', 1, 'AKTIF');
        $room = $this->buatRoom($penugasan, 'ACTIVE');

        $this->tambahAnggota($room, $mhsUser, 'mahasiswa', true, true);
        $message = $this->buatPesan($room, $mhsUser, 1);

        $msgPolicy = new BchatMessagePolicy();

        // Verifikasi update() selalu false bagi siapapun (termasuk pengirim asli & admin)
        $this->assertFalse(Gate::forUser($mhsUser)->allows('update', $message));
        $this->assertFalse(Gate::forUser($dsnUser)->allows('update', $message));
        $this->assertFalse(Gate::forUser($adminUser)->allows('update', $message));
        $this->assertFalse($msgPolicy->update($mhsUser, $message));

        // Verifikasi delete() selalu false bagi siapapun
        $this->assertFalse(Gate::forUser($mhsUser)->allows('delete', $message));
        $this->assertFalse(Gate::forUser($dsnUser)->allows('delete', $message));
        $this->assertFalse(Gate::forUser($adminUser)->allows('delete', $message));
        $this->assertFalse($msgPolicy->delete($mhsUser, $message));
    }
}

