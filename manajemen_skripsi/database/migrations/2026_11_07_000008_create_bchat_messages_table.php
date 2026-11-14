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
        Schema::create('bchat_messages', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('room_id');
            $table->foreign('room_id', 'fk_msg_room')
                ->references('id')
                ->on('bchat_rooms')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->unsignedBigInteger('server_sequence')->comment('Monotonik naik per room; celah akibat rollback ditoleransi');
            $table->char('message_uuid', 36)->comment('UUID v4 pesan yang dibangkitkan klien (lingkup per-room)');

            $table->unsignedBigInteger('sender_id')->comment('FK ke users(id) pengirim pesan');
            $table->foreign('sender_id', 'fk_msg_sender')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->unsignedBigInteger('epoch')->comment('Epoch saat pesan dienkripsi (uint64 RFC 9420)');
            $table->unsignedInteger('generation')->comment('Generasi ratchet per-pengirim (TreeKEM)');
            $table->unsignedTinyInteger('content_type')->default(1)->comment('1: TEXT, 2: ATTACHMENT_MANIFEST, 3: CONTROL_RECEIPT');

            $table->rawColumn('ciphertext_payload', 'longblob')->comment('Paket MLS PrivateMessage biner (TreeKEM ratcheted)');
            $table->char('payload_hash_sha256', 64)->comment('Hash SHA-256 verifikasi integritas fisik payload at-rest');
            $table->timestamp('client_sent_at')->comment('Waktu lokal pengiriman di peramban klien');

            $table->timestamps();

            // Constraint Unik Sekuens Per Room
            $table->unique(['room_id', 'server_sequence'], 'uk_room_sequence');

            // Constraint Idempotensi Mutlak (Scope: Ruang, Pengirim, UUID Pesan)
            $table->unique(['room_id', 'sender_id', 'message_uuid'], 'uk_msg_idempotency');

            // Target Composite Key bagi tabel bchat_attachments
            $table->unique(['id', 'room_id'], 'uk_msg_id_room');

            $table->index(['room_id', 'server_sequence'], 'idx_msg_pagination');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bchat_messages');
    }
};

