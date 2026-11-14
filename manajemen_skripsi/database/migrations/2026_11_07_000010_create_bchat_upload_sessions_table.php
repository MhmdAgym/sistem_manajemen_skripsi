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
        Schema::create('bchat_upload_sessions', function (Blueprint $table) {
            $table->id();

            $table->char('session_uuid', 36)->unique()->comment('UUID v4 publik sesi unggah (digunakan pada AAD EXP-02)');

            $table->unsignedBigInteger('room_id')->comment('FK ke bchat_rooms');
            $table->foreign('room_id', 'fk_upsess_room')
                ->references('id')
                ->on('bchat_rooms')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->unsignedBigInteger('user_id')->comment('FK ke users(id) pengunggah');
            $table->foreign('user_id', 'fk_upsess_user')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->unsignedBigInteger('total_bytes')->comment('Ukuran total ciphertext tergabung (maks 10.000.000 byte plaintext + overhead)');
            $table->unsignedInteger('total_chunks')->comment('Jumlah potongan chunk (1 MiB plaintext per chunk)');
            $table->enum('status', ['UPLOADING', 'COMPLETED', 'FAILED_ABANDONED', 'EXPIRED'])->default('UPLOADING');
            $table->timestamp('expires_at')->comment('Waktu kedaluwarsa sesi (TTL 120 menit)');

            $table->timestamps();

            // Target Composite Key bagi tabel bchat_attachments
            $table->unique(['id', 'room_id'], 'uk_upsess_id_room');

            $table->index(['status', 'expires_at'], 'idx_upsess_cleanup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bchat_upload_sessions');
    }
};

