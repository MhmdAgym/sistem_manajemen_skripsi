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
        Schema::create('bchat_welcome_challenges', function (Blueprint $table) {
            $table->id();

            // Konteks Operasi Welcome (Immutable per Operasi)
            $table->char('welcome_operation_uuid', 36)->unique()->comment('UUID v4 unik operasi penerbitan Welcome (immutable)');

            $table->unsignedBigInteger('room_id')->comment('FK ke bchat_rooms');
            $table->foreign('room_id', 'fk_challenge_room')
                ->references('id')
                ->on('bchat_rooms')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->unsignedBigInteger('recipient_user_id')->comment('FK ke users(id) anggota penerima');
            $table->foreign('recipient_user_id', 'fk_challenge_recipient')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->rawColumn('welcome_message', 'longblob')->comment('Paket Welcome biner terenkripsi HPKE (immutable per operasi)');
            $table->char('group_id_hash', 64)->comment('SHA-256 hash dari MLS GroupContext.group_id');
            $table->unsignedBigInteger('epoch')->comment('Epoch target Welcome yang dibawa operasi ini (uint64 RFC 9420)');

            $table->enum('operation_status', ['ACTIVE', 'CONSUMED', 'REVOKED_SUPERSEDED'])
                ->default('ACTIVE')
                ->comment('Status daur hidup operasi Welcome induk');

            // Data Challenge Aktif (Dimutasi saat Rotasi Generasi Nonce)
            $table->char('challenge_uuid', 36)->unique()->comment('UUID v4 unik tantangan aktif saat ini');
            $table->char('challenge_nonce', 64)->unique()->comment('Entropy acak 32-byte (hex) anti-replay generasi aktif');
            $table->unsignedInteger('challenge_generation')->default(1)->comment('Nomor generasi tantangan (1, 2, 3...) untuk operasi ini');

            $table->enum('challenge_status', ['ISSUED', 'CONSUMED', 'EXPIRED'])
                ->default('ISSUED')
                ->comment('Status tantangan generasi aktif saat ini');

            $table->timestamp('expires_at')->comment('Tenggat waktu berlakunya tantangan generasi aktif (TTL 5 menit)');

            // Audit Konsumsi ACK & Metadata Forensik
            $table->timestamp('consumed_at')->nullable()->comment('Waktu verifikasi tanda tangan digital ACK berhasil');
            $table->char('ack_payload_hash', 64)->nullable()->comment('Hash SHA-256 Canonical ACK Payload 158-byte bentukan backend');
            $table->text('ack_signature')->nullable()->comment('Tanda tangan digital Ed25519 dari anggota penerima');
            $table->string('ip_address', 45)->nullable()->comment('Alamat IP saat verifikasi ACK');
            $table->text('user_agent')->nullable()->comment('User-Agent peramban saat verifikasi ACK');

            // Concurrency Control (Multi-NULL Safe Virtual Slot)
            $table->string('active_operation_slot', 64)->virtualAs(
                "CASE WHEN `operation_status` = 'ACTIVE' THEN CONCAT(`room_id`, '_', `recipient_user_id`) ELSE NULL END"
            )->nullable()->comment('Slot penjamin maksimal 1 operasi Welcome aktif per room & penerima');

            $table->timestamps();

            // Constraints Integritas & Indeks
            $table->unique('active_operation_slot', 'uk_challenge_active_slot');
            $table->index(['room_id', 'recipient_user_id', 'operation_status'], 'idx_challenge_lookup');
            $table->index(['challenge_uuid', 'challenge_status', 'expires_at'], 'idx_challenge_verify');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bchat_welcome_challenges');
    }
};

