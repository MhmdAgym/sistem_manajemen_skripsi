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
        Schema::create('bchat_trust_bootstrap_challenges', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id')->comment('FK ke users(id) pemilik perangkat');
            $table->foreign('user_id', 'fk_bootstrap_challenge_user')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->unsignedBigInteger('device_id')->comment('FK ke bchat_user_devices(id)');
            $table->foreign('device_id', 'fk_bootstrap_challenge_device')
                ->references('id')
                ->on('bchat_user_devices')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->char('challenge_uuid', 36)->unique('uk_bootstrap_challenge_uuid')->comment('UUID v4 sesi challenge');
            $table->char('device_uuid', 36)->comment('UUID perangkat target bootstrap');
            $table->char('public_key_fingerprint', 64)->comment('SHA-256 fingerprint kunci publik Ed25519 perangkat');
            $table->char('challenge_nonce', 64)->unique('uk_bootstrap_challenge_nonce')->comment('Nonce 256-bit CSPRNG server');
            $table->string('purpose', 32)->default('TRUST_BOOTSTRAP')->comment('Tujuan challenge (TRUST_BOOTSTRAP)');
            $table->enum('status', ['ISSUED', 'CONSUMED', 'EXPIRED'])->default('ISSUED')->comment('Status challenge');
            $table->timestamp('expires_at')->comment('Waktu kedaluwarsa challenge');
            $table->timestamp('consumed_at')->nullable()->comment('Waktu konsumsi challenge');
            $table->string('bootstrap_signature', 255)->nullable()->comment('Tanda tangan Ed25519 (hex)');
            $table->text('bootstrap_payload')->nullable()->comment('Payload kanonikal yang ditandatangani');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'device_uuid', 'status'], 'idx_bootstrap_challenge_lookup');
            $table->index('expires_at', 'idx_bootstrap_challenge_expires');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bchat_trust_bootstrap_challenges');
    }
};

