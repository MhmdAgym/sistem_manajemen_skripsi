<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Tambahkan kolom status kepercayaan (is_trusted) pada bchat_user_devices
        Schema::table('bchat_user_devices', function (Blueprint $table) {
            $table->boolean('is_trusted')->default(false)->after('status')->comment('Status kepercayaan perangkat dalam ekosistem E2EE multi-device');
            $table->timestamp('trusted_at')->nullable()->after('is_trusted')->comment('Waktu perangkat memperoleh status tepercaya');
            $table->unsignedBigInteger('trusted_by_device_id')->nullable()->after('trusted_at')->comment('FK perangkat tepercaya yang memberikan persetujuan pairing');

            $table->foreign('trusted_by_device_id', 'fk_device_trusted_by')
                ->references('id')
                ->on('bchat_user_devices')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->index(['user_id', 'is_trusted'], 'idx_device_user_trusted');
        });

        // Catatan: Seluruh perangkat secara default is_trusted = false (Fail-Closed).
        // Tidak ada auto-trust berdasarkan ID, umur, atau status ACTIVE. Status tepercaya
        // hanya diperoleh melalui evaluasi bukti kriptografis sah (Welcome ACK / KeyPackage / Pairing).

        // 2. Buat tabel sesi pairing perangkat tepercaya
        Schema::create('bchat_device_pairings', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id')->comment('FK ke users(id) pemilik akun');
            $table->foreign('user_id', 'fk_pairing_user')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->char('pairing_uuid', 36)->unique('uk_pairing_uuid')->comment('UUID v4 sesi pairing');
            $table->string('pairing_code', 16)->unique('uk_pairing_code')->comment('Kode pairing manusia/QR 8-karakter (contoh: PAIR-ABCD)');

            // Perangkat peminta (Target Device)
            $table->unsignedBigInteger('target_device_id')->comment('FK ke bchat_user_devices(id) perangkat peminta kepercayaan');
            $table->foreign('target_device_id', 'fk_pairing_target_device')
                ->references('id')
                ->on('bchat_user_devices')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->char('target_device_uuid', 36)->comment('UUID perangkat target peminta kepercayaan');
            $table->char('target_public_key_fingerprint', 64)->comment('SHA-256 fingerprint kunci publik perangkat target');

            // Challenge kriptografis server (32 bytes = 64 hex chars)
            $table->char('challenge_nonce', 64)->comment('Nonce acak 256-bit CSPRNG server untuk mencegah replay');

            // Status sesi pairing
            $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED', 'EXPIRED', 'CANCELED'])
                ->default('PENDING')
                ->comment('Status sesi pairing');

            // Perangkat pemberi persetujuan (Approver Device)
            $table->unsignedBigInteger('approver_device_id')->nullable()->comment('FK ke bchat_user_devices(id) perangkat tepercaya yang menyetujui');
            $table->foreign('approver_device_id', 'fk_pairing_approver_device')
                ->references('id')
                ->on('bchat_user_devices')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->char('approver_device_uuid', 36)->nullable()->comment('UUID perangkat pemberi persetujuan');

            // Bukti kriptografis persetujuan
            $table->string('approval_signature', 255)->nullable()->comment('Detached Ed25519 signature hex (128 karakter) dari approver device');
            $table->text('approval_payload')->nullable()->comment('Hex representasi buffer kanonis yang ditandatangani');

            // Stempel waktu daur hidup sesi
            $table->timestamp('approved_at')->nullable()->comment('Waktu persetujuan diverifikasi');
            $table->timestamp('rejected_at')->nullable()->comment('Waktu penolakan');
            $table->string('rejection_reason', 255)->nullable()->comment('Alasan penolakan pairing');
            $table->timestamp('expires_at')->comment('Batas waktu kedaluwarsa sesi pairing (biasanya 5 menit)');

            $table->timestamps();

            // Indeks pendukung performa dan query konkurensi
            $table->index(['user_id', 'status'], 'idx_pairing_user_status');
            $table->index('target_device_uuid', 'idx_pairing_target_uuid');
            $table->index('expires_at', 'idx_pairing_expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bchat_device_pairings');

        Schema::table('bchat_user_devices', function (Blueprint $table) {
            $table->dropForeign('fk_device_trusted_by');
            $table->dropIndex('idx_device_user_trusted');
            $table->dropColumn(['trusted_by_device_id', 'trusted_at', 'is_trusted']);
        });
    }
};

