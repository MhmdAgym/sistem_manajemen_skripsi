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
        Schema::create('bchat_user_devices', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id')->comment('FK ke users(id) pemilik perangkat');
            $table->foreign('user_id', 'fk_device_user')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->char('device_uuid', 36)->comment('UUID v4 unik identitas perangkat keras klien');
            $table->string('device_name', 100)->comment('Nama/label peramban atau perangkat klien');
            $table->unsignedInteger('key_version')->default(1)->comment('Versi kunci monotonik per device_uuid');

            $table->char('public_key', 64)->unique('uk_device_public_key')->comment('Kunci publik Ed25519 dedicated ACK identity unik global (64 karakter lowercase hex)');
            $table->char('public_key_fingerprint', 64)->comment('Hash SHA-256 dari public key (64 karakter lowercase hex)');

            $table->enum('status', ['ACTIVE', 'REVOKED', 'EXPIRED'])
                ->default('ACTIVE')
                ->comment('Status daur hidup perangkat (hanya boleh ada 1 ACTIVE per user)');

            $table->timestamp('revoked_at')->nullable()->comment('Waktu perangkat dicabut status aktifnya');
            $table->string('revocation_reason', 255)->nullable()->comment('Alasan pencabutan (e.g. MANAGED_REPLACEMENT, SECURITY_REVOCATION)');
            $table->timestamp('last_active_at')->nullable()->comment('Waktu terakhir aktivitas perangkat terdeteksi');

            // Concurrency Control (Multi-NULL Safe Virtual Slot untuk Single Active Device)
            $table->string('active_user_slot', 64)->virtualAs(
                "CASE WHEN `status` = 'ACTIVE' THEN CONCAT('user_', `user_id`) ELSE NULL END"
            )->nullable()->comment('Slot penjamin maksimal 1 perangkat aktif per pengguna');

            $table->timestamps();

            // Constraints Integritas & Indeks
            $table->unique('active_user_slot', 'uk_device_active_user_slot');
            $table->unique(['user_id', 'device_uuid', 'key_version'], 'uk_device_user_uuid_version');
            $table->index(['user_id', 'status'], 'idx_device_user_status');
            $table->index('device_uuid', 'idx_device_uuid');
            $table->index('public_key_fingerprint', 'idx_device_pubkey_fingerprint');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bchat_user_devices');
    }
};

