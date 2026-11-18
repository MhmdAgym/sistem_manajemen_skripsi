<?php

use App\Services\BchatDeviceService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Buat tabel tantangan autentikasi kriptografis WebSocket (Signed WebSocket Auth Challenge)
        Schema::create('bchat_ws_auth_challenges', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id')->comment('FK ke users(id) pemilik sesi');
            $table->foreign('user_id', 'fk_ws_challenge_user')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->unsignedBigInteger('device_id')->comment('FK ke bchat_user_devices(id)');
            $table->foreign('device_id', 'fk_ws_challenge_device')
                ->references('id')
                ->on('bchat_user_devices')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->char('challenge_uuid', 36)->unique('uk_ws_challenge_uuid')->comment('UUID v4 sesi challenge');
            $table->char('device_uuid', 36)->comment('UUID perangkat penandatangan');
            $table->string('socket_id', 100)->comment('Socket ID Reverb/Pusher');
            $table->string('channel_name', 255)->comment('Nama kanal privat (private-bchat.rooms.{uuid})');
            $table->char('challenge_nonce', 64)->unique('uk_ws_challenge_nonce')->comment('Nonce 256-bit acak kanonikal');
            $table->enum('status', ['ISSUED', 'CONSUMED', 'EXPIRED'])->default('ISSUED')->comment('Status siklus hidup challenge');
            $table->timestamp('expires_at')->comment('Batas waktu kedaluwarsa challenge (TTL 60 detik)');
            $table->timestamp('consumed_at')->nullable()->comment('Waktu konsumsi tanda tangan');
            $table->timestamps();

            $table->index(['user_id', 'device_uuid', 'status'], 'idx_ws_challenge_lookup');
            $table->index(['socket_id', 'channel_name'], 'idx_ws_challenge_target');
        });

        // 2. Koreksi Legacy Trust Root Fail-Closed:
        // Evaluasi seluruh perangkat yang saat ini berstatus ACTIVE di bchat_user_devices.
        // HANYA perangkat yang memiliki bukti historis kriptografis nyata yang dipertahankan sebagai is_trusted = true:
        // - Bukti A: Terdaftar pada Welcome Challenge yang CONSUMED dengan ack_signature terverifikasi Ed25519.
        // - Bukti B: Memiliki catatan persetujuan pairing resmi (APPROVED) dengan detached signature terverifikasi.
        // Seluruh perangkat ACTIVE lainnya yang tidak memiliki bukti historis konkret diubah menjadi is_trusted = false (Fail-Closed).
        // Perangkat MANAGED_REPLACEMENT, KeyPackage tanpa ACK, atau kesamaan user_id TIDAK DAPAT dijadikan bukti trust.
        $activeDevices = DB::table('bchat_user_devices')
            ->where('status', 'ACTIVE')
            ->get(['id', 'user_id', 'device_uuid']);

        foreach ($activeDevices as $dev) {
            $isLegitimateTrustRoot = BchatDeviceService::hasHistoricalTrustProof($dev->id);

            if ($isLegitimateTrustRoot) {
                DB::table('bchat_user_devices')
                    ->where('id', $dev->id)
                    ->update([
                        'is_trusted' => true,
                        'trusted_at' => now(),
                    ]);
            } else {
                DB::table('bchat_user_devices')
                    ->where('id', $dev->id)
                    ->update([
                        'is_trusted'           => false,
                        'trusted_at'           => null,
                        'trusted_by_device_id' => null,
                    ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bchat_ws_auth_challenges');
    }
};

