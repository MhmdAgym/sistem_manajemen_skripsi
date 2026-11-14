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
        Schema::create('bchat_registration_nonces', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id')->comment('FK ke users(id) pemohon registrasi');
            $table->foreign('user_id', 'fk_regnonce_user')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->char('device_uuid', 36)->comment('UUID v4 identitas perangkat yang memohon');
            $table->char('nonce_uuid', 36)->unique('uk_regnonce_uuid')->comment('UUID v4 publik identifier sesi challenge registrasi');
            $table->char('nonce_value', 64)->unique('uk_regnonce_value')->comment('Entropy acak kriptografis 32-byte (64 hex lowercase)');
            $table->char('public_key_fingerprint', 64)->comment('Hash SHA-256 public key perangkat yang diikat ke nonce ini');

            $table->enum('status', ['ISSUED', 'CONSUMED', 'EXPIRED'])
                ->default('ISSUED')
                ->comment('Status daur hidup nonce registrasi');

            $table->unsignedBigInteger('issued_at')->comment('Timestamp kanonis server (epoch uint64) yang wajib dimuat dalam PoP');
            $table->timestamp('expires_at')->comment('Tenggat waktu berlakunya nonce (TTL 5 menit)');
            $table->timestamp('consumed_at')->nullable()->comment('Waktu konsumsi atomik saat registrasi berhasil');

            $table->timestamps();

            // Indeks Pendukung
            $table->index(['user_id', 'status', 'expires_at'], 'idx_regnonce_lookup');
            $table->index(['device_uuid', 'status'], 'idx_regnonce_device');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bchat_registration_nonces');
    }
};

