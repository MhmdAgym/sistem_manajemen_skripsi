<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Bchat;

use App\Exceptions\BchatAttachmentException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bchat\BchatCreateUploadSessionRequest;
use App\Http\Requests\Bchat\BchatUploadChunkRequest;
use App\Models\BchatAttachment;
use App\Models\BchatAttachmentChunk;
use App\Models\BchatRoom;
use App\Models\BchatRoomSession;
use App\Models\BchatUploadSession;
use App\Models\BchatUserDevice;
use App\Policies\BchatAttachmentPolicy;
use App\Services\BchatAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Controller untuk API Pengunggahan dan Pengunduhan Ciphertext Lampiran E2EE (B-CHAT EXP-02 / Tahap 2).
 *
 * Menangani:
 * 1. POST /api/bchat/rooms/{roomUuid}/attachments/upload-session (Inisiasi Sesi Unggah)
 * 2. PUT /api/bchat/rooms/{roomUuid}/attachments/upload-session/{sessionUuid}/chunks/{chunkIndex} (Upload Chunk)
 * 3. POST /api/bchat/rooms/{roomUuid}/attachments/upload-session/{sessionUuid}/complete (Finalisasi Sesi)
 * 4. GET /api/bchat/rooms/{roomUuid}/attachments/{attachmentUuid}/chunks/{chunkIndex} (Download Chunk)
 *
 * Invarian Keamanan:
 * - Zero Plaintext Knowledge: Server hanya menerima dan melayani ciphertext biner.
 * - Anti-IDOR & Room Isolation: Akses diverifikasi ketat terhadap keanggotaan pengguna pada room terkait.
 * - Idempotensi Chunk: Upload ulang chunk dengan hash identik menghasilkan respons 200 OK.
 * - Zero Path Leakage: Lokasi absolut storage privat tidak pernah diekspos ke klien.
 */
class BchatAttachmentController extends Controller
{
    public function __construct(
        protected readonly BchatAttachmentService $attachmentService,
        protected readonly BchatAttachmentPolicy $attachmentPolicy = new BchatAttachmentPolicy()
    ) {
    }

    /**
     * [POST /api/bchat/rooms/{roomUuid}/attachments/upload-session]
     *
     * Membuat sesi unggah lampiran dan mengaitkannya dengan record attachment PENDING_MESSAGE.
     */
    public function createUploadSession(BchatCreateUploadSessionRequest $request, string $roomUuid): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->errorResponse('Pengguna belum terautentikasi.', 'UNAUTHENTICATED', 401);
        }

        $room = $this->findAndAuthorizeRoom($roomUuid, $user, 'upload');
        if ($room instanceof JsonResponse) {
            return $room;
        }

        // 1. Dapatkan sesi kriptografi MLS ACTIVE dari room
        $activeCryptoSession = BchatRoomSession::where('room_id', $room->id)
            ->where('status', 'ACTIVE')
            ->first();

        if (!$activeCryptoSession) {
            $hasAnySession = BchatRoomSession::where('room_id', $room->id)->exists();
            if ($hasAnySession) {
                return $this->errorResponse(
                    'Ruang obrolan tidak memiliki sesi kriptografi yang aktif untuk mengunggah lampiran.',
                    'NO_ACTIVE_CRYPTO_SESSION',
                    409
                );
            }

            // Auto-create Generasi 1 aktif jika room legacy/fixture aktif belum memiliki record session
            $activeCryptoSession = BchatRoomSession::create([
                'session_uuid'       => (string) Str::uuid(),
                'room_id'            => $room->id,
                'generation_number'  => 1,
                'status'             => 'ACTIVE',
                'group_id_hash'      => $room->group_id_hash,
                'start_epoch'        => 1,
                'start_sequence'     => 1,
                'current_sequence'   => (int) $room->current_sequence,
                'activated_at'       => now(),
            ]);
        }

        $attachmentUuid = strtolower(trim((string) $request->input('attachment_uuid')));
        $totalChunks = (int) $request->input('total_chunks');
        $ciphertextBytes = (int) $request->input('ciphertext_bytes');
        $plaintextBytes = (int) $request->input('plaintext_bytes');
        $fileHashSha256 = $request->has('file_hash_sha256')
            ? strtolower(trim((string) $request->input('file_hash_sha256')))
            : str_repeat('0', 64);

        try {
            $result = DB::transaction(function () use (
                $room,
                $user,
                $activeCryptoSession,
                $attachmentUuid,
                $totalChunks,
                $ciphertextBytes,
                $plaintextBytes,
                $fileHashSha256
            ) {
                // Periksa apakah attachment_uuid sudah pernah digunakan
                $existingAttachment = BchatAttachment::where('attachment_uuid', $attachmentUuid)->first();
                if ($existingAttachment) {
                    throw new BchatAttachmentException(
                        'Konflik identitas: attachment_uuid sudah terdaftar pada sistem.',
                        'ATTACHMENT_UUID_CONFLICT',
                        409
                    );
                }

                $sessionUuid = (string) Str::uuid();

                $session = BchatUploadSession::create([
                    'session_uuid' => $sessionUuid,
                    'room_id'      => $room->id,
                    'user_id'      => $user->id,
                    'total_bytes'  => $ciphertextBytes,
                    'total_chunks' => $totalChunks,
                    'status'       => 'UPLOADING',
                    'expires_at'   => now()->addMinutes(120), // TTL 120 menit
                ]);

                $attachment = BchatAttachment::create([
                    'attachment_uuid'   => $attachmentUuid,
                    'upload_session_id' => $session->id,
                    'room_id'           => $room->id,
                    'session_id'        => $activeCryptoSession->id,
                    'message_id'        => null, // Diisi nanti saat pesan manifest terkirim
                    'plaintext_bytes'   => $plaintextBytes,
                    'ciphertext_bytes'  => $ciphertextBytes,
                    'total_chunks'      => $totalChunks,
                    'file_hash_sha256'  => $fileHashSha256,
                    'status'            => 'PENDING_MESSAGE',
                ]);

                return [
                    'session'    => $session,
                    'attachment' => $attachment,
                ];
            });

            return response()->json([
                'status'  => 'success',
                'message' => 'Sesi unggah lampiran berhasil dibuat.',
                'data'    => [
                    'session_uuid'     => $result['session']->session_uuid,
                    'attachment_uuid'  => $result['attachment']->attachment_uuid,
                    'room_uuid'        => $room->room_uuid,
                    'total_chunks'     => $result['session']->total_chunks,
                    'ciphertext_bytes' => $result['session']->total_bytes,
                    'plaintext_bytes'  => $result['attachment']->plaintext_bytes,
                    'status'           => $result['session']->status,
                    'expires_at'       => $result['session']->expires_at->toIso8601String(),
                ],
            ], 201);
        } catch (BchatAttachmentException $e) {
            return $this->errorResponse($e->getMessage(), $e->getErrorCode(), $e->getHttpStatus());
        } catch (Throwable $e) {
            return $this->errorResponse(
                'Terjadi kesalahan internal saat membuat sesi unggah: ' . $e->getMessage(),
                'INTERNAL_SERVER_ERROR',
                500
            );
        }
    }

    /**
     * [PUT /api/bchat/rooms/{roomUuid}/attachments/upload-session/{sessionUuid}/chunks/{chunkIndex}]
     *
     * Menerima potongan chunk ciphertext biner (application/octet-stream) dan menyimpannya secara atomik.
     */
    public function uploadChunk(
        BchatUploadChunkRequest $request,
        string $roomUuid,
        string $sessionUuid,
        string $chunkIndex
    ): JsonResponse {
        $user = $request->user();
        if (!$user) {
            return $this->errorResponse('Pengguna belum terautentikasi.', 'UNAUTHENTICATED', 401);
        }

        $room = $this->findAndAuthorizeRoom($roomUuid, $user, 'upload');
        if ($room instanceof JsonResponse) {
            return $room;
        }

        $cleanSessionUuid = strtolower(trim($sessionUuid));
        if (!Str::isUuid($cleanSessionUuid)) {
            return $this->errorResponse('Format UUID sesi unggah tidak valid.', 'INVALID_SESSION_UUID', 422);
        }

        // Cari sesi dalam lingkup room (Mencegah probe sesi lintas room)
        $session = BchatUploadSession::where('room_id', $room->id)
            ->where('session_uuid', $cleanSessionUuid)
            ->first();

        if (!$session) {
            return $this->errorResponse('Sesi unggah tidak ditemukan pada ruang obrolan ini.', 'SESSION_NOT_FOUND', 404);
        }

        // Validasi kepemilikan sesi (Hanya pengguna pembuat sesi yang berhak mengunggah chunk)
        if ((int) $session->user_id !== (int) $user->id) {
            return $this->errorResponse(
                'Anda tidak memiliki izin mengunggah potongan chunk pada sesi milik pengguna lain.',
                'FORBIDDEN_NOT_SESSION_OWNER',
                403
            );
        }

        // Validasi status sesi dan batas kedaluwarsa
        if ($session->isExpired() || $session->status === 'EXPIRED') {
            return $this->errorResponse('Sesi unggah telah kedaluwarsa.', 'SESSION_EXPIRED', 410);
        }

        if ($session->status !== 'UPLOADING') {
            return $this->errorResponse(
                "Sesi unggah tidak dalam status penerimaan berkas (status: {$session->status}).",
                'INVALID_SESSION_STATUS',
                409
            );
        }

        // Validasi indeks chunk numerik integer (zero-based: 0 <= chunkIndex < total_chunks)
        $chunkIndexInt = filter_var($chunkIndex, FILTER_VALIDATE_INT);
        if ($chunkIndexInt === false || $chunkIndexInt < 0 || $chunkIndexInt >= (int) $session->total_chunks) {
            return $this->errorResponse(
                "Indeks chunk ({$chunkIndex}) di luar rentang sah [0.." . ($session->total_chunks - 1) . '].',
                'CHUNK_INDEX_OUT_OF_RANGE',
                422
            );
        }

        // Ambil payload biner mentah dari body request
        $rawBytes = $request->getContent();
        $payloadLength = strlen($rawBytes);

        if ($payloadLength === 0) {
            return $this->errorResponse('Payload ciphertext biner kosong (0 byte ditolak mutlak).', 'EMPTY_CHUNK_PAYLOAD', 422);
        }

        // Validasi batas framing Format A: Li (1 B .. 1.048.576 B) + 28 B framing = 29 B .. 1.048.604 B
        if ($payloadLength < 29 || $payloadLength > 1048604) {
            return $this->errorResponse(
                "Ukuran chunk biner ({$payloadLength} B) melanggar batas framing Format A (29 B .. 1.048.604 B).",
                'INVALID_CHUNK_PAYLOAD_LENGTH',
                422
            );
        }

        $calculatedHash = hash('sha256', $rawBytes);

        // Verifikasi kesesuaian hash jika dikirimkan oleh klien
        $expectedHash = $request->header('X-Payload-Hash') ?? $request->input('chunk_hash_sha256');
        if ($expectedHash !== null) {
            $cleanExpected = strtolower(trim((string) $expectedHash));
            if (!hash_equals($cleanExpected, $calculatedHash)) {
                return $this->errorResponse(
                    'Hash SHA-256 fisik tidak cocok dengan hash yang dikirimkan.',
                    'PAYLOAD_HASH_MISMATCH',
                    422
                );
            }
        }

        $attachment = $session->attachment;
        if (!$attachment) {
            return $this->errorResponse('Relasi berkas lampiran tidak ditemukan pada sesi ini.', 'ATTACHMENT_NOT_FOUND', 500);
        }

        try {
            // Evaluasi Idempotensi dan Pencegahan Konflik Duplikasi Hash
            $existingChunk = BchatAttachmentChunk::where('attachment_id', $attachment->id)
                ->where('chunk_index', $chunkIndexInt)
                ->first();

            if ($existingChunk) {
                if (hash_equals(strtolower($existingChunk->storage_hash_sha256), $calculatedHash)) {
                    // Idempotent hit: chunk identik sudah pernah berhasil disimpan
                    return response()->json([
                        'status'  => 'success',
                        'message' => 'Potongan chunk sudah pernah diterima sebelumnya (idempoten).',
                        'data'    => [
                            'session_uuid'        => $session->session_uuid,
                            'attachment_uuid'     => $attachment->attachment_uuid,
                            'chunk_index'         => $existingChunk->chunk_index,
                            'payload_length'      => $existingChunk->payload_length,
                            'storage_hash_sha256' => $existingChunk->storage_hash_sha256,
                            'is_replayed'         => true,
                        ],
                    ], 200);
                }

                // Konflik: chunk dengan indeks yang sama memiliki hash berbeda
                return $this->errorResponse(
                    'Benturan data: chunk dengan indeks ini sudah tersimpan dengan isi/hash yang berbeda.',
                    'CHUNK_HASH_CONFLICT',
                    409
                );
            }

            // Simpan biner ciphertext ke disk privat Laravel
            $storeResult = $this->attachmentService->storeCiphertextChunk($rawBytes, $calculatedHash);

            // Simpan rekaman chunk ke basis data
            $chunk = BchatAttachmentChunk::create([
                'attachment_id'       => $attachment->id,
                'chunk_index'         => $chunkIndexInt,
                'payload_length'      => $storeResult['payload_length'],
                'storage_path'        => $storeResult['storage_path'],
                'storage_hash_sha256' => $storeResult['storage_hash_sha256'],
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'Potongan chunk ciphertext berhasil disimpan.',
                'data'    => [
                    'session_uuid'        => $session->session_uuid,
                    'attachment_uuid'     => $attachment->attachment_uuid,
                    'chunk_index'         => $chunk->chunk_index,
                    'payload_length'      => $chunk->payload_length,
                    'storage_hash_sha256' => $chunk->storage_hash_sha256,
                    'is_replayed'         => false,
                ],
            ], 201);
        } catch (BchatAttachmentException $e) {
            return $this->errorResponse($e->getMessage(), $e->getErrorCode(), $e->getHttpStatus());
        } catch (Throwable $e) {
            return $this->errorResponse(
                'Terjadi kesalahan internal saat menyimpan chunk: ' . $e->getMessage(),
                'INTERNAL_SERVER_ERROR',
                500
            );
        }
    }

    /**
     * [POST /api/bchat/rooms/{roomUuid}/attachments/upload-session/{sessionUuid}/complete]
     *
     * Memfinalisasi sesi unggah setelah seluruh chunk terkumpul lengkap dan konsisten.
     */
    public function completeUploadSession(Request $request, string $roomUuid, string $sessionUuid): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return $this->errorResponse('Pengguna belum terautentikasi.', 'UNAUTHENTICATED', 401);
        }

        $room = $this->findAndAuthorizeRoom($roomUuid, $user, 'upload');
        if ($room instanceof JsonResponse) {
            return $room;
        }

        $cleanSessionUuid = strtolower(trim($sessionUuid));
        if (!Str::isUuid($cleanSessionUuid)) {
            return $this->errorResponse('Format UUID sesi unggah tidak valid.', 'INVALID_SESSION_UUID', 422);
        }

        try {
            $result = DB::transaction(function () use ($room, $user, $cleanSessionUuid, $request) {
                // Kunci baris sesi unggah
                $session = BchatUploadSession::where('room_id', $room->id)
                    ->where('session_uuid', $cleanSessionUuid)
                    ->lockForUpdate()
                    ->first();

                if (!$session) {
                    throw new BchatAttachmentException(
                        'Sesi unggah tidak ditemukan pada ruang obrolan ini.',
                        'SESSION_NOT_FOUND',
                        404
                    );
                }

                if ((int) $session->user_id !== (int) $user->id) {
                    throw new BchatAttachmentException(
                        'Anda tidak memiliki izin memfinalisasi sesi milik pengguna lain.',
                        'FORBIDDEN_NOT_SESSION_OWNER',
                        403
                    );
                }

                // Idempotent hit: jika sudah selesai, kembalikan rekaman yang ada
                if ($session->status === 'COMPLETED') {
                    return [
                        'session'     => $session,
                        'attachment'  => $session->attachment,
                        'is_replayed' => true,
                    ];
                }

                if ($session->isExpired() || $session->status === 'EXPIRED') {
                    throw new BchatAttachmentException('Sesi unggah telah kedaluwarsa.', 'SESSION_EXPIRED', 410);
                }

                if ($session->status !== 'UPLOADING') {
                    throw new BchatAttachmentException(
                        "Sesi unggah tidak dapat difinalisasi pada status {$session->status}.",
                        'INVALID_SESSION_STATUS',
                        409
                    );
                }

                $attachment = $session->attachment()->lockForUpdate()->first();
                if (!$attachment) {
                    throw new BchatAttachmentException('Record lampiran tidak ditemukan.', 'ATTACHMENT_NOT_FOUND', 500);
                }

                // Validasi bahwa sesi kriptografi tempat upload dimulai masih ACTIVE dan tidak berganti generasi
                $currentActiveSession = BchatRoomSession::where('room_id', $room->id)
                    ->where('status', 'ACTIVE')
                    ->lockForUpdate()
                    ->first();

                if (!$currentActiveSession) {
                    throw new BchatAttachmentException(
                        'Ruang obrolan tidak memiliki sesi kriptografi yang aktif untuk memfinalisasi lampiran.',
                        'NO_ACTIVE_CRYPTO_SESSION',
                        409
                    );
                }

                if ($attachment->session_id !== null && (int) $attachment->session_id !== (int) $currentActiveSession->id) {
                    throw new BchatAttachmentException(
                        'Sesi kriptografi tempat lampiran ini diunggah sudah tidak aktif atau telah berganti generasi.',
                        'CRYPTO_SESSION_MISMATCH',
                        409
                    );
                }

                if ($attachment->session_id === null) {
                    $attachment->session_id = $currentActiveSession->id;
                }

                // Ambil seluruh chunk yang tersimpan
                $chunks = BchatAttachmentChunk::where('attachment_id', $attachment->id)
                    ->orderBy('chunk_index', 'asc')
                    ->lockForUpdate()
                    ->get();

                // 1. Verifikasi kelengkapan jumlah chunk
                if ($chunks->count() !== (int) $session->total_chunks) {
                    throw new BchatAttachmentException(
                        "Finalisasi ditolak: jumlah chunk belum lengkap (ditemukan {$chunks->count()} dari {$session->total_chunks} chunk).",
                        'MISSING_CHUNKS',
                        422
                    );
                }

                // 2. Verifikasi indeks chunk berurutan tanpa celah
                $accumulatedBytes = 0;
                foreach ($chunks as $idx => $chunk) {
                    if ((int) $chunk->chunk_index !== $idx) {
                        throw new BchatAttachmentException(
                            "Integritas indeks chunk terputus pada urutan ke-{$idx} (tercatat indeks {$chunk->chunk_index}).",
                            'CHUNK_INDEX_GAP',
                            422
                        );
                    }

                    // Verifikasi fisik keberadaan file pada storage privat
                    if (!$this->attachmentService->hasChunk($chunk->storage_path)) {
                        throw new BchatAttachmentException(
                            "Chunk index {$idx} hilang dari media penyimpanan fisik.",
                            'STORAGE_CHUNK_MISSING',
                            500
                        );
                    }

                    $accumulatedBytes += (int) $chunk->payload_length;
                }

                // 3. Verifikasi total byte ciphertext terakumulasi
                if ($accumulatedBytes !== (int) $session->total_bytes) {
                    throw new BchatAttachmentException(
                        "Total byte ciphertext terakumulasi ({$accumulatedBytes} B) tidak cocok dengan deklarasi sesi ({$session->total_bytes} B).",
                        'TOTAL_BYTES_MISMATCH',
                        422
                    );
                }

                // 4. Update file hash jika dikirim pada payload complete
                if ($request->has('file_hash_sha256')) {
                    $cleanFileHash = strtolower(trim((string) $request->input('file_hash_sha256')));
                    if (!preg_match('/^[0-9a-f]{64}$/', $cleanFileHash)) {
                        throw new BchatAttachmentException('Format file_hash_sha256 tidak valid.', 'INVALID_FILE_HASH', 422);
                    }
                    $attachment->file_hash_sha256 = $cleanFileHash;
                }

                // Finalisasi status sesi dan attachment
                $session->status = 'COMPLETED';
                $session->save();

                $attachment->status = 'PENDING_MESSAGE';
                $attachment->save();

                return [
                    'session'     => $session,
                    'attachment'  => $attachment,
                    'is_replayed' => false,
                ];
            });

            return response()->json([
                'status'  => 'success',
                'message' => $result['is_replayed']
                    ? 'Sesi unggah sudah pernah difinalisasi sebelumnya (idempoten).'
                    : 'Sesi unggah lampiran berhasil difinalisasi.',
                'data'    => [
                    'session_uuid'     => $result['session']->session_uuid,
                    'attachment_uuid'  => $result['attachment']->attachment_uuid,
                    'room_uuid'        => $room->room_uuid,
                    'status'           => $result['attachment']->status,
                    'total_chunks'     => $result['attachment']->total_chunks,
                    'ciphertext_bytes' => $result['attachment']->ciphertext_bytes,
                    'plaintext_bytes'  => $result['attachment']->plaintext_bytes,
                    'file_hash_sha256' => $result['attachment']->file_hash_sha256,
                ],
            ], 200);
        } catch (BchatAttachmentException $e) {
            return $this->errorResponse($e->getMessage(), $e->getErrorCode(), $e->getHttpStatus());
        } catch (Throwable $e) {
            return $this->errorResponse(
                'Terjadi kesalahan internal saat memfinalisasi sesi unggah: ' . $e->getMessage(),
                'INTERNAL_SERVER_ERROR',
                500
            );
        }
    }

    /**
     * [GET /api/bchat/rooms/{roomUuid}/attachments/{attachmentUuid}/chunks/{chunkIndex}]
     *
     * Mengunduh potongan chunk ciphertext biner tanpa membocorkan path penyimpanan privat.
     */
    public function downloadChunk(
        Request $request,
        string $roomUuid,
        string $attachmentUuid,
        string $chunkIndex
    ): Response|JsonResponse {
        $user = $request->user();
        if (!$user) {
            return $this->errorResponse('Pengguna belum terautentikasi.', 'UNAUTHENTICATED', 401);
        }

        $room = $this->findAndAuthorizeRoom($roomUuid, $user, 'download');
        if ($room instanceof JsonResponse) {
            return $room;
        }

        $cleanAttUuid = strtolower(trim($attachmentUuid));
        if (!Str::isUuid($cleanAttUuid)) {
            return $this->errorResponse('Format UUID lampiran tidak valid.', 'INVALID_ATTACHMENT_UUID', 422);
        }

        // Cari lampiran dalam lingkup room yang sama (Isolasi room ketat)
        $attachment = BchatAttachment::where('room_id', $room->id)
            ->where('attachment_uuid', $cleanAttUuid)
            ->first();

        if (!$attachment) {
            return $this->errorResponse('Lampiran tidak ditemukan pada ruang obrolan ini.', 'ATTACHMENT_NOT_FOUND', 404);
        }

        $chunkIndexInt = filter_var($chunkIndex, FILTER_VALIDATE_INT);
        if ($chunkIndexInt === false || $chunkIndexInt < 0) {
            return $this->errorResponse('Indeks chunk tidak valid.', 'INVALID_CHUNK_INDEX', 422);
        }

        $chunk = $attachment->chunks()->where('chunk_index', $chunkIndexInt)->first();
        if (!$chunk) {
            return $this->errorResponse('Potongan chunk tidak ditemukan pada lampiran ini.', 'CHUNK_NOT_FOUND', 404);
        }

        try {
            $bytes = $this->attachmentService->getCiphertextChunk($chunk->storage_path);

            return response($bytes, 200, [
                'Content-Type'          => 'application/octet-stream',
                'Content-Length'        => (string) $chunk->payload_length,
                'X-Storage-Hash-Sha256' => $chunk->storage_hash_sha256,
                'Cache-Control'         => 'private, no-cache, no-store, must-revalidate',
                'Pragma'                => 'no-cache',
            ]);
        } catch (BchatAttachmentException $e) {
            return $this->errorResponse($e->getMessage(), $e->getErrorCode(), $e->getHttpStatus());
        } catch (Throwable $e) {
            return $this->errorResponse(
                'Terjadi kesalahan internal saat membaca chunk: ' . $e->getMessage(),
                'INTERNAL_SERVER_ERROR',
                500
            );
        }
    }

    /**
     * Helper untuk memvalidasi format room_uuid, memeriksa keberadaan room,
     * serta mengotorisasi akses pengguna menggunakan BchatAttachmentPolicy.
     */
    protected function findAndAuthorizeRoom(string $roomUuid, $user, string $action): BchatRoom|JsonResponse
    {
        $cleanRoomUuid = strtolower(trim($roomUuid));
        if (!Str::isUuid($cleanRoomUuid)) {
            return $this->errorResponse('Format Room UUID tidak valid.', 'INVALID_ROOM_UUID', 422);
        }

        $room = BchatRoom::where('room_uuid', $cleanRoomUuid)->first();
        if (!$room) {
            return $this->errorResponse('Ruang obrolan tidak ditemukan.', 'ROOM_NOT_FOUND', 404);
        }

        // Validasi perangkat aktif (B-CHAT Tahap 1B)
        $deviceError = $this->validateActiveDevice($user);
        if ($deviceError instanceof JsonResponse) {
            return $deviceError;
        }

        $isAuthorized = match ($action) {
            'upload'   => $this->attachmentPolicy->upload($user, $room),
            'download' => $this->attachmentPolicy->viewAny($user, $room),
            default    => false,
        };

        if (!$isAuthorized) {
            return $this->errorResponse(
                'Pengguna tidak memiliki izin untuk melakukan tindakan ini pada ruang obrolan.',
                'FORBIDDEN',
                403
            );
        }

        return $room;
    }

    /**
     * Helper standardisasi format respons JSON galat.
     */
    protected function errorResponse(string $message, string $errorCode, int $httpStatus): JsonResponse
    {
        return response()->json([
            'status'     => 'error',
            'message'    => $message,
            'error_code' => $errorCode,
        ], $httpStatus);
    }

    /**
     * Memvalidasi bahwa pengguna memiliki perangkat aktif dan tepercaya,
     * serta perangkat pemanggil tidak berstatus REVOKED atau UNTRUSTED.
     */
    protected function validateActiveDevice($user): ?JsonResponse
    {
        $requestDeviceUuid = request()->header('X-Bchat-Device-Uuid');
        if ($requestDeviceUuid !== null && trim($requestDeviceUuid) !== '') {
            $cleanUuid = strtolower(trim($requestDeviceUuid));
            $device = BchatUserDevice::where('user_id', $user->id)
                ->where('device_uuid', $cleanUuid)
                ->first();

            if (!$device || $device->status !== 'ACTIVE') {
                return $this->errorResponse(
                    'Perangkat tidak valid atau telah dicabut (REVOKED). Akses lampiran ditolak.',
                    'DEVICE_NOT_ACTIVE',
                    403
                );
            }

            if (!$device->is_trusted) {
                return $this->errorResponse(
                    'Perangkat belum berstatus tepercaya. Lakukan pairing terlebih dahulu.',
                    'DEVICE_NOT_TRUSTED',
                    403
                );
            }

            return null;
        }

        if (BchatUserDevice::where('user_id', $user->id)->exists()) {
            $hasActive = BchatUserDevice::where('user_id', $user->id)
                ->where('status', 'ACTIVE')
                ->exists();

            if (!$hasActive) {
                return $this->errorResponse(
                    'Pengguna tidak memiliki perangkat berstatus aktif. Akses lampiran ditolak.',
                    'DEVICE_NOT_ACTIVE',
                    403
                );
            }

            $hasTrusted = BchatUserDevice::where('user_id', $user->id)
                ->where('status', 'ACTIVE')
                ->where('is_trusted', true)
                ->exists();

            if (!$hasTrusted) {
                return $this->errorResponse(
                    'Pengguna tidak memiliki perangkat tepercaya yang aktif. Lakukan pairing terlebih dahulu.',
                    'DEVICE_NOT_TRUSTED',
                    403
                );
            }
        }

        return null;
    }
}

