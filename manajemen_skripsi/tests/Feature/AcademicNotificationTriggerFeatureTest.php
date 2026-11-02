<?php

namespace Tests\Feature;

use App\Events\AcademicNotificationBroadcasted;
use App\Models\Admin;
use App\Models\Dosen;
use App\Models\DosenPA;
use App\Models\Mahasiswa;
use App\Models\PengajuanDospem;
use App\Models\PengajuanDospemLog;
use App\Models\PengajuanJudul;
use App\Models\PengajuanJudulLog;
use App\Models\PenugasanKaprodi;
use App\Models\Skripsi;
use App\Models\User;
use App\Services\AcademicNotificationDispatcher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AcademicNotificationTriggerFeatureTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['broadcasting.default' => 'reverb']);
        require_once base_path('routes/channels.php');
        Storage::fake('public');
        Event::fake([AcademicNotificationBroadcasted::class]);
    }

    // -----------------------------------------------------------------------
    // HELPERS
    // -----------------------------------------------------------------------

    private function buatAdmin(): array
    {
        $user = User::factory()->create([
            'role'        => 'admin',
            'nomor_induk' => 'ADM_' . uniqid(),
            'name'        => 'Admin Utama ' . uniqid(),
        ]);

        $admin = Admin::create([
            'users_id'   => $user->id,
            'nama_admin' => $user->name,
        ]);

        return compact('user', 'admin');
    }

    private function buatMahasiswa(string $prodi = 'Pendidikan Teknologi Informasi'): array
    {
        $nim = (string) rand(1000000000, 9999999999);
        $user = User::factory()->create([
            'role'        => 'mahasiswa',
            'nomor_induk' => $nim,
            'name'        => 'Mahasiswa ' . $nim,
        ]);

        $mahasiswa = Mahasiswa::create([
            'user_id'        => $user->id,
            'NIM'            => $nim,
            'nama_mahasiswa' => $user->name,
            'prodi'          => $prodi,
            'fakultas'       => 'Fakultas Ilmu Pendidikan',
        ]);

        return compact('user', 'mahasiswa');
    }

    private function buatDosen(): array
    {
        $nidn = (string) rand(1000000000, 9999999999);
        $user = User::factory()->create([
            'role'        => 'dosen',
            'nomor_induk' => $nidn,
            'name'        => 'Dosen ' . $nidn,
        ]);

        $dosen = Dosen::create([
            'user_id'    => $user->id,
            'NIDN'       => $nidn,
            'nama_dosen' => $user->name,
        ]);

        return compact('user', 'dosen');
    }

    private function buatSkripsi(Mahasiswa $mahasiswa, Dosen $dosenPa, string $statusJudul = 'belum_diajukan'): Skripsi
    {
        $skripsi = Skripsi::create([
            'id_mahasiswa' => $mahasiswa->id_mahasiswa,
            'judul'        => 'Rancang Bangun Sistem Monitoring Skripsi Berbasis AI',
            'status_judul' => $statusJudul,
        ]);

        DosenPA::create([
            'id_dosen'   => $dosenPa->id_dosen,
            'id_skripsi' => $skripsi->id_skripsi,
        ]);

        return $skripsi;
    }

    private function tugaskanKaprodi(Dosen $dosen, string $prodi, bool $isAktif = true, int $offsetDays = 0): PenugasanKaprodi
    {
        $mulai = now()->addDays($offsetDays - 30)->toDateString();
        $selesai = now()->addDays($offsetDays + 30)->toDateString();

        return PenugasanKaprodi::create([
            'dosen_id'        => $dosen->id_dosen,
            'prodi'           => $prodi,
            'tanggal_mulai'   => $mulai,
            'tanggal_selesai' => $selesai,
            'is_aktif'        => $isAktif,
        ]);
    }

    // -----------------------------------------------------------------------
    // PENGUJIAN EVT-01 SAMPAI EVT-11
    // -----------------------------------------------------------------------

    public function test_01_evt01_mahasiswa_kirim_judul_baru_memicu_notifikasi_dosen_pa(): void
    {
        $mhsData = $this->buatMahasiswa();
        $paData = $this->buatDosen();
        $this->buatSkripsi($mhsData['mahasiswa'], $paData['dosen']);

        $file = UploadedFile::fake()->create('judul.pdf', 1000, 'application/pdf');

        $response = $this->actingAs($mhsData['user'], 'sanctum')->postJson('/api/mahasiswa/pengajuan-judul', [
            'judul'      => 'Sistem Otomasi Cerdas',
            'deskripsi'  => 'Deskripsi awal judul',
            'file_judul' => $file,
        ]);

        $response->assertStatus(201);
        $pengajuanId = $response->json('data.id');

        // Verifikasi notifikasi sampai di akun Dosen PA
        $notif = DatabaseNotification::where('notifiable_id', $paData['user']->id)
            ->where('notifiable_type', $paData['user']->getMorphClass())
            ->latest()
            ->first();

        $this->assertNotNull($notif, 'Dosen PA harus menerima notifikasi EVT-01.');
        $this->assertStringContainsString('Pengajuan Judul Baru', $notif->data['title']);
        $this->assertStringContainsString("pengajuan_judul:{$pengajuanId}:submit:log_", $notif->data['event_identity']);
        $this->assertEquals('/dashboarddosen/pembimbing_akademik', $notif->data['url']);
    }

    public function test_02_evt02_mahasiswa_kirim_ulang_revisi_judul_memicu_notifikasi_dosen_pa(): void
    {
        $mhsData = $this->buatMahasiswa();
        $paData = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhsData['mahasiswa'], $paData['dosen']);

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'    => $skripsi->id_skripsi,
            'mahasiswa_id'  => $mhsData['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'   => $paData['user']->id,
            'judul'         => 'Judul Revisi Dosen',
            'deskripsi'     => 'Deskripsi',
            'status'        => 'Revisi Dosen PA',
            'catatan_dosen' => 'Perbaiki rumusan masalah.',
            'file_path'     => 'pengajuan-judul/dummy.pdf',
            'nama_file'     => 'dummy.pdf',
            'ukuran_file'   => '100 KB',
        ]);

        $fileBaru = UploadedFile::fake()->create('revisi.pdf', 1000, 'application/pdf');

        $response = $this->actingAs($mhsData['user'], 'sanctum')->postJson("/api/mahasiswa/pengajuan-judul/{$pengajuan->id}/revisi", [
            'judul'             => 'Judul Diperbaiki',
            'deskripsi'         => 'Deskripsi baru',
            'catatan_mahasiswa' => 'Sudah diperbaiki rumusan masalahnya.',
            'file_judul'        => $fileBaru,
        ]);

        $response->assertStatus(200);

        // Verifikasi notifikasi resubmit ke Dosen PA
        $notif = DatabaseNotification::where('notifiable_id', $paData['user']->id)
            ->where('data->event_identity', 'like', "pengajuan_judul:{$pengajuan->id}:resubmit:log_%")
            ->first();

        $this->assertNotNull($notif, 'Dosen PA harus menerima notifikasi EVT-02 setelah resubmit revisi.');
        $this->assertStringContainsString('Perbaikan Pengajuan Judul', $notif->data['title']);
        $this->assertEquals('/dashboarddosen/pembimbing_akademik', $notif->data['url']);
    }

    public function test_03_evt03_dosen_pa_setujui_judul_memicu_notifikasi_mahasiswa_dan_kaprodi_aktif(): void
    {
        $mhsData = $this->buatMahasiswa('Pendidikan Teknologi Informasi');
        $paData = $this->buatDosen();
        $kaprodiData = $this->buatDosen();
        $this->tugaskanKaprodi($kaprodiData['dosen'], 'Pendidikan Teknologi Informasi');

        $skripsi = $this->buatSkripsi($mhsData['mahasiswa'], $paData['dosen']);

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhsData['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $paData['user']->id,
            'judul'        => 'Judul Siap Disetujui PA',
            'deskripsi'    => 'Deskripsi',
            'status'       => 'Menunggu Verifikasi Dosen PA',
            'file_path'    => 'pengajuan-judul/dummy.pdf',
            'nama_file'    => 'dummy.pdf',
            'ukuran_file'  => '100 KB',
        ]);

        $response = $this->actingAs($paData['user'], 'sanctum')->patchJson("/api/dosen/pengajuan-judul/{$pengajuan->id}/verifikasi", [
            'status' => 'Menunggu Persetujuan Kaprodi',
        ]);

        $response->assertStatus(200);

        // 1. Verifikasi notifikasi ke Mahasiswa
        $notifMhs = DatabaseNotification::where('notifiable_id', $mhsData['user']->id)
            ->where('data->event_identity', 'like', "pengajuan_judul:{$pengajuan->id}:setujui_dosen_pa:log_%")
            ->first();
        $this->assertNotNull($notifMhs, 'Mahasiswa harus menerima notifikasi EVT-03 saat Dosen PA menyetujui.');
        $this->assertEquals('/dashboardmahasiswa/pengajuan_judul', $notifMhs->data['url']);

        // 2. Verifikasi notifikasi ke Kaprodi Aktif
        $notifKaprodi = DatabaseNotification::where('notifiable_id', $kaprodiData['user']->id)
            ->where('data->event_identity', 'like', "pengajuan_judul:{$pengajuan->id}:setujui_dosen_pa:log_%")
            ->first();
        $this->assertNotNull($notifKaprodi, 'Kaprodi aktif harus menerima notifikasi EVT-03 saat Dosen PA menyetujui.');
        $this->assertEquals('/dashboarddosen/persetujuan_kaprodi', $notifKaprodi->data['url']);
    }

    public function test_04_evt04_dosen_pa_minta_revisi_memicu_notifikasi_mahasiswa(): void
    {
        $mhsData = $this->buatMahasiswa();
        $paData = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhsData['mahasiswa'], $paData['dosen']);

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhsData['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $paData['user']->id,
            'judul'        => 'Judul Butuh Revisi',
            'deskripsi'    => 'Deskripsi',
            'status'       => 'Menunggu Verifikasi Dosen PA',
            'file_path'    => 'pengajuan-judul/dummy.pdf',
            'nama_file'    => 'dummy.pdf',
            'ukuran_file'  => '100 KB',
        ]);

        $response = $this->actingAs($paData['user'], 'sanctum')->patchJson("/api/dosen/pengajuan-judul/{$pengajuan->id}/verifikasi", [
            'status'        => 'Revisi Dosen PA',
            'catatan_dosen' => 'Tolong lengkapi metodologinya.',
        ]);

        $response->assertStatus(200);

        $notifMhs = DatabaseNotification::where('notifiable_id', $mhsData['user']->id)
            ->where('data->event_identity', 'like', "pengajuan_judul:{$pengajuan->id}:revisi_dosen_pa:log_%")
            ->first();

        $this->assertNotNull($notifMhs, 'Mahasiswa harus menerima notifikasi EVT-04 saat Dosen PA minta revisi.');
        $this->assertStringContainsString('Revisi Pengajuan Judul', $notifMhs->data['title']);
        $this->assertStringContainsString('Tolong lengkapi metodologinya', $notifMhs->data['message']);
        $this->assertEquals('/dashboardmahasiswa/pengajuan_judul', $notifMhs->data['url']);
    }

    public function test_05_evt05_dosen_pa_tolak_judul_memicu_notifikasi_mahasiswa(): void
    {
        $mhsData = $this->buatMahasiswa();
        $paData = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhsData['mahasiswa'], $paData['dosen']);

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhsData['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $paData['user']->id,
            'judul'        => 'Judul Ditolak',
            'deskripsi'    => 'Deskripsi',
            'status'       => 'Menunggu Verifikasi Dosen PA',
            'file_path'    => 'pengajuan-judul/dummy.pdf',
            'nama_file'    => 'dummy.pdf',
            'ukuran_file'  => '100 KB',
        ]);

        $response = $this->actingAs($paData['user'], 'sanctum')->patchJson("/api/dosen/pengajuan-judul/{$pengajuan->id}/verifikasi", [
            'status'        => 'Ditolak Dosen PA',
            'catatan_dosen' => 'Topik tidak sesuai dengan kompetensi program studi.',
        ]);

        $response->assertStatus(200);

        $notifMhs = DatabaseNotification::where('notifiable_id', $mhsData['user']->id)
            ->where('data->event_identity', 'like', "pengajuan_judul:{$pengajuan->id}:tolak_dosen_pa:log_%")
            ->first();

        $this->assertNotNull($notifMhs, 'Mahasiswa harus menerima notifikasi EVT-05 saat Dosen PA menolak.');
        $this->assertStringContainsString('Pengajuan Judul Ditolak', $notifMhs->data['title']);
        $this->assertStringContainsString('Topik tidak sesuai', $notifMhs->data['message']);
        $this->assertEquals('/dashboardmahasiswa/pengajuan_judul', $notifMhs->data['url']);
    }

    public function test_06_evt06_kaprodi_setujui_judul_memicu_notifikasi_mahasiswa_dan_dosen_pa(): void
    {
        $mhsData = $this->buatMahasiswa('Pendidikan Teknologi Informasi');
        $paData = $this->buatDosen();
        $kaprodiData = $this->buatDosen();
        $this->tugaskanKaprodi($kaprodiData['dosen'], 'Pendidikan Teknologi Informasi');

        $skripsi = $this->buatSkripsi($mhsData['mahasiswa'], $paData['dosen']);

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhsData['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $paData['user']->id,
            'judul'        => 'Judul Menunggu Kaprodi',
            'deskripsi'    => 'Deskripsi',
            'status'       => 'Menunggu Persetujuan Kaprodi',
            'file_path'    => 'pengajuan-judul/dummy.pdf',
            'nama_file'    => 'dummy.pdf',
            'ukuran_file'  => '100 KB',
        ]);

        $response = $this->actingAs($kaprodiData['user'], 'sanctum')->patchJson("/api/kaprodi/pengajuan-judul/{$pengajuan->id}/persetujuan", [
            'status' => 'Disetujui Kaprodi',
        ]);

        $response->assertStatus(200);

        // 1. Notifikasi Mahasiswa
        $notifMhs = DatabaseNotification::where('notifiable_id', $mhsData['user']->id)
            ->where('data->event_identity', 'like', "pengajuan_judul:{$pengajuan->id}:setujui_kaprodi:log_%")
            ->first();
        $this->assertNotNull($notifMhs);
        $this->assertStringContainsString('Judul Resmi Disetujui Kaprodi', $notifMhs->data['title']);
        $this->assertEquals('/dashboardmahasiswa/pengajuan_judul', $notifMhs->data['url']);

        // 2. Notifikasi Dosen PA
        $notifPA = DatabaseNotification::where('notifiable_id', $paData['user']->id)
            ->where('data->event_identity', 'like', "pengajuan_judul:{$pengajuan->id}:setujui_kaprodi:log_%")
            ->first();
        $this->assertNotNull($notifPA);
        $this->assertStringContainsString('Persetujuan Judul oleh Kaprodi', $notifPA->data['title']);
        $this->assertEquals('/dashboarddosen/pembimbing_akademik', $notifPA->data['url']);
    }

    public function test_07_evt07_kaprodi_minta_revisi_memicu_notifikasi_mahasiswa_dan_dosen_pa(): void
    {
        $mhsData = $this->buatMahasiswa('Pendidikan Teknologi Informasi');
        $paData = $this->buatDosen();
        $kaprodiData = $this->buatDosen();
        $this->tugaskanKaprodi($kaprodiData['dosen'], 'Pendidikan Teknologi Informasi');

        $skripsi = $this->buatSkripsi($mhsData['mahasiswa'], $paData['dosen']);

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhsData['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $paData['user']->id,
            'judul'        => 'Judul Butuh Revisi Kaprodi',
            'deskripsi'    => 'Deskripsi',
            'status'       => 'Menunggu Persetujuan Kaprodi',
            'file_path'    => 'pengajuan-judul/dummy.pdf',
            'nama_file'    => 'dummy.pdf',
            'ukuran_file'  => '100 KB',
        ]);

        $response = $this->actingAs($kaprodiData['user'], 'sanctum')->patchJson("/api/kaprodi/pengajuan-judul/{$pengajuan->id}/persetujuan", [
            'status'          => 'Revisi Kaprodi',
            'catatan_kaprodi' => 'Persempit ruang lingkup penelitian.',
        ]);

        $response->assertStatus(200);

        // Notifikasi Mahasiswa & Dosen PA
        $notifMhs = DatabaseNotification::where('notifiable_id', $mhsData['user']->id)
            ->where('data->event_identity', 'like', "pengajuan_judul:{$pengajuan->id}:revisi_kaprodi:log_%")
            ->first();
        $this->assertNotNull($notifMhs);
        $this->assertStringContainsString('Persempit ruang lingkup', $notifMhs->data['message']);

        $notifPA = DatabaseNotification::where('notifiable_id', $paData['user']->id)
            ->where('data->event_identity', 'like', "pengajuan_judul:{$pengajuan->id}:revisi_kaprodi:log_%")
            ->first();
        $this->assertNotNull($notifPA);
    }

    public function test_08_evt08_kaprodi_tolak_judul_memicu_notifikasi_mahasiswa_dan_dosen_pa(): void
    {
        $mhsData = $this->buatMahasiswa('Pendidikan Teknologi Informasi');
        $paData = $this->buatDosen();
        $kaprodiData = $this->buatDosen();
        $this->tugaskanKaprodi($kaprodiData['dosen'], 'Pendidikan Teknologi Informasi');

        $skripsi = $this->buatSkripsi($mhsData['mahasiswa'], $paData['dosen']);

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhsData['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $paData['user']->id,
            'judul'        => 'Judul Ditolak Kaprodi',
            'deskripsi'    => 'Deskripsi',
            'status'       => 'Menunggu Persetujuan Kaprodi',
            'file_path'    => 'pengajuan-judul/dummy.pdf',
            'nama_file'    => 'dummy.pdf',
            'ukuran_file'  => '100 KB',
        ]);

        $response = $this->actingAs($kaprodiData['user'], 'sanctum')->patchJson("/api/kaprodi/pengajuan-judul/{$pengajuan->id}/persetujuan", [
            'status'          => 'Ditolak Kaprodi',
            'catatan_kaprodi' => 'Topik sudah pernah diteliti tahun lalu.',
        ]);

        $response->assertStatus(200);

        // Notifikasi Mahasiswa & Dosen PA
        $notifMhs = DatabaseNotification::where('notifiable_id', $mhsData['user']->id)
            ->where('data->event_identity', 'like', "pengajuan_judul:{$pengajuan->id}:tolak_kaprodi:log_%")
            ->first();
        $this->assertNotNull($notifMhs);

        $notifPA = DatabaseNotification::where('notifiable_id', $paData['user']->id)
            ->where('data->event_identity', 'like', "pengajuan_judul:{$pengajuan->id}:tolak_kaprodi:log_%")
            ->first();
        $this->assertNotNull($notifPA);
    }

    public function test_09_evt09_mahasiswa_ajukan_dospem_proposal_memicu_notifikasi_seluruh_admin(): void
    {
        $admin1 = $this->buatAdmin();
        $admin2 = $this->buatAdmin();

        $mhsData = $this->buatMahasiswa();
        $kaprodi = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhsData['mahasiswa'], $kaprodi['dosen'], 'disetujui');

        // Buat prasyarat persetujuan resmi
        $pengajuanJudul = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhsData['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $kaprodi['user']->id,
            'judul'        => $skripsi->judul,
            'deskripsi'    => 'Deskripsi',
            'status'       => 'Disetujui Kaprodi',
            'file_path'    => 'pengajuan-judul/dummy.pdf',
            'nama_file'    => 'dummy.pdf',
            'ukuran_file'  => '100 KB',
        ]);

        PengajuanJudulLog::create([
            'pengajuan_judul_id' => $pengajuanJudul->id,
            'user_id'            => $kaprodi['user']->id,
            'aktor_nama'         => 'Kaprodi User',
            'aktor_role'         => 'kaprodi',
            'tahap'              => 'persetujuan_kaprodi',
            'tindakan'           => 'setujui_kaprodi',
            'status_sesudah'     => 'Disetujui Kaprodi',
        ]);

        $response = $this->actingAs($mhsData['user'], 'sanctum')->postJson('/api/mahasiswa/pengajuan-dospem', [
            'catatan_mahasiswa' => 'Mohon penetapan pembimbing yang mendalami NLP.',
        ]);

        $response->assertStatus(201);
        $dospemId = $response->json('data.id');

        // Verifikasi kedua admin menerima notifikasi
        $notifAdmin1 = DatabaseNotification::where('notifiable_id', $admin1['user']->id)
            ->where('data->event_identity', 'like', "pengajuan_dospem:{$dospemId}:submit:log_%")
            ->first();
        $this->assertNotNull($notifAdmin1, 'Admin 1 harus menerima notifikasi EVT-09.');
        $this->assertEquals('/dashboardadmin/penetapan_dospem', $notifAdmin1->data['url']);

        $notifAdmin2 = DatabaseNotification::where('notifiable_id', $admin2['user']->id)
            ->where('data->event_identity', 'like', "pengajuan_dospem:{$dospemId}:submit:log_%")
            ->first();
        $this->assertNotNull($notifAdmin2, 'Admin 2 harus menerima notifikasi EVT-09.');
    }

    public function test_10_evt10_admin_tetapkan_dospem_memicu_notifikasi_mahasiswa_dan_dosen_terpilih(): void
    {
        $admin = $this->buatAdmin();
        $mhsData = $this->buatMahasiswa();
        $paData = $this->buatDosen();
        $dosenTerpilih = $this->buatDosen();

        $skripsi = $this->buatSkripsi($mhsData['mahasiswa'], $paData['dosen'], 'disetujui');

        $pengajuanJudul = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhsData['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $paData['user']->id,
            'judul'        => $skripsi->judul,
            'deskripsi'    => 'Deskripsi',
            'status'       => 'Disetujui Kaprodi',
            'file_path'    => 'pengajuan-judul/dummy.pdf',
            'nama_file'    => 'dummy.pdf',
            'ukuran_file'  => '100 KB',
        ]);

        $pengajuanDospem = PengajuanDospem::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhsData['mahasiswa']->id_mahasiswa,
            'tahap'        => 'proposal',
            'status'       => 'menunggu_penetapan',
        ]);

        $response = $this->actingAs($admin['user'], 'sanctum')->postJson("/api/admin/pengajuan-dospem/{$pengajuanDospem->id}/tetapkan", [
            'id_dosen'      => $dosenTerpilih['dosen']->id_dosen,
            'catatan_admin' => 'Ditetapkan sesuai topik skripsi.',
        ]);

        $response->assertStatus(200);

        // 1. Notifikasi Mahasiswa
        $notifMhs = DatabaseNotification::where('notifiable_id', $mhsData['user']->id)
            ->where('data->event_identity', 'like', "pengajuan_dospem:{$pengajuanDospem->id}:tetapkan:log_%")
            ->first();
        $this->assertNotNull($notifMhs, 'Mahasiswa harus menerima notifikasi EVT-10.');
        $this->assertEquals('/dashboardmahasiswa/pengajuan_dospem', $notifMhs->data['url']);

        // 2. Notifikasi Dosen yang Ditetapkan
        $notifDosen = DatabaseNotification::where('notifiable_id', $dosenTerpilih['user']->id)
            ->where('data->event_identity', 'like', "pengajuan_dospem:{$pengajuanDospem->id}:tetapkan:log_%")
            ->first();
        $this->assertNotNull($notifDosen, 'Dosen terpilih harus menerima notifikasi EVT-10.');
        $this->assertEquals('/dashboarddosen/pembimbing_skripsi', $notifDosen->data['url']);
    }

    public function test_11_evt11_admin_tolak_dospem_memicu_notifikasi_mahasiswa(): void
    {
        $admin = $this->buatAdmin();
        $mhsData = $this->buatMahasiswa();
        $paData = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhsData['mahasiswa'], $paData['dosen'], 'disetujui');

        $pengajuanDospem = PengajuanDospem::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhsData['mahasiswa']->id_mahasiswa,
            'tahap'        => 'proposal',
            'status'       => 'menunggu_penetapan',
        ]);

        $response = $this->actingAs($admin['user'], 'sanctum')->patchJson("/api/admin/pengajuan-dospem/{$pengajuanDospem->id}/status", [
            'status'        => 'ditolak',
            'catatan_admin' => 'Kuota bimbingan semester ini penuh.',
        ]);

        $response->assertStatus(200);

        $notifMhs = DatabaseNotification::where('notifiable_id', $mhsData['user']->id)
            ->where('data->event_identity', 'like', "pengajuan_dospem:{$pengajuanDospem->id}:tolak:log_%")
            ->first();

        $this->assertNotNull($notifMhs, 'Mahasiswa harus menerima notifikasi EVT-11 saat pengajuan ditolak.');
        $this->assertStringContainsString('Pengajuan Pembimbing Proposal Ditolak', $notifMhs->data['title']);
        $this->assertStringContainsString('Kuota bimbingan semester ini penuh', $notifMhs->data['message']);
        $this->assertEquals('/dashboardmahasiswa/pengajuan_dospem', $notifMhs->data['url']);
    }

    // -----------------------------------------------------------------------
    // PENGUJIAN KEPUTUSAN BISNIS & KEANDALAN TRANSAKSI
    // -----------------------------------------------------------------------

    public function test_12_keputusan_bisnis_1_dosen_pa_merangkap_kaprodi_tetap_menerima_notifikasi(): void
    {
        $mhsData = $this->buatMahasiswa('Pendidikan Teknologi Informasi');
        // Buat 1 Dosen yang berperan sebagai Dosen PA sekaligus Kaprodi
        $dosenRangkap = $this->buatDosen();
        $this->tugaskanKaprodi($dosenRangkap['dosen'], 'Pendidikan Teknologi Informasi');

        $skripsi = $this->buatSkripsi($mhsData['mahasiswa'], $dosenRangkap['dosen']);

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhsData['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dosenRangkap['user']->id,
            'judul'        => 'Judul Kasus Rangkap Jabatan',
            'deskripsi'    => 'Deskripsi',
            'status'       => 'Menunggu Persetujuan Kaprodi',
            'file_path'    => 'pengajuan-judul/dummy.pdf',
            'nama_file'    => 'dummy.pdf',
            'ukuran_file'  => '100 KB',
        ]);

        // Dosen bertindak sebagai Kaprodi menyetujui
        $response = $this->actingAs($dosenRangkap['user'], 'sanctum')->patchJson("/api/kaprodi/pengajuan-judul/{$pengajuan->id}/persetujuan", [
            'status' => 'Disetujui Kaprodi',
        ]);

        $response->assertStatus(200);

        // Keputusan Bisnis 1: Dosen PA TETAP menerima notifikasi meskipun ID akun sama dengan aktor Kaprodi
        $notifPA = DatabaseNotification::where('notifiable_id', $dosenRangkap['user']->id)
            ->where('data->event_identity', 'like', "pengajuan_judul:{$pengajuan->id}:setujui_kaprodi:log_%")
            ->first();

        $this->assertNotNull($notifPA, 'Dosen PA yang merangkap Kaprodi TETAP harus menerima notifikasi sesuai Keputusan Bisnis 1.');
        $this->assertStringContainsString('Persetujuan Judul oleh Kaprodi', $notifPA->data['title']);

        // Mahasiswa juga tetap menerima
        $notifMhs = DatabaseNotification::where('notifiable_id', $mhsData['user']->id)
            ->where('data->event_identity', 'like', "pengajuan_judul:{$pengajuan->id}:setujui_kaprodi:log_%")
            ->first();
        $this->assertNotNull($notifMhs);
    }

    public function test_13_keputusan_bisnis_2_tidak_ada_kaprodi_aktif_tetap_berhasil_dan_notif_kaprodi_dilewati(): void
    {
        $mhsData = $this->buatMahasiswa('Pendidikan Teknologi Informasi');
        $paData = $this->buatDosen();
        // TIDAK ADA penugasan Kaprodi yang dibuat untuk prodi ini!

        $skripsi = $this->buatSkripsi($mhsData['mahasiswa'], $paData['dosen']);

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhsData['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $paData['user']->id,
            'judul'        => 'Judul Tanpa Kaprodi Aktif',
            'deskripsi'    => 'Deskripsi',
            'status'       => 'Menunggu Verifikasi Dosen PA',
            'file_path'    => 'pengajuan-judul/dummy.pdf',
            'nama_file'    => 'dummy.pdf',
            'ukuran_file'  => '100 KB',
        ]);

        // Verifikasi bahwa warning tercatat di Log::warning
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message) {
                return str_contains($message, 'Tidak ditemukan penugasan Kaprodi aktif')
                    && str_contains($message, 'Notifikasi Kaprodi dilewati');
            });
        Log::shouldReceive('error')->never();
        Log::shouldReceive('info')->zeroOrMoreTimes();

        $response = $this->actingAs($paData['user'], 'sanctum')->patchJson("/api/dosen/pengajuan-judul/{$pengajuan->id}/verifikasi", [
            'status' => 'Menunggu Persetujuan Kaprodi',
        ]);

        // Keputusan Bisnis 2: Transaksi akademik TETAP berhasil!
        $response->assertStatus(200);

        // Mahasiswa tetap menerima notifikasi
        $notifMhs = DatabaseNotification::where('notifiable_id', $mhsData['user']->id)
            ->where('data->event_identity', 'like', "pengajuan_judul:{$pengajuan->id}:setujui_dosen_pa:log_%")
            ->first();
        $this->assertNotNull($notifMhs, 'Mahasiswa tetap harus menerima notifikasi meskipun Kaprodi belum ada.');

        // Tidak ada record notifikasi Kaprodi fiktif
        $this->assertEquals(1, DatabaseNotification::where('data->event_identity', 'like', "pengajuan_judul:{$pengajuan->id}:setujui_dosen_pa:log_%")->count());
    }

    public function test_14_kaprodi_prodi_berbeda_atau_kadaluarsa_tidak_menerima_notifikasi(): void
    {
        $mhsData = $this->buatMahasiswa('Pendidikan Teknologi Informasi');
        $paData = $this->buatDosen();
        $kaprodiLain = $this->buatDosen();
        // Tugaskan Kaprodi tapi untuk prodi 'Pendidikan Bahasa Inggris' (berbeda)
        $this->tugaskanKaprodi($kaprodiLain['dosen'], 'Pendidikan Bahasa Inggris');

        $skripsi = $this->buatSkripsi($mhsData['mahasiswa'], $paData['dosen']);

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhsData['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $paData['user']->id,
            'judul'        => 'Judul Uji Kaprodi Beda Prodi',
            'deskripsi'    => 'Deskripsi',
            'status'       => 'Menunggu Verifikasi Dosen PA',
            'file_path'    => 'pengajuan-judul/dummy.pdf',
            'nama_file'    => 'dummy.pdf',
            'ukuran_file'  => '100 KB',
        ]);

        $this->actingAs($paData['user'], 'sanctum')->patchJson("/api/dosen/pengajuan-judul/{$pengajuan->id}/verifikasi", [
            'status' => 'Menunggu Persetujuan Kaprodi',
        ])->assertStatus(200);

        // Kaprodi dari prodi lain TIDAK boleh menerima notifikasi
        $notifLain = DatabaseNotification::where('notifiable_id', $kaprodiLain['user']->id)->count();
        $this->assertEquals(0, $notifLain, 'Kaprodi prodi lain tidak boleh menerima notifikasi.');
    }

    public function test_15_validasi_atau_otorisasi_gagal_tidak_mengirim_notifikasi(): void
    {
        $mhsData = $this->buatMahasiswa();
        $dosenBukanPa = $this->buatDosen();
        $dosenPaAsli = $this->buatDosen();

        $skripsi = $this->buatSkripsi($mhsData['mahasiswa'], $dosenPaAsli['dosen']);

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhsData['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $dosenPaAsli['user']->id,
            'judul'        => 'Judul Otorisasi Test',
            'deskripsi'    => 'Deskripsi',
            'status'       => 'Menunggu Verifikasi Dosen PA',
            'file_path'    => 'pengajuan-judul/dummy.pdf',
            'nama_file'    => 'dummy.pdf',
            'ukuran_file'  => '100 KB',
        ]);

        // Dosen yang bukan PA mencoba memverifikasi -> 404/403
        $response = $this->actingAs($dosenBukanPa['user'], 'sanctum')->patchJson("/api/dosen/pengajuan-judul/{$pengajuan->id}/verifikasi", [
            'status' => 'Menunggu Persetujuan Kaprodi',
        ]);

        $response->assertStatus(404);

        // Pastikan TIDAK ADA notifikasi yang terkirim
        $notifCount = DatabaseNotification::where('data->event_identity', 'like', "pengajuan_judul:{$pengajuan->id}:%")->count();
        $this->assertEquals(0, $notifCount, 'Kegagalan otorisasi tidak boleh memicu notifikasi.');
    }

    public function test_16_rollback_transaksi_tidak_mengirim_notifikasi(): void
    {
        $mhsData = $this->buatMahasiswa();
        $paData = $this->buatDosen();

        $skripsi = $this->buatSkripsi($mhsData['mahasiswa'], $paData['dosen']);

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhsData['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $paData['user']->id,
            'judul'        => 'Judul Uji Rollback',
            'deskripsi'    => 'Deskripsi',
            'status'       => 'Menunggu Verifikasi Dosen PA',
            'file_path'    => 'pengajuan-judul/dummy.pdf',
            'nama_file'    => 'dummy.pdf',
            'ukuran_file'  => '100 KB',
        ]);

        // Simulasi transaksi dengan error paksa
        try {
            DB::transaction(function () use ($pengajuan, $paData) {
                $log = PengajuanJudulLog::create([
                    'pengajuan_judul_id' => $pengajuan->id,
                    'user_id'            => $paData['user']->id,
                    'aktor_nama'         => 'PA',
                    'aktor_role'         => 'dosen_pa',
                    'tahap'              => 'verifikasi_dosen_pa',
                    'tindakan'           => 'setujui_dosen_pa',
                    'status_sesudah'     => 'Menunggu Persetujuan Kaprodi',
                ]);

                app(AcademicNotificationDispatcher::class)->dispatchEvt03DosenPaSetujui($pengajuan, $log);

                throw new \Exception('Simulasi kegagalan transaksi alur akademik.');
            });
        } catch (\Exception $e) {
            $this->assertEquals('Simulasi kegagalan transaksi alur akademik.', $e->getMessage());
        }

        // Pastikan tidak ada notifikasi yang disimpan di tabel notifications
        $notifCount = DatabaseNotification::where('data->event_identity', 'like', "pengajuan_judul:{$pengajuan->id}:setujui_dosen_pa:%")->count();
        $this->assertEquals(0, $notifCount, 'Transaksi yang di-rollback tidak boleh memicu pengiriman notifikasi afterCommit.');
    }

    public function test_17_idempotensi_pengiriman_ulang_event_yang_sama_tidak_membuat_record_duplikat(): void
    {
        $mhsData = $this->buatMahasiswa();
        $paData = $this->buatDosen();
        $skripsi = $this->buatSkripsi($mhsData['mahasiswa'], $paData['dosen']);

        $pengajuan = PengajuanJudul::create([
            'skripsi_id'   => $skripsi->id_skripsi,
            'mahasiswa_id' => $mhsData['mahasiswa']->id_mahasiswa,
            'dosen_pa_id'  => $paData['user']->id,
            'judul'        => 'Judul Idempoten',
            'deskripsi'    => 'Deskripsi',
            'status'       => 'Menunggu Verifikasi Dosen PA',
            'file_path'    => 'pengajuan-judul/dummy.pdf',
            'nama_file'    => 'dummy.pdf',
            'ukuran_file'  => '100 KB',
        ]);

        $log = PengajuanJudulLog::create([
            'pengajuan_judul_id' => $pengajuan->id,
            'user_id'            => $mhsData['user']->id,
            'aktor_nama'         => 'Mhs',
            'aktor_role'         => 'mahasiswa',
            'tahap'              => 'pengajuan_awal',
            'tindakan'           => 'submit',
            'status_sesudah'     => 'Menunggu Verifikasi Dosen PA',
        ]);

        // Panggil dispatcher dua kali berturut-turut untuk event dan log yang sama
        DB::transaction(function () use ($pengajuan, $log) {
            app(AcademicNotificationDispatcher::class)->dispatchEvt01PengajuanJudulBaru($pengajuan, $log);
        });

        DB::transaction(function () use ($pengajuan, $log) {
            app(AcademicNotificationDispatcher::class)->dispatchEvt01PengajuanJudulBaru($pengajuan, $log);
        });

        // Jumlah record di database untuk Dosen PA harus tepat 1
        $count = DatabaseNotification::where('notifiable_id', $paData['user']->id)
            ->where('data->event_identity', "pengajuan_judul:{$pengajuan->id}:submit:log_{$log->id}")
            ->count();

        $this->assertEquals(1, $count, 'Pengiriman berulang untuk log yang sama harus idempoten dan tidak menduplikasi record notifikasi.');
    }
}
