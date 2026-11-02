<?php

namespace App\Http\Controllers\Mahasiswa;

use App\Http\Controllers\Controller;
use App\Models\DosenPembimbing;
use App\Models\Mahasiswa;
use App\Models\PengajuanDospem;
use App\Models\PengajuanDospemLog;
use App\Models\PengajuanJudul;
use App\Models\PengajuanJudulLog;
use App\Models\Skripsi;
use App\Services\AcademicNotificationDispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PengajuanDospemMahasiswaController extends Controller
{
    /**
     * Helper internal untuk memverifikasi kelayakan akademik pengajuan Dospem Proposal secara terpusat.
     * Mengembalikan array hasil validasi atau melempar DomainException / JsonResponse jika tidak valid.
     */
    private function resolveAcademicEligibility($user): array
    {
        $mahasiswa = Mahasiswa::where('user_id', $user->id)->first();

        if (!$mahasiswa) {
            throw new \DomainException('Profil mahasiswa tidak ditemukan untuk akun ini. Hubungi Administrator.', 404);
        }

        // Anti-Ambigu: Pastikan mahasiswa memiliki tepat 1 record skripsi yang sah
        $kandidatSkripsi = Skripsi::where('id_mahasiswa', $mahasiswa->id_mahasiswa)->get();

        if ($kandidatSkripsi->isEmpty()) {
            throw new \DomainException('Data skripsi Anda belum tersedia di sistem. Hubungi Administrator.', 422);
        }

        if ($kandidatSkripsi->count() > 1) {
            throw new \DomainException('Ditemukan lebih dari satu data skripsi aktif untuk akun Anda. Harap hubungi Administrator untuk resolusi data.', 422);
        }

        $skripsi = $kandidatSkripsi->first();

        // Triple Verification: Buktikan persetujuan judul resmi oleh Kaprodi
        $judulApproved = false;
        $alasanKetidaklayakan = null;

        if ($skripsi->status_judul !== 'disetujui') {
            $alasanKetidaklayakan = 'Status judul skripsi Anda saat ini belum disetujui.';
        } else {
            $pengajuanJudulResmi = PengajuanJudul::where('skripsi_id', $skripsi->id_skripsi)
                ->where('mahasiswa_id', $mahasiswa->id_mahasiswa)
                ->where('status', 'Disetujui Kaprodi')
                ->latest()
                ->first();

            if (!$pengajuanJudulResmi) {
                $alasanKetidaklayakan = 'Data pengajuan judul resmi berstatus Disetujui Kaprodi tidak ditemukan.';
            } else {
                $logResmi = PengajuanJudulLog::where('pengajuan_judul_id', $pengajuanJudulResmi->id)
                    ->where('tindakan', 'setujui_kaprodi')
                    ->where('status_sesudah', 'Disetujui Kaprodi')
                    ->where('aktor_role', 'kaprodi')
                    ->exists();

                if (!$logResmi) {
                    $alasanKetidaklayakan = 'Log audit persetujuan resmi oleh Kaprodi tidak ditemukan pada sistem.';
                } else {
                    $judulApproved = true;
                }
            }
        }

        // Pemeriksaan penugasan legacy / aktif di tabel dosen_pembimbing
        $hasPembimbingLegacy = DosenPembimbing::where('id_skripsi', $skripsi->id_skripsi)->exists();

        // Pemeriksaan pengajuan aktif di tabel pengajuan_dospem
        $pengajuanAktif = PengajuanDospem::where('skripsi_id', $skripsi->id_skripsi)
            ->where('tahap', 'proposal')
            ->where('status', 'menunggu_penetapan')
            ->first();

        // Pemeriksaan pengajuan yang sudah ditetapkan
        $pengajuanDitetapkan = PengajuanDospem::with('dosen')
            ->where('skripsi_id', $skripsi->id_skripsi)
            ->where('tahap', 'proposal')
            ->where('status', 'ditetapkan')
            ->first();

        return [
            'mahasiswa'             => $mahasiswa,
            'skripsi'               => $skripsi,
            'judul_approved'        => $judulApproved,
            'alasan_ketidaklayakan' => $alasanKetidaklayakan,
            'has_pembimbing'        => $hasPembimbingLegacy,
            'pengajuan_aktif'       => $pengajuanAktif,
            'pengajuan_ditetapkan'  => $pengajuanDitetapkan,
        ];
    }

    /**
     * [MAHASISWA] Periksa status kelayakan akademik untuk mengajukan Dospem Proposal.
     */
    public function statusKelayakan(Request $request): JsonResponse
    {
        $user = $request->user();

        try {
            $eligibility = $this->resolveAcademicEligibility($user);
        } catch (\DomainException $e) {
            $statusCode = in_array($e->getCode(), [404, 422], true) ? $e->getCode() : 422;
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], $statusCode);
        }

        $skripsi = $eligibility['skripsi'];

        // Jika judul belum disetujui Kaprodi
        if (!$eligibility['judul_approved']) {
            return response()->json([
                'status' => 'success',
                'data'   => [
                    'layak_mengajukan'          => false,
                    'alasan'                    => 'Pengajuan Dosen Pembimbing Proposal hanya dapat dilakukan setelah judul skripsi resmi disetujui oleh Kaprodi.',
                    'detail_alasan'             => $eligibility['alasan_ketidaklayakan'],
                    'skripsi'                   => [
                        'id_skripsi'   => $skripsi->id_skripsi,
                        'judul'        => $skripsi->judul,
                        'status_judul' => $skripsi->status_judul,
                    ],
                    'status_pengajuan_terkini'  => null,
                ],
            ]);
        }

        // Jika skripsi sudah memiliki pembimbing proposal yang ditetapkan
        if ($eligibility['has_pembimbing'] || $eligibility['pengajuan_ditetapkan']) {
            return response()->json([
                'status' => 'success',
                'data'   => [
                    'layak_mengajukan'          => false,
                    'alasan'                    => 'Dosen pembimbing proposal untuk skripsi Anda sudah ditetapkan.',
                    'skripsi'                   => [
                        'id_skripsi'   => $skripsi->id_skripsi,
                        'judul'        => $skripsi->judul,
                        'status_judul' => $skripsi->status_judul,
                    ],
                    'status_pengajuan_terkini'  => 'ditetapkan',
                    'pengajuan'                 => $eligibility['pengajuan_ditetapkan'],
                ],
            ]);
        }

        // Jika masih terdapat pengajuan aktif yang menunggu penetapan
        if ($eligibility['pengajuan_aktif']) {
            return response()->json([
                'status' => 'success',
                'data'   => [
                    'layak_mengajukan'          => false,
                    'alasan'                    => 'Masih terdapat pengajuan pembimbing proposal yang aktif dan sedang menunggu penetapan oleh Administrator.',
                    'skripsi'                   => [
                        'id_skripsi'   => $skripsi->id_skripsi,
                        'judul'        => $skripsi->judul,
                        'status_judul' => $skripsi->status_judul,
                    ],
                    'status_pengajuan_terkini'  => 'menunggu_penetapan',
                    'pengajuan'                 => $eligibility['pengajuan_aktif'],
                ],
            ]);
        }

        // Memenuhi syarat
        return response()->json([
            'status' => 'success',
            'data'   => [
                'layak_mengajukan'         => true,
                'pesan'                    => 'Anda memenuhi syarat untuk mengajukan Dosen Pembimbing Proposal.',
                'skripsi'                  => [
                    'id_skripsi'   => $skripsi->id_skripsi,
                    'judul'        => $skripsi->judul,
                    'status_judul' => $skripsi->status_judul,
                ],
                'status_pengajuan_terkini' => null,
            ],
        ]);
    }

    /**
     * [MAHASISWA] Riwayat seluruh pengajuan Dospem milik mahasiswa login.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $mahasiswa = Mahasiswa::where('user_id', $user->id)->first();

        if (!$mahasiswa) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Profil mahasiswa tidak ditemukan untuk akun ini. Hubungi Administrator.',
            ], 404);
        }

        $riwayat = PengajuanDospem::with(['dosen', 'skripsi', 'logs'])
            ->where('mahasiswa_id', $mahasiswa->id_mahasiswa)
            ->latest()
            ->get();

        return response()->json([
            'status' => 'success',
            'data'   => $riwayat,
        ]);
    }

    /**
     * [MAHASISWA] Kirim pengajuan Dosen Pembimbing Proposal baru.
     *
     * Keamanan & Integritas:
     * - Tidak mempercayai input frontend untuk ID, status, atau pilihan dosen.
     * - Membungkus seluruh pengecekan dan mutasi dalam DB::transaction dengan lockForUpdate.
     * - Enforce generated virtual column MySQL 8.0.30 untuk pencegahan pengajuan aktif ganda.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'catatan_mahasiswa' => 'nullable|string|max:1000',
        ]);

        $user = $request->user();

        try {
            $eligibility = $this->resolveAcademicEligibility($user);
        } catch (\DomainException $e) {
            $statusCode = in_array($e->getCode(), [404, 422], true) ? $e->getCode() : 422;
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], $statusCode);
        }

        $mahasiswa = $eligibility['mahasiswa'];
        $skripsi = $eligibility['skripsi'];

        // Gatekeeper pra-transaksi
        if (!$eligibility['judul_approved']) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Pengajuan Dosen Pembimbing Proposal hanya dapat dilakukan setelah judul skripsi resmi disetujui oleh Kaprodi.',
            ], 422);
        }

        if ($eligibility['has_pembimbing'] || $eligibility['pengajuan_ditetapkan']) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Skripsi Anda sudah memiliki Dosen Pembimbing yang ditetapkan.',
            ], 409);
        }

        if ($eligibility['pengajuan_aktif']) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Masih terdapat pengajuan pembimbing proposal yang aktif dan sedang dalam proses.',
            ], 409);
        }

        try {
            DB::transaction(function () use ($user, $mahasiswa, $skripsi, $validated, &$pengajuanBaru) {
                // 1. Lock baris induk skripsi untuk mutual exclusion
                $lockedSkripsi = Skripsi::where('id_skripsi', $skripsi->id_skripsi)
                    ->lockForUpdate()
                    ->first();

                if (!$lockedSkripsi || (int) $lockedSkripsi->id_mahasiswa !== (int) $mahasiswa->id_mahasiswa) {
                    throw new \DomainException('Data skripsi Anda tidak valid atau tidak konsisten dengan profil Anda.', 422);
                }

                // 2. Verifikasi di dalam lock: status judul
                if ($lockedSkripsi->status_judul !== 'disetujui') {
                    throw new \DomainException('Judul skripsi Anda belum berstatus disetujui.', 422);
                }

                // 3. Verifikasi di dalam lock: pembimbing yang sudah terdaftar
                if (DosenPembimbing::where('id_skripsi', $lockedSkripsi->id_skripsi)->exists()) {
                    throw new \DomainException('Skripsi Anda sudah memiliki Dosen Pembimbing yang ditetapkan.', 409);
                }

                // 4. Verifikasi di dalam lock: pengajuan aktif tahap proposal
                $activeExists = PengajuanDospem::where('skripsi_id', $lockedSkripsi->id_skripsi)
                    ->where('tahap', 'proposal')
                    ->where('status', 'menunggu_penetapan')
                    ->exists();

                if ($activeExists) {
                    throw new \DomainException('Masih terdapat pengajuan pembimbing proposal yang aktif dan sedang dalam proses.', 409);
                }

                // 5. Verifikasi di dalam lock: pengajuan proposal yang sudah ditetapkan
                $ditetapkanExists = PengajuanDospem::where('skripsi_id', $lockedSkripsi->id_skripsi)
                    ->where('tahap', 'proposal')
                    ->where('status', 'ditetapkan')
                    ->exists();

                if ($ditetapkanExists) {
                    throw new \DomainException('Dosen pembimbing proposal untuk skripsi Anda sudah ditetapkan.', 409);
                }

                // 6. Buat record pengajuan_dospem baru
                $pengajuanBaru = PengajuanDospem::create([
                    'skripsi_id'        => $lockedSkripsi->id_skripsi,
                    'mahasiswa_id'      => $mahasiswa->id_mahasiswa,
                    'tahap'             => 'proposal',
                    'status'            => 'menunggu_penetapan',
                    'dosen_id'          => null,
                    'catatan_mahasiswa' => $validated['catatan_mahasiswa'] ?? null,
                    'catatan_admin'     => null,
                ]);

                // 7. Buat log audit append-only
                $log = PengajuanDospemLog::create([
                    'pengajuan_dospem_id' => $pengajuanBaru->id,
                    'user_id'             => $user->id,
                    'aktor_nama'          => $user->name,
                    'aktor_role'          => 'mahasiswa',
                    'aktor_nomor_induk'   => $mahasiswa->NIM ?? $user->nomor_induk,
                    'tahap'               => 'proposal',
                    'tindakan'            => 'submit',
                    'status_sebelum'      => null,
                    'status_sesudah'      => 'menunggu_penetapan',
                    'catatan'             => $validated['catatan_mahasiswa'] ?? null,
                    'snapshot_dosen_id'   => null,
                    'snapshot_dosen_nama' => null,
                ]);

                app(AcademicNotificationDispatcher::class)
                    ->dispatchEvt09PengajuanDospemProposal($pengajuanBaru, $log);
            });
        } catch (\Throwable $e) {
            if ($e instanceof \DomainException) {
                $code = in_array($e->getCode(), [404, 409, 422], true) ? $e->getCode() : 422;
                return response()->json([
                    'status'  => 'error',
                    'message' => $e->getMessage(),
                ], $code);
            }

            // Tangani benturan unique constraint pada generated virtual column uk_skripsi_active_tahap (SQLSTATE 23000 / error 1062)
            if ($e instanceof QueryException && in_array($e->errorInfo[1] ?? null, [1062, 19], true)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Masih terdapat pengajuan pembimbing proposal yang aktif dan sedang dalam proses.',
                ], 409);
            }

            Log::error('Kegagalan internal saat menyimpan pengajuan Dospem Proposal: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id'   => $user->id,
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Terjadi kesalahan pada server saat memproses pengajuan Dosen Pembimbing. Silakan coba lagi.',
            ], 500);
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Pengajuan Dosen Pembimbing Proposal berhasil dikirim ke Administrator.',
            'data'    => $pengajuanBaru->fresh(['dosen', 'skripsi', 'logs']),
        ], 201);
    }
}
