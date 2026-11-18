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
        Schema::create('bchat_history_transfers', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id')->comment('FK ke users(id) pemilik akun');
            $table->foreign('user_id', 'fk_history_transfer_user')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->unsignedBigInteger('room_id')->comment('FK ke bchat_rooms(id) ruang obrolan');
            $table->foreign('room_id', 'fk_history_transfer_room')
                ->references('id')
                ->on('bchat_rooms')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->char('transfer_uuid', 36)->unique('uk_history_transfer_uuid')->comment('UUID v4 sesi transfer riwayat');
            $table->char('room_uuid', 36)->comment('UUID room target');

            // Perangkat Penerima (Target penerima transfer riwayat)
            $table->unsignedBigInteger('recipient_device_id')->comment('FK ke bchat_user_devices(id) perangkat penerima');
            $table->foreign('recipient_device_id', 'fk_history_transfer_recipient')
                ->references('id')
                ->on('bchat_user_devices')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->char('recipient_device_uuid', 36)->comment('UUID perangkat penerima');

            // Perangkat Sumber (Pengunggah snapshot riwayat terenkripsi)
            $table->unsignedBigInteger('source_device_id')->comment('FK ke bchat_user_devices(id) perangkat sumber');
            $table->foreign('source_device_id', 'fk_history_transfer_source')
                ->references('id')
                ->on('bchat_user_devices')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->char('source_device_uuid', 36)->comment('UUID perangkat sumber');

            // Kriptografi Pertukaran Kunci Sementara (Ephemeral Key Agreement)
            $table->string('key_exchange_algorithm', 32)->default('ECDH-P256')->comment('Algoritma pertukaran kunci ephemeral (ECDH-P256 atau X25519)');
            $table->text('recipient_ephemeral_public_key')->comment('Kunci publik ephemeral perangkat penerima (hex/base64)');
            $table->text('source_ephemeral_public_key')->nullable()->comment('Kunci publik ephemeral perangkat sumber (hex/base64)');
            $table->char('transfer_nonce', 64)->comment('Nonce acak 32-byte (64 hex) pencegah replay');

            // Payload & Tanda Tangan Digital Permintaan Transfer oleh Penerima
            $table->text('request_payload')->nullable()->comment('Kanonikal payload permintaan transfer');
            $table->char('request_signature', 128)->comment('Tanda tangan digital Ed25519 perangkat penerima (128 hex chars)');

            // Status Sesi Transfer
            $table->enum('status', [
                'REQUESTED',   // Dibuat oleh penerima, menunggu sumber
                'READY',       // Sumber telah mengunggah ciphertext snapshot
                'COMPLETED',   // Penerima telah mengunduh, mendekripsi, dan mengimpor
                'CANCELLED',   // Dibatalkan oleh pengguna
                'EXPIRED',     // Kedaluwarsa melebihi TTL
                'FAILED',      // Gagal verifikasi integritas
            ])->default('REQUESTED')->comment('Status siklus hidup transfer riwayat');

            // Snapshot Riwayat Terenkripsi (HANYA CIPHERTEXT, SERVER ZERO PLAINTEXT)
            $table->longText('snapshot_ciphertext')->nullable()->comment('Ciphertext snapshot riwayat AES-256-GCM (Base64)');
            $table->char('snapshot_hash_sha256', 64)->nullable()->comment('Hash SHA-256 integritas snapshot ciphertext');
            $table->char('snapshot_iv', 32)->nullable()->comment('IV/nonce acak AES-GCM (hex)');
            $table->char('snapshot_auth_tag', 32)->nullable()->comment('Tag autentikasi AES-GCM 128-bit (hex)');
            $table->json('snapshot_metadata')->nullable()->comment('Metadata operasional: message_count, session_generations');

            // Tanda Tangan Digital Pengesahan Snapshot oleh Perangkat Sumber
            $table->char('source_signature', 128)->nullable()->comment('Tanda tangan digital Ed25519 perangkat sumber atas snapshot (128 hex chars)');

            // Tanda Tangan Penyelesaian oleh Perangkat Penerima
            $table->char('completion_signature', 128)->nullable()->comment('Tanda tangan digital Ed25519 penerima saat konfirmasi impor');

            // Stempel Waktu Siklus Hidup
            $table->timestamp('uploaded_at')->nullable()->comment('Waktu ciphertext diunggah oleh sumber');
            $table->timestamp('completed_at')->nullable()->comment('Waktu transfer berhasil diselesaikan');
            $table->timestamp('cancelled_at')->nullable()->comment('Waktu transfer dibatalkan');
            $table->string('cancellation_reason', 255)->nullable()->comment('Alasan pembatalan');
            $table->timestamp('expires_at')->comment('Waktu kedaluwarsa sesi transfer');
            $table->timestamps();

            // Indeks performa & integritas
            $table->index(['user_id', 'status'], 'idx_ht_user_status');
            $table->index(['room_id', 'recipient_device_id', 'status'], 'idx_ht_room_recipient_status');
            $table->index(['source_device_id', 'status'], 'idx_ht_source_status');
            $table->index('expires_at', 'idx_ht_expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bchat_history_transfers');
    }
};

