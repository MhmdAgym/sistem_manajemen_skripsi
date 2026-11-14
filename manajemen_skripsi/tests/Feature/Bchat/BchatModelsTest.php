<?php

namespace Tests\Feature\Bchat;

use App\Constants\AcademicConstant;
use App\Models\BchatKeyPackage;
use App\Models\BchatMessage;
use App\Models\BchatMessageReceipt;
use App\Models\BchatRoom;
use App\Models\BchatRoomMember;
use App\Models\BchatUserDevice;
use App\Models\BchatWelcomeChallenge;
use App\Models\Dosen;
use App\Models\Mahasiswa;
use App\Models\PenugasanPembimbing;
use App\Models\Skripsi;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BchatModelsTest extends TestCase
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
            'judul'        => 'Judul Skripsi B-CHAT ' . uniqid(),
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

    private function buatDevice(User $user, string $status = 'ACTIVE'): BchatUserDevice
    {
        $keypair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keypair);

        return BchatUserDevice::create([
            'user_id'                => $user->id,
            'device_uuid'            => (string) Str::uuid(),
            'device_name'            => 'Test Device ' . uniqid(),
            'key_version'            => 1,
            'public_key'             => bin2hex($publicKey),
            'public_key_fingerprint' => hash('sha256', $publicKey),
            'status'                 => $status,
            'last_active_at'         => now(),
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
     * Skenario 2: Model BchatRoom - Tabel, Primary Key, Fillable, Casts, dan Helper Status.
     */
    public function test_bchat_room_model_attributes_and_helpers(): void
    {
        ['user' => $mhsUser, 'mahasiswa' => $mhs] = $this->buatMahasiswa();
        ['user' => $dsnUser, 'dosen' => $dsn] = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhs);
        $penugasan = $this->buatPenugasan($skripsi, $dsn, 'proposal', 1, 'AKTIF');

        $room = $this->buatRoom($penugasan, 'ACTIVE');

        // Verifikasi konfigurasi dasar model
        $this->assertEquals('bchat_rooms', $room->getTable());
        $this->assertEquals('id', $room->getKeyName());
        $this->assertTrue($room->timestamps);

        // Verifikasi type casting atribut runtime
        $this->assertIsInt($room->penugasan_pembimbing_id);
        $this->assertIsInt($room->pembimbing_ke);
        $this->assertIsInt($room->current_sequence);
        $this->assertIsInt($room->current_epoch);
        $this->assertInstanceOf(Carbon::class, $room->created_at);
        $this->assertInstanceOf(Carbon::class, $room->updated_at);

        // Verifikasi status helpers
        $this->assertTrue($room->isActive());
        $this->assertFalse($room->isArchived());
        $this->assertFalse($room->isPendingWelcomeAck());

        $room->status = 'ARCHIVED_READONLY';
        $this->assertFalse($room->isActive());
        $this->assertTrue($room->isArchived());

        $room->status = 'PENDING_WELCOME_ACK';
        $this->assertTrue($room->isPendingWelcomeAck());
    }

    /**
     * Skenario 3: Model BchatRoom - Relasi Eloquent Lengkap.
     */
    public function test_bchat_room_relationships(): void
    {
        ['user' => $mhsUser, 'mahasiswa' => $mhs] = $this->buatMahasiswa();
        ['user' => $dsnUser, 'dosen' => $dsn] = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhs);
        $penugasan = $this->buatPenugasan($skripsi, $dsn, 'proposal', 1, 'AKTIF');
        $room = $this->buatRoom($penugasan, 'ACTIVE');

        // Tambahkan keanggotaan
        $memberMhs = BchatRoomMember::create([
            'room_id'         => $room->id,
            'user_id'         => $mhsUser->id,
            'role'            => 'mahasiswa',
            'is_initiator'    => true,
            'is_active'       => true,
            'joined_at_epoch' => 0,
            'welcome_ack_at'  => now(),
        ]);

        $memberDsn = BchatRoomMember::create([
            'room_id'         => $room->id,
            'user_id'         => $dsnUser->id,
            'role'            => 'dosen_pembimbing',
            'is_initiator'    => false,
            'is_active'       => true,
            'joined_at_epoch' => 0,
            'welcome_ack_at'  => now(),
        ]);

        // Tambahkan pesan
        $message = BchatMessage::create([
            'room_id'              => $room->id,
            'server_sequence'      => 1,
            'message_uuid'         => Str::uuid()->toString(),
            'sender_id'            => $mhsUser->id,
            'epoch'                => 0,
            'generation'           => 1,
            'content_type'         => 1,
            'ciphertext_payload'   => 'ciphertext_bytes_mock',
            'payload_hash_sha256'  => hash('sha256', 'mock_1'),
            'client_sent_at'       => now(),
        ]);

        // Verifikasi relasi belongsTo penugasanPembimbing
        $this->assertInstanceOf(PenugasanPembimbing::class, $room->penugasanPembimbing);
        $this->assertEquals($penugasan->id, $room->penugasanPembimbing->id);

        // Verifikasi relasi hasMany members
        $this->assertCount(2, $room->members);
        $this->assertTrue($room->members->contains($memberMhs));
        $this->assertTrue($room->members->contains($memberDsn));

        // Verifikasi relasi belongsToMany users via pivot
        $this->assertCount(2, $room->users);
        $this->assertTrue($room->users->contains('id', $mhsUser->id));
        $this->assertTrue($room->users->contains('id', $dsnUser->id));
        $this->assertNotNull($room->users->first()->pivot->role);
        $this->assertNotNull($room->users->first()->pivot->welcome_ack_at);

        // Verifikasi relasi hasMany messages
        $this->assertCount(1, $room->messages);
        $this->assertEquals($message->id, $room->messages->first()->id);
    }

    /**
     * Skenario 4: Model BchatRoomMember - Atribut, Casts, dan Relasi.
     */
    public function test_bchat_room_member_model_and_relations(): void
    {
        ['user' => $mhsUser, 'mahasiswa' => $mhs] = $this->buatMahasiswa();
        ['user' => $dsnUser, 'dosen' => $dsn] = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhs);
        $penugasan = $this->buatPenugasan($skripsi, $dsn);
        $room = $this->buatRoom($penugasan);

        $member = BchatRoomMember::create([
            'room_id'         => $room->id,
            'user_id'         => $mhsUser->id,
            'role'            => 'mahasiswa',
            'is_initiator'    => true,
            'is_active'       => false,
            'joined_at_epoch' => 0,
            'welcome_ack_at'  => null,
        ]);

        $this->assertEquals('bchat_room_members', $member->getTable());
        $this->assertIsInt($member->room_id);
        $this->assertIsInt($member->user_id);
        $this->assertIsBool($member->is_initiator);
        $this->assertIsBool($member->is_active);
        $this->assertTrue($member->is_initiator);
        $this->assertFalse($member->is_active);

        // Relasi belongsTo room dan user
        $this->assertInstanceOf(BchatRoom::class, $member->room);
        $this->assertEquals($room->id, $member->room->id);
        $this->assertInstanceOf(User::class, $member->user);
        $this->assertEquals($mhsUser->id, $member->user->id);
    }

    /**
     * Skenario 5: Model BchatKeyPackage - Atribut, Casts, dan Relasi.
     */
    public function test_bchat_key_package_model_and_relations(): void
    {
        ['user' => $dsnUser] = $this->buatDosen();
        $device = $this->buatDevice($dsnUser);

        $keyPackage = BchatKeyPackage::create([
            'user_id'          => $dsnUser->id,
            'device_id'        => $device->id,
            'key_package_hash' => hash('sha256', Str::random(32)),
            'key_package_data' => 'mock_binary_key_package_payload',
            'is_consumed'      => false,
            'consumed_at'      => null,
        ]);

        $this->assertEquals('bchat_key_packages', $keyPackage->getTable());
        $this->assertIsInt($keyPackage->user_id);
        $this->assertIsInt($keyPackage->device_id);
        $this->assertIsBool($keyPackage->is_consumed);
        $this->assertFalse($keyPackage->is_consumed);

        // Relasi belongsTo user dan device
        $this->assertInstanceOf(User::class, $keyPackage->user);
        $this->assertEquals($dsnUser->id, $keyPackage->user->id);
        $this->assertInstanceOf(BchatUserDevice::class, $keyPackage->device);
        $this->assertEquals($device->id, $keyPackage->device->id);

        // Simulasi konsumsi paket kunci
        $keyPackage->update([
            'is_consumed' => true,
            'consumed_at' => now(),
        ]);
        $this->assertTrue($keyPackage->fresh()->is_consumed);
        $this->assertInstanceOf(Carbon::class, $keyPackage->fresh()->consumed_at);
    }

    /**
     * Skenario 6: Model BchatWelcomeChallenge - Atribut, Relasi, dan Proteksi Kolom Virtual.
     */
    public function test_bchat_welcome_challenge_model_and_relations(): void
    {
        ['user' => $mhsUser, 'mahasiswa' => $mhs] = $this->buatMahasiswa();
        ['user' => $dsnUser, 'dosen' => $dsn] = $this->buatDosen();
        $device = $this->buatDevice($dsnUser);
        $skripsi = $this->buatSkripsi($mhs);
        $penugasan = $this->buatPenugasan($skripsi, $dsn);
        $room = $this->buatRoom($penugasan, 'PENDING_WELCOME_ACK');

        $challenge = BchatWelcomeChallenge::create([
            'welcome_operation_uuid' => Str::uuid()->toString(),
            'room_id'                => $room->id,
            'recipient_user_id'      => $dsnUser->id,
            'recipient_device_id'    => $device->id,
            'welcome_message'        => 'mock_hpke_welcome_blob',
            'group_id_hash'          => hash('sha256', 'mock_group_id'),
            'epoch'                  => 0,
            'operation_status'       => 'ACTIVE',
            'challenge_uuid'         => Str::uuid()->toString(),
            'challenge_nonce'        => hash('sha256', Str::random(32)),
            'challenge_generation'   => 1,
            'challenge_status'       => 'ISSUED',
            'expires_at'             => now()->addMinutes(5),
            'consumed_at'            => null,
        ]);

        $this->assertEquals('bchat_welcome_challenges', $challenge->getTable());
        $this->assertIsInt($challenge->room_id);
        $this->assertIsInt($challenge->recipient_user_id);
        $this->assertIsInt($challenge->recipient_device_id);
        $this->assertIsInt($challenge->epoch);
        $this->assertIsInt($challenge->challenge_generation);
        $this->assertInstanceOf(Carbon::class, $challenge->expires_at);

        // Relasi belongsTo room, recipientUser, dan recipientDevice
        $this->assertInstanceOf(BchatRoom::class, $challenge->room);
        $this->assertEquals($room->id, $challenge->room->id);
        $this->assertInstanceOf(User::class, $challenge->recipientUser);
        $this->assertEquals($dsnUser->id, $challenge->recipientUser->id);
        $this->assertInstanceOf(BchatUserDevice::class, $challenge->recipientDevice);
        $this->assertEquals($device->id, $challenge->recipientDevice->id);

        // Verifikasi bahwa kolom virtual generated active_operation_slot TIDAK ada di fillable
        $this->assertNotContains('active_operation_slot', $challenge->getFillable());

        // Verifikasi nilai virtual column dihitung secara otomatis oleh MySQL
        $freshChallenge = $challenge->fresh();
        $expectedSlot = $room->id . '_' . $dsnUser->id;
        $this->assertEquals($expectedSlot, $freshChallenge->active_operation_slot);
    }

    /**
     * Skenario 7: Model BchatMessage - Atribut, Sekuens Server, dan Relasi.
     */
    public function test_bchat_message_model_and_relations(): void
    {
        ['user' => $mhsUser, 'mahasiswa' => $mhs] = $this->buatMahasiswa();
        ['user' => $dsnUser, 'dosen' => $dsn] = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhs);
        $penugasan = $this->buatPenugasan($skripsi, $dsn);
        $room = $this->buatRoom($penugasan, 'ACTIVE');

        $message = BchatMessage::create([
            'room_id'             => $room->id,
            'server_sequence'     => 101,
            'message_uuid'        => Str::uuid()->toString(),
            'sender_id'           => $mhsUser->id,
            'epoch'               => 2,
            'generation'          => 5,
            'content_type'        => 1,
            'ciphertext_payload'  => 'mock_encrypted_payload',
            'payload_hash_sha256' => hash('sha256', 'payload_101'),
            'client_sent_at'      => now(),
        ]);

        $this->assertEquals('bchat_messages', $message->getTable());
        $this->assertIsInt($message->room_id);
        $this->assertIsInt($message->server_sequence);
        $this->assertEquals(101, $message->server_sequence);
        $this->assertIsInt($message->sender_id);
        $this->assertIsInt($message->epoch);
        $this->assertIsInt($message->generation);
        $this->assertIsInt($message->content_type);
        $this->assertInstanceOf(Carbon::class, $message->client_sent_at);

        // Relasi belongsTo room dan sender
        $this->assertInstanceOf(BchatRoom::class, $message->room);
        $this->assertEquals($room->id, $message->room->id);
        $this->assertInstanceOf(User::class, $message->sender);
        $this->assertEquals($mhsUser->id, $message->sender->id);

        // Relasi hasMany receipts
        $receipt = BchatMessageReceipt::create([
            'message_id' => $message->id,
            'user_id'    => $dsnUser->id,
            'status'     => 'DELIVERED',
            'status_at'  => now(),
        ]);

        $this->assertCount(1, $message->receipts);
        $this->assertEquals($receipt->id, $message->receipts->first()->id);
    }

    /**
     * Skenario 8: Model BchatMessageReceipt - Penonaktifan Timestamps dan Casts.
     */
    public function test_bchat_message_receipt_model_and_timestamps(): void
    {
        ['user' => $mhsUser, 'mahasiswa' => $mhs] = $this->buatMahasiswa();
        ['user' => $dsnUser, 'dosen' => $dsn] = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhs);
        $penugasan = $this->buatPenugasan($skripsi, $dsn);
        $room = $this->buatRoom($penugasan, 'ACTIVE');

        $message = BchatMessage::create([
            'room_id'             => $room->id,
            'server_sequence'     => 1,
            'message_uuid'        => Str::uuid()->toString(),
            'sender_id'           => $mhsUser->id,
            'epoch'               => 0,
            'generation'          => 1,
            'content_type'        => 1,
            'ciphertext_payload'  => 'mock_payload',
            'payload_hash_sha256' => hash('sha256', 'mock_payload'),
            'client_sent_at'      => now(),
        ]);

        $receipt = BchatMessageReceipt::create([
            'message_id' => $message->id,
            'user_id'    => $dsnUser->id,
            'status'     => 'READ',
            'status_at'  => now(),
        ]);

        $this->assertEquals('bchat_message_receipts', $receipt->getTable());

        // Verifikasi bahwa timestamps bawaan Laravel dinonaktifkan
        $this->assertFalse($receipt->timestamps, 'CRITICAL: BchatMessageReceipt wajib menonaktifkan $timestamps.');

        // Verifikasi casts
        $this->assertIsInt($receipt->message_id);
        $this->assertIsInt($receipt->user_id);
        $this->assertInstanceOf(Carbon::class, $receipt->status_at);

        // Verifikasi relasi
        $this->assertInstanceOf(BchatMessage::class, $receipt->message);
        $this->assertEquals($message->id, $receipt->message->id);
        $this->assertInstanceOf(User::class, $receipt->user);
        $this->assertEquals($dsnUser->id, $receipt->user->id);
    }
}

