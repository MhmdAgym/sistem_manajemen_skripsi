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
        Schema::create('bchat_step_up_tokens', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id')->comment('FK ke users(id) terotentikasi step-up');
            $table->foreign('user_id', 'fk_stepup_user')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->char('device_uuid', 36)->comment('UUID v4 target perangkat yang diotorisasi oleh step-up ini');

            $table->char('token_hash', 64)->unique('uk_stepup_token_hash')->comment('Hash SHA-256 dari bearer step-up token');
            $table->string('action_scope', 50)->default('DEVICE_REGISTRATION')->comment('Cakupan aksi yang diizinkan token ini');

            $table->boolean('is_consumed')->default(false)->comment('Flag konsumsi satu kali atomik');
            $table->timestamp('expires_at')->comment('Tenggat waktu berlakunya otorisasi step-up (TTL 5 menit)');
            $table->timestamp('consumed_at')->nullable()->comment('Waktu konsumsi token');

            $table->string('ip_address', 45)->nullable()->comment('Alamat IP klien saat otentikasi step-up');
            $table->text('user_agent')->nullable()->comment('User-Agent peramban saat otentikasi step-up');

            $table->timestamps();

            // Indeks Pendukung
            $table->index(['user_id', 'device_uuid', 'action_scope', 'is_consumed', 'expires_at'], 'idx_stepup_verify');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bchat_step_up_tokens');
    }
};

