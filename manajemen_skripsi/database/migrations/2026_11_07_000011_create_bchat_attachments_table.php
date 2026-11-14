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
        Schema::create('bchat_attachments', function (Blueprint $table) {
            $table->id();

            $table->char('attachment_uuid', 36)->unique()->comment('UUID v4 lampiran publik');
            $table->unsignedBigInteger('upload_session_id')->comment('FK ke bchat_upload_sessions(id)');
            $table->unsignedBigInteger('room_id');
            $table->unsignedBigInteger('message_id')->nullable()->comment('Nullable saat upload awal; diisi saat pesan manifest terbit');

            $table->unsignedBigInteger('plaintext_bytes')->comment('Ukuran plaintext terdekripsi (maks 10.000.000 byte)');
            $table->unsignedBigInteger('ciphertext_bytes')->comment('Ukuran total fisik ciphertext tergabung (plaintext + N*28 byte)');
            $table->unsignedInteger('total_chunks')->comment('Jumlah chunk biner (1 MiB plaintext per chunk)');
            $table->char('file_hash_sha256', 64)->comment('Hash SHA-256 seluruh ciphertext tergabung');

            $table->enum('status', ['PENDING_MESSAGE', 'ATTACHED', 'ORPHANED'])->default('PENDING_MESSAGE');

            $table->timestamps();

            // Composite FK 1: Mengunci sesi unggah wajib berasal dari room yang sama
            $table->foreign(['upload_session_id', 'room_id'], 'fk_attach_session_composite')
                ->references(['id', 'room_id'])
                ->on('bchat_upload_sessions')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            // Composite FK 2: Mengunci pesan manifest wajib berasal dari room yang sama (Nullable-safe)
            $table->foreign(['message_id', 'room_id'], 'fk_attach_msg_composite')
                ->references(['id', 'room_id'])
                ->on('bchat_messages')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->index(['room_id', 'status'], 'idx_attach_room');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bchat_attachments');
    }
};

